#!/usr/bin/env bash
# Builds the core for the Raspberry Pi (ARMv6, every model) with Docker, from any computer:
#   core/build/pi.sh        -> core/target-pi/arm-unknown-linux-gnueabihf/release/remote-pi
# Prints the binary's path. The build image is made on first use (a few minutes).
set -euo pipefail
core=$(cd "$(dirname "$0")/.." && pwd)
image=remote-pi-core-build:pi-1
target=arm-unknown-linux-gnueabihf
command -v docker >/dev/null || { echo "ERROR: Docker is needed to build the core for the Pi" >&2; exit 1; }
if ! docker image inspect "$image" >/dev/null 2>&1; then
	docker build --platform linux/amd64 -t "$image" -f "$core/build/pi.Dockerfile" "$core/build" >&2
fi
docker run --rm --platform linux/amd64 -v "$core:/work" -w /work -v remote-pi-cargo-registry:/opt/cargo/registry \
	-e CARGO_TARGET_DIR=/work/target-pi "$image" cargo build --release --locked --target "$target" >&2
binary=$core/target-pi/$target/release/remote-pi
# Every object in it must be ARMv6: one ARMv7 object would make the whole file v7.
arch=$(docker run --rm --platform linux/amd64 -v "$core:/work" "$image" \
	arm-unknown-linux-gnueabihf-readelf -A "/work/target-pi/$target/release/remote-pi" | sed -n 's/^ *Tag_CPU_arch: //p')
[[ "$arch" == v6* ]] || { echo "ERROR: $binary is built for $arch, not ARMv6" >&2; exit 1; }
echo "$binary"
