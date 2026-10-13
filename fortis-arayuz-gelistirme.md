# Görev: Fortis Arayüzü — Görsel Akış Editörü, Çalışmalar, Kullanım ve Maliyet

## 1. Bağlam

Fortis'in motoru çalışıyor:

- Kullanıcı chat'te ne istediğini yazıyor.
- Claude Kestra YAML üretiyor, FlowValidator kontrol ediyor.
- Laravel akışı Kestra'ya gönderiyor ve çalıştırıyor.

Bu görevde **Gumloop benzeri bir arayüz** yapılacak. Kullanıcı:

1. Oluşturulan akışı **tuvalde blok blok görür**,
2. blokları **form ile düzenler**, **ekler, siler**,
3. aynı akışı **chat ile de düzenler**, AI'ın önerdiği değişikliği tuvalde fark olarak görür,
4. akışın **çalışmalarını** ve onay bekleyen adımları yönetir,
5. AI için **model seçer**, **token kullanımını ve maliyeti** görür.

Görsel referans: "Fortis Arayüz Tasarımı" canvas'ı. Dört ekran var: Akış editörü, Akışlar, Çalışmalar, Kullanım ve maliyet. Renk ve yerleşim için o tasarımı esas al. Projede zaten bir UI kütüphanesi (Tailwind, shadcn vb.) varsa onu kullan, tasarımı ona uyarla.

---

## 2. Kesin kurallar

1. **Tek doğruluk kaynağı Kestra YAML'ıdır.** Tuval, YAML'ın bir görünümüdür. Tuvalde yapılan her değişiklik önce YAML'a çevrilir, sonra **FlowValidator**'dan geçer, sonra kaydedilir. Validator'ı atlayan bir kaydetme yolu olmamalı.
2. **Palette sadece izinli bloklar görünür.** Katalogda olmayan ya da yasaklı türler (Script, Shell, Docker, SSH, katalog dışı HTTP adresi) paletten eklenemez.
3. **Bilinmeyen görevler kaybolmaz.** YAML'da tanınmayan bir görev varsa tuvalde "Gelişmiş" bloğu olarak, salt okunur gösterilir. Tuvalden yapılan düzenlemeler bu görevi değiştirmez ve silmez.
4. **Taslak ve yayın ayrı.** Düzenlemeler taslak sürüm oluşturur. Kestra'ya sadece **Yayınla** butonu ile gider.
5. **AI'a operasyon verisi gitmez.** "AI'a hatayı açıklat" özelliği sadece hata mesajını ve görev adını gönderir. Görev çıktılarını ve logları göndermez (bunlarda uçuş ya da personel verisi olabilir).
6. **Sırlar arayüzde görünmez.** YAML görünümünde `secret('X')` adları görünebilir, değerleri asla görünmez.

---

## 3. Teknoloji

| Ne için | Kütüphane |
|---|---|
| Tuval | `@xyflow/react` (React Flow) |
| Otomatik yerleşim | `@dagrejs/dagre` (dikey, yukarıdan aşağı) |
| Sunucu verisi | `@tanstack/react-query` |
| Blok ayar formları | Katalogdaki JSON Schema'dan otomatik form: `@rjsf/core` veya `react-hook-form` + `zod` |
| YAML görünümü ve fark | `@monaco-editor/react` (DiffEditor dahil) |
| Grafikler | `recharts` |
| Laravel tarafında YAML | `symfony/yaml` |

Mevcut React kurulumu (Inertia veya SPA, Vite) neyse ona uy. Yeni bir derleme aracı ekleme.

---

## 4. Blok modeli

Kullanıcı Kestra görev türlerini değil, **Fortis bloklarını** görür. Her blok bir veya birkaç Kestra görev kalıbına karşılık gelir.

### 4.1 Blok türleri

