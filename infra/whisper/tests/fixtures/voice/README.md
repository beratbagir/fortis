# Test kayıtları

Şu anki kayıtlar macOS'un Türkçe sentetik sesiyle (`say -v Yelda`) üretildi; geçici. Ekip kendi sesiyle kaydedip
aynı adlarla değiştirmeli (gerçek uçuş verisi veya kişi adı içermemeli). Yeni kayıt eklemek için dosyayı koyup
`expected.json`'a beklenen metni yazın. Sayılar Whisper'ın yazdığı gibi rakamla yazılır ("3 saate", "8'de").

    WHISPER_REAL=1 .venv/bin/pytest -s tests/test_accuracy.py
