# Fortis — Chat ile Akış Oluşturma (MVP Planı)

## 1. Amaç

Kullanıcı bir **chat ekranında** ne istediğini yazar. AI (Claude) gerekli soruları sorar, isteği netleştirir, bir **Kestra akışı** hazırlar, kullanıcıya sade dille özetler ve onay alınca akışı Kestra'ya yükleyip çalıştırır. Chat üzerinden **herhangi bir akış** istenebilir.

**Bu aşamada kapsam dışı:** Rol, yetki, departman, çok müşteri yapısı, deploy süreçleri.

**Mevcut durum:** Kestra, DigitalOcean'da Docker ile kurulu. Claude ile API üzerinden örnek bir akış oluşturulup çalıştırıldı.

---

## 2. Mimari

```
┌──────────────┐     SSE / REST      ┌──────────────────────────┐
│  React       │ ◄─────────────────► │  Laravel (API)           │
│  Chat ekranı │                     │  - sohbetler, mesajlar   │
└──────────────┘                     │  - Claude entegrasyonu   │
                                     │  - akış doğrulayıcı      │
                                     │  - KestraClient          │
                                     └───────┬─────────▲────────┘
                         Claude API          │         │  Kestra → Laravel
                     (tool use, streaming)   │         │  (durum bildirimi, iç uçlar)
                                     ┌───────▼───┐ ┌───┴──────────────────┐
                                     │  Claude   │ │  Kestra (DigitalOcean│
                                     └───────────┘ │  Docker)             │
                                         Laravel → │  - akışları çalıştırır│
                                     Kestra API    │  - zamanlar, bekler   │
                                                   └──────────────────────┘
```

**Görev dağılımı**

| Bileşen | Görevi |
|---|---|
| React | Chat ekranı, akış kartları, çalışma durumu, akış listesi |
| Laravel | Sohbet geçmişi, Claude ile konuşma, YAML doğrulama, Kestra ile tüm iletişim |
| Claude | Soruları sormak, akışı tasarlamak, Kestra YAML'ı üretmek, sonucu açıklamak |
| Kestra | Akışları saklamak ve çalıştırmak (tetikleyiciler, zamanlama, bekleme, yeniden deneme) |

**Temel kural:** React ve Claude, Kestra'ya **hiçbir zaman doğrudan** erişmez. Kestra ile tüm iletişim Laravel üzerinden geçer.

---

## 3. Laravel ↔ Kestra iletişimi

### 3.1 Ağ ve güvenlik (ilk iş)

Kestra'nın ücretsiz sürümünde yetkilendirme yok. Kestra'ya erişen herkes her şeyi yapabilir. Bu yüzden:

- **Kestra internete açık olmamalı.**
  - Laravel de DigitalOcean'daysa: İki sunucu aynı **VPC**'ye alınır, Laravel Kestra'ya **özel IP** üzerinden bağlanır. Kestra'nın portu dışarıya kapatılır.
  - Laravel başka yerdeyse: Kestra önüne TLS'li bir reverse proxy (Caddy veya Nginx) konur, DigitalOcean firewall'u sadece Laravel sunucusunun IP'sine izin verir.
- **Basic auth açık olmalı.** Varsayılan örnek şifre kullanılmaz. Kullanıcı adı ve şifre Laravel'in `.env` dosyasında durur.
- **Kestra arayüzü** sadece geliştirme ekibine, SSH tüneli veya IP kısıtlamasıyla açılır.

### 3.2 Laravel → Kestra (Kestra API)

`KestraClient` servisi şu işlemleri yapar:

| İşlem | Kestra API |
|---|---|
| Akış oluşturma | `POST /api/v1/main/flows` (gövde: YAML, `Content-Type: application/x-yaml`) |
| Akış güncelleme | Akışın mevcut sürümünü değiştiren uç |
| Akışı çalıştırma | `POST /api/v1/main/executions/{namespace}/{flowId}` |
| Çalışma durumu | `GET /api/v1/main/executions/{executionId}` |
| Bekleyen akışı devam ettirme | `POST .../executions/{executionId}/resume` |
| Webhook ile tetikleme | `.../executions/webhook/{namespace}/{flowId}/{key}` |
| Akış listeleme, silme, aktif/pasif yapma | İlgili uçlar |

Kestra sürümü sabitlenir (`latest` kullanılmaz). Uç noktaların kesin yolları o sürümün API dokümanından alınır ve `KestraClient` içinde tek yerde tutulur.