| Blok | Kestra karşılığı | Tanıma kuralı |
|---|---|---|
| Tetikleyici · Zaman | `triggers[]` → `core.trigger.Schedule` | tür |
| Tetikleyici · Webhook | `triggers[]` → `core.trigger.Webhook` | tür |
| Veri | `core.http.Request` | `uri` iç uç noktalardan biri ve kataloğun `data_sources` listesinde |
| Bildirim · E-posta | `core.http.Request` | `uri` → `/api/internal/notify/email` |
| Bildirim · Teams | `core.http.Request` | `uri` → `/api/internal/notify/teams` |
| Döngü | `core.flow.ForEach` | tür, içinde `tasks` |
| Koşul | `core.flow.If` | tür, `then` / `else` dalları |
| Onay bekle | `core.flow.Pause` | tür |
| AI adımı | `core.http.Request` | `uri` → `/api/internal/ai/...` (varsa) |
| Gelişmiş | Diğer her şey | Hiçbir kurala uymayan görev, salt okunur |

### 4.2 Katalog

`config/kestra-catalog.json` dosyasına `blocks` bölümü eklenir. Her blok için:

```json
{
  "kind": "notify_email",
  "label": "Bildirim · E-posta",
  "group": "Bildirim",
  "match": { "type": "io.kestra.plugin.core.http.Request", "uri_suffix": "/api/internal/notify/email" },
  "settings_schema": {
    "type": "object",
    "required": ["to", "subject", "message_template"],
    "properties": {
      "to": { "type": "array", "items": { "type": "string" }, "title": "Alıcılar" },
      "subject": { "type": "string", "title": "Konu" },
      "message_template": { "type": "string", "title": "Mesaj", "format": "textarea" }
    }
  },
  "summary_template": "{to} adresine gönder",
  "container": false
}
```

- `settings_schema`: Sağ paneldeki form bu şemadan otomatik üretilir.
- Her blok için iki fonksiyon yazılır:
  - `fromTask(task) → settings`: YAML görevinden form değerlerini çıkarır.
  - `toTask(settings, existingTask) → task`: Form değerlerinden YAML görevini üretir. `existingTask` içindeki bilinmeyen alanları (`retry`, `timeout` vb.) **korur**.
- Veri bloğunun formu, seçilen `data_source`'un parametrelerine göre değişir. Parametreler katalogda zaten var (`apm_flight_delays`, `hr_employee_changes` vb.).

### 4.3 Graf modeli

Laravel, YAML'ı arayüze şu formatta verir:

```json
{
  "flow_id": "long_delay_notice",
  "version": 4,
  "status": "draft",
  "triggers": [{ "id": "every_15_min", "kind": "trigger_schedule", "settings": { "cron": "*/15 * * * *" } }],
  "tasks": [
    { "id": "get_delays", "kind": "data", "settings": { "data_source": "apm_flight_delays", "params": { "min_delay_minutes": 240 } } },
    { "id": "notify_each", "kind": "foreach", "settings": { "values": "{{ outputs.get_delays.body }}" },
      "children": [
        { "id": "send_mail", "kind": "notify_email", "settings": { "to": ["occ@..."], "subject": "...", "message_template": "..." } }
      ]
    },
    { "id": "legacy_step", "kind": "advanced", "readonly": true, "raw_type": "io.kestra.plugin.xyz.Something" }
  ],
  "validation": { "ok": true, "errors": [], "warnings": [] }
}
```

- Görev `id`'leri **sabit kalır**. Bir bloğu düzenlemek `id`'yi değiştirmez. Yeni blok için benzersiz `id` üretilir (`notify_teams`, `notify_teams_2`).
- Konumlar YAML'da tutulmaz. Tuval her açılışta dagre ile yerleşim yapar. Kullanıcı bir bloğu sürüklerse konum `flow_versions.layout` (JSON) kolonuna kaydedilir, yoksa otomatik yerleşim kullanılır.
- Konteyner bloklar (Döngü, Koşul) React Flow'da **grup düğümü** olarak çizilir. Çocukları içinde dikey sıralanır.

### 4.4 Uygulamalar ve bağlantılar (Outlook, Teams vb.)

Tuvalde `+`'ya tıklanınca sağda **Blok ekle** paneli açılır (tasarımdaki "Blok ekle (sağ panel)" ekranı):

