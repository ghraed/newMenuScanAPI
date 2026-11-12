from __future__ import annotations

import os
import secrets
import shutil
import subprocess
import tempfile
import threading
import time
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

import boto3
import requests
from fastapi import FastAPI, Header, HTTPException
from pydantic import BaseModel, ConfigDict, Field


def _now_iso() -> str:
    return datetime.now(timezone.utc).isoformat()


WORKER_AUTH_TOKEN = os.getenv("WORKER_AUTH_TOKEN", "").strip()
WORKER_PORT = int(os.getenv("WORKER_PORT", "3000"))
REMBG_BIN = os.getenv("REMBG_BIN", "/opt/rembg-env/bin/rembg")
MESHROOM_BIN = os.getenv("MESHROOM_BIN", "/usr/local/bin/meshroom_batch")
BLENDER_BIN = os.getenv("BLENDER_BIN", "/usr/bin/blender")
USDZ_BIN = os.getenv("USDZ_BIN", "/opt/runpod-worker/scripts/glb_to_usdz.sh")
OBJ_TO_GLB_SCRIPT = os.getenv("OBJ_TO_GLB_SCRIPT", "/opt/runpod-worker/scripts/obj_to_glb.py")
FORCE_CPU_EXTRACTION = os.getenv("MESHROOM_FORCE_CPU_EXTRACTION", "true").lower() in {"1", "true", "yes", "on"}
GLB_TARGET_TRIANGLES = max(0, int(os.getenv("GLB_TARGET_TRIANGLES", "120000")))
GLB_MAX_TEXTURE_SIZE = max(512, int(os.getenv("GLB_MAX_TEXTURE_SIZE", "2048")))
B2_KEY_ID = os.getenv("B2_KEY_ID", "").strip()
B2_APPLICATION_KEY = os.getenv("B2_APPLICATION_KEY", "").strip()

app = FastAPI(title="Menu Scan RunPod Worker", version="0.2.0")
_jobs: dict[str, dict[str, Any]] = {}
_jobs_lock = threading.Lock()


class ImageInput(BaseModel):
    slot: int
    heading: int | float | None = None
    url: str


class B2OutputConfig(BaseModel):
    bucket: str
    endpoint: str
    region: str
    keyPrefix: str


class OutputConfig(BaseModel):
    uploadMode: str = Field(default="b2")
    b2: B2OutputConfig | None = None


class WorkerOptions(BaseModel):
    removeBackground: bool = True
    generatePreview: bool = True
    generateUsdz: bool = True


class CallbackConfig(BaseModel):
    url: str
    token: str


class JobCreateRequest(BaseModel):
    localJobId: str | None = None
    scanId: str
    images: list[ImageInput]
    scaleMeters: float = 0.0
    outputs: OutputConfig
    options: WorkerOptions = Field(default_factory=WorkerOptions)
    callback: CallbackConfig
    model_config = ConfigDict(extra="allow")


def _require_auth(authorization: str | None) -> None:
    if WORKER_AUTH_TOKEN == "":
        raise HTTPException(status_code=503, detail="Worker auth is not configured.")
    if not authorization or not authorization.startswith("Bearer "):
        raise HTTPException(status_code=401, detail="Missing bearer token.")
    token = authorization.removeprefix("Bearer ").strip()
    if not secrets.compare_digest(token, WORKER_AUTH_TOKEN):
        raise HTTPException(status_code=401, detail="Invalid bearer token.")


def _update_job(job_id: str, **changes: Any) -> dict[str, Any]:
    with _jobs_lock:
        record = _jobs.get(job_id)
        if record is None:
            raise KeyError(job_id)
        record.update(changes)
        return dict(record)


def _get_job(job_id: str) -> dict[str, Any] | None:
    with _jobs_lock:
        record = _jobs.get(job_id)
        return dict(record) if record else None


def _cancel_event(job_id: str) -> threading.Event:
    with _jobs_lock:
        record = _jobs.get(job_id)
        if record is None:
            raise KeyError(job_id)
        return record["cancelEvent"]


