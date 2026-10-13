import io
import logging
import os
import subprocess
import wave

import pytest
from fastapi.testclient import TestClient

import app as service
from conftest import TMP_DIR

SECRET_TEXT = "eşiği üç saate indir gizli-cümle"


class Segment:
    def __init__(self, text):
        self.text = text


class FakeModel:
    def __init__(self, fail=False):
        self.fail = fail
        self.calls = []

    def transcribe(self, audio, **kwargs):
        self.calls.append(kwargs)
        if self.fail:
            raise RuntimeError("model hatası")
        return iter([Segment(" " + SECRET_TEXT + " ")]), None


def wav_bytes(seconds: float) -> bytes:
    buffer = io.BytesIO()
    with wave.open(buffer, "wb") as w:
        w.setnchannels(1)
        w.setsampwidth(2)
        w.setframerate(16000)
        w.writeframes(b"\x00\x01" * int(16000 * seconds))
    return buffer.getvalue()


@pytest.fixture
def client():
    service.app.state.model = FakeModel()
    with TestClient(service.app, raise_server_exceptions=False) as c:
        yield c


def tmp_files():
    return os.listdir(TMP_DIR)


def test_health(client):
    assert client.get("/health").json() == {"status": "ok", "model": service.MODEL_NAME}


def test_transcribe_returns_text_and_measures(client):
    response = client.post("/transcribe", files={"file": ("a.wav", wav_bytes(2), "audio/wav")}, data={"prompt": "Fortis, APM"})
    assert response.status_code == 200
    body = response.json()
    assert body["text"] == SECRET_TEXT
    assert body["duration_s"] == 2.0
    assert body["language"] == "tr"
    assert isinstance(body["processing_ms"], int)
    call = service.app.state.model.calls[0]
    assert call["language"] == "tr" and call["beam_size"] == 1 and call["vad_filter"] is True
    assert call["initial_prompt"] == "Fortis, APM"


def test_longer_than_30_seconds_is_413(client):
    response = client.post("/transcribe", files={"file": ("a.wav", wav_bytes(31), "audio/wav")})
    assert response.status_code == 413
    assert service.app.state.model.calls == []


def test_larger_than_5_mb_is_413(client):
    response = client.post("/transcribe", files={"file": ("a.wav", b"\x00" * (5 * 1024 * 1024 + 1), "audio/wav")})
    assert response.status_code == 413


def test_undecodable_audio_is_422(client):
    response = client.post("/transcribe", files={"file": ("a.webm", b"bu bir ses degil", "audio/webm")})
    assert response.status_code == 422


@pytest.mark.parametrize("case", ["ok", "too_long", "bad_audio", "model_error"])
def test_no_file_left_in_tmp(client, case):
    if case == "model_error":
        service.app.state.model = FakeModel(fail=True)
    payload = {"ok": wav_bytes(1), "too_long": wav_bytes(31), "bad_audio": b"xx", "model_error": wav_bytes(1)}[case]
    client.post("/transcribe", files={"file": ("a.wav", payload, "audio/wav")})
    assert tmp_files() == []


def test_logs_do_not_contain_text(client, caplog):
    caplog.set_level(logging.DEBUG)
    response = client.post("/transcribe", files={"file": ("a.wav", wav_bytes(1), "audio/wav")}, data={"prompt": "Fortis"})
    assert response.status_code == 200
    assert "duration_s=" in caplog.text
    assert "gizli-cümle" not in caplog.text
    assert "Fortis" not in caplog.text
