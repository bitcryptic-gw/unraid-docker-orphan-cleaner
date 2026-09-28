# Docker Orphan Cleaner

Unraid plugin that lists Docker images no container references, classifies how
safe each one is to remove, and deletes the selected images in bulk. Unraid
only removes orphan images one at a time.

Open it from the **Tasks** menu, next to Docker. Settings live under the
Settings section on the same page.

## What is an orphan?

A local image whose ID is not the `Image` of any container, running or stopped.
Each orphan is classified, in this precedence order:

| Class | Meaning | Ticked by default |
|-------|---------|-------------------|
| Pinned | Matches one of your pin patterns | no, cannot be deleted |
| Template | `repo:tag` matches a `<Repository>` in a `templates-user` XML | no |
| Compose | `repo:tag` appears as an `image:` in a Docker Compose stack | no |
| Tagged | Has a real tag, nothing references it | no |
| Untagged | `<none>` image | yes |

Deletion is never forced. Docker refuses to remove a parent image while a child
exists; that refusal is reported for the image rather than failing the batch.

## Schedule

Scheduled runs default to **notify only**. Even in delete mode they only ever
touch untagged images and they respect pins and the minimum age. Turn the
schedule off, or use notify mode, if you want to review every deletion by hand.

## Safety

- Docker is reached through the Engine API over `/var/run/docker.sock`. No
  Docker command is ever run through a shell.
- Every state changing request needs a valid Unraid CSRF token.
- Image IDs are validated as exact `sha256:` digests; at most 200 per request.
- The orphan set and classification are recomputed on the server at delete
  time. Client side state is never trusted.
- The plugin only writes its own settings under
  `/boot/config/plugins/docker.orphan.cleaner/`.

## Uninstall

Removing the plugin deletes its files, its cron fragment and its config
directory. No other plugin, container or template is touched.
