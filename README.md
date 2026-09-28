# unraid-docker-orphan-cleaner

An Unraid plugin, `docker.orphan.cleaner`, that lists Docker images no
container references, classifies how safe each one is to remove, and deletes
the selected images in bulk. Unraid only offers one-at-a-time removal.

> Status: **phase 1** (build + test). Not yet listed in Community
> Applications. The CA listing entry lives in [`ca/`](ca/) for review and is
> not published anywhere.

## Install (manual, for testing)

Plugins ▸ Install Plugin, and paste:

```
https://raw.githubusercontent.com/bitcryptic-gw/unraid-docker-orphan-cleaner/main/plugin/docker.orphan.cleaner.plg
```

Requires Unraid 7.0.0 or newer. The plugin appears in the **Tasks** menu, next
to Docker. Settings are on the same page.

## What it does

- Lists every orphan image (no container, running or stopped, references it).
- Classifies each orphan, in precedence order:
  1. **Pinned** - matches a user pin pattern. Never deleted, in the UI or by a
     scheduled run.
  2. **Template** - `repo:tag` matches a `<Repository>` in
     `/boot/config/plugins/dockerMan/templates-user/*.xml`. The container was
     removed but the template remains, so the user may reinstall it.
  3. **Compose** - `repo:tag` appears as an `image:` in a Docker Compose stack.
     A stack that is down makes its images look orphaned.
  4. **Tagged** - has a real tag, nothing references it.
  5. **Untagged** - `<none>`. Ticked by default.
- Shows image id, tags, created date, size and whether the image has children.
- Deletes in bulk, one image per API call, never forced. A refused parent image
  is reported per image instead of failing the whole batch.
- Shows the reclaimable build cache from `/system/df` and prunes it on request,
  behind its own confirmation.
- Optional scheduled run (daily or weekly), notify-only by default. A scheduled
  delete can only ever remove untagged images and always respects pins and the
  minimum age.

See the shipped [`README.md`](src/usr/local/emhttp/plugins/docker.orphan.cleaner/README.md)
for the end-user view.

## Architecture

```
plugin/docker.orphan.cleaner.plg         Unraid plugin definition (SHA256 pinned)
src/usr/local/emhttp/plugins/docker.orphan.cleaner/
    DockerOrphanCleaner.page             UI (Tasks menu, next to Docker)
    include/DockerApi.php                unix-socket Engine API client
    include/Orphans.php                  orphan computation + classification
    include/Action.php                   JSON endpoint: list / delete /
                                         prune-cache / save-settings
    include/Config.php                   settings load / validate / save
    include/Logger.php                   syslog helper
    include/Exec.php                     shell-free external process helper
    scripts/scheduled.php                cron entry point
    images/icon.png                      plugin icon
    README.md
ca/docker.orphan.cleaner.xml             CA listing entry (not published)
build/mkpkg.sh                           builds the .txz, stamps the .plg
build/mkpkg.py                           deterministic packaging
build/make-icon.py                       regenerates images/icon.png
.github/workflows/lint.yml               CI
```

### Menu placement

On Unraid 7.2+ the Docker page is a top-level item in the **Tasks** navigation
group (`Menu="Tasks:60"`), not a tab on a Docker page. A sibling entry
(`Menu="Tasks:62"`, just after Docker and Docker Networks) is therefore the
modern equivalent of a "Docker tab". This follows the pattern used by the
in-CA `docker.networks` plugin (`Networks.page`, `Menu="Tasks:61"`).

### Docker access

All Docker operations go through the Engine API over `/var/run/docker.sock`
using PHP curl with `CURLOPT_UNIX_SOCKET_PATH`. `GET /images/json`,
`GET /containers/json?all=1`, `GET /images/{id}/json`, `DELETE /images/{id}`
with `force=0`, `GET /system/df` and `POST /build/prune`. **The plugin never
uses a shell.** Docker is reached only through the Engine API; logging uses
PHP's native `syslog`; and the only external programs (`notify` and
`update_cron`) are run through `include/Exec.php` via `proc_open` given an
**argv array**, so no `/bin/sh` is involved and there is nothing to quote or
inject. `Exec` allowlists the exact program path, strips NULs and caps each
argument, passes a minimal `PATH`, closes stdin and enforces a timeout. CI
greps `src/` to fail the build if any other process-spawning call appears.