- **Üst kısım:** Arama ve kategori filtreleri (Microsoft 365, Havayolu verisi, İK, Mantık, AI).
- **Bağlı uygulamalar:** Teams, Outlook, APM, IBS, SuccessFactors. Bir uygulamaya tıklanınca aksiyonları açılır (Teams: kanala mesaj, kişiye mesaj, onay kartı). Aksiyon seçilince blok `+`'nın olduğu yere eklenir, sağ panel o bloğun ayarlarına geçer.
- **Mantık ve AI:** Koşul, Döngü, Onay bekle, Bekle, AI · Özetle, AI · Sınıflandır.
- **Bağlanabilecek uygulamalar:** SharePoint, Excel Online, SMS, Jira vb. Yanlarında **Bağla** butonu var.
- **Alt kısım:** "AI'a tarif et" kutusu. Yazılan isteğe göre o noktaya blok önerilir.

**Modelleme:**

- Her uygulama bir **connector**'dır (`app/Integrations/<App>`). Her connector'ın aksiyonları katalogda blok olarak tanımlanır (`kind: "teams.post_channel"`, `"outlook.send_mail"`).
- Kestra tarafında her aksiyon, Laravel'deki tek bir iç uç noktaya HTTP isteğidir: `POST /api/internal/apps/{app}/{action}`. Kestra hiçbir zaman Microsoft Graph gibi dış API'lere doğrudan bağlanmaz.
- **Bağlantılar** (`app_connections` tablosu): `app`, `display_name` (örn. `fortis@gozendigital.com`), `auth_type` (oauth / api_key / webhook), şifreli kimlik bilgileri (Laravel `encrypted` cast), `scopes`, `status`, `connected_by`, `connected_at`. Kimlik bilgileri API cevaplarında, YAML'da ve AI'a giden içerikte **asla** yer almaz.
- **Microsoft 365 (Outlook, Teams):** Tek bir Entra ID uygulaması üzerinden Microsoft Graph OAuth kullanılır. Şimdilik Teams için Workflows webhook'u da desteklenir (mevcut yöntem). Gerekli Graph izinleri ve admin onayı IT ile belirlenmeli.
- **Bağla** akışı: OAuth yönlendirmesi veya API anahtarı formu. Bağlantıyı **yönetici** onaylar. Rol sistemi gelene kadar onay `.env` → `FORTIS_ADMIN_EMAILS` listesi ile yapılır.
- Bir uygulama bağlı değilse aksiyonları panelde pasif görünür, "Önce bağla" uyarısı gösterilir.
- **Okuma ve yazma ayrımı:** Veri kaynakları (APM, IBS, SuccessFactors) sadece okuma aksiyonlarına sahiptir. Yazan aksiyonlar (e-posta gönder, mesaj at, satır ekle) blok kartında "dış sisteme yazar" etiketiyle gösterilir ve test çalıştırmada **kuru çalıştırma** (dry-run) modunda gerçekten gönderilmez.

Yeni uç noktalar:

| Uç | Ne yapar |
|---|---|
| `GET /api/apps` | Uygulama kataloğu, bağlantı durumu ve aksiyonlar |
| `POST /api/apps/{app}/connect` | OAuth başlatır veya API anahtarı kaydeder |
| `GET /api/apps/{app}/callback` | OAuth dönüşü |
| `DELETE /api/apps/{app}/connection` | Bağlantıyı kaldırır (kullanan akışlar listelenir, onay istenir) |
| `GET /api/apps/{app}/options/{field}` | Ayar formundaki açılır listeler için (örn. Teams kanalları) |

---

## 5. Laravel API (arayüz için)

Bu uçlar oturum açmış kullanıcı içindir. Kestra'nın kullandığı `/api/internal/*` uçlarından ayrıdır.

### 5.1 Akışlar ve düzenleme

