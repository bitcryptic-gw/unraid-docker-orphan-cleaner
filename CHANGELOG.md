# Changelog

## 2026.09.29

- Fix: superseded image pulls were not listed. An image that lost its tag when
  a tag moved to a newer pull (empty `RepoTags`, still carrying `RepoDigests`)
  is now shown as an untagged orphan, as are all other untagged images. The
  orphan set now matches the daemon's top-level image list (what the Unraid
  Docker page enumerates).
- Untagged rows now show the repository from their digest (for example
  `wordpress@sha256:abcd1234abcd`).
- Moved to Settings > User Utilities instead of a top-level nav entry.
- Header summary now reports per-class counts.
- Added a fixture-based classifier test; CI runs it.

## 2026.09.28

Initial release.

- Lists Docker images not referenced by any container (running or stopped).
- Classifies orphans as pinned, template-referenced, compose-referenced, tagged
  or untagged, in that precedence order.
- Bulk selection and deletion through the Engine API, one image per call, never
  forced; per-image results including parent/child conflicts.
- Dry-run mode, confirmation dialog listing every tag, results panel.
- Build cache reclaimable size from `/system/df`, with a confirmed prune.
- Optional daily/weekly scheduled run (notify-only by default, delete-untagged
  as an opt-in) respecting pins and a minimum age.
- Unraid native cron fragment; notifications through the webGui notify helper.
