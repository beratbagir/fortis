# Görev: Fortis Aero — Faz A1: Akıllı Cevaplar

Önce `docs/fortis-aero-yol-haritasi.md` dosyasını oku.

## 1. Amaç

Yönetimin isteği: **Fortis, şirket verisiyle ilgili sorulara Claude'un sohbette verdiği gibi cevap versin.** Yani veriye baksın, karşılaştırsın, nedenini açıklasın, öneri yapsın.

Bugün bu mümkün değil, çünkü bilerek öyle kurduk:

- `resources/prompts/system.md`: *"Şirket verisini sohbette tahmin etme… Bu veri sadece rapor veya akış ile alınır."*
- `get_execution` ve `create_report` Claude'a satır verisi göstermiyor, sadece durum ve sayım veriyor.

Sonuç olarak "Dün hangi rotalar kötüydü?" sorusuna Fortis ya bir dosya üretiyor ya da akış kuruyor. Yorum yapamıyor, çünkü veriyi görmüyor.

Bu fazda Claude'a **veriyi sorgulayıp görebileceği araçlar** verilir. Kişisel veri maskelenir, sınırlar konur, her sorgu kayıt altına alınır.

**Ön şart:** Yönetim onayı (verildi, 2026-10-13): *"Operasyonel veri (uçuş, rota, rötar, uçak, sayılar, departman) Claude'a gidebilir. Kişisel veri maskelenir."*

---

## 2. Kesin kurallar

1. **Sadece okuma.** Yeni araçlar hiçbir sisteme yazmaz.
2. **Kişisel veri Claude'a gitmez.** Bütün araç sonuçları Claude'a gitmeden önce tek bir sınıftan geçer (`AiDataGate`, bkz. 4). Bu sınıfı atlayan bir yol olmamalı. Bunun için bir test yazılır.
3. **Sınırlar:** Bir araç çağrısı Claude'a en fazla `AI_QUERY_MAX_ROWS` (varsayılan 200) satır ve `AI_QUERY_MAX_CHARS` (varsayılan 40.000) karakter döndürür. Fazlası kesilir, Claude'a toplam satır sayısı ve "kesildi" bilgisi verilir.
4. **Her sorgu kayıt altında:** `audit_logs`'a kim sordu, hangi araç, hangi sistem, kaç satır döndü, kaç alan maskelendi yazılır. Sorgunun kendisi (SQL veya parametreler) `meta`'da tutulur. Dönen veri **tutulmaz.**
5. **Politika anahtarı:** `settings` tablosunda `ai_data_access` (`off` / `masked`). `off` iken yeni araçlar Claude'a hiç sunulmaz ve sistem eski davranışa döner. Admin ayarlar ekranından değiştirir. Varsayılan `off`.
6. **Var olan güvenlik korunur:** `SqlGuard`, `NationalIdMasker`, warehouse EXPLAIN kontrolü, APM uç nokta izin listesi aynen kullanılır.

---

## 3. Yeni araçlar

### 3.1 `query_warehouse` (IBS)

Claude'un IBS veri ambarında **SELECT** sorgusu çalıştırıp sonucu görmesi.

- **Girdi:** `sql` (parametreli, `:ad` yer tutucularıyla), `parameters`, `purpose` (kullanıcıya gösterilecek kısa açıklama, örn. "Dünkü uçuşlar, rota bazında yük faktörü").
- **Kontroller (çalıştırmadan önce):**
  - `SqlGuard` geçmeli (sadece SELECT, tek ifade, yasaklı tablo yok).
  - `LIMIT` zorunlu, en fazla 1.000. Claude'a dönen en fazla 200 satır; fazlası kesilir ama `total_rows` doğru döner.
  - EXPLAIN ile tam tarama uyarısı gelirse çalıştırılmaz, Claude'a "indeksli tarih sütunuyla filtrele" uyarısı döner (akışlardaki mevcut mantık).
  - Zaman aşımı: 30 saniye.
- **Yasaklı tablolar** (`config/warehouse.php` → `ai_blocked_tables`): `pnr_pax`, `chk_passenger`, `chk_pax_ssr`, `inf_details` ve kişisel veri içeren diğer tablolar. Bu tablolar sadece **sayım** sorgularında (`COUNT`, `SUM`, `GROUP BY` sonucu tek kişiye inmeyen) kullanılabilir. Kişi düzeyinde satır döndüren sorgu reddedilir.
- **Çıktı:** `{ columns: [...], rows: [...], total_rows, truncated, masked_fields: [...], source: "IBS", query_id }`

