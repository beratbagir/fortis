"""Fortis ses → metin servisi (faster-whisper, CPU).

Sadece Laravel erişir. Ses diske yalnızca çeviri süresince geçici dosya olarak yazılır ve her durumda silinir.
Metin ve ses loglanmaz; sadece süre ve işlem zamanı loglanır.
"""

import asyncio
import logging
import os
import subprocess
import tempfile
import time
from contextlib import asynccontextmanager

import numpy as np
from fastapi import FastAPI, File, Form, HTTPException, UploadFile
from fastapi.concurrency import run_in_threadpool

MODEL_NAME = os.environ.get("WHISPER_MODEL", "small")
MAX_CONCURRENT = int(os.environ.get("WHISPER_MAX_CONCURRENT", "2"))
MAX_SECONDS = float(os.environ.get("WHISPER_MAX_SECONDS", "30"))
MAX_BYTES = int(os.environ.get("WHISPER_MAX_BYTES", str(5 * 1024 * 1024)))
TMP_DIR = os.environ.get("WHISPER_TMP_DIR") or None
SAMPLE_RATE = 16000

log = logging.getLogger("fortis.whisper")
logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(name)s %(message)s")
# faster-whisper kendi INFO loglarında ses süresini ve VAD ayrıntısını yazar; metin yazmaz ama gürültüyü azaltalım.
logging.getLogger("faster_whisper").setLevel(logging.WARNING)


def load_model():
    from faster_whisper import WhisperModel

    return WhisperModel(MODEL_NAME, device="cpu", compute_type="int8")


@asynccontextmanager
async def lifespan(app: FastAPI):
    # Model servis açılırken bir kez yüklenir (testlerde app.state.model önceden verilebilir).
    if getattr(app.state, "model", None) is None:
        app.state.model = load_model()
    app.state.slots = asyncio.Semaphore(MAX_CONCURRENT)
    log.info("model hazır: %s", MODEL_NAME)
    yield


app = FastAPI(title="Fortis Whisper", lifespan=lifespan, docs_url=None, redoc_url=None, openapi_url=None)


def decode(path: str) -> np.ndarray:
    """ffmpeg ile 16 kHz mono float32'ye çevirir. Sınırın biraz fazlası okunur; fazlası 413 için yeterli."""
    result = subprocess.run(
        ["ffmpeg", "-nostdin", "-v", "error", "-i", path, "-t", str(MAX_SECONDS + 1),
         "-f", "s16le", "-ac", "1", "-ar", str(SAMPLE_RATE), "-"],
        capture_output=True,
        timeout=30,
    )
    if result.returncode != 0:
        raise HTTPException(status_code=422, detail="Ses dosyası çözülemedi.")
    return np.frombuffer(result.stdout, np.int16).astype(np.float32) / 32768.0


def transcribe_audio(model, audio: np.ndarray, prompt: str | None) -> str:
    segments, _ = model.transcribe(
        audio,
        language="tr",
        beam_size=1,
        vad_filter=True,
        initial_prompt=prompt or None,
    )
    return " ".join(segment.text.strip() for segment in segments).strip()


@app.get("/health")
async def health():
    return {"status": "ok", "model": MODEL_NAME}


@app.post("/transcribe")
async def transcribe(file: UploadFile = File(...), prompt: str | None = Form(None)):
    started = time.perf_counter()
    path = None
    try:
        data = await file.read(MAX_BYTES + 1)
        if len(data) > MAX_BYTES:
            raise HTTPException(status_code=413, detail="Ses dosyası 5 MB'tan büyük.")
        if not data:
            raise HTTPException(status_code=422, detail="Ses dosyası boş.")

        with tempfile.NamedTemporaryFile(prefix="voice-", suffix=".audio", dir=TMP_DIR, delete=False) as tmp:
            path = tmp.name
            tmp.write(data)
        del data

        audio = await run_in_threadpool(decode, path)
        duration = len(audio) / SAMPLE_RATE
        if duration > MAX_SECONDS:
            raise HTTPException(status_code=413, detail=f"Ses {int(MAX_SECONDS)} saniyeden uzun.")

        async with app.state.slots:
            text = await run_in_threadpool(transcribe_audio, app.state.model, audio, prompt)

        processing_ms = int((time.perf_counter() - started) * 1000)
        log.info("çeviri: duration_s=%.1f processing_ms=%d", duration, processing_ms)
        return {"text": text, "duration_s": round(duration, 1), "processing_ms": processing_ms, "language": "tr"}
    finally:
        await file.close()
        if path is not None:
            try:
                os.unlink(path)
            except FileNotFoundError:
                pass
