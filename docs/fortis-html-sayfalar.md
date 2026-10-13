# Görev: Sohbette HTML sayfalar (pano, rapor, etkileşimli içerik)

Önce `ILERLEME.md`, `docs/fortis-sohbet-grafik.md` ve `docs/fortis-aero-yol-haritasi.md` (ekran 13 Artifacts) dosyalarını oku. Grafik görevi (`:::chart`) bu görevden önce ya da bununla birlikte yapılabilir; ikisi birlikte yaşar.

## 1. Amaç

Kullanıcı "son 30 günün rötar panosunu hazırla", "bu verilerle filtrelenebilir bir sayfa yap", "yönetime sunulacak tek sayfalık rapor hazırla" dediğinde Fortis **çalışan bir HTML sayfası** üretsin: birden çok grafik, filtreler, sekmeler, kartlar, metin.

- Basit soru → cümle. Liste/karşılaştırma → tablo. Tek grafik yeterli → `:::chart`.
- **Birden çok görsel, filtre, düzen ya da "sayfa/pano/rapor/HTML" isteği → HTML sayfa (artifact).**

Sayfa sohbette bir kart olarak açılır, büyütülür, kaydedilir, indirilir, ekipte paylaşılır.

---

## 2. Güvenlik modeli (pazarlıksız)

Modelin yazdığı HTML **güvenilmeyen koddur**. Aşağıdakilerin hepsi uygulanır; biri eksikse özellik kapalı kalır.

1. **İzole çerçeve.** Sayfa `<iframe sandbox="allow-scripts">` içinde `srcdoc` ile açılır. **`allow-same-origin` yok** (sayfa opak origin'de çalışır: Fortis çerezlerine, localStorage'a, üst pencereye erişemez). `allow-forms`, `allow-popups`, `allow-top-navigation`, `allow-modals` yok.
2. **Ağ kapalı.** `srcdoc`'un başına sunucu tarafından CSP eklenir:
   `default-src 'none'; script-src 'unsafe-inline' <fortis-origin>/vendor/; style-src 'unsafe-inline' <fortis-origin>/vendor/; img-src data: blob:; font-src <fortis-origin>/vendor/; connect-src 'none'; form-action 'none'; frame-src 'none'; base-uri 'none'`
   Sayfa hiçbir yere istek atamaz, veri dışarı çıkamaz.
3. **Kütüphaneler sadece Fortis'ten.** İzinli kütüphaneler sabit sürümle `public/vendor/` altında: grafik için **Chart.js** (veya ECharts, birini seç), **Tailwind derlenmiş CSS** (veya sade CSS). CDN yok. Model başka kaynak eklerse CSP zaten engeller; sunucu ayrıca `<script src>`/`<link href>` adreslerini izin listesine göre temizler.
4. **Veri modelin yazdığı sayılar değil.** Model HTML'e sayı gömmez; veriyi `window.FORTIS_DATA["q:ID"]` üzerinden okur. Sunucu, bu cevapta çalışmış sorguların **maskelenmiş** sonuçlarını (`AiDataGate` çıktısı, Claude'a giden veriyle aynı) sayfaya JSON olarak enjekte eder. Böylece:
   - Sayılar uydurulamaz (kaynak sorgudan gelir).
   - Kişisel veri sayfaya giremez (maskelenmiş veri).
   - Sayfa yenilendiğinde aynı sorgular yeniden çalıştırılabilir (bkz. 6).
   Modelin HTML içine gömdüğü serbest sayılar (başlık metni gibi) olabilir; ama tablo/grafik verisi `FORTIS_DATA`'dan gelmeli. Prompt bunu zorunlu kılar; sunucu, `FORTIS_DATA` kullanmayan ve içinde 20'den fazla sayısal değer bulunan sayfaya "Veri kaynağı doğrulanamadı" uyarısı ekler.
5. **Üst pencereyle tek kanal.** Sayfa üst pencereye sadece `postMessage` ile iki şey gönderebilir: `{type:"resize", height}` ve `{type:"ask", text}` ("Bu çubuğa tıklayınca Fortis'e sor"). Üst pencere `event.source === iframe.contentWindow` kontrol eder, başka mesajı yok sayar; `ask` metni kullanıcıya gösterilir ve **kullanıcı onaylamadan gönderilmez**.
6. **Boyut sınırı:** HTML en fazla 200 KB, enjekte veri en fazla 2 MB. Aşarsa üretim reddedilir, modelden toplulaştırması istenir.
7. **Kayıt:** Her üretim ve görüntüleme `audit_logs`'a (`artifact.create`, `artifact.view`, `artifact.download`, `artifact.share`).

