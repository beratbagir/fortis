# Fortis Aero — Ürün Ekranları Yol Haritası

Kaynak: `docs/design/fortis-aero-product-screens.pdf` (21 ekran, 12 Ekim 2026 taslağı).
Bu dosya, tasarımdaki her ekranı **mevcut koda** eşler, eksikleri çıkarır ve fazlara böler. Her fazın ayrıntılı görev dosyası ayrıca yazılır.

---

## 1. Kavram eşlemesi

Tasarım "agent" diyor. Bizde karşılığı **akış** (Kestra flow). Yeni bir motor yazılmayacak, mevcut yapının üstüne isim ve ekran eklenecek.

| Tasarımdaki kavram | Fortis'teki karşılığı | Durum |
|---|---|---|
| Agent | **Yeni tanım (A3):** talimat + izinli araçlar + beceriler/bellek + model + tetikleyiciler + onay kuralları. Motor: A1'deki araç döngüsü (Claude tool use). Zamanlayıcı/olay: Kestra. | **Yeni** (`agents` tablosu) |
| Akış (sabit kurallı otomasyon) | `flows` + `flow_versions` (Kestra) | Var, "Akışlar" olarak kalır |
| Architect (agent oluşturma) | Chat + görsel akış editörü + `flow_proposals` | Var, yeniden düzenlenecek. |
| Run | `executions` | Var |
| Approval / onay adımı | Kestra `Pause` + `executions/{id}/resume` | Var, Inbox'a bağlanacak |
| Agent'ın sorduğu soru | Yok | **Yeni** |
| Connector | Katalog `data_sources` + `apps` (APM, IBS, SF, Outlook, Teams) | Kısmen var |
| Artifacts | `reports` + `artifacts` tabloları | Var, tek listede birleşecek |
| Governance | `ai_calls`, kullanım ekranı, bütçe | Kısmen var |
| Credits | Yok | **Yeni** (bkz. 5) |
| Memory | Yok | **Yeni** |
| Skills | `resources/prompts/system.md` içinde sabit | **Yeni** (yönetilebilir hale gelecek) |
| Knowledge Graph | Yok | **Yeni**, en son |
| Kullanıcı, departman, sahip, paylaşım | Rol ve giriş yok | **Yeni, çoğu ekranın ön şartı** |

---

## 2. Ekran ekran durum

| # | Ekran | Mevcut | Yapılacak | Faz |
|---|---|---|---|---|
| 01 | Home | Chat ekranı var | Karşılama, kaynak çipleri, örnek sorular, "Seni bekleyenler", bugünkü agent etkinliği | A2 |
| 02 | Model seçici | Ayarlar ve sohbette model seçimi var | "Auto" yönlendirme, sağlayıcıya göre liste, yönetici politikası notu | A2 (sadece Anthropic), A8 (diğer sağlayıcılar) |
| 03 | Konuşma — canlı iz | `executions/{id}/stream`, araç etiketleri var | Adım adım iz: bellek, agent seçimi, model, izin kapsamı, her sorgu ve sonuç sayısı. Agent'ın çoktan seçmeli soru kartı. | A1 (veri) + A2 (ekran) |
| 04 | Konuşma — kaynaklı cevap | Markdown cevap var | Özet + kanıt tablosu (kopyala, CSV, büyüt), numaralı kaynaklar, önerilen aksiyonlar, "Agent olarak kaydet" | A1 (veri) + A2 (ekran) |
| 05 | Agent Registry | `#/agents` ekranı var | Sahip, departman, durum (Live / Pilot / Draft / Template), haftalık çalışma, "faydalı" oranı, departman filtresi | A3 |
| 06 | Agent detay — izleme | Agent paneli var | 30 günlük KPI'lar, çalışma grafiği, departmana göre benimsenme, yapılandırma özeti, sekmeler | A3 |
| 07 | Build — Architect girişi | Chat'ten akış oluşturma var | Departman seçimi, boş / şablondan / kopyala, havacılık örnekleri ve şablonlar | A3 |
| 08 | Architect — orkestrasyon | Görsel editör + editör sohbeti var | Solda sohbet, sağda akış. Adım türleri (Trigger, Read, Look up, Decide, Draft, Approve, Act), dallanma, okur/yazar/maliyet kutuları, "Son veriyle test et", "Pilota al" | A3 |
| 09 | Tools & Integrations | Katalog ve `apps` uç noktası var | Bağlantı kartları, durum (Connected / Attention / Needs setup), kapsam etiketleri (read, write·approval, events), MCP sekmesi | A4 |
| 10 | Governance | Kullanım ve maliyet ekranı var | Canlı çalışmalar, krediler, dikkat gerekenler, departmana göre kullanım, politikalar, değiştirilemez denetim kaydı | A4 |
| 11 | Memory | Yok | Kişisel ve ortak bellek, tercihler, iş kuralları, profil | A5 |
| 12 | Knowledge Graph | Yok | Politika, sözleşme, tanım wiki'si ve graf görünümü | A8 |
| 13 | Artifacts | `reports`, `artifacts` var | Tek liste, türe göre filtre, önizleme, indirme, canlı sayfa | A5 |
| 14 | Skills | Yok | Freebird becerileri (KPI tanımları, EU261 vb.) ve Fortis çıktı becerileri | A5 |
| 15 | Inbox — onay detayı | Çalışma ekranında Devam/Reddet var | Gönderilecek içeriğin tam önizlemesi, "Onaylarsan ne olur", Onayla / Düzenle / Agent'a sor / Reddet | A3 |
| 16 | Morning Brief e-postası | Rapor ve e-posta bildirimi var | Üç ana sayı, kişiye özel aksiyon butonları (imzalı bağlantı), ağ izleme, e-postaya cevapla soru sor | A6 |
| 17 | In-app widget | Yok | Başka sistemlerin (IBS, SkyBreathe) içinde Fortis penceresi | A8 |
| 18 | Inbox | Yok | Onay, soru, bahsetme, güncelleme, uyarı; toplu işlem | A3 |
| 19 | Required actions | Yok | Kişiye atanan işler panosu, kaynak sistemden otomatik doğrulama | A6 |
| 20 | Shared agents | Yok | Kişi, departman, gruba paylaşma; her kişi kendi izniyle çalıştırır | A7 |
| 21 | Dark mode | Yok | Tema değişkenleri, kenar çubuğundan geçiş | A0 (tamamlandı) |

