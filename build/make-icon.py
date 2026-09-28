#!/usr/bin/env python3
"""Generate the plugin's 128x128 PNG icon.

Pure standard library (zlib + struct) so the build has no image dependencies.
Run: python3 build/make-icon.py [output.png]
"""
import math
import struct
import sys
import zlib

SIZE = 128
OUT = sys.argv[1] if len(sys.argv) > 1 else "src/usr/local/emhttp/plugins/docker.orphan.cleaner/images/icon.png"

# RGBA canvas, fully transparent.
px = bytearray(SIZE * SIZE * 4)


def blend(x, y, r, g, b, a):
    if x < 0 or y < 0 or x >= SIZE or y >= SIZE or a <= 0:
        return
    i = (y * SIZE + x) * 4
    inv = 1.0 - a
    px[i] = int(r * a + px[i] * inv)
    px[i + 1] = int(g * a + px[i + 1] * inv)
    px[i + 2] = int(b * a + px[i + 2] * inv)
    px[i + 3] = int(min(255, 255 * a + px[i + 3] * inv))


def rounded_rect(x0, y0, x1, y1, radius, color):
    r, g, b, a = color
    for y in range(int(y0), int(y1)):
        for x in range(int(x0), int(x1)):
            dx = max(x0 + radius - x, 0, x - (x1 - 1 - radius))
            dy = max(y0 + radius - y, 0, y - (y1 - 1 - radius))
            if dx * dx + dy * dy <= radius * radius:
                blend(x, y, r, g, b, a)


def disc(cx, cy, radius, color):
    r, g, b, a = color
    for y in range(int(cy - radius), int(cy + radius) + 1):
        for x in range(int(cx - radius), int(cx + radius) + 1):
            if (x - cx) ** 2 + (y - cy) ** 2 <= radius * radius:
                blend(x, y, r, g, b, a)


def thick_line(x0, y0, x1, y1, width, color):
    steps = int(max(abs(x1 - x0), abs(y1 - y0))) * 2 + 1
    for s in range(steps + 1):
        t = s / steps
        disc(x0 + (x1 - x0) * t, y0 + (y1 - y0) * t, width / 2.0, color)


BG = (43, 49, 56, 1.0)
BODY = (198, 205, 214, 1.0)
LID = (150, 158, 168, 1.0)
SLOT = (60, 68, 78, 1.0)
GREEN = (46, 204, 113, 1.0)
DARKGREEN = (35, 165, 90, 1.0)

# Rounded background
rounded_rect(4, 4, 124, 124, 24, BG)

# Storage box body + lid
rounded_rect(30, 44, 98, 100, 8, BODY)
rounded_rect(24, 34, 104, 48, 6, LID)

# A couple of horizontal "layer" slots on the body
for y in (60, 74, 88):
    rounded_rect(38, y, 90, y + 4, 2, SLOT)

# Green check badge, bottom-right
disc(96, 96, 26, BG)
disc(96, 96, 22, GREEN)
thick_line(86, 96, 93, 104, 8, (255, 255, 255, 1.0))
thick_line(93, 104, 108, 86, 8, (255, 255, 255, 1.0))
# Slight shading so it is not flat
thick_line(86, 96, 93, 104, 3, DARKGREEN)


def chunk(tag, data):
    return struct.pack(">I", len(data)) + tag + data + struct.pack(">I", zlib.crc32(tag + data) & 0xFFFFFFFF)


raw = bytearray()
for y in range(SIZE):
    raw.append(0)
    raw.extend(px[y * SIZE * 4:(y + 1) * SIZE * 4])

png = b"\x89PNG\r\n\x1a\n"
png += chunk(b"IHDR", struct.pack(">IIBBBBB", SIZE, SIZE, 8, 6, 0, 0, 0))
png += chunk(b"IDAT", zlib.compress(bytes(raw), 9))
png += chunk(b"IEND", b"")

with open(OUT, "wb") as fh:
    fh.write(png)
print("wrote", OUT, len(png), "bytes")
