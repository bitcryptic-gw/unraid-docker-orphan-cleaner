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

Requires Unraid 7.0.0 or newer. The plugin appears as a tile under
**Settings ▸ User Utilities**. Settings are on the same page.

## What it does

A local image whose ID is not the `Image` of any container, running or stopped,
is an orphan. Each orphan is classified in precedence order:

| Class | Meaning | Ticked by default |
|-------|---------|-------------------|
| Pinned | Matches a user pin pattern. Never deleted, in the UI or by a scheduled run | no |
| Template | `repo:tag` matches a `<Repository>` in `/boot/config/plugins/dockerMan/templates-user/*.xml`; the container was removed but the template remains | no |
| Compose | `repo:tag` appears as an `image:` in a Docker Compose stack; a stack that is down makes its images look orphaned | no |
| Tagged | Has a real tag, nothing references it | no |
| Untagged | No tag (`<none>`), with or without a repo digest; superseded pulls are this class | yes |

- Lists every orphan image (no container, running or stopped, references it).
- Shows image id, tags, created date, size, a unique size (`Size − SharedSize`)
  and whether the image has children.
- Deletes in bulk, one image per API call, never forced. A refused parent image
  is reported per image instead of failing the whole batch.
- Shows the reclaimable build cache from `/system/df` and prunes it on request
  (asynchronously), behind its own confirmation.

### Schedule

Scheduled runs (daily or weekly) default to **notify only**. Even in delete mode
they only ever touch untagged images, and they respect pins and the minimum age.
Turn the schedule off, or use notify mode, if you want to review every deletion
by hand.

The packaged plugin `README.md` is intentionally just the one-line blurb shown
on the Unraid Plugins page; this file is the full documentation.

## Architecture

```
plugin/docker.orphan.cleaner.plg         Unraid plugin definition (SHA256 pinned)
src/usr/local/emhttp/plugins/docker.orphan.cleaner/
    DockerOrphanCleaner.page             UI (Settings > User Utilities tile)
    include/DockerApi.php                unix-socket Engine API client
    include/Orphans.php                  orphan computation + classification
    include/Action.php                   JSON endpoint: list / delete /
                                         prune-cache / save-settings
    include/Config.php                   settings load / validate / save
    include/Logger.php                   syslog helper
    include/Exec.php                     shell-free external process helper
    include/Pruner.php                   prune lock + status bookkeeping
    scripts/scheduled.php                cron entry point
    scripts/prune.php                    detached build-cache prune worker
    images/icon.png                      plugin icon
    README.md
ca/docker.orphan.cleaner.xml             CA listing entry (not published)
build/mkpkg.sh                           builds the .txz, stamps the .plg
build/mkpkg.py                           deterministic packaging
build/make-icon.py                       regenerates images/icon.png
.github/workflows/lint.yml               CI
```

### Menu placement

The page sets `Menu="Utilities"`, so it is listed as a tile on the
**User Utilities** page (`Utilities.page`), under Settings, and the `.plg`
`launch` attribute points at `Settings/DockerOrphanCleaner`. This is the same
placement used by in-CA utility plugins such as Appdata Cleanup Plus and
docker.networks' settings page. The plugin is deliberately **not** a top-level
nav item.

### Docker access

All Docker operations go through the Engine API over `/var/run/docker.sock`
using PHP curl with `CURLOPT_UNIX_SOCKET_PATH`. `GET /images/json`,
`GET /containers/json?all=1`, `GET /images/{id}/json`, `DELETE /images/{id}`
with `force=0`, `GET /system/df` and `POST /build/prune`. **The plugin never
uses a shell.** Docker is reached only through the Engine API; logging uses
PHP's native `syslog`; and the only external programs (`notify` and
`update_cron`) are run through `include/Exec.php` via `proc_open` given an
**argv array**, so no shell command string exists to quote or inject. `Exec`
allowlists the exact program path, strips NULs and caps each argument, passes a
minimal `PATH`, closes stdin and enforces a timeout. (Unraid's `update_cron`
has a broken shebang — `#/bin/bash` with no `!` — so `Exec` runs that one fixed
allowlisted script through `/bin/bash`; there is still no command string or
user data involved.) CI greps `src/` to fail the build if any other
process-spawning call appears.

**Build-cache prune is asynchronous.** A large `POST /build/prune` runs for
minutes and returns nothing until it finishes, which exceeds nginx's
`fastcgi_read_timeout` and would tie up an FPM worker. So `action=prune-cache`
refuses a second prune (`409` while one runs), launches a detached worker
(`scripts/prune.php`, via `Exec::spawnDetached()` → `setsid -f /usr/bin/php …`,
stdio to `/dev/null`) and returns `{"started":true}`. The worker holds an
`flock` and writes progress to `/tmp/docker.orphan.cleaner/prune.status`; the UI
polls `action=prune-status` and shows "Pruning… / Done: X / Failed".

Deletion is never forced and always one image per API call. Per-image results
(`deleted`, `conflict`, `not-found`, `refused`) are collected and returned.

### Orphan set definition

The candidate set is the daemon's top-level image list, `GET /images/json?all=0`
— the images the Unraid Docker page enumerates — minus the image IDs of every
container. `all=1` is fetched separately only to work out the child/parent flag.

An image with empty `RepoTags` is **untagged**, whether or not it still carries
`RepoDigests`. This is what surfaces superseded pulls: when a tag moves to a
newer pull, the old image keeps its `RepoDigests`, loses its tag, and is exactly
what a user needs to clean up. (`docker images` hides digest-only images unless
run with `-a`, so on some Docker versions `all=0` equals `docker images -a`
rather than plain `docker images`; the Unraid Docker page uses the daemon's
list, so the plugin matches that.) One row is produced per image ID, even when
the image has several digests; untagged rows show `repository@sha256:<short>` so
the user can tell what the image was.

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

## Uninstall

Removing the plugin deletes its files, its cron fragment and its config
directory (`/boot/config/plugins/docker.orphan.cleaner/`). No other plugin,
container or template is touched. The prune worker keeps its lock and status
under `/tmp/docker.orphan.cleaner/`, which clears on reboot.

## Build

```bash
./build/mkpkg.sh
```

Produces `build/dist/docker.orphan.cleaner-<version>-noarch-1.txz` and stamps
its SHA256 into `plugin/docker.orphan.cleaner.plg`. Packaging is deterministic,
so rebuilding anywhere yields the same file; CI relies on this.

`VERSION` is `YYYY.MM.DD`, with a **zero-padded** same-day counter when needed
(`YYYY.MM.DD.NN`). Zero-padding is deliberate: Unraid's plugin manager compares
plugin versions with `strcmp` (not `version_compare`), so `.10` must sort after
`.09`. The version contains no hyphen, so the identical string is used in the
git tag, the `.plg` and the package filename.

## Testing

`lint.yml` runs `php -l` over all PHP (including the `.page` body),
`shellcheck` over the shell scripts, `xmllint --noout` over the `.plg` and CA
XML, checks `VERSION` against the `.plg`, and rebuilds the package to prove the
committed `<SHA256>` matches the built `.txz`.

## License

MIT. See [LICENSE](LICENSE).
