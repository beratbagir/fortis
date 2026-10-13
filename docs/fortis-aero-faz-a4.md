# Görev: Fortis Aero — Faz A4: Bağlantılar ve Yönetişim

Önce `docs/fortis-aero-yol-haritasi.md`, `docs/fortis-aero-faz-a3.md` ve `ILERLEME.md` dosyalarını oku.
Tasarım: `docs/design/fortis-aero-product-screens.pdf` (ekran 09 Tools & Integrations, 10 Governance). Referans: `docs/design/gumloop-ui-frames.pdf` (Integrations, Admin/Usage bölümleri).

## 1. Amaç

A3 ile ürünün kalbi (agentlar, Inbox) bitti. A4, pilot öncesi **yöneticinin ve IT'nin "kontrol bizde mi?" sorusunu** cevaplayan iki ekranı getirir:

- **09 Bağlantılar (`#/connectors`):** Fortis hangi sistemlere bağlı, bağlantı sağlıklı mı, her bağlantıyla ne yapılabiliyor (okuma, onaylı yazma, olay), kim kullanıyor.
- **10 Yönetişim (`#/governance`):** Şu an ne çalışıyor, ne kadar harcandı, dikkat gereken ne var, hangi politikalar geçerli, kim ne yaptı (denetim kaydı).

Bu fazda **yeni bir dış sistem bağlanmaz** (AirRM, Oracle vb. yok) ve **yeni bir yetki modeli kurulmaz** (kişiye göre paylaşım A7). Var olan veriler (`connector_checks`, `ai_calls`, `agent_runs`, `agent_actions`, `audit_logs`, ayarlar) yönetilebilir ve görünür hale gelir.

---

## 2. Kesin kurallar

