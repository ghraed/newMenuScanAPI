import os
import sys

import bpy


def _args_after_double_dash():
    if "--" not in sys.argv:
        return []
    idx = sys.argv.index("--")
    return sys.argv[idx + 1 :]


def _clear_scene():
    bpy.ops.object.select_all(action="SELECT")
    bpy.ops.object.delete(use_global=False)
    for block in list(bpy.data.meshes):
        bpy.data.meshes.remove(block, do_unlink=True)
    for block in list(bpy.data.materials):
        bpy.data.materials.remove(block, do_unlink=True)
    for block in list(bpy.data.images):
        if block.users == 0:
            bpy.data.images.remove(block, do_unlink=True)


def _import_glb(filepath):
    try:
        bpy.ops.import_scene.gltf(filepath=filepath)
    except Exception as exc:
        raise SystemExit(f"GLB import failed: {exc}") from exc


def _export_usd(filepath):
    try:
        bpy.ops.wm.usd_export(filepath=filepath)
    except Exception as exc:
        raise SystemExit(f"USD export failed: {exc}") from exc


def main():
    args = _args_after_double_dash()
    if len(args) != 2:
        raise SystemExit(
            "Usage: blender -b -P scripts/glb_to_usd.py -- <input_glb> <output_usd>"
        )

    input_glb = os.path.abspath(args[0])
    output_usd = os.path.abspath(args[1])
    output_dir = os.path.dirname(output_usd)

    if not os.path.isfile(input_glb):
        raise SystemExit(f"Input GLB not found: {input_glb}")

    os.makedirs(output_dir, exist_ok=True)

    _clear_scene()
    _import_glb(input_glb)
    _export_usd(output_usd)

    if not os.path.isfile(output_usd):
        raise SystemExit(f"USD export failed: output missing at {output_usd}")


if __name__ == "__main__":
    main()