### 3.3 Kestra → Laravel

İki yön gerekiyor:

1. **Çalışma durumunu chat'e yansıtmak**
   - **İlk sürüm (basit):** Laravel, chat'te başlatılan çalışmaların durumunu birkaç saniyede bir sorgular (polling) ve React'e SSE ile iletir.
   - **Sonra:** Akışların sonuna Laravel'e "bitti" bilgisi gönderen bir adım eklenir.
2. **Akışın Laravel'deki bir hizmeti kullanması** (örneğin chat'e mesaj bırakmak, Laravel'deki veriye erişmek)
   - Akış, Laravel'in iç uç noktalarını `http.Request` ile çağırır.
   - Bu uçlar bir **paylaşılan gizli anahtar** başlığıyla korunur. Anahtar Kestra'da secret olarak tutulur.

### 3.4 Akışların kullanacağı gizli bilgiler

Bir akışın SMTP şifresi veya bir API anahtarı gibi bilgilere ihtiyacı olabilir.

- Bu değerler Kestra'da **secret** olarak tanımlanır.
- AI sadece secret'ların **isimlerini** bilir (`SMTP_PASSWORD` gibi) ve YAML'da `{{ secret('SMTP_PASSWORD') }}` şeklinde kullanır. Değerleri asla görmez.
- Laravel, kullanılabilir secret isimlerinin listesini AI'a katalogla birlikte verir.
- AI'ın ihtiyaç duyduğu bir secret yoksa kullanıcıya söyler: "Bu akış için X bilgisi tanımlı değil, yönetici tarafından eklenmesi gerekiyor."

---

## 4. Chat ve AI tasarımı

### 4.1 Konuşma akışı

Claude her istekte şu adımları izler:

1. **Anla:** Kullanıcının ne istediğini kendi cümleleriyle tekrar eder.
2. **Sor:** Eksik veya belirsiz noktaları sorar (ne zaman çalışacak, hangi koşulda, kime ne gidecek, hata olursa ne olacak). Bir mesajda en fazla 3–4 soru.
3. **Özetle:** Akışı sade dille, madde madde anlatır.
4. **Üret:** Kestra YAML'ını hazırlar.
5. **Doğrulat:** Laravel'in doğrulayıcısından geçirir. Hata varsa düzeltir (en fazla 3 deneme).
6. **Onay iste:** Kullanıcıya akış kartını gösterir. Kullanıcı "Yükle" demeden hiçbir şey Kestra'ya gönderilmez.
7. **Çalıştır ve raporla:** Kullanıcı isterse çalıştırır, sonucu chat'te açıklar.

### 4.2 Claude'a verilen araçlar (tool use)

| Araç | Ne yapar | Kullanıcı onayı |
|---|---|---|
| `get_catalog` | İzinli görev tipleri, örnekleri ve kullanılabilir secret isimleri | Gerekmez |
| `validate_flow` | YAML'ı Laravel doğrulayıcısından ve Kestra'nın kendi kontrolünden geçirir | Gerekmez |
| `propose_flow` | Akışı chat'te kart olarak gösterir (özet + YAML + butonlar) | Kart kullanıcıya gösterilir |
| `list_flows` | Mevcut akışları listeler | Gerekmez |
| `get_flow` | Mevcut bir akışın YAML'ını getirir (değişiklik için) | Gerekmez |
| `get_execution` | Bir çalışmanın durumu ve logları | Gerekmez |

**Yükleme, çalıştırma, silme ve aktif/pasif yapma** Claude'un aracı değildir. Bu işlemleri kullanıcı, akış kartındaki butonlarla yapar. Claude'un yapabileceği en ileri adım, akışı kart olarak önermektir.

### 4.3 Sistem prompt'unun içeriği

- Rol ve konuşma adımları (bölüm 4.1)
- Kestra YAML yazım kuralları ve kullanılan Kestra sürümü
- Namespace kuralı (örneğin `fortis.chat`)
- İzinli ve yasaklı görev tipleri (bölüm 5)
- 3–5 örnek akış (zamanlanmış, webhook ile tetiklenen, onay bekleyen, bildirim gönderen)
- "Bilmediğin bir görev tipi veya eklenti uydurma; katalogda yoksa kullanıcıya yapılamayacağını söyle" kuralı
- "Gizli bilgileri asla YAML'a açık yazma, secret kullan" kuralı