1. **Gizli bilgi ekrana ve API'ye çıkmaz.** Şifre, token, client secret, API anahtarı hiçbir yanıtta yer almaz; sadece "tanımlı / tanımsız" ve son 4 karakter değil, **hiçbir parçası** gösterilmez. Kimlik bilgileri yalnızca `.env`'de kalır; arayüzden kimlik bilgisi girilmez.
2. **Denetim kaydı değiştirilemez.** `audit_logs` için güncelleme/silme ucu yok (A0 kuralı). Ekran sadece okur, filtreler, dışa aktarır.
3. **Yetki:** Yönetişim ve politika değişiklikleri sadece `admin`. Bağlantılar ekranını herkes görür (kendi erişebildiği kaynaklar); bağlantıyı kapatma/açma ve "şimdi kontrol et" sadece `admin`.
4. **Politika değişikliği kayda geçer.** Her ayar değişikliği `audit_logs`'a önceki ve yeni değerle (`settings.update`) yazılır.
5. **Kapatılan bağlantı her yerde kapanır.** Admin bir kaynağı kapatınca: sohbette ve agentlarda o kaynağın araçları Claude'a sunulmaz, çağrılırsa reddedilir; o kaynağı kullanan agentlar sahibinin Inbox'ına uyarı düşürür. (A2'deki sohbet bazlı `disabled_sources` ile aynı mekanizma, şirket genelinde.)
6. **Maliyet tek kaynaktan.** Bütün harcama rakamları `ai_calls.cost_usd`'den hesaplanır. Ekranda ayrı tutulan bir sayaç yok.
7. **Kişisel veri yok.** Denetim kaydı ekranında sorgu sonucu gösterilmez (zaten yazılmıyor); sorgu metni (SQL/yol) sadece admin ve builder görür (A2 kuralı).

---

## 3. Veri modeli

Yeni tablolar az; çoğu iş mevcut tablolardan okuma.

| Tablo | Alanlar | Not |
|---|---|---|
| `connectors` | `key` (apm, ibs, successfactors, email, teams, outlook…), `enabled`, `scopes` json, `disabled_reason`, `disabled_by`, `disabled_at` | Katalogdaki her bağlantı için bir satır; seed ile oluşur. Kimlik bilgisi tutmaz. |
| `budgets` | `scope` (`org` / `department` / `user`), `scope_id`, `monthly_limit_usd`, `warn_at_percent` (varsayılan 80), `hard_stop` bool | Org bütçesi zaten `config`'te varsa buraya taşınır. |
| `governance_alerts` | `type`, `severity` (info/warning/critical), `subject_type`, `subject_id`, `message`, `resolved_at`, `resolved_by` | "Dikkat gerekenler" listesi. |

Mevcut tablolarda değişiklik:

- `ai_calls`: `department_id` yoksa ekle (kullanıcının departmanından doldurulur; geçmiş kayıtlar için bir kerelik doldurma komutu).
- `connector_checks`: değişmez; durum hesabı A2'deki gibi.

### 3.1 Kapsam etiketleri (`connectors.scopes`)

Her bağlantı neler yapabildiğini gösterir; araç kataloğundan (`config/agents.php`) türetilir, elle yazılmaz:

- `read`: Veri okuma (`query_*`, `describe_*`, `preview_*`)
- `write·approval`: Onaylı yazma (`send_email`, `post_teams`, `deliver_report`)
- `events`: Olay tetikleyici (APM `flight.delayed` / `flight.cancelled`)

---

## 4. Bağlantılar ekranı (`#/connectors`)

### 4.1 Liste

- Üstte özet: Bağlı N · Dikkat N · Kurulum gerekli N.
- Sekmeler: **Tümü / Veri kaynakları / Kanallar / MCP**.
- Kart: logo/ikon (marka logosu yok, genel ikon + 2 harf), ad, kısa açıklama, durum rozeti (`Connected` / `Attention` / `Needs setup` / `Disabled`), kapsam etiketleri, son kontrol zamanı (mono, göreli: "3 dk önce"), bu hafta kullanım sayısı.
- MCP sekmesi: boş durum kartı: "Fortis'i Claude Desktop, Teams gibi araçlara bağlama A6'da gelecek." Sahte bağlantı gösterilmez.

### 4.2 Bağlantı detayı (`#/connectors/:key`)

- **Durum:** son 24 saatin kontrol geçmişi (başarılı/başarısız şerit), son hata mesajı (gizli bilgi temizlenmiş), ortalama yanıt süresi.
- **Ne yapabilir:** kapsam etiketleri ve bu bağlantının araç listesi (ad + bir cümle açıklama).
- **Kim kullanıyor:** bu kaynağı kullanan agentlar (ad, sahip, durum) ve son 7 günde sohbetlerde kaç sorgu yapıldı.
- **Veri koruması:** bu kaynakta maskelenen alan grupları (`config/ai-data.php`'den; ör. "Yolcu kimliği, iletişim bilgileri") ve engellenen tablolar/uçlar. Sadece kategori adları; değer yok.
- **Yapılandırma:** `.env` değişkenlerinin **adları** ve tanımlı/tanımsız durumu (ör. `APM_BASE_URL ✓`, `APM_CLIENT_SECRET ✓`). Değer asla.
- **Admin işlemleri:** "Şimdi kontrol et" (dakikada en fazla 1), "Kapat" (gerekçe zorunlu) / "Aç". Kapatınca etkilenen agent sayısı onay penceresinde gösterilir.

### 4.3 Kurulum gerekli kartları

Outlook, SharePoint, AirRM, Oracle, SkyBreathe, Octopus: `Needs setup` rozeti ve "Ne gerekiyor?" açıklaması (ör. "Entra ID uygulama kaydı, Mail.Send izni"). Tıklanınca detayda gerekenlerin listesi; "IT'ye iletmek için kopyala" düğmesi (düz metin). Form yok.

---

## 5. Yönetişim ekranı (`#/governance`)

Sekmeler: **Genel bakış / Kullanım / Politikalar / Denetim kaydı**. Mevcut kullanım ve maliyet ekranı ile A1'deki "AI veri erişimi" kartı buraya taşınır; eski adresler yönlendirilir.

### 5.1 Genel bakış

- **Canlı:** şu an çalışan agent çalışmaları ve akış çalışmaları (ad, başlatan, süre, araç çağrısı sayısı, anlık maliyet). 10 sn'de bir yenilenir. Admin bir çalışmayı **durdurabilir** (gerekçe, denetim kaydı).
- **Bu ay:** toplam harcama / bütçe (çubuk), geçen aya göre fark, soru sayısı, agent çalışması sayısı, onaylanan/reddedilen aksiyon sayısı.
- **Dikkat gerekenler:** `governance_alerts` (çözülmemişler). Kaynaklar:
  - Bağlantı 30 dk'dan uzun `Attention`
  - Bütçe uyarı eşiği aşıldı / doldu
  - Agent çalışması sınır aştı (maliyet, süre, araç sayısı) veya üst üste 3 kez başarısız
  - Ortalama değerlendirme puanı 60'ın altına düştü (son 10 çalışma)
  - İzinsiz araç denemesi (`agent.tool_blocked`)
  - 24 saatten uzun bekleyen onay
  Her uyarıda "Git" (ilgili ekran) ve "Çözüldü" (admin).
- **Kalite:** eval son çalıştırma sonucu (tarih, geçme oranı, doğru araç oranı) ve agentların ortalama puanı.

### 5.2 Kullanım

- Filtre: tarih aralığı (bu ay varsayılan), departman, kullanıcı, model, tür (sohbet / agent / başlık / puanlama / eval).
- Grafikler: günlük harcama (yığılmış: tür), departmana göre harcama, modele göre harcama, en çok harcayan 10 agent ve 10 kullanıcı.
- Tablo: `ai_calls` satırları (zaman, kullanıcı, departman, amaç, model, giriş/çıkış token, maliyet). CSV dışa aktarma.
- **Kredi gösterimi:** Ayarlarda `credit_usd_value` tanımlıysa (ör. 0,02) rakamlar "kredi" olarak da gösterilir; tanımsızsa sadece dolar. Hesap her zaman dolardan.

### 5.3 Politikalar (admin)

Tek sayfada, gruplu. Her değişiklik onay penceresi + denetim kaydı.

| Grup | Ayarlar |
|---|---|
| Veri | AI veri erişimi (Kapalı / Maskeli), çalışan adı/soyadı AI'a gidebilir, sorgu başına satır ve karakter sınırı (salt okunur gösterim, `.env`'den) |
| Modeller | İzinli modeller, varsayılan model, veri sorusu modeli, hızlı model |
| Bütçe | Şirket aylık bütçesi, departman bütçeleri, uyarı eşiği, "dolunca durdur" (durunca sadece admin kullanabilir; agentlar duraklar) |
| Agentlar | Varsayılan onay politikası (her araç için), şirket dışı alıcı (kapalı/onaylı), izinli alıcı alan adları, çalışma sınırları (araç sayısı, maliyet, süre) |
| Bağlantılar | Şirket genelinde açık/kapalı (Bağlantılar ekranıyla aynı veri) |

Bütçe kuralları:

- Uyarı eşiği aşılınca: admin ve departman yöneticisine (varsa) Inbox uyarısı, bir kez.
- `hard_stop` açık ve bütçe dolmuşsa: yeni sohbet sorusu ve agent çalışması başlamaz, kullanıcıya açık mesaj gösterilir ("Departman bütçesi bu ay doldu; yöneticinize başvurun"). Devam eden çalışma bitirilir.
- Bütçe kontrolü ucuz olmalı: bu ayın toplamı önbellekte (1 dk), her `ai_calls` yazımında artırılır.

### 5.4 Denetim kaydı

- Filtre: tarih, aktör (kullanıcı / agent / sistem), olay türü (`ai.query`, `agent.*`, `inbox.*`, `settings.update`, `auth.*`…), sonuç (başarılı / reddedildi / hata), serbest arama.
- Satır: zaman (mono), aktör, olay, hedef, sonuç; açılınca ayrıntı (json, gizli alanlar temizlenmiş; sorgu metni sadece admin/builder).
- Sayfalama (imleçle), CSV dışa aktarma (en fazla 50.000 satır; dışa aktarma da kayda geçer).
- Değiştirme/silme düğmesi yok.

---

## 6. API

| Uç | Yetki | Not |
|---|---|---|
| `GET /api/connectors` | herkes | durum, kapsam, haftalık kullanım |
| `GET /api/connectors/{key}` | herkes | detay; `.env` sadece ad + tanımlı mı (admin) |
| `POST /api/connectors/{key}/check` | admin | hız sınırı 1/dk |
| `POST /api/connectors/{key}/disable`, `/enable` | admin | gerekçe zorunlu |
| `GET /api/governance/overview` | admin | canlı, bu ay, uyarılar, kalite |
| `POST /api/governance/runs/{id}/stop` | admin | agent veya akış çalışması |
| `GET /api/governance/usage` | admin | filtreli toplamlar + satırlar; `?format=csv` |
| `GET/PUT /api/governance/policies` | admin | mevcut `/api/settings/ai` buraya taşınır (eski uç yönlendirir) |
| `GET/PUT /api/governance/budgets` | admin | |
| `GET /api/governance/alerts`, `POST …/{id}/resolve` | admin | |
| `GET /api/audit-logs` | admin | filtre, imleç; `?format=csv` |

Uyarılar zamanlayıcıyla üretilir: `php artisan governance:scan` (5 dk). Aynı konu için açık uyarı varsa yenisi oluşmaz.

---

## 7. Alt fazlar

| Alt faz | Kapsam | Bitti ölçütü |
|---|---|---|
| **A4.1** | `connectors` tablosu, kapsam etiketleri, Bağlantılar listesi ve detayı, kapat/aç (sohbet + agent'a etkisi), şimdi kontrol et | Admin APM'yi kapatınca sohbette APM araçları yok, APM kullanan agent sahibine uyarı düşüyor |
| **A4.2** | Yönetişim: Genel bakış (canlı, bu ay, kalite), çalışma durdurma, `governance_alerts` ve `governance:scan` | Sınırı aşan test çalışması "Dikkat gerekenler"de görünüyor; admin durdurabiliyor |
| **A4.3** | Kullanım sekmesi, `ai_calls.department_id`, bütçeler, uyarı eşiği, `hard_stop` | Departman bütçesi dolunca o departmandan yeni soru başlamıyor, admin kullanabiliyor |
| **A4.4** | Politikalar sekmesi (eski ayarlar buraya), denetim kaydı ekranı, CSV dışa aktarma | Her politika değişikliği denetim kaydında önceki/yeni değerle görünüyor |

Her alt fazdan sonra `ILERLEME.md`'ye aynı formatta yaz ve dur.

---

## 8. Testler

Backend (PHPUnit, gerçek Anthropic çağrısı yok):

- `ConnectorsTest`: liste ve detayda gizli değer yok (bilinen `.env` değerleri yanıtta aranır, bulunmamalı); kullanıcı sadece görür, admin kapatır; kapatılan kaynağın araçları sohbette ve agentta sunulmuyor, çağrılırsa reddediliyor; etkilenen agent sahibine Inbox uyarısı; "şimdi kontrol et" hız sınırı.
- `GovernanceOverviewTest`: canlı çalışmalar, bu ay toplamı `ai_calls` ile tutarlı; çalışma durdurma (agent ve akış) ve denetim kaydı; admin olmayan 403.
- `GovernanceAlertsTest`: her uyarı türü üretiliyor, tekrar üretilmiyor, çözülünce kapanıyor.
- `BudgetTest`: uyarı eşiğinde bir kez Inbox uyarısı; `hard_stop` ile soru ve agent çalışması başlamıyor, admin muaf, devam eden çalışma bitiyor; önbellek doğru artıyor.
- `PoliciesTest`: her ayar değişikliği `settings.update` olarak önceki/yeni değerle kayıtta; eski `/api/settings/ai` uyumlu; geçersiz değer reddediliyor.
- `AuditLogScreenTest`: filtreler, imleç, CSV (sınır ve dışa aktarma kaydı), sorgu metni rol filtresi, güncelleme/silme ucu yok.
- A1–A3 testleri değişmeden geçmeli.

Frontend: Vitest (kartlar, durum rozetleri, politika formu, denetim filtresi), Playwright (admin: Bağlantılar → APM detay → Kapat → sohbette APM çipi soluk; Yönetişim genel bakış ve denetim kaydı; açık/koyu görsel temeller).

---

## 9. Kabul kriterleri

1. Bağlantılar ekranında APM, IBS, SuccessFactors, e-posta ve Teams gerçek durumlarıyla, Outlook ve diğerleri "Kurulum gerekli" olarak görünüyor.
2. Hiçbir ekranda ve API yanıtında gizli bilgi yok (test ile kanıtlı).
3. Admin bir kaynağı kapatınca sohbet ve agentlar o kaynağı kullanamıyor; açınca geri geliyor.
4. Yönetişim'de bu ayın harcaması, departman ve model kırılımı `ai_calls` ile birebir tutuyor.
5. Bütçe uyarısı ve `hard_stop` çalışıyor.
6. Politika değişiklikleri ve denetim kaydı ekranda; kayıt değiştirilemiyor.
7. Tüm testler geçiyor.

---

## 10. Kapsam dışı

- Yeni dış sistem bağlantısı (AirRM, Oracle, SkyBreathe, Octopus, SharePoint) ve Outlook/Graph entegrasyonu → Entra uygulaması gelince ayrı iş.
- Arayüzden kimlik bilgisi girme/değiştirme.
- MCP sunucusu → A6.
- Kişiye/gruba göre veri izni ve paylaşım → A7.
- Diğer model sağlayıcıları → A8.

---

## 11. Açık sorular (kod yazmadan önce bana sor, makul varsayımla ilerleyebiliyorsan varsayımı ILERLEME'ye yaz)

1. Departman bütçeleri başlangıçta tanımlı mı olsun, yoksa sadece şirket bütçesi mi? (Varsayım: sadece şirket bütçesi, `hard_stop` kapalı.)
2. "Departman yöneticisi" kavramı var mı? Yoksa bütçe uyarısı sadece admine gider.
3. Kredi gösterimi açık mı başlasın? (Varsayım: kapalı, sadece dolar.)