def _ensure_not_canceled(job_id: str) -> None:
    event = _cancel_event(job_id)
    if event.is_set():
        raise RuntimeError("Job canceled.")


def _run_command(command: list[str], job_id: str, cwd: str | None = None) -> None:
    # A verbose process can fill an unread pipe and block forever. Spool output
    # to a temporary file so cancellation remains responsive during long jobs.
    with tempfile.TemporaryFile() as output:
        process = subprocess.Popen(command, cwd=cwd, stdout=output, stderr=subprocess.STDOUT)
        try:
            while process.poll() is None:
                if _cancel_event(job_id).is_set():
                    raise RuntimeError("Job canceled.")
                time.sleep(0.5)

            if process.returncode != 0:
                output.seek(0, os.SEEK_END)
                output.seek(max(0, output.tell() - 4096))
                message = output.read().decode("utf-8", errors="replace").strip()
                raise RuntimeError(message[-400:] or "Command failed.")
        finally:
            if process.poll() is None:
                process.kill()
            process.wait()


def _download_images(payload: JobCreateRequest, image_dir: Path, job_id: str) -> list[Path]:
    session = requests.Session()
    downloaded: list[Path] = []
    total = max(1, len(payload.images))

    for index, image in enumerate(sorted(payload.images, key=lambda item: item.slot)):
        _ensure_not_canceled(job_id)
        response = session.get(image.url, timeout=300)
        response.raise_for_status()
        extension = Path(image.url.split("?")[0]).suffix or ".jpg"
        local_path = image_dir / f"{image.slot}{extension}"
        local_path.write_bytes(response.content)
        downloaded.append(local_path)
        _update_job(
            job_id,
            progress=round(10 + ((index + 1) / total) * 15, 2),
            message=f"Downloaded {index + 1}/{total} input images.",
        )

    return downloaded


def _run_background_removal(source_paths: list[Path], processed_dir: Path, job_id: str) -> list[Path]:
    processed_paths: list[Path] = []
    total = max(1, len(source_paths))
    for index, source_path in enumerate(source_paths):
        _ensure_not_canceled(job_id)
        output_path = processed_dir / f"{source_path.stem}.png"
        _run_command([REMBG_BIN, "i", str(source_path), str(output_path)], job_id)
        processed_paths.append(output_path)
        _update_job(
            job_id,
            progress=round(25 + ((index + 1) / total) * 15, 2),
            message=f"Background removal {index + 1}/{total}.",
        )
    return processed_paths


def _create_preview(source_path: Path, preview_path: Path, job_id: str) -> None:
    _ensure_not_canceled(job_id)
    if source_path.suffix.lower() in {".jpg", ".jpeg"}:
        shutil.copyfile(source_path, preview_path)
        return
    _run_command(["convert", str(source_path), str(preview_path)], job_id)


def _locate_best_obj(output_dir: Path) -> Path | None:
    best: tuple[int, float, Path] | None = None
    for candidate in output_dir.rglob("*.obj"):
        if not candidate.is_file():
            continue
        stat = candidate.stat()
        ranking = (stat.st_size, stat.st_mtime, candidate)
        if best is None or ranking > best:
            best = ranking
    return best[2] if best else None


def _run_meshroom(input_dir: Path, output_dir: Path, job_id: str) -> Path:
    command = [MESHROOM_BIN, "--input", str(input_dir), "--output", str(output_dir)]
    if FORCE_CPU_EXTRACTION:
        command.extend(["--paramOverrides", "FeatureExtraction:forceCpuExtraction=1"])
    _run_command(command, job_id)
    best_obj = _locate_best_obj(output_dir)
    if best_obj is None:
        raise RuntimeError("meshroom failed: no OBJ found in output")
    return best_obj


def _run_obj_to_glb(obj_path: Path, glb_path: Path, scale_meters: float, job_id: str) -> None:
    _run_command(
        [
            BLENDER_BIN,
            "-b",
            "-P",
            OBJ_TO_GLB_SCRIPT,
            "--",
            str(obj_path),
            str(glb_path),
            f"{max(0.0, scale_meters):.6f}",
            str(GLB_TARGET_TRIANGLES),
            str(GLB_MAX_TEXTURE_SIZE),
        ],
        job_id,
    )
    if not glb_path.is_file():
        raise RuntimeError("blender failed: GLB output missing")


