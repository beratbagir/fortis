"""Gerçek modelle tanıma doğruluğu. Model indirilmiş olmalı; WHISPER_REAL=1 ile çalışır (ilk seferde model iner)."""

import json
import os
import re
from pathlib import Path

import jiwer
import pytest
from fastapi.testclient import TestClient

import app as service

FIXTURES = Path(__file__).parent / "fixtures" / "voice"
EXPECTED = json.loads((FIXTURES / "expected.json").read_text())
# backend/config/voice.php → vocabulary ile aynı tutulmalı
VOCABULARY = (
    "Fortis, akış, blok, tetikleyici, rötar, iptal, STD, ETD, OCC, APM, IBS, SuccessFactors, Teams, Outlook, Kestra, "
    "AYT, IST, SAW, ESB, ADB, DLM, BJV, DUS, FRA, MUC, CGN, e-posta, Excel, PDF, rapor, dakika, saat"
)

pytestmark = pytest.mark.skipif(os.environ.get("WHISPER_REAL") != "1", reason="WHISPER_REAL=1 ile çalışır")


def normalize(text: str) -> str:
    text = text.replace("I", "ı").replace("İ", "i").lower()
    text = re.sub(r"['’]", "", text)
    text = re.sub(r"[^\w\s]", " ", text)
    return " ".join(text.split())


@pytest.fixture(scope="module")
def client():
    service.app.state.model = None
    with TestClient(service.app) as c:
        yield c


def test_recordings_are_recognized(client):
    errors = []
    for name in sorted(EXPECTED):
        with open(FIXTURES / f"{name}.wav", "rb") as f:
            response = client.post("/transcribe", files={"file": (f"{name}.wav", f, "audio/wav")}, data={"prompt": VOCABULARY})
        assert response.status_code == 200
        body = response.json()
        errors.append(jiwer.wer(normalize(EXPECTED[name]), normalize(body["text"])))
        print(f"\n{name}: wer={errors[-1]:.2f} {body['processing_ms']} ms / {body['duration_s']} s → {body['text']!r}")

    # Ortalama kelime hata oranı %20'nin altında olmalı.
    assert sum(errors) / len(errors) < 0.2