### 3.2 `query_apm` (APM)

Mevcut `preview_apm` mantığı genişletilir.

- **Girdi:** İzin listesindeki uç nokta + parametreler (mevcut kural).
- **İşleme:** APM'nin büyük JSON cevabı Claude'a ham haliyle gönderilmez. Uç nokta başına bir **dönüştürücü** (`ApmResultShaper`) önemli alanları düz satırlara çevirir. Örnek: `/api/legs` → `flight_no, dep, arr, std_utc, atd_utc, delay_min, delay_codes, aircraft_reg, status`.
- Mürettebat adı, yolcu sayıları dışındaki yolcu bilgisi, telefon gibi alanlar dönüştürücüde hiç seçilmez.
- **Çıktı:** 3.1 ile aynı biçim, `source: "APM"`.

### 3.3 `query_successfactors` (SuccessFactors)

Mevcut `preview_successfactors` genişletilir.

- Ad, soyad, departman, pozisyon, lokasyon, işe giriş tarihi döner.
- TC kimlik, telefon, adres, doğum tarihi, maaş **hiçbir zaman** seçilmez (izin listesi ile, kara liste ile değil).
- E-posta adresleri maskelenir (`b***@gozendigital.com`).
- **Çıktı:** 3.1 ile aynı biçim, `source: "SuccessFactors"`.

### 3.4 Ortak davranış

- Bütün araçlar sonuçlarını `AiDataGate`'ten geçirerek döndürür.
- Her sonuçta bir `query_id` bulunur. Claude cevabında kaynak gösterirken bu kimliği kullanır (bkz. 5.3).
- Araç hatası Claude'a sade dille döner ("APM'ye şu an ulaşılamıyor", "Sorgu 30 saniyede bitmedi, tarih aralığını daralt").

---

## 4. `AiDataGate` (maskeleme ve sınır katmanı)

`app/Ai/AiDataGate.php`. Araç sonucu → Claude'a gidecek içerik.

**Sırası:**

1. **Alan adına göre maskeleme:** `config/ai-data.php` → `masked_columns` listesindeki sütunlar (`first_name`, `last_name`, `pax_name`, `passenger_name`, `email`, `phone`, `mobile`, `pnr`, `record_locator`, `ticket_number`, `passport`, `birth_date`, `national_id` ve benzerleri) değer yerine `[MASKED]` olur.
2. **İçeriğe göre maskeleme:** Bütün metin değerleri taranır: TC kimlik (mevcut `NationalIdMasker`), e-posta, telefon numarası, PNR biçimi (6 harf/rakam), bilet numarası (13 hane), pasaport biçimi. Eşleşenler `[MASKED]` olur.
3. **Personel istisnası:** SuccessFactors'tan gelen çalışan adı ve soyadı maskelenmez (yönetim sorularında gerekli: "AYT istasyon müdürü kim?"). Bu istisna sadece `source: "SuccessFactors"` için ve sadece ad/soyad için geçerlidir. Ayarla kapatılabilir (`ai_allow_employee_names`).
4. **Kesme:** Satır ve karakter sınırı (bkz. 2.3).
5. **Sayım:** Kaç alan maskelendi, `masked_fields` ve denetim kaydına yazılır.

Bu sınıf için ayrı ve kapsamlı birim testleri yazılır (bkz. 8).

---

## 5. Sistem promptu

`resources/prompts/system.md` güncellenir.

### 5.1 Kaldırılacak / değişecek kurallar

`ai_data_access = masked` iken:

- *"Şirket verisini sohbette tahmin etme veya uydurma. Bu veri sadece rapor veya akış ile alınır."* → **"Şirket verisini tahmin etme veya uydurma. Gerektiğinde `query_*` araçlarıyla sorgula ve sonuca dayanarak cevap ver. Görmediğin bir sayıyı yazma."**
- Promptun ilgili bölümleri `ai_data_access` değerine göre iki sürüm halinde tutulur (`{{#if data_access}}` benzeri basit bir şablon). `off` iken mevcut metin aynen kalır.

### 5.2 Soru mu, otomasyon mu?

Yeni bölüm:

