import os
import sys
import tempfile

# Testlerde geçici dosyalar ayrı bir klasöre yazılır; test sonrası boş olduğu kontrol edilir.
TMP_DIR = tempfile.mkdtemp(prefix="whisper-test-")
os.environ["WHISPER_TMP_DIR"] = TMP_DIR
sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