| Uç | Ne yapar |
|---|---|
| `GET /api/flows` | Liste: ad, `id`, durum (taslak / yayında / durduruldu / hata), tetikleyici özeti, kullanılan kaynaklar, son çalışma, bu ayki maliyet |
| `GET /api/flows/{id}` | Son sürümün graf modeli (4.3), YAML ve `layout` |
| `GET /api/catalog/blocks` | Palet ve formlar için blok kataloğu |
| `POST /api/flows/{id}/operations` | Tuval düzenlemelerini uygular, yeni taslak sürüm döner (5.2) |
| `PUT /api/flows/{id}/yaml` | YAML editöründen doğrudan kaydetme. Aynı validator. |
| `POST /api/flows/{id}/publish` | Taslağı doğrular, Kestra'ya gönderir, sürümü "yayında" yapar |
| `POST /api/flows/{id}/test-run` | Yayınlanmış veya taslak akışı test namespace'inde bir kez çalıştırır |
| `GET /api/flows/{id}/versions` | Sürüm listesi: kim, ne zaman, kaynak (tuval / chat / YAML) |
| `GET /api/flows/{id}/versions/{v}/diff?against={v2}` | İki sürümün YAML farkı ve graf farkı |
| `POST /api/flows/{id}/versions/{v}/restore` | Eski sürümü yeni taslak olarak geri yükler |

### 5.2 Düzenleme işlemleri

Tuval, YAML göndermez. **İşlem** (operation) listesi gönderir. Laravel bu işlemleri YAML'a uygular:

```json
{
  "base_version": 4,
  "operations": [
    { "op": "update_settings", "task_id": "get_delays", "settings": { "params": { "min_delay_minutes": 180 } } },
    { "op": "insert", "parent_id": "notify_each", "after_id": "send_mail", "kind": "notify_teams", "settings": { "channel": "occ-operasyon" } },
    { "op": "delete", "task_id": "old_step" },
    { "op": "move", "task_id": "send_mail", "parent_id": null, "after_id": "get_delays" },
    { "op": "update_trigger", "trigger_id": "every_15_min", "settings": { "cron": "*/10 * * * *" } }
  ]
}
```

Sıra:

1. `base_version` son sürüm değilse `409 Conflict` döner (başka biri ya da chat değiştirmiş).
2. Önce bütün işlemler uygulanır, sonra yeni YAML **FlowValidator**'dan geçer.
3. Hata varsa kaydedilmez, `422` ile hata listesi döner. Hatalar bloklara bağlanır (`task_id`), tuvalde ilgili blok kırmızı çerçeveyle gösterilir.
4. Başarılıysa yeni taslak sürüm oluşur (`source = canvas`).

`advanced` bloklara `update_settings` uygulanamaz. Silme ve taşıma ise izinlidir, ama silmeden önce arayüz onay ister.

### 5.3 Chat ile düzenleme

Mevcut chat akışı akış bağlamında da çalışır:

- Akış editöründeki sohbet paneli, mesajla birlikte `flow_id` ve `base_version` gönderir.
- Claude güncel YAML'ı `get_flow` aracıyla alır ve yeni YAML önerir (`propose_flow`).
- Laravel öneriyi doğrular ama **kaydetmez**. Bir "öneri" olarak saklar (`flow_proposals`: `id`, `flow_id`, `base_version`, `yaml`, `status`).
- Arayüz öneriyi graf farkı olarak gösterir: eklenen bloklar yeşil, silinenler kırmızı ve üstü çizili, değişenler sarı.
- Kullanıcı **Uygula** derse yeni taslak sürüm oluşur (`source = chat`), **Geri al** derse öneri reddedilir.

### 5.4 Çalışmalar