- **Soru** (şimdi, bir kez): "Dün hangi rotalar kötüydü?", "AYT'de bu hafta kaç rötar oldu?", "Finans departmanında kaç kişi var?" → **Akış kurma.** `query_*` araçlarıyla sorgula, yorumla, cevapla.
- **Otomasyon** (tekrar eden veya bir olaya bağlı): "Her sabah…", "Rötar 4 saati geçerse…", "Pazartesileri…" → Akış kur (mevcut davranış).
- **Dosya istendiğinde:** "Excel olarak ver", "PDF hazırla" → `create_report` (mevcut davranış). İstersen önce `query_*` ile bakıp kısa bir özet de verebilirsin.
- Emin değilsen sor: "Bunu şimdi bir kere mi görmek istiyorsun, yoksa düzenli olarak mı?"
- Bir sorunun cevabından sonra uygunsa öner: "Bunu her sabah otomatik almak ister misin?"

### 5.3 Cevap üslubu

Yeni bölüm. Veri sorularında cevap şu düzende olur:

1. **Sonuç (1–3 cümle):** Sorunun doğrudan cevabı. En önemli sayı ve bulgu kalın.
2. **Kanıt tablosu:** Markdown tablo, en fazla 10 satır. Sayılar sağa hizalı, birimleri belli (%, dk, €).
3. **Olası nedenler:** Veride görülen nedenler. Veride olmayan bir nedeni kesinmiş gibi yazma; tahminse "muhtemelen" de ve neye dayandığını söyle.
4. **Önerilen sonraki adımlar (isteğe bağlı, en fazla 3):** "AYT–HAJ için grup iptallerini inceleyelim mi?", "Bunu her sabah otomatik göndereyim mi?"
5. **Kaynak satırı:** Hangi sistemlerden, hangi tarih aralığında bakıldığı. Örn. *Kaynak: IBS (2 Eki 2026, planlı seferler), APM.*

Genel kurallar:
- Türkçe, sade, kısa. "Harika soru", "Tabii ki" gibi girişler yok.
- Tarih ve saatler Türkiye saatiyle, açıkça yazılır.
- Yüzdeleri ve farkları hesapla, kullanıcıya hesap yaptırma.
- Veri eksik veya şüpheliyse söyle ("APM test ortamında gerçekleşen saatler boş").
- Tek bir sorgu yetmiyorsa birden fazla sorgu yap. Bir sonuca bakıp yeni soru sormak serbest. Ama bir cevap için en fazla 8 araç çağrısı.
- Birden fazla anlamı olan terimlerde ("kötü performans", "rötar") önce kullanıcıya sor veya varsayımını açıkça yaz.

Prompta **3 örnek** soru-cevap eklenir (gerçek veri değil, sahte ama gerçekçi):

1. "Dün en çok rötar yapan 5 uçuş hangileri?" (APM)
2. "Bu hafta AYT çıkışlı uçuşlarda ortalama doluluk nasıl, geçen haftayla karşılaştır." (IBS)
3. "Finans departmanında son bir ayda kimler işe başladı?" (SuccessFactors, ad-soyad gösterilir)

### 5.4 Model

- Veri sorularında varsayılan model **Opus**, diğer işlerde mevcut varsayılan. Bu, `config/ai.php` → `data_question_model` ile ayarlanır. Kullanıcı sohbette modeli elle seçtiyse onun seçimi geçerlidir.
- Hangi isteğin veri sorusu olduğunu anlamak için ayrı bir model çağrısı yapılmaz. İlk turda varsayılan modelle başlanır, Claude bir `query_*` aracı çağırdığında **sonraki turlar** `data_question_model` ile devam eder.

---

## 6. Arayüz (küçük değişiklikler)

Asıl ekran tasarımı (canlı iz, kaynaklı cevap) Faz A2'de. Bu fazda sadece:

- `toolLabels.ts`: `query_warehouse` → "IBS sorgulanıyor", `query_apm` → "APM sorgulanıyor", `query_successfactors` → "SuccessFactors sorgulanıyor".
- Araç adımında `purpose` metni gösterilir ("Dünkü uçuşlar, rota bazında yük faktörü") ve sonuç gelince satır sayısı ("212 satır").
- Maskelenen alan varsa küçük bir not: "Kişisel bilgiler gizlendi".
- Markdown tablolar düzgün görünür (sağa hizalı sayılar, yatay kaydırma).
- Ayarlar ekranında (admin) **"AI veri erişimi"** anahtarı: Kapalı / Maskeli. Açıklama: "Açıkken Fortis veriye bakarak yorumlu cevap verir. Kişisel bilgiler AI'a gönderilmez."

---

## 7. Değerlendirme seti

Cevap kalitesini ölçmek için `tests/eval/questions.json`: 15–20 gerçekçi soru. Her biri için:

