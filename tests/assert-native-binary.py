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
    expected = {"linux-x86_64": 62, "linux-arm64": 183, "linux-x86_64-musl": 62, "linux-arm64-musl": 183}[platform]
    machine = struct.unpack_from("<H", data, 18)[0]
    if platform.endswith("-musl"):
        # Reject a dynamic interpreter or runtime shared-library dependency.
        phoff = struct.unpack_from("<Q", data, 32)[0]
        phentsize, phnum = struct.unpack_from("<HH", data, 54)
        for index in range(phnum):
            offset = phoff + index * phentsize
            kind = struct.unpack_from("<I", data, offset)[0]
            if kind == 3:  # PT_INTERP
                raise SystemExit("musl release must not require a dynamic loader")
            if kind == 2:  # PT_DYNAMIC (static PIE may have relocation entries)
                start = struct.unpack_from("<Q", data, offset + 8)[0]
                size = struct.unpack_from("<Q", data, offset + 32)[0]
                for entry in range(start, start + size, 16):
                    tag = struct.unpack_from("<q", data, entry)[0]
                    if tag == 0:
                        break
                    if tag == 1:  # DT_NEEDED
                        raise SystemExit("musl release must not require shared libraries")
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