### 4.4 Model

- Varsayılan: **Claude Sonnet 5.5** (akış üretimi için yeterli ve uygun maliyetli)
- Konfigürasyondan değiştirilebilir. Zor isteklerde Opus 5.5 denenebilir.
- Yanıtlar streaming ile React'e akar.
- Her çağrının token ve maliyeti kaydedilir.

---

## 5. Güvenlik: "herhangi bir akış" ama sınırlı araçlarla

Kullanıcı chat'ten her şeyi isteyebilir. Ama AI'ın ürettiği akış sadece **izin verilen görev tiplerini** kullanabilir. Rol ve yetki olmadığı için bu doğrulama tek güvenlik katmanı.

### 5.1 Görev kategorileri

| Kategori | Örnekler | Durum |
|---|---|---|
| **Serbest** | Akış kontrolü (if, switch, parallel, foreach), log, `Pause`, zamanlayıcı ve webhook tetikleyicileri, alt akış | Doğrudan kullanılabilir |
| **Uyarılı** | `http.Request`, e-posta, Slack / Teams bildirimleri, KV deposu | Kullanılabilir. Akış kartında kullanıcıya açıkça gösterilir: "Bu akış şu adrese istek atıyor", "Şu adrese mail gönderiyor" |
| **Yasak** | Python, Shell, Node vb. script görevleri, Docker, SSH, dosya sistemi, komut çalıştırma | Doğrulayıcı reddeder |

İzin listesi Laravel'de bir JSON dosyasında (`config/kestra-catalog.json`) tutulur. Her girdi şunları içerir: görev tipi, kategori, kısa açıklama, örnek YAML parçası.

### 5.2 Doğrulayıcının kontrolleri (`FlowValidator`)

1. YAML geçerli mi?
2. Zorunlu alanlar var mı (`id`, `namespace`, `tasks`)? Namespace izinli mi?
3. **Tüm** görevlerin, tetikleyicilerin, `errors` ve `finally` bloklarının, iç içe görevlerin `type` değerleri izin listesinde mi? (Özyinelemeli kontrol)
4. Yasaklı bir tip varsa akış reddedilir ve nedeni Claude'a geri döner.
5. Şablon ifadelerinde (`{{ }}`) ortam değişkeni okuma gibi riskli fonksiyonlar var mı? Sadece katalogdaki secret isimleri kullanılabilir.
6. YAML'da açık yazılmış şifre veya anahtar benzeri değer var mı?
7. Son olarak Kestra'nın kendi kontrolü (uygun uç varsa) veya pasif yükleme ile doğrulama.

### 5.3 Kestra tarafında ek önlemler

- `slim` imaj kullanılır, script eklentileri hiç kurulmaz.
- Gereken eklentiler (e-posta, Slack, Teams vb.) tek tek kurulur ve sürümleri sabitlenir.

---

## 6. Laravel veri modeli

| Tablo | İçerik |
|---|---|
| `conversations` | Sohbet başlığı, oluşturulma zamanı |
| `messages` | Sohbet mesajları (kullanıcı, AI, araç çağrıları ve sonuçları) |
| `flows` | Kestra'daki akışın kaydı: namespace, flow id, başlık, özet, durum (taslak / yüklendi / aktif / pasif), hangi sohbetten çıktığı |
| `flow_versions` | Her YAML sürümü: içerik, doğrulama sonucu, Kestra'daki revision numarası, kim ve ne zaman yükledi |
| `executions` | Chat'ten başlatılan çalışmaların özeti: Kestra execution id, durum, başlangıç ve bitiş |
| `ai_calls` | Model, token, maliyet, süre |

Çalışma anının asıl kaynağı Kestra'dır. Laravel taslakları, sürümleri ve chat ile bağlantıyı tutar.

---

## 7. Kullanıcı arayüzü

**Chat ekranı**
- Solda sohbet listesi, ortada sohbet
- AI yanıtları canlı akar (streaming)
- **Akış kartı:** başlık, sade dille özet, tetikleyici, uyarılı adımlar ("şu adrese istek atıyor"), açılır YAML görünümü ve butonlar: **Yükle**, **Değiştir**, **Şimdi çalıştır**
- **Çalışma kartı:** durum (çalışıyor / bekliyor / başarılı / hata), adımlar, loglar. Onay bekleyen akışlarda **Devam et** ve **Reddet** butonları

