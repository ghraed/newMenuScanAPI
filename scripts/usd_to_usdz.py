import os
import sys
from contextlib import nullcontext

from pxr import Ar, Sdf, UsdUtils


def _resolve_context(asset_path):
    resolver = Ar.GetResolver()
    create_context = getattr(resolver, "CreateDefaultContextForAsset", None)
    if not callable(create_context):
        return None

    try:
        return create_context(asset_path)
    except Exception:
        return None


def _resolve_binder(context):
    if context is None:
        return nullcontext()

    binder_class = getattr(Ar, "ResolverContextBinder", None)
    if binder_class is None:
        return nullcontext()

    return binder_class(context)


def _create_usdz(input_usd, output_usdz):
    asset_path = Sdf.AssetPath(input_usd)
    first_layer_name = os.path.basename(input_usd)
    candidates = [
        lambda: UsdUtils.CreateNewARKitUsdzPackage(asset_path, output_usdz, first_layer_name),
        lambda: UsdUtils.CreateNewARKitUsdzPackage(input_usd, output_usdz, first_layer_name),
        lambda: UsdUtils.CreateNewUsdzPackage(asset_path, output_usdz, first_layer_name),
        lambda: UsdUtils.CreateNewUsdzPackage(input_usd, output_usdz, first_layer_name),
    ]
    last_error = None

    for candidate in candidates:
        try:
            return bool(candidate())
        except TypeError as exc:
            last_error = exc

    if last_error is not None:
        raise last_error

    return False


def main():
    if len(sys.argv) != 3:
        raise SystemExit("Usage: usd_to_usdz.py <input_usd> <output_usdz>")

    input_usd = os.path.abspath(sys.argv[1])
    output_usdz = os.path.abspath(sys.argv[2])
    output_dir = os.path.dirname(output_usdz)

    if not os.path.isfile(input_usd):
        raise SystemExit(f"Input USD not found: {input_usd}")

    os.makedirs(output_dir, exist_ok=True)

    context = _resolve_context(input_usd)
    with _resolve_binder(context):
        created = _create_usdz(input_usd, output_usdz)

    if not created or not os.path.isfile(output_usdz):
        raise SystemExit(f"USDZ packaging failed for {input_usd}")


if __name__ == "__main__":
    main()