- Beklenen araç(lar) (`query_apm`, `query_warehouse`…)
- Akış kurulmaması gerektiği (`expect_no_flow: true`)
- Cevapta bulunması gerekenler (örn. bir tablo, bir kaynak satırı)

`php artisan fortis:eval` komutu soruları gerçek modele sorar (test ortamı verisiyle) ve bir rapor üretir: araç doğru mu, akış kurulmadı mı, tablo var mı, kaynak var mı. Bu komut CI'da değil, prompt değiştiğinde elle çalıştırılır. Sonuç `storage/app/eval/` altına yazılır.

---

## 8. Testler

**Pest:**

1. `ai_data_access = off` iken `query_*` araçları Claude'a gönderilen araç listesinde yok.
2. `masked` iken var.
3. `AiDataGate`:
   - `pax_name`, `email`, `phone`, `pnr` sütunları maskeleniyor.
   - Serbest metin içindeki TC kimlik, e-posta, telefon, PNR, bilet no maskeleniyor.
   - SuccessFactors ad/soyad maskelenmiyor, `ai_allow_employee_names = false` iken maskeleniyor.
   - 500 satırlık sonuç 200'e kesiliyor, `total_rows = 500`, `truncated = true`.
   - 40.000 karakter sınırı uygulanıyor.
4. **Atlama yok:** Claude'a giden her `tool_result` içeriği `AiDataGate`'ten geçmiş (`Http::fake` ile Anthropic'e giden istek gövdesi taranıyor; fixture'daki bir yolcu adı, e-posta ve PNR hiçbir koşulda gövdede yok).
5. `query_warehouse`: INSERT/UPDATE/çoklu ifade reddediliyor. `LIMIT`'siz sorgu reddediliyor. Yasaklı tablodan kişi düzeyinde satır isteği reddediliyor, aynı tablodan `COUNT … GROUP BY` kabul ediliyor.
6. `query_apm`: İzin listesi dışındaki uç nokta reddediliyor. Dönüştürücü mürettebat adını döndürmüyor.
7. `query_successfactors`: TC kimlik, telefon, adres alanları hiçbir koşulda dönmüyor.
8. Denetim kaydı: Her sorgu için kayıt düşüyor, `meta`'da sorgu var, dönen veri yok.
9. Model geçişi: `query_*` çağrısından sonraki tur `data_question_model` ile yapılıyor. Kullanıcı modeli elle seçtiyse değişmiyor.
10. Araç çağrısı sınırı: 9. araç çağrısında Claude'a "sınır doldu, elindekiyle cevap ver" mesajı dönüyor.
11. Var olan bütün testler geçiyor.

**Vitest:**

12. Yeni araç etiketleri, `purpose` metni ve satır sayısı gösteriliyor.
13. "Kişisel bilgiler gizlendi" notu `masked_fields` doluyken görünüyor.

---

## 9. Kabul kriterleri

- [x] Yönetim onayı (verildi, 2026-10-13) ve `ai_data_access = masked` yapıldı.
- [ ] "Dün en çok rötar yapan 5 uçuş hangileri?" sorusuna Fortis akış kurmadan, tablolu ve kaynaklı, yorumlu bir cevap veriyor.
- [ ] "Her sabah bunu bana gönder" denince hâlâ akış kuruluyor.
- [ ] Değerlendirme setinde soruların en az %80'inde doğru araç kullanılıyor ve gereksiz akış kurulmuyor.
- [ ] Kişisel veri Claude'a gitmiyor (test 4).
- [ ] `ai_data_access = off` yapılınca sistem eski davranışa dönüyor.
- [ ] `ILERLEME.md` güncellendi.

---

## 10. Kapsam dışı

- Canlı iz ve kaynaklı cevap ekranlarının yeni tasarımı (Faz A2)
- Memory ve Skills (Faz A5). Bu fazda şirket tanımları sistem promptuna sabit olarak yazılabilir (örn. yük faktörü hedefi), sonra Skills'e taşınır.
- AirRM, Oracle, Octopus, SkyBreathe (erişim yok)
- Kişiye göre veri izni (Faz A7). Bu fazda giriş yapmış herkes aynı veriyi sorgulayabilir.

---

## 11. Açık sorular

- ~~Yönetim onayı kimden ve nasıl alınacak?~~ Yönetim onayı (verildi, 2026-10-13): Kerem Bey, sözlü.
- Personel ad-soyadı AI'a gidebilir mi? (Varsayılan: evet, ayarla kapatılabilir.)
- Veri sorularında Opus kullanmanın maliyeti kabul edilebilir mi? (Bir veri sorusu tahminen $0.05–0.30.)