---

## 3. Fazlar

| Faz | Kapsam | Durum / Neden bu sırada |
|---|---|---|
| **A0 — Temel** | Giriş, kullanıcı, departman, rol, denetim kaydı, yeni kabuk, açık/koyu tema | **Tamamlandı** |
| **A1 — Akıllı cevaplar** | Claude'un veriyi sorgulayıp görmesi ve yorumlaması: veri döndüren sorgu araçları, kişisel veri maskeleme, soru / otomasyon ayrımı, cevap üslubu. Görev dosyası: `docs/fortis-aero-faz-a1.md` | **Kod tamam; yönetim onayı (verildi, 2026-10-13), veri erişimi `masked` açık.** Yönetimin asıl şikâyeti cevapların kuru olması. Altyapının çoğu hazır. Ekranlardan önce cevapların içi doldurulmalı. |
| **A2 — Home ve Konuşma ekranları** | 01, 02 (Anthropic modelleri + Auto), 03 canlı iz ve soru kartı, 04 kaynaklı cevap. Görev dosyası: `docs/fortis-aero-faz-a2.md` | A1'in ürettiği zengin cevaplar PDF'teki ekranlarda gösterilir. |
| **A3 — Agentlar ve Inbox** | 05, 06, 07, 08, 15, 18. Agent = AI asistanı (talimat, araçlar, tetikleyici, onay). Agent sayfası: sohbet + Agent / Tetikleyiciler / Erişim / Ayarlar sekmeleri, görevler, performans. Görev dosyası: `docs/fortis-aero-faz-a3.md` | Ürünün kalbi. Gumloop ve Fortis PDF'indeki agent deneyimi. Akışlar (Kestra) sabit kurallı işler için kalır. |
| **A4 — Platform** | 09, 10 | Yönetici görünürlüğü, krediler, politikalar. A0'daki denetim kaydı burada ekrana çıkar. |
| **A5 — Bilgi** | 11 Memory, 13 Artifacts, 14 Skills | Sohbet ve agentlar Freebird tanımlarını aynı şekilde kullanır. A1'deki cevap kalitesini artırır. |
| **A6 — Erişim ve kanallar** | 16 Morning Brief, 19 Required actions, agent kanalları (Teams'ten agent'a yazmak, e-postaya cevapla soru sormak, MCP) | Fortis'i açmayan kişilere ulaşmak. |
| **A7 — Paylaşım** | 20 Shared agents, kişiye göre veri izni | Rol altyapısı olgunlaştıktan sonra. |
| **A8 — İleri** | 02 (diğer sağlayıcılar), 12 Knowledge Graph, 17 In-app widget | Yüksek emek, belirsiz getiri. Gerçek ihtiyaç görülünce. |

