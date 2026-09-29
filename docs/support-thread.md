# Docker Orphan Cleaner

Support thread for the **Docker Orphan Cleaner** plugin for Unraid
(`docker.orphan.cleaner`).

## What the plugin does

Unraid removes orphan Docker images (images that no container references) only
one at a time, from the Docker tab. That is slow when a host has accumulated a
lot of them, and there is no way to see the whole set or to tell how safe each
one is to remove.

Docker Orphan Cleaner lists every orphan in one place, classifies how safe each
one is to delete, and removes the selected images in bulk. It also reports the
reclaimable build cache and can prune it on request.

It is deliberately conservative. Nothing is force-removed, every manual deletion
is confirmed, and a scheduled run only ever deletes untagged images.

## How images are classified

Each orphan is placed in exactly one class, in this precedence order:

| Class | Meaning | Ticked by default |
|-------|---------|-------------------|
| Pinned | Matches a user pin pattern. Never deleted, in the UI or on a schedule | no |
| Template | `repo:tag` matches a `<Repository>` in `/boot/config/plugins/dockerMan/templates-user/*.xml`; the container was removed but its template remains | no |
| Compose | `repo:tag` appears as an `image:` in a Docker Compose stack; a stack that is down makes its images look orphaned | no |
| Tagged | Has a real tag, but nothing references it | no |
| Untagged | No tag (`<none>`), with or without a repo digest | yes |

Untagged includes **superseded pulls**: when a tag moves to a newer pull, the
old image keeps its digest, loses its tag, and is exactly the kind of image you
want to clean up. One row is produced per image ID; untagged rows show
`repository@sha256:<short>` so you can tell what the image was.

## Safety properties

- **Dry run is on by default and enforced on the server.** Delete and Prune do
  nothing unless the request explicitly carries `"dryRun": false`. A missing or
  malformed flag is treated as a dry run.
- **The orphan set is recomputed at delete time** from the Docker daemon. What
  the browser sends is treated as a request, never as authority. An image that
  is no longer an orphan, or that is pinned, is refused.
- **Nothing is force-removed.** Deletion is one image per API call, `force=0`.
  A parent image that cannot be removed is reported per image instead of
  failing the whole batch.
- **Scheduled runs are notify-only by default.** Even in delete mode they only
  touch untagged images, and they respect pins and the minimum age.
- **The plugin never modifies container templates or icons.** It only reads
  them.

## Screenshots

[SCREENSHOT: main list, showing the classes, sizes and the Dry run toggle]

[SCREENSHOT: delete confirmation, listing the selected images and their sizes]

[SCREENSHOT: results table after a dry run]

## Install

The plugin is **not yet listed in Community Applications** (submission in
progress). Until it is, install it manually:

1. In the Unraid webGUI, go to **Plugins ▸ Install Plugin**.
2. Paste this URL and click **Install**:

   ```
   https://raw.githubusercontent.com/bitcryptic-gw/unraid-docker-orphan-cleaner/main/plugin/docker.orphan.cleaner.plg
   ```

It appears as a tile under **Settings ▸ User Utilities**. Settings (pins,
minimum age, schedule) are on the same page.

**Requires Unraid 7.0.0 or newer.**
**(tested on 7.3.2)**

## Source and issues

- GitHub: https://github.com/bitcryptic-gw/unraid-docker-orphan-cleaner
- Issues: https://github.com/bitcryptic-gw/unraid-docker-orphan-cleaner/issues

## Known limitations

- **The "Unique" size is a lower bound.** It is `Size − SharedSize` from
  `/system/df`. Layers shared only among the images you selected are also freed
  but are not counted, so the real saving can be larger than the Unique figure.
  The "total" figure is the sum of each image's full size and *overstates* the
  saving, because it counts shared layers more than once.
- **Only images are cleaned.** Unused Docker *volumes* are not touched.
- **Tagged orphans are never auto-deleted.** A scheduled run only removes
  untagged images that are older than the minimum age.
- The plugin needs the Docker service to be running.
