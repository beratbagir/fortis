# Görev: Fortis Aero — Faz A2: Home ve Konuşma Ekranları

Önce `docs/fortis-aero-yol-haritasi.md` ve `ILERLEME.md` dosyalarını oku. Tasarım: `docs/design/fortis-aero-product-screens.pdf` (sayfa 2 Home, 3 model seçici, 4 canlı iz, 5 kaynaklı cevap, 22 koyu Home).

## 1. Amaç

A1, Claude'un veriyi sorgulayıp yorumlamasını sağladı. A2, bu cevapları tasarımdaki ekranlarda gösterir:

1. **Home:** Karşılama, soru kutusu, bağlantı çipleri, örnek sorular, "Seni bekleyenler", bugünkü etkinlik.
2. **Model seçici:** Auto + Anthropic modelleri.
3. **Canlı iz:** Cevap hazırlanırken her adımın görünmesi.
4. **Soru kartı:** Claude'un emin olmadığında çoktan seçmeli soru sorması.
5. **Kaynaklı cevap:** Özet, kanıt tablosu, numaralı kaynaklar, önerilen aksiyonlar.

Bu faz A1'in araçlarını ve güvenlik kurallarını **değiştirmez**, sadece kullanır. `ai_data_access = off` iken de ekranlar çalışır (veri adımları görünmez).

---

## 2. Kesin kurallar

1. **Sahte veri yok.** Bağlı olmayan sistem "Connected" görünmez. Bekleyen iş yoksa "Seni bekleyenler" boş durum gösterir. Tasarımdaki örnek metinler sadece demo seed'inde kullanılır (`php artisan db:seed --class=DemoSeeder`, canlıda çalışmaz).
2. **Kaynak numaraları gerçek sorgulara bağlı.** Cevaptaki `[1]` gibi her numara, o cevapta gerçekten çalışmış bir `query_id`'ye karşılık gelir. Karşılığı olmayan numara gösterilmez (bkz. 6.3).
3. **İz, gerçekte olan adımları gösterir.** Uydurma "bellek yüklendi" veya "agent seçildi" adımı yok. Bellek A5'te, agent yönlendirme A3'te gelince bu adımlar eklenir.
4. **Önerilen aksiyonlar tıklanınca kendiliğinden bir şey yapmaz.** Yazan aksiyonlar onaya gider (A3 gelene kadar sadece sohbete hazır bir mesaj olarak yazılır).
5. **Model seçici sadece izinli modelleri gösterir** (`config/ai.php` → `models`). Tasarımdaki OpenAI, Google, Meta, Mistral bu fazda **gösterilmez.**
6. **Politika metni gerçeği söyler.** Model seçicinin altındaki not, tasarımdaki "data stays in EU region" yerine gerçek durumu yazar (bkz. 4.3).

---

## 3. Home

Rota: `#/chat` (yeni sohbet). Sohbet açıkken `#/chat/:id`.

### 3.1 Düzen

- **Başlık:** Logo yıldızı + "Günaydın, {ad}" (saate göre: Günaydın 05–12, İyi günler 12–18, İyi akşamlar 18–05; Türkiye saati). Serif yazı tipi.
- **Soru kutusu:** Çok satırlı, Enter gönderir, Shift+Enter yeni satır. Altta:
  - `+` (dosya ekle): Var olan dosya ekleme davranışı. Yoksa bu fazda gizli.
  - Kaynak seçici (ayar ikonu + "N kaynak"): Bağlı kaynakların listesi, her biri açılıp kapatılabilir. Kapatılan kaynağın aracı o sohbette Claude'a sunulmaz. Varsayılan: hepsi açık.
  - Mikrofon (sesli komut varsa).
  - Model seçici (bkz. 4).
  - Gönder butonu.
