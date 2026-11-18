#!/usr/bin/env bash
set -euo pipefail

workdir="$(mktemp -d)"
trap 'rm -rf "$workdir"' EXIT
mkdir -p "$workdir/Texturing" "$workdir/Meshing"

# The untextured mesh is intentionally larger. Selection must still prefer the
# OBJ whose material points to an available image.
for _ in $(seq 1 150); do
    echo 'v 0 0 0'
done > "$workdir/Meshing/mesh.obj"

convert -size 4x4 xc:red "$workdir/Texturing/texture.png"
cat > "$workdir/Texturing/texturedMesh.mtl" <<'MTL'
newmtl colored
Kd 1 1 1
map_Kd texture.png
MTL
cat > "$workdir/Texturing/texturedMesh.obj" <<'OBJ'
mtllib texturedMesh.mtl
o triangle
v 0 0 0
v 1 0 0
v 0 1 0
vt 0 0
vt 1 0
vt 0 1
usemtl colored
f 1/1 2/2 3/3
OBJ

source_obj="$(PYTHONPATH=/opt/runpod-worker python3 - "$workdir" <<'PY'
import sys
from pathlib import Path

from worker.server import _locate_best_obj

root = Path(sys.argv[1])
selected = _locate_best_obj(root)
assert selected == root / "Texturing" / "texturedMesh.obj"
print(selected)
PY
)"

"${BLENDER_BIN:-/usr/bin/blender}" -b -P /opt/runpod-worker/scripts/obj_to_glb.py -- \
    "$source_obj" "$workdir/model.glb" 0 0 0

PYTHONPATH=/opt/runpod-worker python3 - "$workdir/model.glb" <<'PY'
import json
import struct
import sys
from pathlib import Path

from worker.server import _glb_has_base_color_texture

path = Path(sys.argv[1])
assert _glb_has_base_color_texture(path)
with path.open("rb") as source:
    source.seek(12)
    length, _ = struct.unpack("<II", source.read(8))
    document = json.loads(source.read(length))
assert document["images"][0]["mimeType"] == "image/png"
assert "bufferView" in document["images"][0]
PY

/opt/runpod-worker/scripts/glb_to_usdz.sh "$workdir/model.glb" "$workdir/model.usdz"

/opt/usd-tools-env/bin/python - "$workdir/model.usdz" <<'PY'
import sys
import zipfile

from pxr import Usd

path = sys.argv[1]
with zipfile.ZipFile(path) as archive:
    assert archive.testzip() is None
    assert any(name.endswith(".png") for name in archive.namelist())

stage = Usd.Stage.Open(path)
assert stage is not None
assert any(prim.GetTypeName() == "Mesh" for prim in stage.Traverse())
PY