| Uç | Ne yapar |
|---|---|
| `GET /api/executions?flow_id=&status=&page=` | Liste (Kestra API'den, Laravel üzerinden) |
| `GET /api/executions/{id}` | Adımlar, durum, süreler. Görev çıktıları sadece özet olarak (kayıt sayısı gibi). |
| `GET /api/executions/{id}/logs` | Loglar (sayfalı) |
| `POST /api/executions/{id}/resume` | Onay bekleyen adımı devam ettirir |
| `POST /api/executions/{id}/kill` | Reddet veya durdur |
| `POST /api/executions/{id}/explain` | Hatayı AI'a açıklatır. Sadece hata mesajı ve görev adı gönderilir (kural 5). |

Durum güncellemesi şimdilik react-query ile **polling** yapılır: çalışan akışlar için 3 saniye, liste için 15 saniye. Reverb (WebSocket) daha sonra eklenecek.

### 5.5 Model seçimi

- `config/ai.php` içinde izinli modeller ve fiyatları:

```php
'models' => [
    'claude-sonnet-5-5' => ['label' => 'Claude Sonnet 5.5', 'input_per_mtok' => 2.00, 'output_per_mtok' => 10.00],
    'claude-opus-5-5'   => ['label' => 'Claude Opus 5.5',   'input_per_mtok' => 4.00, 'output_per_mtok' => 20.00],
],
'default_model' => 'claude-sonnet-5-5',
```

- Fiyatlar config'de, ama model kimlikleri ve fiyatlar Anthropic dokümanından **doğrulanmalı**. Prompt cache okuma ve yazma fiyatları da eklenmeli.
- Model seçimi üç seviyede:
  - Genel varsayılan: `GET/PUT /api/settings/ai`
  - Sohbet bazında: `conversations.model` (editördeki üst çubuk seçicisi bunu değiştirir)
  - AI adımı bazında: blok ayarında `model` alanı
- İzinli liste dışında bir model gelirse `422` döner.

### 5.6 Kullanım ve maliyet

Mevcut `ai_calls` tablosu şu kolonlara sahip olmalı. Eksik olanlar migration ile eklenir:

| Kolon | Not |
|---|---|
| `source` | `chat` veya `flow_step` |
| `conversation_id`, `flow_id`, `execution_id` | Boş olabilir |
| `model` | |
| `input_tokens`, `output_tokens`, `cache_read_tokens`, `cache_write_tokens` | API cevabındaki `usage` alanından |
| `cost_usd` | **Çağrı anında** hesaplanıp yazılır (decimal 10,6). Fiyat sonradan değişse de geçmiş maliyet değişmez. |
| `created_at` | İndeks |

| Uç | Döner |
|---|---|
| `GET /api/usage/summary?month=2026-10` | Toplam maliyet, token (girdi/çıktı), çağrı sayısı, akış çalışma sayısı, geçen aya göre fark |
| `GET /api/usage/daily?month=` | Gün gün: `chat_cost`, `flow_step_cost` |
| `GET /api/usage/by-model?month=` | Model başına maliyet, token, çağrı |
| `GET /api/usage/by-flow?month=` | Akış veya sohbet başına çağrı, token, maliyet |
| `GET /api/usage/export.csv?month=` | Aynı veri, CSV |

**Bütçe:**

- `settings.monthly_budget_usd` (varsayılan boş = limitsiz).
- %80'e ulaşıldığında bir kez e-posta uyarısı gönderilir (ayda bir).
- %100'de **yeni sohbet AI çağrıları** durur, kullanıcıya anlaşılır bir mesaj gösterilir.
- Akış içi AI adımlarının limitte durup durmayacağı ayarla belirlenir (`budget_blocks_flow_ai_steps`, varsayılan `false`). Durursa ilgili görev hata verir ve hata mesajı bütçeyi belirtir.
- Sohbet panelinin altında o sohbetin token ve maliyeti gösterilir.

---

## 6. Ekranlar

### 6.1 Akış editörü

- **Üst çubuk:** Akış adı, durum rozeti (Yayında · v3 / Taslak), "Kaydedilmemiş değişiklik var" uyarısı, model seçici, YAML, Sürümler, Test çalıştır, Yayınla.
- **Tuval:** Noktalı arka plan, dikey bloklar. Bloklar arasında `+` butonu var, tıklanınca o noktaya blok eklemek için palet açılır. Seçili blok mavi çerçeveyle gösterilir. Hatalı blok kırmızı çerçeve ve hata ikonuyla gösterilir. Sürükle-bırak ile sıra değiştirilebilir.
- **Alt palet:** Tetikleyici, Veri, Filtre (Koşul), Döngü, Bildirim, Onay bekle, AI adımı.
- **Sağ panel, iki sekme:**
  - **Blok ayarları:** Katalog şemasından otomatik form. Değişiklikler 600 ms bekleyip `update_settings` işlemi olarak gönderilir. Altta doğrulama sonucu gösterilir.
  - **Sohbet:** Akış bağlamında AI sohbeti. Öneriler tuvalde fark olarak görünür, Uygula / Geri al butonları vardır. Altta sohbetin token ve maliyeti yazar.
- **YAML görünümü:** Monaco editör. Varsayılan salt okunur. "Düzenle" ile açılır, kaydetme `PUT /yaml` ile yapılır. Sürüm karşılaştırmada DiffEditor kullanılır.

### 6.2 Akışlar

Tablo: ad, `id`, durum, tetikleyici, kaynaklar, son çalışma, bu ayki maliyet. Arama kutusu ve "Sohbetle yeni akış" butonu. Satıra tıklayınca editör açılır.

### 6.3 Çalışmalar

- **Sol:** Çalışma listesi, filtreler (Tümü / Onay bekleyen / Hatalı).
- **Sağ:** Seçili çalışmanın adım zaman çizelgesi. Onay bekleyen adım için kart: açıklama, Devam et / Reddet, otomatik zaman aşımı bilgisi. Altında Logları gör, Çıktıları gör ve AI'a hatayı açıklat butonları.

### 6.4 Kullanım ve maliyet

- **Dört özet kutusu:** Toplam maliyet, token, AI çağrısı, akış çalışması.
- **Bütçe çubuğu** ve "Limiti düzenle" butonu.
- **Günlük maliyet grafiği:** Yığılmış çubuk. Sohbet ve akış içi AI ayrı renkte.
- **Modele göre dağılım.**
- **Akış ve sohbet bazında tablo**, CSV indirme.

Erişilebilirlik: Gerçek `<button>`, `<a>` ve `<label>` kullan. İkon butonlarda `aria-label` olsun. Metin kontrastı en az 4.5:1 olmalı. Durumlar sadece renkle değil, metinle de belirtilmeli.

---

## 7. Fazlar

Her faz kendi başına çalışır durumda bitmeli.

| Faz | Kapsam | Bitti sayılması için |
|---|---|---|
| U1 | Akışlar listesi + salt okunur tuval (YAML → graf, dagre yerleşim, YAML görünümü) | Var olan akışlar tuvalde doğru görünüyor, bilinmeyen görevler "Gelişmiş" olarak görünüyor |
| U2 | Blok ayarları paneli (`update_settings`, validator, hata gösterimi, taslak sürüm) | Eşik değiştirilip kaydedilebiliyor, geçersiz değerde blok kırmızı oluyor |
| U3 | Palet: ekle, sil, taşı; tetikleyici düzenleme | Döngü içine Teams bildirimi eklenip kaydedilebiliyor |
| U4 | Yayınla, test çalıştır, sürümler, fark, geri yükleme | Taslak Kestra'ya gidiyor, eski sürüm geri yüklenebiliyor |
| U5 | Editör içi chat + öneri farkı + Uygula / Geri al | Chat'ten istenen değişiklik tuvalde yeşil görünüyor, uygulanınca sürüm oluşuyor |
| U6 | Çalışmalar ekranı, Devam / Reddet, hata açıklama | Pause adımı arayüzden devam ettirilebiliyor |
| U7 | Model seçimi, `ai_calls` maliyet kolonları, kullanım ekranı, bütçe | Ekrandaki toplamlar `ai_calls` verisiyle tutuyor, limit aşılınca chat duruyor |

---

## 8. Testler

**Laravel (Pest):**

1. **Gidiş-dönüş:** `tests/fixtures/flows/*.yaml` içindeki her akış için YAML → graf → (hiçbir değişiklik yapmadan) YAML işlemi, anlam olarak aynı YAML'ı üretiyor. Parse edilmiş diziler karşılaştırılır.
2. **Bilinmeyen alanlar korunuyor:** `retry` ve `timeout` olan bir görevde `update_settings` sonrası bu alanlar yerinde duruyor.
3. **Gelişmiş blok:** Tanınmayan görev, başka bir bloğun düzenlenmesinden sonra aynen kalıyor. `update_settings` ile değiştirilemiyor.
4. **Yasaklı blok:** `insert` ile katalog dışı bir tür eklenmek istendiğinde `422` dönüyor ve sürüm oluşmuyor.
5. **Validator atlanamıyor:** `operations`, `yaml`, `restore` ve chat önerisi uygulamanın hepsi validator'dan geçiyor (her biri için geçersiz girdi testi).
6. **Çakışma:** Eski `base_version` ile gelen işlem `409` dönüyor.
7. **`id` kararlılığı:** Ayar düzenleme `id`'yi değiştirmiyor. Yeni bloklar benzersiz `id` alıyor.
8. **Maliyet:** Örnek `usage` değerleriyle `cost_usd` doğru hesaplanıyor (cache token'ları dahil). Fiyat config'de değişince eski kayıtlar değişmiyor.
9. **Bütçe:** %100'de sohbet çağrısı reddediliyor. %80 uyarısı ayda bir kez gidiyor.
10. **Hata açıklama:** `explain` uç noktasının Claude'a gönderdiği içerikte görev çıktıları ve log satırları yok (`Http::fake` ile istek gövdesi kontrol edilir).
11. **Model:** İzinli liste dışındaki model `422` dönüyor.

**React (Vitest + Testing Library):**

1. Graf modeli → React Flow düğümleri: konteyner blokların çocukları grup içinde gösteriliyor.
2. Form değişikliği doğru `update_settings` işlemini üretiyor, art arda değişikliklerde gecikmeli (debounce) tek istek gidiyor.
3. `422` cevabındaki `task_id` hataları ilgili blokta görünüyor.
4. Öneri farkı: eklenen, silinen ve değişen bloklar doğru işaretleniyor.

**Uçtan uca (Playwright, en az bir senaryo):** Akış aç → eşiği 240'tan 180'e çek → Teams bildirimi ekle → yayınla → test çalıştır → çalışmalar ekranında başarılı gör.

---

## 9. Kabul kriterleri

- [ ] U1–U7 fazlarının "bitti" koşulları sağlandı.
- [ ] Tüm Pest, Vitest ve Playwright testleri geçiyor.
- [ ] Hiçbir kaydetme yolu FlowValidator'ı atlamıyor.
- [ ] Paletten yasaklı tür eklenemiyor, bilinmeyen görevler kaybolmuyor.
- [ ] Kullanım ekranındaki toplamlar `ai_calls` tablosundaki verilerle birebir tutuyor.
- [ ] Ekranlar 1280 px ve üzerinde tasarımdaki gibi, telefon genişliğinde ise menü üstte ve paneller alt alta görünüyor.

---

## 10. Kapsam dışı

- Rol ve yetki (herkes her akışı görebilir ve düzenleyebilir)
- Gerçek zamanlı ortak düzenleme (iki kişi aynı anda; şimdilik `409` yeterli)
- WebSocket (Reverb) ile canlı güncelleme
- Serbest yerleşimli (yatay, çapraz bağlantılı) tuval. Akışlar dikey ve ağaç yapısında.
- Kullanıcı bazında maliyet (rol fazıyla birlikte gelecek)

---

## 11. Açık sorular

- Koşul (`If`) ve Filtre ayrı bloklar mı olacak? Şu an Filtre, veri bloğunun parametreleri (`min_delay_minutes` vb.) ile çözülüyor. Ayrı bir Filtre bloğu gerekiyorsa `If` üzerine kurulacak.
- AI adımı bloğu için Laravel'de `/api/internal/ai/...` uç noktası var mı, yoksa bu fazda mı yazılacak?
- Bütçe uyarı e-postası kime gidecek?
- Mevcut arayüzde Inertia mı kullanılıyor, ayrı SPA mı? API uçlarının biçimi buna göre ayarlanmalı.