**Akışlar sayfası**
- Tüm akışlar: başlık, durum, son çalışma, hangi sohbetten geldiği
- Aktif / pasif yapma, silme, "bu akışı chat'te değiştir" butonu

---

## 8. Fazlar

Süreler tek geliştirici ve kodlama agent'ları varsayımıyla kaba tahminlerdir.

### Faz 0: Altyapı ve bağlantı — Tek agent (2–3 gün)

- Kestra güvenliği: basic auth, VPC veya firewall + TLS, PostgreSQL backend, sabit sürüm, `slim` imaj
- Laravel ve React projeleri, yerel geliştirme ortamı
- `KestraClient` ilk hali: bağlantı testi, akış oluşturma, çalıştırma, durum sorgulama
- Laravel'den basit bir test akışının yüklenip çalıştırılması

**Çıkış kriteri:** Laravel'deki bir komutla Kestra'ya akış yüklenip çalıştırılıyor ve sonucu okunuyor. Kestra internetten erişilemiyor.

### Faz 1: Chat temeli — Tek agent, sonra küçük swarm (3–4 gün)

- `conversations` ve `messages` tabloları, sohbet API'si
- Claude API entegrasyonu (resmi PHP SDK), streaming yanıt, SSE ile React'e aktarım
- React chat ekranı: sohbet listesi, mesajlar, yazma alanı
- **Swarm kısmı:** API sabitlendikten sonra React bileşenleri paralel yazılabilir (mesaj balonu, sohbet listesi, yazma alanı)

**Çıkış kriteri:** Kullanıcı chat'te Claude ile konuşabiliyor, yanıt canlı akıyor, sohbet geçmişi kaydediliyor.

### Faz 2: Akış üretici — Tek agent (1 hafta)

- Sistem prompt'u (bölüm 4.3)
- Görev kataloğu (`kestra-catalog.json`) ilk hali: serbest ve uyarılı görevler, açıklamalar, örnekler
- Claude araçları: `get_catalog`, `validate_flow`, `propose_flow`, `list_flows`, `get_flow`
- Soru sorma davranışı: belirsiz isteklerde Claude akış üretmeden önce soru soruyor

**Çıkış kriteri:** "Her sabah 08:00'de şu adrese GET isteği at, cevap 200 değilse bana mail gönder" gibi bir isteğe Claude önce soru soruyor, sonra geçerli bir YAML öneriyor.

### Faz 3: Doğrulama ve yükleme — Tek agent (1 hafta)

- `FlowValidator` (bölüm 5.2), özellikle özyinelemeli tip kontrolü
- Doğrulama hatasında Claude'un kendini düzeltme döngüsü (en fazla 3 deneme)
- `flows` ve `flow_versions` tabloları
- React akış kartı ve **Yükle** butonu → Laravel → Kestra
- Mevcut akışı değiştirme: yeni sürüm olarak yükleme

**Neden tek agent:** Doğrulayıcı tek güvenlik katmanı. Tek elden yazılmalı ve satır satır gözden geçirilmeli.

**Çıkış kriteri:** Yasaklı görev içeren hiçbir YAML Kestra'ya ulaşmıyor. Onaylanan akış Kestra'da görünüyor, sürümü Laravel'de kayıtlı.

### Faz 4: Çalıştırma ve izleme — Tek agent, sonra küçük swarm (1 hafta)

- **Şimdi çalıştır** butonu, `executions` tablosu
- Durum takibi (polling → SSE), adım ve log gösterimi
- Onay bekleyen akışlar için **Devam et / Reddet** (`resume` ile)
- Webhook ile tetiklenen akışlar için webhook adresinin kullanıcıya gösterilmesi
- Claude'un çalışma sonucunu (`get_execution`) okuyup açıklaması: "Akış 3. adımda hata verdi, çünkü..."
- **Swarm kısmı:** Çalışma kartı ve log görünümü bileşenleri

**Çıkış kriteri:** Chat'ten başlatılan bir akışın durumu canlı izleniyor, bekleyen akış chat'ten devam ettirilebiliyor, hata olursa Claude nedenini açıklıyor.

### Faz 5: Akış yönetimi — Küçük swarm (3–4 gün)

- Akışlar sayfası: liste, aktif / pasif, silme
- "Bu akışı chat'te değiştir": mevcut YAML yeni bir sohbete bağlam olarak yüklenir
- Katalog genişletme: kullanıcıların istediği ama yapılamayan istekler kaydedilir ("istek listesi")