Deletion is never forced and always one image per API call. Per-image results
(`deleted`, `conflict`, `not-found`, `refused`) are collected and returned.

### Orphan set definition

The list uses `GET /images/json?all=1` minus the image IDs of every container.
Images that have no tags but do have a repository digest are skipped: these are
the digest-only/intermediate images that `docker image ls` hides, so the plugin
reports the same set the user sees in the Docker UI. This makes the list equal
to `docker images -q --no-trunc` minus the container image IDs.

## Security

- **CSRF.** Every state-changing action is POST-only and requires Unraid's
  `csrf_token`. Unraid's `webGui/include/local_prepend.php` (installed as
  `auto_prepend_file`) already rejects any POST without a valid token, but it
  *consumes* the token afterwards (`unset($_POST['csrf_token'])`). The plugin
  therefore sends the token a second time in the JSON body (`csrf`) and
  `Action.php` re-checks it with `hash_equals` against
  `/var/local/emhttp/var.ini`. GET is refused for anything that changes state.
- **Body limits.** `CONTENT_LENGTH` over 64 KiB is refused. At most 200 image
  IDs per delete request.
- **Input validation.** Image IDs must match `^sha256:[a-f0-9]{64}$` exactly.
  Action names come from an allowlist. Pin patterns allow only
  `[A-Za-z0-9._/:*@-]`, at most 50 of them, each at most 200 characters.
  Numeric settings are bounds-checked.
- **Server-side authority.** At delete time the orphan set and classification
  are recomputed from the daemon. An ID is deleted only if it is still an
  orphan and not pinned. Client-side classification and checkbox state are
  never trusted.
- **Output escaping.** Tags and template/compose content are treated as
  untrusted. The UI builds DOM nodes with `textContent` (never `innerHTML`) for
  any daemon-provided string. JSON responses carry
  `JSON_HEX_TAG | JSON_HEX_AMP` with `Content-Type: application/json`.
- **Least privilege.** Templates and compose files are read-only. Writes are
  confined to `/boot/config/plugins/docker.orphan.cleaner/`. Settings are
  written atomically (temp file + rename) and validated again on load.
- The plugin never modifies `dynamix.docker.manager`,
  `/boot/config/plugins/dockerMan/`, container icons or any other container or
  template. It only reads templates and (optionally) Compose Manager stack
  files.

## Settings

| Setting | Default | Notes |
|---------|---------|-------|
| Pin patterns | empty | globs; a pinned image is never deleted |
| Minimum age (days) | 7 | scheduled deletes skip newer images |
| Schedule | off | off / daily / weekly |
| Scheduled mode | notify | notify-only, or delete-untagged-only |
| Include build cache | no | also prune the build cache on a scheduled run |

Cron is written to
`/boot/config/plugins/docker.orphan.cleaner/docker.orphan.cleaner.cron` and
loaded with `update_cron`, Unraid's native mechanism, so it survives reboots.

## Build

```bash
./build/mkpkg.sh
```

Produces `build/dist/docker.orphan.cleaner-<version>-noarch-1.txz` and stamps
its SHA256 into `plugin/docker.orphan.cleaner.plg`. Packaging is deterministic,
so rebuilding anywhere yields the same file; CI relies on this.

## Testing

`lint.yml` runs `php -l` over all PHP (including the `.page` body),
`shellcheck` over the shell scripts, `xmllint --noout` over the `.plg` and CA
XML, checks `VERSION` against the `.plg`, and rebuilds the package to prove the
committed `<SHA256>` matches the built `.txz`.

## License

MIT. See [LICENSE](LICENSE).
