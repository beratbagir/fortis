FROM python:3.12-slim

RUN apt-get update \
    && apt-get install -y --no-install-recommends ffmpeg \
    && rm -rf /var/lib/apt/lists/*

RUN useradd --create-home --uid 1000 whisper
WORKDIR /app
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt
COPY app.py .

USER whisper
ENV HF_HOME=/home/whisper/.cache/huggingface
EXPOSE 9000
# Erişim logu kapalı: yol ve IP dışında bir şey yazmıyor ama gereksiz.
CMD ["uvicorn", "app:app", "--host", "0.0.0.0", "--port", "9000", "--no-access-log"]