- **Bağlantı çipleri:** `GET /api/connectors/status` (bkz. 3.3). Kısaltma + ad (IB IBS, AP APM, SF SuccessFactors, OL Outlook, TM Teams). Bağlı değilse soluk ve "Kurulum bekliyor" ipucu. Tıklayınca `#/connectors` (A4'e kadar "Yakında").
- **Örnek sorular:** 4 kart, 2×2. Kaynak: `config/home.php` → `suggestions`, departmana göre (bkz. 3.4). Tıklanınca soru kutusuna yazılır ve gönderilir. Kart ikonu türüne göre: soru (grafik), agent oluştur (anahtar), belge (sayfa), sunum (ekran).
- **Alt bölüm, iki sütun:**
  - **Seni bekleyenler · N** (Tümünü gör → `#/inbox`): Bu fazda kaynak, kullanıcının onayını bekleyen çalışmalar (`executions` durumu PAUSED, akış sahibi kullanıcı veya admin). A3'te Inbox'a bağlanır. En fazla 3 kart: ikon (onay / soru), başlık, kaç dakika önce, bir satır açıklama. Boşsa "Bekleyen işin yok".
  - **Bugünkü etkinlik** (Kayıt → `#/agents`): Bugün çalışan akışlar (A3'ten sonra agentlar), en fazla 5. Durum noktası (yeşil başarılı, kırmızı hata veya bekliyor), ad, bir satır sonuç, saat (mono). Boşsa "Bugün henüz çalışan yok".

### 3.2 Telefon genişliği

Tek sütun. Çipler yatay kayar. Alt bölüm alt alta.

### 3.3 `GET /api/connectors/status`

```json
[
  { "key": "ibs", "label": "IBS", "abbr": "IB", "status": "connected", "detail": null },
  { "key": "apm", "label": "APM", "abbr": "AP", "status": "attention", "detail": "Son başarılı istek 2 saat önce" },
  { "key": "successfactors", "label": "SuccessFactors", "abbr": "SF", "status": "connected", "detail": null },
  { "key": "outlook", "label": "Outlook", "abbr": "OL", "status": "not_configured", "detail": null }
]
```

- `connected`: yapılandırılmış ve son 15 dakikada bir kontrol isteği başarılı.
- `attention`: yapılandırılmış ama son kontrol başarısız veya eski.
- `not_configured`: `.env`'de bilgisi yok.
- Kontrol, zamanlanmış bir komutla yapılır (`php artisan connectors:check`, 10 dakikada bir, `connector_checks` tablosuna yazar). Sayfa açılışında dış sisteme istek atılmaz.

### 3.4 Örnek sorular

```php
// config/home.php
'suggestions' => [
    'default' => [
        ['type' => 'question', 'text' => 'Dün en çok rötar yapan 5 uçuş hangileri?'],
        ['type' => 'question', 'text' => 'Bu hafta AYT çıkışlı uçuşlarda zamanında kalkış oranı nasıl?'],
        ['type' => 'agent',    'text' => 'Her sabah dünkü rötarları özetleyen bir agent kur'],
        ['type' => 'report',   'text' => 'Dünkü iptal edilen uçuşları Excel olarak ver'],
    ],
    'Finance' => [ /* ... */ ],
],
```

- Sadece bağlı kaynaklarla cevaplanabilen sorular gösterilir (her öneride `requires: ['apm']`).
- `ai_data_access = off` ise veri soruları yerine otomasyon ve rapor önerileri gösterilir.

---

## 4. Model seçici

### 4.1 Görünüm

Soru kutusundaki "Auto ⌄" butonu açılır bir panel açar:

- Üstte arama kutusu.
- **Auto** satırı (vurgulu): "Her adım için uygun modeli seçer". Seçiliyse sağda "Seçili".
- Sol sütun: sağlayıcılar (bu fazda sadece **Anthropic**, yanında model sayısı).
- Sağ sütun: o sağlayıcının modelleri, her birinin altında bir satır güçlü yönü:
  - Claude Opus: "Çok adımlı derin analiz"
  - Claude Sonnet: "Dengeli, çoğu iş için varsayılan"
  - Claude Haiku: "Hızlı ve düşük maliyetli sınıflandırma" (sadece `config/ai.php` → `models` içindeyse)
- Altta kilit ikonu ve politika notu (bkz. 4.3).

### 4.2 Auto

`model = auto` sohbet ayarı. `App\Ai\ModelRouter`:

| Durum | Model |
|---|---|
| Sohbet başlığı üretme, kısa sınıflandırma | `ai.models.fast` (Haiku varsa, yoksa Sonnet) |
| İlk tur | `ai.default_model` (Sonnet) |
| Bir `query_*` aracı çağrıldıktan sonraki turlar | `ai.data_question_model` (Opus) — A1'deki mevcut kural |
| Akış (YAML) üretimi | `ai.default_model` |

Kullanıcı belirli bir model seçtiyse Auto devre dışıdır ve her turda o model kullanılır (A1'deki kural).

Her AI çağrısında kullanılan model `ai_calls`'a zaten yazılıyor. Cevabın altındaki bilgi satırında gösterilir (bkz. 6.5).

### 4.3 Politika notu

`config/ai.php` → `policy_note`. Varsayılan:

> "İzinli modeller yöneticin tarafından belirlenir · Kişisel bilgiler AI'a gönderilmez"

`ai_data_access = off` ise ikinci kısım "Şirket verisi AI'a gönderilmez" olur. "AB bölgesinde kalır" gibi doğrulanmamış bir ifade yazılmaz.

---

## 5. Canlı iz

### 5.1 Görünüm

Kullanıcı mesajının altında, cevap gelmeden önce:

```
✦ Düşünüyor  14,2 sn · 23 adım  ▢▢▢▢  ˄
│ ✓ Model seçildi · Auto → Claude Sonnet
│   Veri çekme ve çok adımlı karşılaştırma
│ ✓ İzin kapsamı
│   Berat B. · Admin — 3 bağlı kaynaktan 3'ü kapsamda
│   [IBS] [APM] [SuccessFactors] [~~Outlook~~]
│ ✓ IBS sorgulanıyor · Dünkü uçuşlar, rota bazında yük faktörü
│   212 satır döndü
│   SELECT … WHERE flight_date = '2026-10-02' …     (mono, tek satır, taşarsa …)
│   Fortis tarafından çekildi · IBS bağlayıcısı (salt okunur)
│ ✓ APM sorgulanıyor · Dünkü rötarlar
│   48 satır döndü · Kişisel bilgiler gizlendi
│ ◌ Bekliyor · Cevabın gerekiyor
│   "Kötü performans" birden fazla şekilde tanımlanabilir.
```

- Başlık satırı: geçen süre ve adım sayısı canlı güncellenir. Küçük kareler son 4 adımın durumunu gösterir.
- `˄` ile daraltılır. Cevap tamamlanınca iz kendiliğinden daralır: "✦ 31,4 sn düşündü · 41 adım · Denetim kaydı".
- **Denetim kaydı** bağlantısı, bu cevap için `audit_logs`'taki `ai.query` kayıtlarını gösteren küçük bir pencere açar (admin ve sohbet sahibi).
- Sorgu metni sadece **admin** ve **builder** rolüne gösterilir. `user` rolü sadece amaç ve satır sayısını görür.

### 5.2 Adım türleri

Var olan SSE olaylarından üretilir. Yeni olay türü eklenebilir, var olanlar bozulmaz.

| Adım | Ne zaman | Kaynak |
|---|---|---|
| Model seçildi | İlk tur başında ve model değişince (Sonnet → Opus) | `ModelRouter` |
| İzin kapsamı | Her cevabın başında bir kez | Kullanıcının rolü + açık kaynaklar + `connectors/status` |
| `{Kaynak}` sorgulanıyor | Her `query_*` çağrısı | A1'deki `tool` olayı (`purpose`, `rows`, `total_rows`, `masked`) |
| Akış hazırlanıyor, doğrulanıyor, rapor hazırlanıyor… | Diğer araçlar | Mevcut araç etiketleri |
| Bekliyor · Cevabın gerekiyor | `ask_user` aracı | Bkz. 5.3 |
| Hata | Araç hatası | Sade dille, kırmızı |

### 5.3 `ask_user` aracı ve soru kartı

Claude'a yeni bir araç:

```json
{
  "name": "ask_user",
  "description": "Kullanıcıdan bir karar gerektiğinde çoktan seçmeli soru sor. Sadece birden fazla makul yorum varsa ve yanlış seçim cevabı önemli ölçüde değiştirecekse kullan.",
  "input_schema": {
    "questions": [{
      "question": "\"Kötü performans\" nasıl tanımlansın?",
      "context": "Dün 212 uçuş buldum. Rotaları sıralamadan önce tanımı netleştirmem gerekiyor.",
      "multi_select": true,
      "options": [
        { "label": "Yük faktörü %82 hedefinin altında", "source": "Tanım" },
        { "label": "Rötar oranı ortalamanın üstünde", "source": "APM" }
      ],
      "allow_other": true
    }]
  }
}
```

- En fazla 3 soru, her biri en fazla 5 seçenek.
- Araç çağrılınca tur **durur**, mesaj `awaiting_input` durumuna geçer.
- Arayüz soru kutusunun hemen üstünde bir kart gösterir (tasarım sayfa 4):
  - Üstte gri şerit: `context`.
  - Serif başlık: `question`.
  - Seçenekler: onay kutusu (çoklu) veya radyo (tekli), sağda soluk `source` etiketi.
  - "Başka bir şey…" serbest metin satırı (`allow_other`).
  - Altta "1 / 2" (çok soruda), **Atla**, **Devam**.
- Cevap, kullanıcı mesajı olarak gönderilir ve görünür:
  > Cevaplarım:
  > — Kötü performans = Yük faktörü %82 hedefinin altında
  > — Kapsam = Tarifeli seferler, charter hariç
- Claude bu cevapla devam eder. **Atla** denirse Claude'a "Kullanıcı soruyu atladı, en makul varsayımla devam et ve varsayımını açıkça yaz" gider.
- Bekleyen bir soru varken sohbet kapatılıp açılırsa kart yine görünür.
- Sistem promptuna `ask_user` için kısa bir kural eklenir: belirsizlik yoksa sorma, en fazla bir kez sor, A1'deki "terim belirsizse varsayımını yaz" kuralı korunur.

---

## 6. Kaynaklı cevap

### 6.1 Sohbet üst çubuğu

- Sol: sohbet başlığı (otomatik, Haiku/Sonnet ile 6–8 kelime) ve `⌄` (yeniden adlandır, sil).
- Sağ: **Agent olarak kaydet** (A3'e kadar gizli), **Paylaş** (A7'ye kadar gizli).

### 6.2 Cevap düzeni

A1'deki cevap düzeni (sonuç, kanıt tablosu, olası nedenler, sonraki adımlar, kaynak satırı) burada görsel olarak işlenir:

- **Sonuç paragrafı:** Serif, biraz büyük punto, kalın vurgular korunur.
- **Kanıt tablosu:** Markdown tablolar bir **tablo kartına** dönüşür:
  - Kart başlığı: Claude'un tablonun hemen üstüne yazdığı italik satır (`*Tahminin altındaki rotalar · 2 Eki 2026 · tarifeli*`) varsa o, yoksa boş.
  - Sağ üstte üç ikon: **Kopyala** (TSV olarak panoya), **CSV indir** (tarayıcıda üretilir, sunucuya gitmez), **Büyüt** (tam ekran pencere).
  - Kod/rota sütunları mono. Sayılar sağa hizalı (A1'de yapıldı). Negatif farklar `--danger` renginde.
  - 10 satırdan uzunsa ilk 10 gösterilir, "Tümünü göster (N)".
- **Kaynak numaraları:** Bkz. 6.3.
- **Önerilen aksiyonlar:** Bkz. 6.4.
- **Bilgi satırı:** Bkz. 6.5.
- **Alt butonlar:** Kopyala, 👍, 👎, Yeniden üret. 👍/👎 `message_feedback` tablosuna yazılır (`message_id`, `user_id`, `rating`, `comment`); 👎'de isteğe bağlı kısa yorum kutusu.

### 6.3 Kaynak numaraları

- A1'de her sorgu sonucunda `query_id` var. Sistem promptu güncellenir: "Bir bulgunun dayandığı sorguyu `[^q:QUERY_ID]` biçiminde belirt."
- Arayüz bunları sırayla `[1]`, `[2]`… üst simgesine çevirir. Aynı sorgu birden fazla yerde geçerse aynı numara kullanılır.
- Numaraya tıklanınca/üstüne gelince küçük bir pencere: kaynak (IBS/APM/SuccessFactors), amaç, satır sayısı, zaman, (admin/builder için) sorgu metni.
- O cevapta çalışmamış bir `query_id` yazılmışsa numara gösterilmez ve `ai_calls`'a bir uyarı (`citation_mismatch`) düşülür. Bu, değerlendirme setinde de ölçülür.

### 6.4 Önerilen aksiyonlar

- Sistem promptu güncellenir: Cevabın sonunda, uygun görürse en fazla 3 önerilen aksiyonu şu biçimde yazar:

  ```
  :::actions
  - title: AYT–HAJ için grup iptallerini incele | kind: ask
  - title: Özeti #occ-operasyon kanalına gönder | kind: write | system: Teams
  - title: Bunu her sabah otomatik al | kind: automate
  :::
  ```

- Arayüz bu bloğu **aksiyon kartlarına** çevirir (tasarım: başlık + altında soluk açıklama). Blok metin olarak görünmez.
  - `ask`: Tıklanınca başlık yeni mesaj olarak gönderilir.
  - `automate`: Tıklanınca "Bunu her sabah otomatik almak istiyorum" gibi bir mesaj gönderilir (A3'te "agent olarak kaydet" akışını başlatır).
  - `write`: Kartta "Teams · onay gerekir" yazar. Tıklanınca bu fazda sadece mesaj olarak gönderilir ve Claude mevcut akış/rapor araçlarıyla devam eder. A3'te doğrudan onaya düşen bir aksiyona dönüşür.
- Blok bozuk biçimdeyse sessizce yok sayılır (cevap yine görünür).

### 6.5 Bilgi satırı

Cevabın altında küçük, soluk bir satır:

`[N kaynak]  Auto · Claude Sonnet → Opus — çok sistemli karşılaştırma  ·  Salt okunur · hiçbir sistem değiştirilmedi`

- **N kaynak:** Bu cevapta çalışan sorgu sayısı. Tıklanınca kaynak listesi açılır.
- **Model:** Bu cevapta kullanılan model(ler). Auto ise "Auto · …".
- **Salt okunur:** Cevap sırasında hiçbir yazan araç (akış yayınlama, rapor dosyası hariç) çalışmadıysa gösterilir. Çalıştıysa "1 akış yayınlandı" gibi gerçek durum.

---

## 7. Backend değişiklikleri (özet)

| Ne | Nerede |
|---|---|
| `ModelRouter` (Auto) | `app/Ai/ModelRouter.php`, `conversations.model` artık `auto` alabilir |
| `ask_user` aracı, `awaiting_input` durumu | `FlowTools` / `ClaudeChat`, `messages.status` |
| Kaynak seçici | `conversations.disabled_sources` (json); kapalı kaynağın araçları Claude'a sunulmaz |
| Bağlantı durumu | `connector_checks` tablosu, `connectors:check` komutu, `GET /api/connectors/status` |
| Home verisi | `GET /api/home` → `{ greeting_name, suggestions, waiting: [...], activity: [...] }` |
| Geri bildirim | `message_feedback` tablosu, `POST /api/messages/{id}/feedback` |
| Sohbet başlığı | Var olan başlık üretimi korunur, Auto'da hızlı modelle |
| Kaynak numarası doğrulama | Mesaj kaydedilirken `[^q:…]` kimlikleri o mesajın sorgularıyla karşılaştırılır |
| Sistem promptu | `ask_user` kuralı, kaynak gösterme biçimi, `:::actions` bloğu (sadece `ai_data_access = masked` bölümünde kaynak ve aksiyon kuralları) |
| Demo verisi | `DemoSeeder` (sadece `APP_ENV=local`) |

---

## 8. Testler

**Backend:**

1. `GET /api/connectors/status`: `.env`'de bilgisi olmayan kaynak `not_configured`; başarısız son kontrol `attention`; sayfa isteği dış sisteme istek atmıyor (`Http::assertNothingSent`).
2. `GET /api/home`: Bekleyenler sadece kullanıcının erişebildiği çalışmalar; başka kullanıcınınki görünmüyor (admin hariç).
3. Kapatılan kaynağın aracı Claude'a giden araç listesinde yok.
4. `ModelRouter`: Auto'da ilk tur Sonnet, `query_*` sonrası Opus, başlık hızlı model; kullanıcı model seçtiyse hep o.
5. `ask_user`: Tur duruyor, mesaj `awaiting_input`; cevap gönderilince devam ediyor; Atla'da varsayım mesajı gidiyor; 3'ten fazla soru veya 5'ten fazla seçenek reddediliyor.
6. Kaynak numarası: Çalışmamış `query_id` içeren cevapta uyarı kaydı düşüyor.
7. `message_feedback`: Sadece sohbet sahibi yazabiliyor.
8. `DemoSeeder` `APP_ENV=production`'da çalışmıyor.
9. A1'in `DataAccessTest` testleri değişmeden geçiyor (kişisel veri hâlâ Claude'a gitmiyor).

**Vitest:**

10. Home: Selamlama saate göre değişiyor; bağlı olmayan çip soluk; bekleyen yokken boş durum.
11. Model seçici: Sadece config'teki modeller; Auto seçili görünüyor; politika notu `ai_data_access`'e göre değişiyor.
12. Canlı iz: SSE olaylarından doğru adımlar; `user` rolünde sorgu metni yok; cevap bitince daralıyor.
13. Soru kartı: Çoklu/tekli seçim, "Başka bir şey", Atla, Devam; cevap mesajının biçimi.
14. Tablo kartı: Kopyala TSV üretiyor, CSV indiriliyor, 10+ satırda "Tümünü göster".
15. Kaynak numaraları: `[^q:…]` → `[1]`, aynı sorgu aynı numara, bilinmeyen kimlik gösterilmiyor.
16. `:::actions` bloğu kartlara dönüşüyor, bozuk blok yok sayılıyor.

**Playwright:**

17. Sahte backend ile: Home → örnek soruya tıkla → canlı iz → soru kartı → Devam → tablolu ve kaynaklı cevap → aksiyon kartı → 👍. Açık ve koyu temada ekran görüntüsü.

---

## 9. Kabul kriterleri

- [ ] Home, tasarımdaki gibi ve sadece gerçek veriyle çalışıyor.
- [ ] Auto modu çalışıyor, bilgi satırında kullanılan model görünüyor.
- [ ] Veri sorusunda canlı iz, gerekirse soru kartı, ardından tablolu, numaralı kaynaklı ve aksiyonlu cevap görünüyor.
- [ ] Kaynak numaraları gerçek sorgulara bağlı.
- [ ] `ai_data_access = off` iken ekranlar bozulmadan çalışıyor.
- [ ] Bütün testler geçiyor, `ILERLEME.md` güncellendi.

---

## 10. Kapsam dışı

- Bellek yükleme ve agent yönlendirme adımları (A5 ve A3)
- "Agent olarak kaydet" ve "Paylaş" (A3 ve A7)
- Diğer AI sağlayıcıları (A8)
- Inbox'ın kendisi (A3). Home'daki "Seni bekleyenler" bu fazda bekleyen çalışmalardan beslenir.
