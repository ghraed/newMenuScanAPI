#!/usr/bin/env bash
set -euo pipefail

workdir="$(mktemp -d)"
trap 'rm -rf "$workdir"' EXIT

"${USD_BLENDER_BIN:?USD_BLENDER_BIN is required}" -b --python-expr \
    "import bpy; bpy.ops.object.select_all(action='SELECT'); bpy.ops.object.delete(use_global=False); bpy.ops.mesh.primitive_cube_add(); bpy.ops.export_scene.gltf(filepath='$workdir/cube.glb', export_format='GLB')"

/opt/runpod-worker/scripts/glb_to_usdz.sh "$workdir/cube.glb" "$workdir/cube.usdz"

/opt/usd-tools-env/bin/python - "$workdir/cube.usdz" <<'PY'
import sys
import zipfile

from pxr import Usd

path = sys.argv[1]
with zipfile.ZipFile(path) as archive:
    assert archive.testzip() is None

stage = Usd.Stage.Open(path)
assert stage is not None
assert any(prim.GetTypeName() == "Mesh" for prim in stage.Traverse())
PY
