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


def _find_file_by_basename(root_dir, basename):
    for current_root, _, files in os.walk(root_dir):
        for f in files:
            if f == basename:
                return os.path.join(current_root, f)
    return None


def _ensure_texture_links(obj_dir):
    for image in bpy.data.images:
        if image.source != "FILE":
            continue

        current = bpy.path.abspath(image.filepath or "")
        if current and os.path.exists(current):
            continue

        basename = os.path.basename(image.filepath or "")
        if not basename:
            continue

        found = _find_file_by_basename(obj_dir, basename)
        if found:
            image.filepath = found
            try:
                image.reload()
            except Exception:
                pass


def _enable_alpha_blending():
    for material in bpy.data.materials:
        if not material or not material.use_nodes or not material.node_tree:
            continue

        has_alpha_texture = False

        for node in material.node_tree.nodes:
            if node.type != "TEX_IMAGE" or not getattr(node, "image", None):
                continue

            image = node.image
            if getattr(image, "depth", 0) in (32, 16):
                has_alpha_texture = True
                break

            alpha_output = node.outputs.get("Alpha")
            if alpha_output and alpha_output.is_linked:
                has_alpha_texture = True
                break

        if has_alpha_texture:
            material.blend_method = "BLEND"
            if hasattr(material, "shadow_method"):
                material.shadow_method = "HASHED"
            material.use_backface_culling = False


def main():
    args = _args_after_double_dash()
    if len(args) != 2:
        raise SystemExit("Usage: blender -b -P scripts/obj_to_glb.py -- <input_obj> <output_glb>")

    input_obj = os.path.abspath(args[0])
    output_glb = os.path.abspath(args[1])
    output_dir = os.path.dirname(output_glb)

    if not os.path.isfile(input_obj):
        raise SystemExit(f"Input OBJ not found: {input_obj}")

    os.makedirs(output_dir, exist_ok=True)

    _clear_scene()

    bpy.ops.wm.obj_import(filepath=input_obj)

    obj_dir = os.path.dirname(input_obj)
    _ensure_texture_links(obj_dir)
    _enable_alpha_blending()

    bpy.ops.export_scene.gltf(
        filepath=output_glb,
        export_format="GLB",
        export_image_format="AUTO",
    )


if __name__ == "__main__":
    main()