**Çıkış kriteri:** Kullanıcı tüm akışlarını görüp yönetebiliyor, mevcut bir akışı chat üzerinden değiştirebiliyor.

### Faz 6: Kalite — Tek agent + swarm (sürekli)

- **Doğrulayıcı testleri:** Yasaklı tipleri, iç içe gizlenmiş yasaklı görevleri, açık yazılmış şifreleri içeren örnek YAML'lar. Hepsi reddedilmeli.
- **Prompt eval seti:** 20–30 örnek istek ve her biri için beklenen özellikler (doğru tetikleyici, doğru koşul, soru soruldu mu, yasaklı görev kullanılmadı mı). Swarm'a uygun iş: her agent bir grup örnek istek yazar.
- **Uçtan uca test:** Test Kestra'sına karşı chat → öneri → yükleme → çalıştırma.

**Çıkış kriteri:** Eval setinde akışların büyük çoğunluğu ilk denemede veya kendini düzeltme döngüsünde geçerli çıkıyor, doğrulayıcı testlerinin tamamı geçiyor.

---

## 9. Örnek kullanım

> **Kullanıcı:** Her sabah 08:00'de hava durumu API'sinden İstanbul'un rüzgar hızını al, 40 km/s'i geçerse ops ekibine mail at.
>
> **AI:** Birkaç şeyi netleştireyim:
> 1. Hangi hava durumu servisini kullanıyoruz? Adresi ve varsa anahtar ismi nedir?
> 2. Mail hangi adrese gitsin?
> 3. API cevap vermezse ne olsun: hiçbir şey yapmasın mı, yoksa ayrıca uyarı mı göndersin?
>
> **Kullanıcı:** Servis şu adres, anahtar `WEATHER_API_KEY` olarak tanımlı. Mail ops@... adresine. Cevap gelmezse de mail atsın.
>
> **AI:** [Akış kartı]
> **İstanbul rüzgar uyarısı**
> - Her gün 08:00'de çalışır
> - Hava durumu servisine istek atar *(dış adrese istek)*
> - Rüzgar 40 km/s'i geçerse ops@... adresine mail gönderir *(e-posta)*
> - Servis cevap vermezse yine ops@... adresine hata maili gönderir
>
> [YAML'ı göster] [Yükle] [Değiştir]

Kullanıcı **Yükle**'ye basar, ardından **Şimdi çalıştır** ile test eder. Çalışma kartında adımlar ve sonuç görünür.

---

## 10. Riskler

| Risk | Önlem |
|---|---|
| AI hatalı veya Kestra sürümüne uymayan YAML üretir | Sürüme özel örnekler, doğrulayıcı, kendini düzeltme döngüsü, eval seti |
| AI katalogda olmayan görev uydurur | Doğrulayıcı reddeder, sistem prompt'unda açık kural |
| Kestra'ya yetkisiz erişim | VPC / firewall, basic auth, Kestra'nın internete kapalı olması |
| `http.Request` ile istenmeyen adreslere istek | Akış kartında açıkça gösterilmesi, kullanıcı onayı. Gerekirse adres izin listesi |
| Kullanıcının "her şeyi" yapabileceğini sanması | Katalog dışı isteklerde AI'ın açıkça "yapamıyorum" demesi, istek listesi |
| KVKK: DigitalOcean'da Türkiye bölgesi yok | MVP'de kişisel veri kullanılmaz. Gerçek veriyle önce sunucu yeri veya sözleşme konusu çözülür. |
| AI maliyeti | Her çağrının maliyet kaydı, konuşma geçmişini kısa tutma |

---

## 11. Açık sorular

- [ ] Laravel nerede çalışacak? Kestra ile aynı DigitalOcean VPC'sine alınabilir mi?
- [ ] Hangi Kestra sürümü kurulu? Sürüm sabitlenecek.
- [ ] Bildirim kanalları neler olacak (SMTP, Teams, Slack)? Bunların bilgileri Kestra'ya secret olarak eklenecek.
- [ ] Chat'i kimler kullanacak? Rol olmadığı için uygulamaya erişimi şimdilik IP kısıtlaması veya basit bir giriş ekranıyla sınırlamak gerekebilir.
- [ ] İlk demoda gösterilecek 3–5 örnek akış hangileri olacak?
