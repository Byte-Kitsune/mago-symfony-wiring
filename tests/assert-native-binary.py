#!/usr/bin/env python3
"""Validate the architecture of the binary that will be uploaded to a release."""
import pathlib
import struct
import sys

platform, filename = sys.argv[1:]
data = pathlib.Path(filename).read_bytes()
if platform.startswith("linux-"):
    if data[:6] != b"\x7fELF\x02\x01":
        raise SystemExit("Expected a little-endian ELF64 release binary")
    expected = {"linux-x86_64": 62, "linux-arm64": 183}[platform]
    machine = struct.unpack_from("<H", data, 18)[0]
elif platform.startswith("macos-"):
    if data[:4] != b"\xcf\xfa\xed\xfe":
        raise SystemExit("Expected a little-endian Mach-O64 release binary")
    expected = {"macos-x86_64": 0x01000007, "macos-arm64": 0x0100000C}[platform]
    machine = struct.unpack_from("<I", data, 4)[0]
else:
    raise SystemExit("Unsupported release platform")
if machine != expected:
    raise SystemExit(f"Release architecture mismatch: {platform}, machine {machine}")
print(f"Verified release binary architecture: {platform}")
