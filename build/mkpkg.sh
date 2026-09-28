#!/bin/bash
# Build the plugin release package and stamp its SHA256 into the .plg.
# See build/mkpkg.py for the deterministic packaging itself.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
exec python3 "$ROOT/build/mkpkg.py" "$@"