def _run_glb_to_usdz(glb_path: Path, usdz_path: Path, job_id: str) -> None:
    _run_command([USDZ_BIN, str(glb_path), str(usdz_path)], job_id)
    if not usdz_path.is_file():
        raise RuntimeError("usdz failed: USDZ output missing")


def _b2_client(output: B2OutputConfig):
    if B2_KEY_ID == "" or B2_APPLICATION_KEY == "":
        raise RuntimeError("B2 credentials are missing in the worker environment.")
    return boto3.client(
        "s3",
        aws_access_key_id=B2_KEY_ID,
        aws_secret_access_key=B2_APPLICATION_KEY,
        endpoint_url=output.endpoint,
        region_name=output.region,
    )


def _normalize_prefix(prefix: str) -> str:
    cleaned = prefix.strip().strip("/")
    return f"{cleaned}/" if cleaned else ""


def _upload_file(client: Any, bucket: str, key: str, local_path: Path, content_type: str) -> str:
    client.upload_file(
        str(local_path),
        bucket,
        key,
        ExtraArgs={"ContentType": content_type},
    )
    return key


def _callback(payload: JobCreateRequest, body: dict[str, Any]) -> None:
    headers = {
        "X-RunPod-Callback-Token": payload.callback.token,
        "Content-Type": "application/json",
    }
    response = requests.post(payload.callback.url, json=body, headers=headers, timeout=120)
    response.raise_for_status()


def _process_job(job_id: str, payload: JobCreateRequest) -> None:
    workdir = Path(tempfile.mkdtemp(prefix=f"runpod-{job_id}-"))
    image_dir = workdir / "images"
    processed_dir = workdir / "processed"
    outputs_dir = workdir / "outputs"
    meshroom_dir = workdir / "meshroom-output"
    image_dir.mkdir(parents=True, exist_ok=True)
    processed_dir.mkdir(parents=True, exist_ok=True)
    outputs_dir.mkdir(parents=True, exist_ok=True)

    try:
        _update_job(job_id, status="processing", progress=5, message="Downloading input images.")
        originals = _download_images(payload, image_dir, job_id)

        preview_source = originals[0]
        meshroom_input_dir = image_dir

        if payload.options.removeBackground:
            _update_job(job_id, progress=25, message="Running background removal.")
            processed = _run_background_removal(originals, processed_dir, job_id)
            if processed:
                preview_source = processed[0]
                meshroom_input_dir = processed_dir

        if payload.options.generatePreview:
            _update_job(job_id, progress=40, message="Generating preview image.")
            _create_preview(preview_source, outputs_dir / "preview.jpg", job_id)

        _update_job(job_id, progress=50, message="Running Meshroom photogrammetry.")
        obj_path = _run_meshroom(meshroom_input_dir, meshroom_dir, job_id)
        final_obj = outputs_dir / "model.obj"
        shutil.copyfile(obj_path, final_obj)

        _update_job(job_id, progress=75, message="Converting OBJ to GLB.")
        glb_path = outputs_dir / "model.glb"
        _run_obj_to_glb(final_obj, glb_path, payload.scaleMeters, job_id)

        usdz_warning: str | None = None
        usdz_path = outputs_dir / "model.usdz"
        if payload.options.generateUsdz:
            try:
                _update_job(job_id, progress=90, message="Converting GLB to USDZ.")
                _run_glb_to_usdz(glb_path, usdz_path, job_id)
            except Exception as exc:
                usdz_warning = str(exc).strip() or "USDZ conversion failed."

        if payload.outputs.uploadMode != "b2" or payload.outputs.b2 is None:
            raise RuntimeError("Only B2 uploadMode is supported.")

        _update_job(job_id, progress=95, message="Uploading outputs to B2.")
        output = payload.outputs.b2
        client = _b2_client(output)
        prefix = _normalize_prefix(output.keyPrefix)

        uploaded: dict[str, str] = {
            "objPath": _upload_file(client, output.bucket, f"{prefix}model.obj", final_obj, "text/plain"),
            "glbPath": _upload_file(client, output.bucket, f"{prefix}model.glb", glb_path, "model/gltf-binary"),
        }

        preview_path = outputs_dir / "preview.jpg"
        if preview_path.is_file():
            uploaded["previewPath"] = _upload_file(client, output.bucket, f"{prefix}preview.jpg", preview_path, "image/jpeg")

        if payload.options.generateUsdz and usdz_path.is_file():
            uploaded["usdzPath"] = _upload_file(client, output.bucket, f"{prefix}model.usdz", usdz_path, "model/vnd.usdz+zip")

        message = "3D model ready." if usdz_warning is None else f"3D model ready. USDZ export unavailable: {usdz_warning}"
        _update_job(job_id, status="ready", progress=100, message=message, outputs=uploaded)
        _callback(
            payload,
            {
                "localJobId": payload.localJobId,
                "jobId": job_id,
                "scanId": payload.scanId,
                "status": "ready",
                "message": message,
                "outputs": uploaded,
            },
        )
    except Exception as exc:
        status = "canceled" if str(exc) == "Job canceled." else "error"
        message = str(exc).strip() or "Remote pipeline failed."
        _update_job(job_id, status=status, message=message)
        try:
            _callback(
                payload,
                {
                    "localJobId": payload.localJobId,
                    "jobId": job_id,
                    "scanId": payload.scanId,
                    "status": status,
                    "message": message,
                },
            )
        except Exception:
            pass
    finally:
        shutil.rmtree(workdir, ignore_errors=True)


