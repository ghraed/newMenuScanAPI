#!/usr/bin/env bash
set -euo pipefail

if [ "$#" -ne 2 ]; then
    echo "Usage: glb_to_usdz.sh <input_glb> <output_usdz>" >&2
    exit 64
fi

input_glb="$1"
output_usdz="$2"

if [ ! -f "$input_glb" ]; then
    echo "Input GLB not found: $input_glb" >&2
    exit 66
fi

blender_bin="${BLENDER_BIN:-/usr/bin/blender}"
usd_python_bin="${USD_PYTHON_BIN:-/opt/usd-tools-env/bin/python}"
script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
workdir="$(mktemp -d)"
intermediate_usd="$workdir/model.usdc"

cleanup() {
    rm -rf "$workdir"
}

trap cleanup EXIT

mkdir -p "$(dirname "$output_usdz")"

"$blender_bin" -b -P "$script_dir/glb_to_usd.py" -- "$input_glb" "$intermediate_usd"
"$usd_python_bin" "$script_dir/usd_to_usdz.py" "$intermediate_usd" "$output_usdz"

if [ ! -f "$output_usdz" ]; then
    echo "USDZ conversion failed: output missing at $output_usdz" >&2
    exit 1
fi