---

## 3. Model tarafı

Yeni araç: **`create_page`**

```json
{
  "title": "Rötar panosu – son 30 gün",
  "description": "Günlük OTP, nedenlere göre rötar, meydan kırılımı",
  "sources": ["q:7f3a", "q:91bc"],
  "html": "<!-- sadece body içeriği + <style> + <script> -->"
}
```

- `html` sadece gövde içeriği; `<html>/<head>` ve CSP'yi sunucu ekler.
- `sources` bu cevapta çalışmış sorgular olmalı; değilse araç hata döner.
- Sunucu bir **şablon kabuk** sağlar: tema değişkenleri (açık/koyu, Fortis renkleri), fontlar, `FORTIS_DATA`, yardımcılar (`fortis.formatNumber`, `fortis.formatDate` Türkçe, `fortis.chart(el, config)` = Chart.js'i Fortis temasıyla saran yardımcı, `fortis.table(el, rows, columns)`, `fortis.ask(text)`).
- Prompt'a ekle:
  - Ne zaman sayfa üretileceği (bölüm 1).
  - Veri her zaman `FORTIS_DATA`'dan; yardımcıları kullan; tek eksenli grafikler, en fazla 6 seri, sabit renk sırası (`fortis.colors`), durum renkleri seri rengi değil.
  - Sayfa Türkçe, telefonda da okunur (tek sütuna düşer).
  - Sayfadan sonra sohbette 2–3 cümle özet ve kaynak numaraları.
- **Model:** Sayfa üretiminde varsayılan model (Sonnet) yeterli; ModelRouter sayfa üretimini `ai.page_model` ile ayrı ayarlanabilir yapsın (varsayılan Sonnet).
- **Maliyet:** Sayfa üretimi ayrı `ai_calls.purpose = page`; Kullanım ekranında görünür.

---

## 4. Veri modeli

| Tablo | Alanlar |
|---|---|
| `artifacts` (varsa genişlet) | `id`, `kind` (`page`), `title`, `description`, `owner_id`, `department_id`, `conversation_id`, `message_id`, `agent_run_id` null, `current_version_id`, `visibility` (`private` / `department` / `org`), `created_at` |
| `artifact_versions` | `artifact_id`, `version`, `html` (temizlenmiş), `sources` json (sorgu tanımları: araç, parametreler/SQL), `data_snapshot` json (enjekte edilen maskeli veri), `data_at`, `created_by` (kullanıcı/agent) |

Sorgu **tanımı** saklanır (yeniden çalıştırmak için); veri anlık görüntü olarak saklanır. Sorgu tanımı A1 kurallarıyla aynı şekilde saklanır (SQL/yol sadece admin/builder'a gösterilir).

---

## 5. Arayüz

**Sohbette sayfa kartı:**
- Başlık, açıklama, "Veri: 13 Ekim 14:32" (mono), kaynak numaraları.
- İçinde sayfanın küçük önizlemesi (iframe, yükseklik `resize` mesajıyla, en fazla 520px; taşarsa "Tam ekran aç").
- Düğmeler: Tam ekran, Yeniden oluştur ("şunu değiştir" sohbetle), Kaydet (Sayfalar'a), İndir (HTML; veri ve kütüphaneler gömülü, tek dosya, açınca çalışır), Paylaş (departman / şirket).

**`#/pages` (Sayfalar):** A5'teki Artifacts ekranının ilk hali. Liste (başlık, sahip, departman, son veri zamanı, görüntülenme), arama, filtre (benim / departmanım / şirket). Detay: tam ekran sayfa, sürümler, "Veriyi yenile", sohbet geçmişine bağlantı.

**Paylaşım:** Sadece Fortis içinde, giriş yapmış kullanıcılara; görüntüleyen kişi veriyi **kendi yetkisiyle** görür (yenilemede kendi kaynak izinleri uygulanır; anlık görüntüyü görmek için sahibin paylaşması yeterli). Dış bağlantı yok.

Kenar çubuğuna "Sayfalar" eklenir.

---

## 6. Yenileme

- **Veriyi yenile:** Saklanan sorgu tanımları kullanıcının yetkisiyle yeniden çalıştırılır (`AiDataGate`'ten geçer), yeni veri enjekte edilir, **yeni sürüm** oluşur. AI çağrısı yok (sadece sorgular) → ucuz.
- Göreli tarihli sorgular (dün, son 30 gün) yenilemede **yeniden hesaplanır**: sorgu tanımında göreli ifade (`relative: "last_30_days"`) saklanır; model `create_page` çağrısında sorgunun göreli mi sabit mi olduğunu belirtir. Belirtmezse sabit kabul edilir ve sayfada "Sabit tarih aralığı" yazar.
- **Agent ile:** Agent araçlarına `refresh_page` eklenir: "her sabah 07:00'de rötar panosunu yenile ve bağlantısını OCC'ye mail at". Mail sadece bağlantı içerir (veri değil); onay kuralları aynen geçerli.

---

## 7. Alt fazlar

| Alt faz | Kapsam | Bitti ölçütü |
|---|---|---|
| **H1** | Güvenli çerçeve, CSP, şablon kabuk ve yardımcılar, `create_page`, sohbet kartı, tam ekran, HTML indirme | Model gerçek veriyle 2 grafikli + filtreli bir pano üretiyor; güvenlik testleri geçiyor |
| **H2** | `artifacts`/`artifact_versions`, Kaydet, `#/pages`, sürümler, paylaşım | Kaydedilen sayfa başka kullanıcı tarafından (departman paylaşımıyla) açılıyor |
| **H3** | Veriyi yenile (göreli tarihler), agent `refresh_page` | Kayıtlı pano "Veriyi yenile" ile güncel veriye geçiyor; agent sabah yeniliyor |

Her alt fazdan sonra dur ve ILERLEME'ye yaz.

---

## 8. Testler

**Güvenlik (en önemli; her biri ayrı test):**
- Sayfadaki script `document.cookie`, `localStorage`, `parent.document`, `top.location` erişemiyor (Playwright, gerçek tarayıcı).
- `fetch`, `XMLHttpRequest`, `WebSocket`, `new Image().src = "https://dış…"`, `<link rel=prefetch>`, `<form action>` çalışmıyor (CSP ihlali konsolda, ağ isteği yok — Playwright ağ dinleyicisiyle doğrula).
- Dış `<script src>` ve `<link href>` temizleniyor; `/vendor/` dışındaki adres yüklenmiyor.
- Sahte `postMessage` (başka kaynaktan, bilinmeyen tür) yok sayılıyor; `ask` kullanıcı onayı olmadan gönderilmiyor.
- `FORTIS_DATA`'da kişisel veri yok (A1 fixture'larıyla: yolcu adı, PNR, TC, telefon… sayfada aranır, bulunmamalı).
- 200 KB / 2 MB sınırları; `sources`'ta bu cevapta olmayan sorgu reddi.
- Paylaşılan sayfada yenileme, görüntüleyenin yetkisiyle (kapatılmış kaynak → o veri yenilenmiyor, uyarı).

**İşlev:** kart, tam ekran, indirilen HTML'in çevrimdışı açılıp çalışması, sürümler, paylaşım görünürlüğü, yenileme ve göreli tarih, agent `refresh_page` + onaylı mail.

**Eval (gerçek model, bütçe 5 $):**
- "Son 14 günün rötar panosunu hazırla: günlük OTP, nedenlere göre dağılım, en çok rötar yapan 5 rota" → sayfa, 3 görsel, veri `FORTIS_DATA`'dan.
- "Dün kaç sefer vardı" → sayfa yok.
- "Departmanlara göre çalışan sayısını grafikle göster" → `:::chart` (tek grafik yeterli), sayfa değil.
- "Bu panoya meydan filtresi ekle" (önceki sayfanın üstüne) → yeni sürüm, filtre çalışıyor.

---

## 9. Kabul kriterleri

1. Pano/sayfa isteklerinde çalışan, Türkçe, açık/koyu temaya uyan HTML sayfa geliyor; basit sorularda gelmiyor.
2. Sayfa dışarıya hiçbir istek atamıyor, Fortis oturumuna erişemiyor (testlerle kanıtlı).
3. Sayfadaki veriler kaynak sorgulardan ve maskeli.
4. Kaydet, paylaş, indir, yenile çalışıyor.
5. Tüm testler ve eval soruları geçiyor.

## 10. Kapsam dışı

- Fortis dışına (internete) açık paylaşım bağlantısı.
- Sayfanın kendi başına sorgu çalıştırması (sayfa sadece enjekte veriyi görür).
- Sayfadan dış sisteme yazma.
- PDF/PNG'ye sunucu tarafı dönüştürme (ileride headless Chromium ile; şimdilik tarayıcıdan yazdır).
