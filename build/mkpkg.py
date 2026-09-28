#!/usr/bin/env python3
"""Build the plugin's Slackware .txz and stamp its SHA256 into the .plg.

The tar and xz output are fully deterministic (fixed mtimes, uid/gid, sorted
entry order, fixed xz preset), so a rebuild anywhere produces the identical
file. The CI lint job relies on that: it rebuilds and checks the committed
.plg SHA256 still matches.

Run via build/mkpkg.sh.
"""
import hashlib
import lzma
import os
import shutil
import stat
import sys
import tarfile
import io

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
NAME = "docker.orphan.cleaner"
SRC = os.path.join(ROOT, "src", "usr")
STAGE = os.path.join(ROOT, "build", "stage")
DIST = os.path.join(ROOT, "build", "dist")
PLG = os.path.join(ROOT, "plugin", NAME + ".plg")
PLACEHOLDER = "@@SHA256@@"

SLACK_DESC = """{name}: Docker Orphan Cleaner
{name}:
{name}: Lists Docker images that no container references (orphans), shows how
{name}: safe each one is to remove, and deletes the selected images in bulk.
{name}: Unraid only removes orphan images one at a time; this plugin also shows
{name}: the reclaimable build cache and prunes it on request.
{name}:
{name}: Homepage: https://github.com/bitcryptic-gw/unraid-docker-orphan-cleaner
{name}:
{name}: Licensed under the MIT license.
""".format(name=NAME)

DOINST = """#!/bin/sh
# Slackware post-install script. Runs with cwd=/ after the files land.
chmod 0755 usr/local/emhttp/plugins/{name}/scripts/scheduled.php 2>/dev/null || true
""".format(name=NAME)


def version():
    with open(os.path.join(ROOT, "VERSION")) as fh:
        return fh.read().strip()


def stage(pkgname):
    if os.path.isdir(STAGE):
        shutil.rmtree(STAGE)
    os.makedirs(STAGE)
    shutil.copytree(SRC, os.path.join(STAGE, "usr"))
    install = os.path.join(STAGE, "install")
    os.makedirs(install)
    with open(os.path.join(install, "slack-desc"), "w") as fh:
        fh.write(SLACK_DESC)
    with open(os.path.join(install, "doinst.sh"), "w") as fh:
        fh.write(DOINST)


def build_txz(pkgname):
    os.makedirs(DIST, exist_ok=True)
    out = os.path.join(DIST, pkgname + ".txz")
    if os.path.exists(out):
        os.remove(out)

    paths = []
    for base, dirs, files in os.walk(STAGE):
        dirs.sort()
        for d in dirs:
            paths.append(os.path.join(base, d))
        for f in sorted(files):
            paths.append(os.path.join(base, f))
    paths.sort()

    buf = io.BytesIO()
    with tarfile.open(fileobj=buf, mode="w", format=tarfile.GNU_FORMAT) as tar:
        for path in paths:
            arcname = os.path.relpath(path, STAGE)
            info = tar.gettarinfo(path, arcname)
            info.mtime = 0
            info.uid = 0
            info.gid = 0
            info.uname = "root"
            info.gname = "root"
            if info.isdir():
                info.mode = 0o755
                tar.addfile(info)
            elif info.isfile():
                if arcname.endswith("scripts/scheduled.php"):
                    info.mode = 0o755
                else:
                    info.mode = 0o644
                with open(path, "rb") as fh:
                    tar.addfile(info, fh)
            else:
                tar.addfile(info)

    raw = buf.getvalue()
    compressed = lzma.compress(raw, format=lzma.FORMAT_XZ, check=lzma.CHECK_CRC32, preset=9)
    with open(out, "wb") as fh:
        fh.write(compressed)
    return out


def stamp(pkgname, txz):
    with open(txz, "rb") as fh:
        digest = hashlib.sha256(fh.read()).hexdigest()
    with open(PLG) as fh:
        text = fh.read()
    new = text.replace(PLACEHOLDER, digest)
    if new == text and digest not in text:
        print("ERROR: no SHA256 placeholder found in " + PLG, file=sys.stderr)
        sys.exit(1)
    with open(PLG, "w") as fh:
        fh.write(new)
    return digest


def main():
    ver = version()
    pkgname = "{}-{}-noarch-1".format(NAME, ver)
    stage(pkgname)
    txz = build_txz(pkgname)
    digest = stamp(pkgname, txz)
    size = os.path.getsize(txz)
    print("built  {} ({} bytes)".format(os.path.relpath(txz, ROOT), size))
    print("sha256 {}".format(digest))


if __name__ == "__main__":
    main()