@app.get("/health")
def health() -> dict[str, Any]:
    return {"status": "ok", "service": "runpod-worker", "port": WORKER_PORT, "time": _now_iso()}


@app.post("/jobs")
def create_job(payload: JobCreateRequest, authorization: str | None = Header(default=None)) -> dict[str, Any]:
    _require_auth(authorization)
    if not payload.images:
        raise HTTPException(status_code=422, detail="At least one image is required.")

    job_id = f"rp_job_{secrets.token_hex(12)}"
    record = {
        "jobId": job_id,
        "localJobId": payload.localJobId,
        "scanId": payload.scanId,
        "status": "queued",
        "progress": 0,
        "message": "Queued for processing.",
        "createdAt": _now_iso(),
        "outputs": {},
        "request": payload.model_dump(mode="json"),
        "cancelEvent": threading.Event(),
    }
    with _jobs_lock:
        _jobs[job_id] = record

    worker = threading.Thread(target=_process_job, args=(job_id, payload), daemon=True)
    worker.start()

    return {"jobId": job_id, "status": "queued"}


@app.get("/jobs/{job_id}")
def get_job(job_id: str, authorization: str | None = Header(default=None)) -> dict[str, Any]:
    _require_auth(authorization)
    record = _get_job(job_id)
    if record is None:
        raise HTTPException(status_code=404, detail="Job not found.")
    return {
        "jobId": record["jobId"],
        "localJobId": record.get("localJobId"),
        "scanId": record["scanId"],
        "status": record["status"],
        "progress": record["progress"],
        "message": record["message"],
        "createdAt": record["createdAt"],
        "outputs": record.get("outputs", {}),
    }


@app.post("/jobs/{job_id}/cancel")
def cancel_job(job_id: str, authorization: str | None = Header(default=None)) -> dict[str, Any]:
    _require_auth(authorization)
    with _jobs_lock:
        record = _jobs.get(job_id)
        if record is None:
            raise HTTPException(status_code=404, detail="Job not found.")
        if record["status"] not in {"ready", "error", "canceled"}:
            record["cancelEvent"].set()
            record["status"] = "canceled"
            record["message"] = "Canceled by caller."
        return {
            "jobId": record["jobId"],
            "status": record["status"],
            "message": record["message"],
        }
