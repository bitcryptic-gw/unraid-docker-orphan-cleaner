# Changelog

## 2026.09.29.02

- Trim the packaged plugin `README.md` to the two-line Plugins-page blurb (bold
  name + one sentence). The Unraid Plugins page renders the whole file inline,
  so the full docs made the entry fill a screen. The full documentation now
  lives in the repository README. CI guards the file (≤4 non-empty lines, no
  `#`/`|` lines).

## 2026.09.29.01

Polish from the first real-world run on unraid-syd (27 orphans; 21 untagged
deleted, 7.34 GB freed).

- Results table now repeats the image label (tag, or `repo@sha256:short` for
  untagged images) and size on every row; the Detail column keeps the Docker
  message for refused / conflict / not-found rows.
- New per-orphan **Unique** figure (`Size − SharedSize`, from `/system/df`) and
  header totals of the form `Selected: N · X total, ≥ Y unique`, so the
  reclaimable figure is not overstated by layers shared with other images.
  `SharedSize` of `-1`/missing falls back to showing only Size.
- Plugins-page `README.md` reformatted to the Unraid convention (bold name line
  + one sentence), matching Community Applications / Fix Common Problems.
- Build-cache prune is now asynchronous: the action launches a detached worker
  (`scripts/prune.php`) with its own 900 s timeout, returns immediately, refuses
  a second prune while one is running, and the UI polls `prune-status` until it
  reports the reclaimed space. Fixes the 30 s socket-timeout error on large
  caches (899 entries / 83 GB on unraid-syd).
- Same-day builds use a zero-padded dot counter (`2026.09.29.01`, `.02`, …).
  Zero-padding is deliberate: Unraid's plugin manager compares versions with
  `strcmp`, so `.10` must sort after `.09`.
- Classifier test extended to cover `SharedSize` present and `-1`/missing.

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
