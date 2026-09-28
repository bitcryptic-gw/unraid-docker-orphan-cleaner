# Changelog

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
