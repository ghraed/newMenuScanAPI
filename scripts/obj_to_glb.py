import os
import sys

import bpy
from mathutils import Vector


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


def _import_obj(filepath):
    errors = []

    try:
        bpy.ops.import_scene.obj(filepath=filepath)
        return
    except Exception as exc:
        errors.append(f"import_scene.obj: {exc}")

    try:
        bpy.ops.wm.obj_import(filepath=filepath)
        return
    except Exception as exc:
        errors.append(f"wm.obj_import: {exc}")

    raise SystemExit(
        "OBJ import operator is not available in this Blender build. "
        + " | ".join(errors)
    )


def _root_objects():
    return [obj for obj in bpy.context.scene.objects if obj.parent is None]


def _mesh_objects():
    return [obj for obj in bpy.context.scene.objects if obj.type == "MESH"]


def _combined_bounds():
    mesh_objects = _mesh_objects()
    if not mesh_objects:
        raise SystemExit("No mesh objects were imported from the OBJ file")

    points = []
    for obj in mesh_objects:
        matrix = obj.matrix_world
        points.extend(matrix @ Vector(corner) for corner in obj.bound_box)

    min_corner = Vector(
        (
            min(point.x for point in points),
            min(point.y for point in points),
            min(point.z for point in points),
        )
    )
    max_corner = Vector(
        (
            max(point.x for point in points),
            max(point.y for point in points),
            max(point.z for point in points),
        )
    )
    return min_corner, max_corner


def _apply_uniform_scale(scale_factor):
    if scale_factor <= 0:
        raise SystemExit(f"Invalid scale factor: {scale_factor}")

    for obj in _root_objects():
        obj.scale = tuple(component * scale_factor for component in obj.scale)

    bpy.context.view_layer.update()


def _center_model_on_ground():
    min_corner, max_corner = _combined_bounds()
    offset = Vector(
        (
            -((min_corner.x + max_corner.x) / 2.0),
            -((min_corner.y + max_corner.y) / 2.0),
            -min_corner.z,
        )
    )

    for obj in _root_objects():
        obj.location += offset

    bpy.context.view_layer.update()


def _scale_model_to_target_width(target_width_meters):
    if target_width_meters <= 0:
        return

    min_corner, max_corner = _combined_bounds()
    size = max_corner - min_corner
    horizontal_width = max(size.x, size.y)

    if horizontal_width <= 0:
        raise SystemExit("Imported OBJ has zero horizontal size; cannot apply target width")

    _apply_uniform_scale(target_width_meters / horizontal_width)


def _triangle_count_for_mesh(mesh):
    return sum(max(0, len(polygon.vertices) - 2) for polygon in mesh.polygons)


def _total_triangle_count():
    return sum(_triangle_count_for_mesh(obj.data) for obj in _mesh_objects())


def _decimate_meshes(target_triangles):
    if target_triangles <= 0:
        return

    mesh_objects = _mesh_objects()
    current_triangles = _total_triangle_count()

    if not mesh_objects or current_triangles <= 0 or current_triangles <= target_triangles:
        return

    ratio = max(0.05, min(1.0, target_triangles / current_triangles))

    bpy.ops.object.select_all(action="DESELECT")

    for obj in mesh_objects:
        bpy.context.view_layer.objects.active = obj
        obj.select_set(True)
        modifier = obj.modifiers.new(name="ReduceForGlbExport", type="DECIMATE")
        modifier.ratio = ratio
        if hasattr(modifier, "use_collapse_triangulate"):
            modifier.use_collapse_triangulate = True
        bpy.ops.object.modifier_apply(modifier=modifier.name)
        obj.select_set(False)

    bpy.context.view_layer.update()


def _resize_images(max_texture_size):
    if max_texture_size <= 0:
        return

    for image in bpy.data.images:
        if image.source != "FILE":
            continue

        width = int(image.size[0]) if len(image.size) > 0 else 0
        height = int(image.size[1]) if len(image.size) > 1 else 0
        largest = max(width, height)

        if largest <= 0 or largest <= max_texture_size:
            continue

        scale = max_texture_size / largest
        target_width = max(1, round(width * scale))
        target_height = max(1, round(height * scale))
        image.scale(target_width, target_height)


def main():
    args = _args_after_double_dash()
    if len(args) not in (2, 3, 4, 5):
        raise SystemExit(
            "Usage: blender -b -P scripts/obj_to_glb.py -- "
            "<input_obj> <output_glb> [target_width_meters] [target_triangles] [max_texture_size]"
        )

    input_obj = os.path.abspath(args[0])
    output_glb = os.path.abspath(args[1])
    target_width_meters = float(args[2]) if len(args) >= 3 and args[2] else 0.0
    if len(args) >= 4 and args[3]:
        target_triangles = int(float(args[3]))
    else:
        target_triangles = 0
    if len(args) >= 5 and args[4]:
        max_texture_size = int(float(args[4]))
    else:
        max_texture_size = 0
    output_dir = os.path.dirname(output_glb)

    if not os.path.isfile(input_obj):
        raise SystemExit(f"Input OBJ not found: {input_obj}")

    os.makedirs(output_dir, exist_ok=True)

    _clear_scene()

    _import_obj(input_obj)

    obj_dir = os.path.dirname(input_obj)
    _ensure_texture_links(obj_dir)
    _enable_alpha_blending()
    _scale_model_to_target_width(target_width_meters)
    _center_model_on_ground()
    _decimate_meshes(target_triangles)
    _resize_images(max_texture_size)

    bpy.ops.export_scene.gltf(
        filepath=output_glb,
        export_format="GLB",
        export_image_format="AUTO",
    )


if __name__ == "__main__":
    main()