---

## 4. Tasarımda olup bizde olmayan sistemler

Tasarım şu sistemleri bağlı gösteriyor. Şu an elimizde sadece **IBS, APM, SuccessFactors** var.

| Sistem | Ne | Durum |
|---|---|---|
| AirRM | Gelir yönetimi | Erişim yok |
| Octopus | Operasyon olayları, hasar raporları | Erişim yok |
| Oracle | Finans, bütçe, tedarikçi | Erişim yok |
| SkyBreathe | Yakıt verimliliği | Erişim yok |
| SharePoint, Outlook, Teams | Microsoft 365 | Entra ID uygulaması gerekli |

**Kural:** Bağlı olmayan sistemler arayüzde "Needs setup" olarak görünür. Sahte "Connected" gösterilmez. Örnek sorular ve şablonlar, bağlı sistemlere göre seçilir.

---

## 5. Karar bekleyen konular

1. **AI'a veri gitmesi.** Tasarımda agentlar veriyi okuyup taslak yazıyor (sigortacı bildirimi, rota analizi). Şu anki kuralımız "satır verisi Claude'a gitmez". Seçenekler:
   - (a) Kişisel veri maskelenerek gönderilir (Governance'taki "Mask passenger PII in prompts" politikası). Operasyonel veri (rota, yük faktörü, uçak) gider.
   - (b) Yorum şirket içi açık modelle yapılır.
   Öneri: **(a)**, politika Governance ekranından açılıp kapatılabilir ve her çağrı denetim kaydına düşer. Yönetim onayı alındı (2026-10-13); KVKK değerlendirmesi sunucu taşınırken yapılacak. **Bu karar A1'in ön şartıydı:** yönetim Fortis'in yorumlu cevap vermesini istiyor; bu, verinin maskelenmiş olarak Claude'a gitmesini gerektirir.
2. **"Data stays in EU region" ifadesi.** Türkiye açısından AB de yurt dışı. Arayüzde bu metin, gerçek duruma göre yazılmalı.
3. **Kredi.** Tasarım hem dolar hem kredi gösteriyor. Öneri: 1 kredi = sabit bir dolar karşılığı (örneğin $0.02), aylık kota yönetici ayarı. `ai_calls.cost_usd` üzerinden hesaplanır.
4. **Agent'ın soru sorması.** Akış çalışırken bir karar gerekirse (örneğin "maliyet kaynağı Oracle mı APM mi"), Kestra `Pause` + Inbox sorusu olarak modellenir. Cevap `resume` ile akışa girdi olarak verilir.
5. **Giriş yöntemi.** A0'da e-posta ve şifreyle başlandı, Microsoft Entra ID iskeleti hazır. IT uygulama kaydını açınca geçilecek.

---

## 6. Değişmeyecek kurallar

- Kestra dış sistemlere doğrudan bağlanmaz, her şey Laravel iç uç noktalarından geçer.
- FlowValidator her kaydetme yolunda çalışır.
- Yazan aksiyonlar (e-posta, Teams, AirRM'e yazma) her zaman insan onayından geçer.
- Kimlik bilgileri sadece `.env` ve şifreli `app_connections` içinde.
- Bağlı olmayan sistem için sahte veri veya "Connected" durumu gösterilmez. Tasarımdaki örnek veriler sadece demo seed'inde kullanılır.

---

## 7. Referans: Gumloop

`docs/design/gumloop-ui-frames.pdf`. Alınan fikirler:

- Agent sayfası: ortada agent'la sohbet, sağda Agent (model, talimatlar, bağlantılar, beceriler, bilgi kaynakları, yetenekler), Triggers, Access, Settings (ad, açıklama, önerilen sorular, yedek model) sekmeleri → A3
- Agent sayfaları: Görevler (her çalışma), Performans (kullanım, model başına maliyet, görev sonuçları), Değerlendirmeler (çıktıyı kurallara göre otomatik puanlama) → A3
- Şablon kütüphanesi (kategorilere göre) → A3
- Kanallar: e-posta kutusu, Teams, barındırılan sayfa, Slack, MCP → A6
- Takım daveti → A0 sonrası küçük ekleme
