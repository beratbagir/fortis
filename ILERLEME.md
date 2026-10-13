# Fortis — İlerleme

Plan: [fortis-chat-akis-plani.md](fortis-chat-akis-plani.md)

| Faz | Durum |
|---|---|
| Faz 0: Altyapı ve bağlantı | Büyük ölçüde tamam. Kestra'nın internete kapatılması ertelendi. |
| Faz 1: Chat temeli | Tamam |
| Faz 2: Akış üretici | Tamam |
| Faz 3: Doğrulama ve yükleme | Tamam |
| Faz 4: Çalıştırma ve izleme | Tamam |
| Faz 5: Akış yönetimi | Tamam |
| Faz 6: Kalite | Başlanmadı (doğrulayıcı testlerinin büyük kısmı Faz 3'te yazıldı) |

---

## Faz 0: Altyapı ve bağlantı

**Çıkış kriteri**

- [x] Laravel'deki bir komutla Kestra'ya akış yüklenip çalıştırılıyor ve sonucu okunuyor (`php artisan kestra:smoke-test`, 2026-10-01'de başarılı).
- [ ] Kestra internetten erişilemiyor. **Ertelendi:** sunucuya şimdilik dokunulmayacak. Adımlar hazır: `infra/kestra/README.md`.

**Yapılanlar**

- Proje yapısı: `backend/` (Laravel 13, PHP 8.4), `frontend/` (React 19 + Vite + TypeScript), `infra/`. Git deposu açıldı; henüz commit yok.
- Mevcut Kestra sunucusu: `68.183.66.112:8080`, sürüm **1.3.41 OSS**, basic auth açık. Laravel yerelde çalışıyor ve bu sunucuya bağlanıyor.
- `KestraClient` (`backend/app/Services/Kestra/KestraClient.php`), Kestra ile iletişimin tek noktası:
  - Akışlar: doğrulama, oluşturma, güncelleme (yeni revision), getirme, silme, listeleme
  - Çalışmalar: başlatma (girdi ve etiketlerle), durum, devam ettirme, durdurma, loglar, webhook adresi
  - 1.3 ve 2.0 API farkları sürüme göre ele alınıyor (`KESTRA_VERSION`): 2.0'da `resume`/`kill` yolları `/actions/` altına taşındı, `namespace`/`minLevel` parametreleri kaldırıldı, hatalar RFC 9457 formatında dönüyor.
- Kestra OpenAPI dökümanları: `infra/kestra-api/api-1.3.41.yml` (sunucudan alındı), `api-2.0.4.yml` (imajdan çıkarıldı).
- Artisan komutları:
  - `php artisan kestra:ping`: bağlantı, sürüm ve kimlik kontrolü
  - `php artisan kestra:smoke-test [--cleanup]`: test akışını (`resources/kestra/smoke-test.yml`, namespace `fortis.dev`) doğrulatır, yükler, çalıştırır, bitmesini bekler, adımları ve logları gösterir
- Hedef Kestra kurulumu (`infra/kestra/docker-compose.yml`): `v2.0.4-slim`, eklenti otomatik kurulumu kapalı, basic auth açık, PostgreSQL, Docker soketi bağlı değil, root olarak çalışmıyor, port sadece `127.0.0.1`'de.
- 2.0.4'e geçiş ve sunucuyu kapatma adımları: `infra/kestra/README.md`. **Uygulanmadı.**

**Kararlar ve notlar**

- Hedef sürüm: 2.0.4-slim. Geçiş geri alınamıyor (basic auth şifresi yeniden hash'leniyor); önce yedek alınmalı.
- 2.0 slim imaj varsayılan olarak eksik eklentileri Maven'dan kendisi indiriyor (`KESTRA_PLUGINS_AUTO_INSTALL_ENABLED`). Kapalı tutulmalı; açık kalırsa izin listesi dışındaki görevler çalışabilir hale gelir.
- Kestra'nın kendi AI özelliği kullanılmıyor. Claude'u Laravel çağırıyor.

---

## Faz 1: Chat temeli

**Çıkış kriteri:** Kullanıcı chat'te Claude ile konuşabiliyor, yanıt canlı akıyor, sohbet geçmişi kaydediliyor. **Karşılandı** (tarayıcıda uçtan uca denendi).

**Backend**

- Tablolar: `conversations`, `messages`, `ai_calls`
  - `messages.content`: Claude'un içerik blokları (düşünme blokları dahil) API'nin kendi biçiminde saklanır ve sonraki istekte geri gönderilir. İki istisna: boş (`null`) alanlar kaydederken silinir; yanıt sırasında yedek modele geçildiyse geçişten önceki düşünme ve araç blokları geri gönderilmez (Anthropic'in kuralı). Claude Sonnet 5.5 düşünme bloklarını konuşmaya bağlar; geçmiş değişirse istek reddedilir. `messages.text` ekranda gösterilen metindir.
  - `ai_calls`: her çağrının modeli, girdi/çıktı/önbellek token sayıları, USD maliyeti, süresi, bitiş nedeni ve hatası.
- Claude entegrasyonu: resmi PHP SDK `anthropic-ai/sdk` v0.54
  - `App\Services\Claude\ClaudeChat`: streaming yanıt, kayıt, maliyet logu, Türkçe hata mesajları
  - `ConversationHistory`: geçmişi API formatına çevirir. Yanıt sırasında yedek modele geçildiyse geçişten önceki düşünme bloklarını çıkarır.
  - `CostCalculator`: model fiyat tablosu `config/services.php` → `anthropic.pricing`
  - Model `claude-sonnet-5-5`, effort `medium`, adaptive thinking, prompt caching açık (ikinci turdan itibaren önbellekten okunduğu doğrulandı)
  - Sunucu tarafı yedek model (`fallbacks: default`) açık: güvenlik sınıflandırıcısı bir isteği reddederse API isteği uygun bir modelde yeniden çalıştırır. `ANTHROPIC_FALLBACKS=false` ile kapatılabilir.
- Sistem prompt'u (ilk hali): `resources/prompts/system.md`. Anla → sor → özetle adımları. Akış araçları henüz bağlı değil.
- API (`routes/api.php`):
  - `GET/POST /api/conversations`, `GET/PATCH/DELETE /api/conversations/{id}`
  - `POST /api/conversations/{id}/messages`: SSE ile yanıt verir. Olaylar: `user_message`, `thinking`, `delta`, `assistant_message`, `error`, `done`
- Ayarlar (`backend/.env`): `ANTHROPIC_API_KEY`, `ANTHROPIC_MODEL`, `ANTHROPIC_EFFORT`, `ANTHROPIC_MAX_TOKENS`, `ANTHROPIC_FALLBACKS`
- Laravel Boost kuruldu (geliştirme aracı).

**Frontend**

- Chat ekranı: solda sohbet listesi (yeni, seç, sil), ortada mesajlar (markdown), altta yazma alanı (Enter gönderir, Shift+Enter yeni satır)
- Yanıt canlı akıyor. Model düşünürken "Düşünüyor" göstergesi çıkıyor. İlk mesaj sohbet başlığı oluyor.
- Boş ekranda örnek istekler var.
- SSE `fetch` + `ReadableStream` ile okunuyor (`EventSource` POST desteklemiyor). Vite `/api` isteklerini Laravel'e yönlendiriyor.
- Tailwind CSS v4, `react-markdown` + `remark-gfm`

**Testler:** 23 test, hepsi geçiyor (Kestra istemcisi, sohbet API'si, SSE olay sırası, hata akışı, geçmiş ve maliyet hesabı). Testlerde gerçek API çağrılmıyor.

**Denemeler:** Gerçek Claude API ile 5 çağrı yapıldı. Maliyet, kendi token kayıtlarımız ve `config/services.php`'deki fiyat tablosuyla hesaplandığında yaklaşık 0,035 USD. Bu Anthropic faturası değil; önbellek yazma fiyatı (girdi fiyatının 1,25 katı) varsayım. Mesajlar kısa test mesajlarıydı; gerçek kullanım maliyetini göstermez.

---

## Faz 2: Akış üretici

**Çıkış kriteri:** "Her sabah 08:00'de şu adrese GET isteği at, cevap 200 değilse bana mail gönder" isteğine Claude önce soru soruyor, sonra geçerli bir YAML öneriyor. **Karşılandı** (2026-10-01): Claude adresi ve alıcıyı sordu, varsayımlarını söyledi; cevaplardan sonra katalogu inceledi, YAML'ı doğruladı ve kart olarak önerdi. Akış hem bizim kontrolümüzden hem Kestra'nın doğrulamasından geçti.

**Önemli bulgu: sunucudaki Kestra tam kurulum.** 214 eklenti grubu kurulu; aralarında 61 script görevi (Python, Shell, Node...), Docker, SSH ve dosya sistemi görevleri var. Kestra'nın kendi doğrulaması bir script görevini kabul eder. Yasaklı görevlere karşı tek koruma Laravel'deki izin listesi. Faz 3'teki doğrulayıcı bu yüzden kritik; 2.0.4-slim'e geçiş de bu riski azaltır.

**Görev kataloğu** (`backend/config/kestra-catalog.json`)
- Sunucudaki gerçek eklenti şemalarına göre yazıldı (tip adları ve zorunlu alanlar `/api/v1/plugins` uç noktasından alındı).
- Serbest (14): Log, OutputValues, If, Switch, Parallel, Sequential, ForEach, Pause, Sleep, Subflow, Fail, Labels, Schedule, Webhook
- Uyarılı (7): http.Request, e-posta (`io.kestra.plugin.email.MailSend`), Slack, Teams, KV Set/Get/Delete
- Her girdide açıklama, örnek YAML ve uyarılılar için kartta gösterilecek etiket ve alanlar var.
- Secret isimleri `.env`'deki `KESTRA_SECRETS` satırından okunuyor (virgülle ayrılmış). **Şu an boş.** Laravel ve Claude secret değerlerini hiç görmüyor.
- `php artisan kestra:check-catalog`: katalogdaki tüm örnekleri ve prompt'taki örnek akışları Kestra'ya karşı doğrular. Kestra sürümü değişince çalıştırılmalı. Şu an hepsi geçerli.

**Sistem prompt'u** (`resources/prompts/system.md` + `app/Services/Claude/SystemPrompt.php`)
- Konuşma adımları: anla → sor → üret → doğrulat (en fazla 3 deneme) → öner
- Kestra YAML kuralları, namespace kuralı (`fortis.chat`), saat dilimi (`Europe/Istanbul`), ifade sözdizimi
- İzinli tiplerin listesi (katalogdan), yasaklar, "uydurma" ve "secret'ı açık yazma" kuralları
- 4 örnek akış (`resources/prompts/examples/`): zamanlanmış kontrol + e-posta + hata bloğu, webhook + Slack, onay bekleyen (Pause), çalışmalar arası durum (KV) + Teams
- Örnekler hem statik olarak hem gerçek çalışmada denendi: onay akışı Kestra'da çalıştırılıp onay ve red yollarıyla devam ettirildi; `trigger.body` ve `jq` ifadeleri webhook ile gerçekten tetiklenerek doğrulandı.
- Prompt her istekte aynı olduğu için önbelleğe alınıyor. Secret listesi gibi değişebilen bilgiler `get_catalog` sonucunda.

**Claude araçları** (`app/Services/Claude/FlowTools.php`)

| Araç | Ne yapar |
|---|---|
| `get_catalog` | İzinli tipler, örnekler, tanımlı secret isimleri |
| `validate_flow` | YAML yapısı, zorunlu alanlar, namespace, izinli tipler (iç içe tüm görevler, tetikleyiciler, koşullar), eksik secret'lar, Kestra'nın kendi doğrulaması |
| `propose_flow` | Doğrulamadan geçerse akış kartını gösterir; geçmezse kart gösterilmez, hatalar Claude'a döner |
| `list_flows` | Kestra'daki `fortis.chat` akışları |
| `get_flow` | Bir akışın mevcut YAML'ı (değişiklik için) |

- Hiçbir araç Kestra'ya yazmıyor. Yükleme Faz 3'te kullanıcının butonuyla yapılacak.
- Araç girdileri canlı akıyor (`eagerInputStreaming`). Bu modda API girdiyi doğrulamadığı için her girdi Laravel'de kontrol ediliyor; bozuk girdide araç çalıştırılmıyor.
- Araç döngüsü (`ClaudeChat`): bir kullanıcı mesajı için en fazla 10 API çağrısı. Her tur ve araç sonucu kaydediliyor, her API çağrısı `ai_calls`'a ayrı yazılıyor. Kesilen veya reddedilen bir turdaki araç çağrıları çalıştırılmıyor.

**Akış kartı** (`app/Services/Flows/FlowCard.php` + `frontend/src/components/FlowCardView.tsx`)
- Başlık ve özet Claude'dan geliyor. Tetikleyici, uyarılı adımlar ("Dış adrese istek atıyor: GET https://...", "E-posta gönderiyor: ops@...") ve eksik secret'lar Claude'a bırakılmadan YAML'dan çıkarılıyor.
- Butonlar: **Yükle** (pasif, Faz 3), **Değiştir** (yazma alanına değişiklik cümlesini hazırlar), **YAML'ı göster**
- Kart verisi `messages.meta` sütununda saklanıyor; Claude'a gönderilmiyor.
- Araç çalışırken ekranda durum gösteriliyor: "Kullanılabilir adımlar inceleniyor", "Akış doğrulanıyor", "Akış kartı hazırlanıyor".

**Denenen davranışlar** (gerçek API ile)
- Eksik bilgide önce soru soruyor (adres, alıcı).
- "Varsayımlarınla hazırla" denince soru sormadan kart üretiyor.
- "Değiştir" ile aynı `id`'yi koruyarak değişiklik yapıyor (Slack → Teams).
- Python script / dosya silme isteğini reddediyor, nedenini söylüyor, katalogdaki görevlerle alternatif öneriyor.
- Tanımsız secret'ları kullanıcıya "yönetici tarafından eklenmesi gerekiyor" diye bildiriyor.

**Testler:** 42 test, hepsi geçiyor. Yeni testler: iç içe gizlenmiş yasaklı görevler (`then` → `Parallel` → script, `errors`, tetikleyiciler, `Switch.cases`), namespace ve zorunlu alanlar, eksik secret'lar, Kestra hata ve uyarıları, Kestra'ya ulaşılamadığında davranış, kart içeriği, geçersiz akışta kart gösterilmemesi, eksik araç girdisi, sistem prompt'u.

**Maliyet** (kendi hesabımız, fatura değil): Faz 2 denemelerinde 14 çağrı, ~0,15 USD. Bir akış oluşturma konuşmasının tamamı (2 kullanıcı mesajı, 5 API çağrısı) ~0,055 USD. Sistem prompt'u ve geçmiş önbellekten okunuyor (çağrı başına 6-8 bin token).

Faz 2'deki `FlowChecker` ve `FlowInspector`, Faz 3'te yerini `FlowValidator`'a bıraktı (aşağıda).

---

## Faz 3: Doğrulama ve yükleme

**Çıkış kriteri:** Yasaklı görev içeren hiçbir YAML Kestra'ya ulaşmıyor. Onaylanan akış Kestra'da görünüyor, sürümü Laravel'de kayıtlı. **Karşılandı** (2026-10-01):
- Yasaklı içerikli 70'ten fazla senaryo testte reddediliyor ve hiçbiri Kestra'ya istek göndermiyor (`Http::assertNothingSent`).
- Tarayıcıda chat'ten bir test akışı oluşturulup **Yükle** ile yüklendi. Kestra'da revision 1 olarak göründü; Laravel'deki kayıtta revision, zaman ve yükleyen var; Kestra'daki kaynak doğrulanan YAML'la birebir aynı.
- Aynı akış chat'te değiştirildi; sürüm 2 yüklendi ve Kestra'da revision 2 oldu. Test akışı sonra Kestra'dan silindi.

**Doğrulayıcı** (`app/Services/Flows/FlowValidator.php`). Tek güvenlik katmanı; ilke izin listesi.
1. **YAML:** en fazla 100 KB; özel tag'ler ve tekrar eden anahtarlar reddedilir.
2. **Kanonik YAML:** ayrıştırılan yapı yeniden YAML'a yazılır ve Kestra'ya **yalnızca bu metin** gider. Anchor, alias ve merge gibi iki ayrıştırıcının farklı okuyabileceği yapılar Kestra'ya ulaşmaz. Kullanıcının kartta gördüğü, doğrulanan ve yüklenen metin aynıdır. Symfony belirsiz değerleri tırnaklar (`'yes'`, `'on'`); YAML 1.1 farkı oluşmaz.
3. **Yapı:** en üst seviyede sadece izinli alanlar (`pluginDefaults` gibi alanlar reddedilir). Zorunlu alanlar var mı, namespace tam olarak `fortis.chat` mı?
4. **Tipler:** ağacın tamamında her `type` değeri kontrol edilir (`then`, `else`, `cases`, `defaults`, `errors`, `finally`, `triggers`, `conditions`, `Pause.tasks`...). Noktalı tipler katalogda olmalı; büyük-küçük harf, boşluk, eski takma adlar ve liste olarak verilen tip reddedilir. Noktasız tipler sadece kendi bağlamlarında kabul edilir (`inputs`/`onResume`/`outputs` → STRING, INT...; `retry` → constant...; `auth` → BASIC/BEARER). `FILE` girdisi yasak.
5. **Görev kuralları:**
   - HTTP: adres sabit `http(s)://sunucu` ile başlamalı, sunucu kısmında ifade olamaz. localhost, özel/ayrılmış IP'ler (metadata `169.254.169.254`, `100.64/10` dahil), noktasız sunucu adları (`postgres`), sayısal/hex IP hileleri, `.internal`/`.local`, IPv6, adres içinde kullanıcı adı/şifre, Kestra'nın ve uygulamanın kendi sunucusu ve iç ağa çözümlenen DNS adları reddedilir. `options.proxy` yasak.
   - E-posta: `host`, `username`, `password` (varsa `port`, `from`) sadece secret olabilir; `attachments`/`embeddedImages` yasak.
   - Slack/Teams: `url` sadece secret olabilir.
   - Subflow sadece `fortis.chat` içinden çağrılabilir; KV görevlerinde `namespace` verilemez.
   - Webhook key 24-64 karakter olmalı.
6. **Şablonlar (`{{ }}`, `{% %}`):** fonksiyon, filtre ve etiketler izin listesine göre kontrol edilir. Yasak olanlar: `envs`, `globals`, `_context`, `render()`, `read()`, `kv()`, `http()`, `printContext()`, metot çağrıları (`x.getClass()`), `{% import/include %}`, dinamik `secret(x)`, kapanmamış ifadeler.
7. **Gizli bilgiler:** `password`/`token`/`authorization` gibi alanlar sadece secret olabilir (`Bearer {{ secret('X') }}` kabul). Değerlerde API anahtarı, AWS, GitHub, Slack token, Slack/Teams webhook adresi, özel anahtar, JWT, Bearer token ve adres içinde şifre kalıpları aranır.
8. **Secret'lar:** sadece `KESTRA_SECRETS` listesindekiler. Öneride eksik secret uyarıdır; yüklemede hatadır.
9. **Kestra'nın kendi doğrulaması** kanonik YAML üzerinde. Yüklemede Kestra'ya ulaşılamazsa yükleme yapılmaz.

**Kendini düzeltme döngüsü:** bir kullanıcı mesajında en fazla 3 başarısız doğrulama. Sonra araçlar "deneme hakkı doldu" döner ve Claude kullanıcıya durumu açıklar.

**Tablolar**
- `flows`: namespace, flow_id (benzersiz), başlık, özet, durum (`draft` / `uploaded`), hangi sohbetten çıktığı, güncel sürüm
- `flow_versions`: sürüm numarası, kanonik YAML ve hash'i, kullanılan secret'lar, kart verisi, doğrulama uyarıları, kartın bulunduğu mesaj, Kestra revision, yükleme zamanı, yükleyen (şimdilik IP; kullanıcı yok)
- Aynı YAML tekrar önerilirse yeni sürüm açılmaz.

**Yükleme** (`POST /api/flow-versions/{id}/upload` → `FlowService::upload`)
- Sürüm yükleme modunda yeniden doğrulanır. Kanonik YAML'ın saklanan metinle birebir aynı olması şart; veritabanında elle değiştirilmiş bir sürüm yüklenmez.
- Zaten yüklenmiş sürüm ve eski sürüm (daha yenisi varsa) yüklenemez.
- Akış Kestra'da varsa güncellenir (yeni revision), yoksa oluşturulur.
- Claude'a bilgi: kullanıcının bir sonraki mesajının başına "[Sistem notu: ... yükledi. Kestra revision: N]" eklenir. Geçmiş değişmez; ekranda sadece kullanıcının yazdığı görünür.

**Kart**
- Durum her gösterimde canlı hesaplanır: yüklendi mi, revision, son sürüm mü, eksik secret var mı, yüklenebilir mi.
- **Yükle** butonu sadece yüklenebilir sürümlerde aktif; değilse nedeni yazar. Yükleme hatası kartta listelenir.
- Sürüm numarası ve "Yüklendi" rozeti gösterilir.

**Sistem prompt'u güncellendi:** Yükle butonu, sistem notu, doğrulayıcı kuralları (HTTP adresi, secret alanları, webhook key, izinli fonksiyonlar, düz YAML).

**Testler:** 124 test, hepsi geçiyor.
- `FlowValidatorTest` (82): iç içe gizlenmiş yasaklı görevler (16 yol), engellenen HTTP hedefleri (18), riskli şablonlar (18), açık yazılmış gizli bilgiler (13), yapı, Kestra hataları, prompt örneklerinin yükleme modunda geçerliliği. Reddedilen her senaryoda doğru hata mesajı ve Kestra'ya istek gitmediği kontrol ediliyor.
- `FlowUploadTest` (9): yükleme, yeni revision, veritabanında değiştirilmiş sürüm, eksik secret, eski/zaten yüklü sürüm, Kestra'ya ulaşılamaması, canlı kart durumu, Claude'a sistem notu.

**Bilinen sınırlar**
- DNS kontrolü doğrulama anında yapılır. DNS rebinding (doğrulamadan sonra adresin iç ağa çevrilmesi) bu kontrolle önlenemez. Kesin çözüm Kestra sunucusunda iç ağa çıkışı firewall ile kapatmak.
- Kestra'nın ve uygulamanın sunucu adları engelli, ama Kestra'nın genel IP'si (`68.183.66.112`) adresle yazılırsa engellenmez. `KESTRA_BLOCKED_HOSTS` ile eklenebilir; Kestra internete kapatılınca sorun kalkar.
- Kestra'da silinen bir akış Laravel'de hâlâ "yüklendi" görünür (silme ve senkronizasyon Faz 5'te).
- Aynı sürüme aynı anda iki yükleme isteği gelirse ikisi de Kestra'ya gidebilir (veritabanı kilidi yok; SQLite). Sonuç aynı YAML olduğundan zararsız; PostgreSQL'e geçince kilit eklenmeli.
- Faz 2'de oluşturulan eski kartların sürüm kaydı yok; bunlar yüklenemez ("kartın kaydı bulunamadı").

**Maliyet** (kendi hesabımız): Faz 3 tarayıcı denemeleri 7 çağrı, ~0,06 USD.

---

## Faz 4: Çalıştırma ve izleme

**Çıkış kriteri:** Chat'ten başlatılan bir akışın durumu canlı izleniyor, bekleyen akış chat'ten devam ettirilebiliyor, hata olursa Claude nedenini açıklıyor. **Karşılandı** (2026-10-01, tarayıcıda gerçek Kestra ile):
- Claude'a girdili, onay bekleyen ve webhook tetikleyicili bir test akışı hazırlatıldı, yüklendi ve **Şimdi çalıştır** ile `customer=acme` girdisiyle başlatıldı. Durum canlı olarak "Onay bekliyor"a geçti.
- **Devam et** ile çalışma devam etti ve "Başarılı" bitti. Adımlar canlı güncellendi: `wait_for_approval` → `decide` → `log_approved`.
- İkinci çalışma **Reddet** ile "Hata" bitti; reddedildi dalındaki `Fail` görevi çalıştı.
- **Neden hata verdi?** butonuna basıldı. Claude `get_execution` ile çalışmayı okudu ve nedeni doğru açıkladı: onay reddedildi, akış tasarlandığı gibi çalıştı. Önceki başarılı çalışmayı da ayırt etti.
- Webhook adresi kartta gösterildi; adrese şifresiz POST atılınca akış çalıştı.
- Test akışı ve çalışmaları sonra Kestra'dan temizlendi.

**Backend**
- `executions` tablosu: akış, sürüm, sohbet, Kestra çalışma id'si, durum, girdiler, başlangıç/bitiş, son okunan adımlar ve loglar (`snapshot`), başlatan (şimdilik IP).
- `ExecutionService`:
  - `start`: sadece Kestra'da yüklü olan güncel sürüm çalıştırılır. Girdiler akışın tanımına göre süzülür: tanımsız girdi reddedilir, zorunlu girdi istenir. Kestra'da çalışmaya `source:fortis-chat` ve `conversation:<id>` etiketleri eklenir.
  - `refresh`: Kestra'dan durum, adımlar ve son 200 log satırı okunur.
  - `resume`: **Devam et** bekleyen Pause görevinin tek BOOL girdisini `true`, **Reddet** `false` gönderir; böylece akışın reddedildi dalı çalışır. BOOL onay girdisi yoksa Reddet çalışmayı durdurur. Diğer onResume girdileri formdan gelir.
  - `kill`
- `FlowDefinition`: kaydedilmiş (doğrulanmış) YAML'dan akış girdilerini, bekleyen Pause görevinin girdilerini ve webhook key'ini okur.
- Uç noktalar:
  - `POST /api/flows/{flow}/executions`: çalıştır
  - `GET /api/executions/{id}`: güncel durum
  - `GET /api/executions/{id}/stream`: SSE. Laravel 2 saniyede bir Kestra'yı sorgular, değişince iletir; bağlantı 55 saniyede kapanır ve tarayıcı yeniden bağlanır; çalışma bitince `done` gelir.
  - `POST /api/executions/{id}/resume`: Devam et / Reddet
- Kart artık şunları da içeriyor: çalıştırma bilgisi (çalıştırılabilir mi, girdiler, webhook adresi) ve bu sohbetten başlatılan son 5 çalışma.
- Claude aracı `get_execution`: çalışma id'siyle ya da akış id'siyle (o akışın en son çalışması; zamanlayıcı veya webhook ile başlamış olanlar dahil) okur. Sadece `fortis.chat` namespace'i. Önce uyarı/hata logları, sonra son bilgi logları döner.
- Sistem notları: son kullanıcı mesajından bu yana bu sohbette başlatılan veya biten çalışmalar ve son durumları, bir sonraki mesajda Claude'a bildirilir (yükleme notlarına ek olarak).
- Sistem prompt'una çalıştırma bölümü eklendi: Şimdi çalıştır butonu, hataları sade dille ve uydurmadan açıklama, onay akışlarında tek BOOL girdisi, webhook adresi.
- `php artisan serve` artık 4 işçiyle çalışıyor (`PHP_CLI_SERVER_WORKERS=4`, `--no-reload` gerekli). Chat ve çalışma takibi aynı anda SSE kullanabiliyor.

**Frontend**
- Kartta **Şimdi çalıştır** butonu; akışın girdileri varsa form açılıyor (metin, sayı, seçim, evet/hayır).
- Webhook adresi ve **Kopyala** butonu.
- Çalışma kartı (`ExecutionCard.tsx`): durum rozeti (Sırada, Çalışıyor, Onay bekliyor, Başarılı, Hata, Durduruldu...), başlangıç zamanı ve süre, adımlar (durum işareti, süre, deneme sayısı), açılır log görünümü, canlı güncelleme (`EventSource`).
- Onay bekleyen çalışmada **Devam et** / **Reddet** ve varsa ek onay girdileri.
- Hata veya uyarıyla biten çalışmada **Neden hata verdi?** butonu, soruyu chat'e gönderiyor.

**Bulunan ve düzeltilen hata:** Kestra'nın çalışma durdurma ucu yalnızca `text/json` kabul ediyor; `application/json` isteği 406 alıyordu. Yani BOOL onay girdisi olmayan akışlarda Reddet çalışmazdı. Testlerdeki sahte Kestra bunu yakalamamıştı; gerçek Kestra denemesinde bulundu. Düzeltildi, test eklendi ve gerçek Kestra'da doğrulandı (KILLED).

**Testler:** 136 test, hepsi geçiyor. Yeni `ExecutionTest` (11): girdiler ve etiketlerle başlatma, taslak akış ve hatalı girdilerin reddi, adım ve log okuma, YAML'dan onay girdileri, Devam et/Reddet'in gönderdiği değerler, BOOL girdisi yokken durdurma, beklemeyen çalışmada reddetme, SSE olay sırası, kartta webhook adresi ve çalışmalar, `get_execution` (akış id'si, başka namespace'in gizlenmesi), Claude'a çalışma notu. Ayrıca durdurma ucunun `Accept` başlığı testi.

**Bilinen sınırlar**
- Webhook adresini bilen herkes, Kestra şifresi olmadan akışı tetikleyebiliyor (key bir şifre gibi davranıyor). Kestra internete kapatılınca dış sistemlerin webhook'a nasıl ulaşacağı ayrıca karar verilmeli (ör. Laravel üzerinden bir yönlendirme ucu).
- Açık duran bir kart, onay bekleyen çalışmayı 2 saniyede bir sorgulamaya devam ediyor (bekleme 24 saat sürebilir). Çok kullanıcıda bu aralık uzatılmalı veya Kestra'dan bildirim alınmalı (planın 3.3 maddesi).
- Kartta sadece bu sohbetten başlatılan çalışmalar görünüyor. Zamanlayıcı veya webhook ile başlayanlar kartta yok; Claude `get_execution` ile bunları okuyabiliyor. Tüm çalışmaların listesi Faz 5'teki akışlar sayfasına uygun.
- Kestra 2.0'da çalışma yanıtından görev çıktıları kaldırıldı; çıktılar gösterilmiyor (loglar gösteriliyor).

**Maliyet** (kendi hesabımız): Faz 4 tarayıcı denemeleri 7 çağrı, ~0,07 USD.

---

## Faz 5: Akış yönetimi

**Çıkış kriteri:** Kullanıcı tüm akışlarını görüp yönetebiliyor, mevcut bir akışı chat üzerinden değiştirebiliyor. **Karşılandı** (2026-10-01, tarayıcıda gerçek Kestra ile):
- Chat'te bir test akışı oluşturuldu ve yüklendi; akışlar sayfasında "Aktif · Kestra revision 1" olarak göründü.
- **Pasif yap**: Kestra'dan yeniden okunan durum "Pasif", uyumsuzluk yok.
- **Chat'te değiştir**: yeni bir sohbet açıldı, üstte "Bu sohbet … akışını değiştirmek için açıldı" bilgisi çıktı. Claude mevcut YAML'ı bildiği için aynı id ile 2. sürümü önerdi. Yüklenen 2. sürüm **pasif kaldı**.
- Aynı mesajdaki "SFTP'den Excel indirip Python ile işle" isteğini Claude reddetti, istek listesine kaydetti ve alternatif önerdi; kayıt **İstek listesi** sekmesinde göründü.
- **Ayrıntı**: sürüm geçmişi (hangi sürüm Kestra'da, revision'lar) ve Kestra'daki son çalışmalar.
- Silme (API üzerinden): akış Kestra'dan kalktı, Laravel'de yumuşak silindi. Faz 3 ve 4'te Kestra'dan elle silinmiş iki eski test akışı sayfada "Kestra'da bulunamadı" uyarısıyla göründü ve silindi.

**Akış durumları:** `draft` (hiç yüklenmedi), `active` (Kestra'da, tetikleyicileri çalışır), `inactive` (Kestra'da, pasif). Eski `uploaded` durumu `active` oldu.
- **Silme yumuşak:** akış Kestra'dan silinir; kaydı, sürümleri ve çalışmaları kalır. Eski sohbetlerdeki kartlar "Bu akış silindi" gösterir; yüklenemez, çalıştırılamaz.
- Silinmiş bir akışın id'siyle yeni öneri gelirse kayıt taslak olarak geri gelir; eski sürümler geçmişte kalır.
- Pasif bir akışa yeni sürüm yüklenince akış pasif kalır. Yeni YAML Kestra'da akışı aktifleştireceği için yüklemenin ardından yeniden pasifleştirilir.
- Kestra'da pasifleştirme ve aktifleştirme yeni bir revision açar (YAML'a `disabled` yazılır). Sayfada Kestra'daki güncel revision gösterilir; sürüm kaydında yükleme anındaki revision kalır.

**Backend**
- `FlowManager`:
  - Laravel kayıtlarını Kestra'nın gerçek durumuyla birleştirir: Kestra'da var mı, pasif mi, revision; namespace'teki son 200 çalışmadan her akışın son çalışması (zamanlayıcı ve webhook dahil).
  - Uyumsuzlukları işaretler: Kestra'da bulunamadı; taslak ama Kestra'da aynı id var; durumlar farklı.
  - Kestra'da olup Fortis kaydı olmayan akışları ayrıca listeler.
  - Kestra'ya ulaşılamazsa sayfa yine açılır ve bunu belirtir.
- Aktif/pasif için Kestra'nın `flows/enable|disable/by-ids` uçları (1.3 ve 2.0'da aynı). Önce akışın Kestra'da var olduğu kontrol edilir, çünkü toplu uç olmayan akış için hata vermeden durumu yanlış kaydettirebilirdi.
- "Bu akışı chat'te değiştir": `conversations.context_flow_id` ile yeni sohbet açılır. Akışın mevcut YAML'ı, durumu ve sürümü kullanıcının **ilk** mesajının başına sistem notu olarak eklenir; geçmiş değişmez.
- İstek listesi: `feature_requests` tablosu ve Claude aracı `record_unsupported_request` (istek, eksik yetenek, neden). Liste en çok istenen eksik yeteneklere göre gruplanıyor; her kaydın durumu değiştirilebiliyor (açık, planlandı, yapıldı, kapatıldı).
- Uç noktalar: `GET /api/flows`, `GET /api/flows/{id}`, `POST /api/flows/{id}/enable|disable`, `DELETE /api/flows/{id}`, `POST /api/flows/{id}/edit-in-chat`, `GET /api/feature-requests`, `PATCH /api/feature-requests/{id}`
- Sistem prompt'u: yapılamayan istekleri kaydetme, "Chat'te değiştir" ile açılan sohbetlerde davranış.

**Frontend**
- Kenar çubuğunda **Sohbetler / Akışlar** sekmeleri (`#/flows`).
- Akışlar sayfası:
  - Her akış için durum rozeti, namespace.id, sürüm, Kestra revision, tetikleyiciler ve son çalışma (durum, zaman, tetikleyen).
  - Uyumsuzluk uyarısı.
  - İşlemler: **Pasif yap / Aktif yap**, **Chat'te değiştir**, **Sohbete git**, **Ayrıntı** (sürümler, YAML'ları, Kestra'daki son 10 çalışma), **Sil** (onay penceresiyle).
- **İstek listesi** sekmesi: en çok istenen eksik yetenekler, kayıtlar, durum seçimi ve sohbete bağlantı.
- "Chat'te değiştir" ile açılan sohbette üstte bağlam bilgisi.

**Testler:** 147 test, hepsi geçiyor. Yeni `FlowManagementTest` (11): Kestra durumu, son çalışma ve uyumsuzlukların birleştirilmesi; Kestra'ya ulaşılamaması; aktif/pasif; Kestra'da olmayan ve taslak akışın değiştirilememesi; silme ve eski kartların durumu; Kestra'da olmayan akışın silinmesi; silinmiş id'nin geri gelmesi; pasif akışın yeni sürümünün pasif kalması; chat'te değiştirmede YAML'ın sadece ilk mesajla gitmesi; istek listesi kaydı, gruplama ve durum.

**Bilinen sınırlar**
- Tarayıcı otomasyonu onay penceresini kapatamadığı için **Sil** butonu tarayıcıda denenmedi; aynı uç API üzerinden denendi.
- Kestra'da olup Fortis kaydı olmayan akışlar sadece listeleniyor; içe aktarma yok.
- Akış listesinde her akış için ayrıca sürüm sayısı sorgusu yapılıyor; akış sayısı çok artarsa toplu sorguya çevrilmeli.
- Rol ve yetki olmadığı için uygulamaya erişen herkes tüm akışları silebilir veya pasifleştirebilir (açık soru 4).

**Maliyet** (kendi hesabımız): Faz 5 tarayıcı denemeleri 7 çağrı, ~0,11 USD. Projenin başından beri toplam 43 çağrı, ~0,47 USD.

---

## Karmaşık akış denemesi (2026-10-01)

**Senaryo:** Her sabah 08:00'de İstanbul, Ankara ve İzmir için Open-Meteo'dan (anahtar gerektirmeyen herkese açık API) sıcaklık ve rüzgar. Bunlara ek olarak:
- eşiği geçen şehir için uyarı logu ve etiket,
- KV'de saklanan önceki değere göre 15 km/s'ten fazla değişim raporu,
- bir şehrin API'si cevap vermezse diğerlerine devam,
- elle çalıştırmada eşik girdisi (varsayılan 40).

Kestra'da secret olmadığı için bildirim yerine log kullanıldı.

**İlk deneme hata verdi** ve kök neden bizdeydi. Kestra'nın ForEach içindeki ifade kuralları katalogda ve prompt'ta yoktu. Kestra 1.3.41'de deneyerek doğruladım:

| Konum | Döngü değeri | Kardeş görevin çıktısı |
|---|---|---|
| Döngünün doğrudan altı | `taskrun.value` | `outputs.X[taskrun.value]` (indekssiz `outputs.X` çalışmaz) |
| Döngüdeki If'in `then`/`else` altı | `parent.taskrun.value` (`taskrun.value` çalışmaz) | `outputs.X[parent.taskrun.value]` (`currentEachOutput` çalışmaz) |

Sayı karşılaştırma: metin değerler `| number` ile çevrilmeli; INT/FLOAT girdilere `| number` uygulanırsa hata verir.

Bu kurallar kataloğa ve sistem prompt'una eklendi. Yeni örnek akış `05-foreach-api.yml` Open-Meteo ile gerçekten çalıştırılarak doğrulandı.

**Sonuç:** Yeni bir sohbette aynı istek ilk denemede doğru akışı üretti (`city-wind-watch`, tek doğrulama). Gerçek veriyle:
- eşik 5'te üç şehir için uyarı çıktı, varsayılan 40'ta hiç çıkmadı,
- KV'deki değer elle 0 yapılınca İstanbul için "DEĞİŞİM: 0 → 28.8 km/s" yazıldı.

Akış Kestra'da **aktif**: her sabah 08:00'de çalışacak. Akışlar sayfasından pasif yapılabilir. Önceki deneme akışı (`weather-wind-watch`), sohbeti ve KV değerleri silindi.

**Bu denemede bulunan ve düzeltilen hatalar**
- **Aynı sohbete eşzamanlı iki yanıt:** kullanıcı arayüzdeki "Neden hata verdi?" butonuna, ben de API'den aynı anda soru gönderince iki yanıt birbirine karıştı. Claude iki kart önerdi ve geçmiş bozuldu. Düzeltmeler:
  - Sohbet başına kilit; ikinci istek 409 alıyor.
  - Arayüz yanıt sürerken karttaki butonlar dahil gönderimi engelliyor.
  - Kaydedilmeyen geçici mesaj ekrandan kalkıyor.
  - Kullanıcı sayfayı kapatsa da yanıt tamamlanıyor (`ignore_user_abort`); yarım kalan bir araç döngüsü geçmişi bozardı.
- **Doğrulayıcıda yanlış pozitif:** `x is defined and (y > 1)` ifadesindeki `and (`, izinsiz fonksiyon sanılıyordu. Pebble anahtar sözcükleri artık fonksiyon sayılmıyor; test eklendi.

**Bilinen sınırlar ve gözlemler**
- Akışta etiket anahtarı tek (`wind_alert`). Birden fazla şehir eşiği geçerse son şehir öncekilerin üzerine yazıyor (denemede sadece `wind_alert:Izmir` kaldı). Şehir başına anahtar (`wind_alert_Istanbul` gibi) daha doğru olur.
- "API cevap vermezse" dalı gerçek bir hatayla denenmedi; yalnızca statik olarak doğru.
- Daha derin iç içe yapı (döngü içinde If içinde If) doğrulanmadı. Prompt bu yüzden iç içe If'i yasaklıyor.
- **Sistem prompt'u değişince devam eden sohbetler etkilenebilir.** Claude Sonnet 5.5 düşünme bloklarını sistem prompt'una bağlıyor. Bu hesapta sorun çıkmadı (prompt değişikliklerinden sonra eski sohbetler çalıştı), ama 31 Ağustos 2026'dan sonra açılan hesaplarda bu kontrol zorunlu ve istek reddedilebilir. Üretimde prompt güncellemesi öncesi `thinking-binding-controls` beta'sı ile `drop_block` ayarı değerlendirilmeli.

---

## IBS veri ambarı entegrasyonu (2026-10-02)

**Sonuç:** Akışlar IBS iFly Res veri ambarını (MySQL 8.4, `ires_dwh_fh_tbls`, havayolu FH) okuyabiliyor. Chat'ten istenen "dünün uçuş doluluğu ve bilet işlemleri" akışı Kestra'da gerçek veriyle başarılı çalıştı (3. sürümde):
- 37 uçuş bacağı kontrol edildi, düşük doluluk uyarısı üretildi.
- Bilet kesme ve iade sayıları loglandı.

Veri kopyalanmıyor; sorgular her çalışmada doğrudan ambara gidiyor.

**Bağlantı**
- **Laravel → ambar:** bu Mac VPN'deyken doğrudan `10.184.210.52:18501`. Kullanıcı `dwh_fh_rpt_user`, yetkileri sadece `SELECT` ve `EXECUTE` (yazamaz). Ayarlar `backend/.env` → `IBS_DB_*`, `config/database.php` → `ibs` bağlantısı.
- **Kestra → ambar:** Mac'teki ters SSH tüneli üzerinden: `ssh -N -R 172.17.0.1:13306:10.184.210.52:18501 root@68.183.66.112` (`caffeinate` ile). Kestra'daki bağlantı adresi `jdbc:mysql://172.17.0.1:13306/ires_dwh_fh_tbls`.
- **Kestra secret'ları:** `IBS_JDBC_URL`, `IBS_DB_USERNAME`, `IBS_DB_PASSWORD`. Sunucuda `/opt/kestra/.env.secrets` (600) ve compose'da `env_file`; compose yedeği `docker-compose.yml.bak-20261002-112023`. Kestra yeniden başlatıldı (sürüm değişmedi, 1.3.41).
- **Önemli:** tünel bu Mac'e bağlı. Mac kapanır, uyur ya da VPN düşerse veri ambarı akışları hata verir. Kalıcı çözüm: tüneli bir sunucuya taşımak ya da Kestra'yı şirket ağına almak.

**Hazır sorgular** (`backend/resources/warehouse/queries.json`). Hepsi gerçek veriyle çalıştırılarak test edildi; en yavaşı ~4 sn. NULL döndürmezler.

| Sorgu | Ne verir |
|---|---|
| `gunluk_satis_ozeti` | Gün ve para birimine göre PNR satış hareketi ve net tutar |
| `kanal_bazli_satis` | Satış kanalına göre |
| `acente_satis_top` | En çok satan 20 acente |
| `odeme_ozeti` | Ödeme yöntemi ve durumuna göre |
| `ucus_doluluk` | Uçuş bacağı başına kapasite, yolcu kaydı, binen, doluluk % (kapasite bilinmiyorsa -1) |
| `bilet_islemleri` | Kesildi/iade/iptal/değişim bilet ve kupon sayıları |

**Serbest SQL:** Claude tüm görünür tablolara kendi `SELECT`'ini yazabiliyor.
- `SqlGuard` kuralları:
  - tek bir SELECT/WITH,
  - `INSERT`/`UPDATE`/`DELETE`/`INTO OUTFILE`/`SLEEP` gibi ifadeler yasak,
  - `MAX_EXECUTION_TIME` ipucu zorunlu (≤120 sn),
  - `FETCH` için `LIMIT` zorunlu (≤5000),
  - SQL içinde `{{ }}` yok; değerler `:ad` yer tutucusu ve `parameters` ile bağlanıyor,
  - yer tutucular ve parametreler birebir eşleşmeli,
  - gizli sütunlara erişim yok.
- Doğrulayıcı ayrıca:
  - bağlantı bilgisi yalnızca secret'tan gelebilir,
  - `fetchType` yalnızca `FETCH`/`FETCH_ONE`,
  - `afterSQL`/`store`/`inputFile` yasak,
  - SQL veri ambarında **`EXPLAIN`** ile çalıştırmadan deneniyor; olmayan sütun hata, büyük tabloda tam tarama uyarı.
- Kart: hazır sorguysa "Onaylı sorgu: …", değilse "Serbest SQL: …".

**Claude araçları:**
- `get_queries`: hazır sorgular.
- `describe_warehouse`: genel kurallar ve 79 görünür tablo (yedek tablolar gizli); istenen tabloların sütunları ve indeksleri (kredi kartı ve banka sütunları gizli).
- Tablo sözlüğü (`resources/warehouse/tables.json`): IBS veri modeli dokümanından 62 tablo açıklaması ve canlı veritabanı incelemesinden genel kurallar (tarih ID'leri, indeksler, büyük tablolar, `flight_suffix='*'`).

**Gerçek veriyle öğrenilen Kestra kuralları** (prompt'ta ve katalogda):
- DB'nin sayısal sütunları sayı olarak gelir; `| number` uygulanmaz.
- NULL değerli sütunlar satırda hiç yer almaz. Koşulda `is defined`, mesajda `| default('-')` kullanılmalı, SQL'de `COALESCE`.

**Testler:** 191 test, hepsi geçiyor. Veri ambarı testleri **gerçek ambara** bağlanıyor; VPN kapalıyken atlanıyor.
- `SqlGuardTest` (veritabanı gerektirmez): 17 yasaklı senaryo ve diğer kurallar.
- `WarehouseTest`: hazır sorguların EXPLAIN'i ve gerçek çalışması, gizli tablo ve sütunlar, EXPLAIN'in hata ve tam tarama tespiti, MySQL görevi kuralları, kart ayrımı, araçlar.

**Açık konular**
- Güvenlik ve KVKK (kullanıcı kararıyla ertelendi):
  - Ambar kişisel veri içeriyor; sorgu sonuçları DigitalOcean'daki Kestra'da saklanıyor.
  - Kestra hâlâ internete açık ve SSH tüneli üzerinden şirket ağındaki ambara erişebiliyor.
- `daily-flight-load-check` akışı **aktif**; her sabah 08:30'da çalışacak (tünel açıksa).
- Maliyet: bu sohbet (3 düzeltme turu dahil) 13 çağrı, kendi hesabımızla ~0,61 USD. Veri ambarı araçlarının dönüşleri (şema, sorgular) büyük olduğu için çağrı başı maliyet önceki akışlardan yüksek.

---

## SAP SuccessFactors entegrasyonu (2026-10-05)

**Kaynak:** `success-factor.postman_collection 1.json`, SuccessFactors OData v2 (`https://api55.sapsf.eu/odata/v2/`, basic auth).
- İçeriği: organizasyon (FOLocation, FOCompany, FOJobCode, FODepartment), çalışan (EmpJob, User), kişi (PerPersonal, PerPerson, PerPhone) okuma istekleri ve iki yazma isteği (PerEmail ve User upsert).
- Koleksiyondaki sorunlar:
  - `$expend` yazım hatası, doğrusu `$expand` (Fortis'te düzeltildi).
  - "Passive Employees" isteği "Employee List" ile aynı; pasif filtresi eksik.
  - Şifre dosyada düz metin; yenilenmesi önerildi.

**Sonuç:** Chat'ten istenen "şirket bazında çalışan sayısı" akışı Kestra'da gerçek SuccessFactors verisiyle ilk denemede başarılı çalıştı:
- 10 şirket için sayılar loglandı; 20'nin altındaki 4 şirket için uyarı yazıldı.
- Claude hazır istekler yetmediği için FOCompany + `EmpJob/$count` birleşimini kendisi kurdu.

Örnek akış (07) de Kestra'da çalıştırılarak doğrulandı (`jq` `//` operatörü, nesne listesi üzerinde döngü).

**Kurulanlar**
- **Bağlantı:** SuccessFactors internetten erişilebiliyor; Kestra doğrudan bağlanıyor, tünel gerekmiyor.
  - Laravel: `backend/.env` → `SF_*` (şemayı okumak için).
  - Kestra: `SF_USERNAME` ve `SF_PASSWORD` secret'ları (`/opt/kestra/.env.secrets`, yedekli). Kestra yeniden başlatıldı.
- **Hazır istekler** (`resources/successfactors/requests.json`): 8 okuma isteği; hepsi gerçek SuccessFactors'ta 200 döndü. Kişisel veri içerenler (`sf_calisan_listesi`, `sf_kullanici`, `sf_telefonlar`) işaretli. Kayıt sayıları: 35 lokasyon, 10 şirket, 171 departman, 2.354 EmpJob, 1.399 kullanıcı.
- **Claude aracı `describe_successfactors`:** entity verilmezse bağlantı, OData kuralları ve hazır istekler; verilirse alanlar (SAP etiketleriyle, `$metadata`'dan) ve ilişkiler. Varlığa özel `$metadata` ilişkileri içermediği için ilişki adları bir kaydın `__deferred` bağlantılarından alınıyor; kayıt değerleri kullanılmıyor. Öncelik: önce hazır istek, yoksa Claude kendi isteğini yazıyor.
- **Doğrulayıcı:**
  - SuccessFactors adresine giden isteklerde `options.auth` (BASIC, username ve password yalnızca secret) zorunlu.
  - Adreste `$format=JSON` zorunlu (`$count` hariç).
  - **Yazma kapalı** (`SF_ALLOW_WRITES=false`): yalnızca GET.
- **Kart:** "SuccessFactors'tan (İK) veri okuyor" ile birlikte "Hazır istek: …" (kişisel veri uyarısıyla) ya da "Serbest istek: GET <varlık>".
- **Kapatılan açıklar** (tüm HTTP istekleri için): `options.basicAuthPassword` ve `proxyPassword` alanlarına açık şifre yazılabiliyordu; `proxyAddress` gibi proxy alanları engellenmiyordu.
- **Testler:** 197 test, hepsi geçiyor. `SuccessFactorsTest`:
  - hazır isteklerin doğrulayıcıdan geçmesi,
  - yazmanın reddedilmesi,
  - secret ve `$format` kuralları,
  - eski şifre ve proxy alanları,
  - kart ayrımı,
  - **gerçek SuccessFactors'tan** şema (ulaşılamazsa atlanır).

**Şirket ve izin sorguları (2026-10-05):** "bu hafta GDA'da kimler izine çıkacak" sorusu ilk denemede doğru sonuç verdi (6 kayıt, Kestra'da gerçek veriyle).
- Bulunanlar:
  - Çalışanın şirketi `EmpJob.company`'de (`User.custom03` boş).
  - `EmployeeTime`'dan şirkete `userIdNav/empInfo/jobInfoNav/company` ile ulaşılıyor.
  - `emplStatus` seçim listesi kodları: 4464 Etkin, 4471 Sonlandırıldı, 4472 Ücretsiz izin. GDA'nın 253 EmpJob kaydının 98'i etkin.
- Eklenenler:
  - hazır istek `sf_izinler`,
  - şirket kodu listesi (GDA = Gözen Digital Aviation, HLD = Gözen Holding, …),
  - `/Date(ms)/` → tarih için Pebble kalıbı (jq'da strftime/todate çalışmıyor),
  - pazartesi-pazar hesabı,
  - metin listelerde `| length` tuzağı (karakter sayısı veriyor; `fromJson(...) | length` kullanılmalı).

**Genel İK soruları (2026-10-05):** izinle sınırlı kalmasın diye:
- **Claude aracı `preview_successfactors`:** isteği gerçek SuccessFactors'ta dener. Toplam sayıyı, ilk 5 kaydı (meta alanlar çıkarılmış, TC kimlik maskeli) veya SuccessFactors'un hata mesajını döndürür. Claude serbest istekleri önermeden önce bununla doğruluyor; alan adı tahmin hataları (ör. `EmpJob/employmentType`) akışa geçmeden yakalanıyor.
- **11 yeni hazır istek (hepsi canlıda denendi):** aktif çalışanlar (şirket), işe girenler, işten ayrılanlar (neden ve yeniden işe alınabilirlik ile), doğum tarihleri, kıdem, sözleşmesi bitenler, deneme süresi bitenler, süresi dolan belgeler (pasaport/vize), boş pozisyonlar, kişi arama, resmi tatiller. Toplam 20 hazır istek.
- **Notlarda konu rehberi:**
  - hangi soru hangi varlıkta,
  - aktif çalışan süzmesi (`emplStatusNav/externalCode eq 'A'`, tüm şirketlerde 1382 kişi; 1000 sınırı nedeniyle şirket bazında),
  - picklist Türkçe etiketleri,
  - nesne ve liste ilişkilerinin yanıttaki yapısı,
  - jq'da `sfdate` tanımı (`/Date(ms)/` → `yyyy-MM-dd`, Kestra'da doğrulandı),
  - bugünün tarihini jq'ya Pebble `~` ile verme,
  - jq'da çalışan ve çalışmayan fonksiyonlar.
- **Uçtan uca deneme** (sohbetten akış, sonra Kestra'da gerçek veri), hepsi ilk denemede SUCCESS:
  - "GDA'da bu ay doğum günü olanlar": 6 kişi, gün sırasıyla.
  - "Son 30 günde işten ayrılanlar, şirket ve nedenle": 22 kişi.
  - "Önümüzdeki 3 ayda dolacak pasaport/belgeler": 70 belge, Türkçe belge türüyle.
  - "GDA'da departmanlara göre aktif çalışan": 98 kişi, departman dağılımı.

**Açık konular**
- ~~Çalışan sayıları işten ayrılmış çalışanları içerebilir~~ → aktif süzmesi eklendi (`emplStatusNav/externalCode eq 'A'`).
- Yazma işlemleri (e-posta veya kullanıcı adı güncelleme) kapalı; açmak kullanıcı kararı.
- Kişisel veri: kullanıcı kararıyla (2026-10-05) ad, soyad, telefon gibi bilgiler loglarda ve sohbette gösterilebilir. Sadece TC kimlik gizli:
  - Doğrulayıcı `nationalId` geçen SuccessFactors isteklerini reddediyor (`PerNationalId`, `nationalIdNav`, URL kodlu hali dahil).
  - `describe_successfactors` bu alanları ve ilişkileri göstermiyor.
  - Hazır istek `sf_calisan_listesi` artık TC kimlik çekmiyor.
  - Kestra'dan okunan çalışma bilgisi ve loglarda geçerli TC kimlik numaraları (kontrol haneli 11 hane) `***********` olarak maskeleniyor (`NationalIdMasker`). Telefon gibi diğer sayılar maskelenmiyor.
  - Not: Kestra'nın kendi arayüzünde ham veri görünebilir; Fortis'in gösterdiği her yer maskeli.
- Maliyet: "şirket bazında çalışan sayısı" sohbeti 5 çağrı, kendi hesabımızla ~0,13 USD.

## APM entegrasyonu (2026-10-05, kod hazır, gerçek bağlantı bekliyor)

**Kaynak:** `apm.md` (OpenAPI 3.1, 111 uç), repoda `docs/integrations/apm-openapi.json` olarak duruyor. İçerik: uçuş bacakları (legs), uçaklar, ekip, havalimanları, gecikmeler, uçuş logları, zamanında kalkış/varış raporları.
- Kimlik: `POST /oauth/token` (form, `client_credentials` veya `password`) ile JWT, sonraki isteklerde Bearer.
- Sunucu iç ağda: `apm-test01.gozen.systems:64070` (`172.31.68.9`). Mac'ten erişiliyor (401 döner); Kestra sunucusu adı çözemiyor ve ulaşamıyor, bu yüzden tünel gerekiyor.

**Kurulanlar**
- **Ayarlar:** `backend/config/apm.php`, `.env` içinde `APM_*` alanları.
  - `APM_KESTRA_BASE_URL=http://172.17.0.1:14070/test-restapi` (tünel adresi).
  - Kimlik bilgileri boş; doldurulmalı.
- **`ApmApi` servisi:** spec'ten okur.
  - Adresi spec'teki uçla eşleştirir; `{id}` ve `{{ }}` segmentleri eşleşir.
  - Okuma işlemlerini listeler: yazma kapalıyken 81 işlem (GET'ler, token ve iki rapor POST'u).
  - Bir işlemin parametrelerini ve yanıt alanlarını düz satırlar olarak verir.
- **Claude aracı `describe_apm`:**
  - path verilmezse: taban adres, token görevi kalıbı, gereken secret'lar, işlem listesi.
  - path verilirse: işlemin parametreleri ve yanıt alanları.
- **Doğrulayıcı:**
  - APM tünel adresi iç IP engelinden bilerek muaf; yerine APM kuralları uygulanıyor.
  - Uç spec'te olmalı. `..`, `.` ve kodlanmış bölü yasak.
  - **Yazma kapalı** (`APM_ALLOW_WRITES=false`).
  - Token görevi: form içeriği, doğru `grant_type`, kimlik bilgileri yalnızca secret.
  - Diğer istekler: `options.auth` BEARER, token aynı akıştaki token görevinin `access_token` alanından.
  - **Token sızıntısı engeli:** token görevinin çıktısı (veya tüm `outputs`) yalnızca APM isteklerinin `options.auth.token` alanında kullanılabilir. Log, bildirim ve dış adrese istek reddediliyor.
  - Proxy alanları APM isteklerinde de yasak.
- **Kart:** "APM'ye giriş yapıyor (token alır)", "APM'den veri okuyor: GET /api/legs — özet".
- **Prompt ve örnek:** `system.md` içinde APM bölümü ve örnek `08-apm.yml` (token, dünkü bacaklar, sayı, döngüyle rota). Örnek Kestra'nın doğrulamasından geçti.
- **Testler:** 208 test, hepsi geçiyor. `ApmTest` (11 test).
- **Durum (2026-10-05): çalışıyor.** Doğru client bilgileriyle token alınıyor (`client_credentials`, 1 saat).
  - Secret'lar: `APM_CLIENT_ID`/`APM_CLIENT_SECRET` Kestra'da tanımlı.
  - Uçtan uca: örnek akış (08) Kestra'da çalıştı; dünkü 64 bacak rotalarıyla loglandı.
  - Tarih biçimi: `dateFrom`/`dateTo` sadece ISO UTC kabul ediyor (`2026-10-04T00:00:00Z`); başka biçimde "Date from is mandatory" hatası geliyor.
  - Sayfalama: sayfa başına 100 kayıt, `page` 1'den başlıyor. ForEach ve Pebble `{% for %}` ile toplama kalıbı Kestra'da doğrulandı (5 sayfa, 440 bacak).
  - Claude aracı `preview_apm`: Laravel, VPN üzerinden `APM_BASE_URL=http://172.31.68.9:64070/test-restapi` adresine kendi token'ıyla bağlanıp isteği deniyor; listeler ilk 3 elemana kısaltılıyor.
  - Test ortamı verisi: gerçekleşen saatler ve uçuş logları çoğunlukla boş, planlanan saatler dolu.
- **Kalan:** sohbetten APM sorusuyla uçtan uca deneme (Claude API kredisi bekleniyor).

**Kalanlar**
- [x] `backend/.env`: APM kimlik bilgileri
- [x] Tünel (`-R 172.17.0.1:14070:172.31.68.9:64070`)
- [x] Kestra secret'ları, `KESTRA_SECRETS`, Kestra yeniden başlatma
- [x] Token biçimi, tarih biçimi, Kestra'da uçtan uca çalıştırma
- [ ] Sohbetten APM denemesi

---

## Arayüz: görsel akış editörü, çalışmalar, kullanım ve maliyet (2026-10-06)

Plan: [fortis-arayuz-gelistirme.md](fortis-arayuz-gelistirme.md), tasarım: `fortis-tasarim/`.

| Faz | Durum | Nasıl doğrulandı |
|---|---|---|
| U1 Akışlar listesi + tuval + YAML görünümü | Tamam | Gerçek akışlar tuvalde; OutputValues, KV, Labels, Fail gibi blok karşılığı olmayanlar "Gelişmiş" (salt okunur) |
| U2 Blok ayarları, doğrulama, taslak sürüm | Tamam | Tarayıcıda parametre değişti (tek istek, v2); APM'de olmayan uç → blok kırmızı, kaydedilmedi |
| U3 Palet, ekle/sil/taşı, tetikleyici | Tamam | Döngü içine blok eklendi (v3). Teams bloğu Playwright senaryosunda; gerçek ortamda Teams secret'ı olmadığı için "Bağla" altında |
| U4 Yayınla, test çalıştır, sürümler, fark, geri yükleme | Tamam | Demo akış yayınlandı, kuru çalıştırma gerçek APM verisiyle başarılı; sürüm farkı Monaco DiffEditor |
| U5 Editör içi chat + öneri farkı | Tamam | Gerçek Claude ile: öneri tuvalde sarı/kırmızı göründü, Uygula ile yeni taslak sürüm (sohbet ~$0.09) |
| U6 Çalışmalar, Devam/Reddet, hata açıklama | Tamam | Onay bekleyen test çalışması arayüzden devam ettirildi; "AI'a hatayı açıklat" gerçek hatayı açıkladı |
| U7 Model, maliyet kolonları, kullanım, bütçe | Tamam | Ekran `ai_calls` verisiyle; bütçe %100'de sohbet 402 (testte) |

**Backend**
- Blok modeli `config/kestra-catalog.json` → `blocks` (19 blok) ve `apps`. `App\Services\Flows\Blocks`:
  - `BlockCatalog`: görev ↔ form değerleri. Güvence: görev ancak `toTask(fromTask(görev)) == görev` ise bloğa çevrilir; değilse "Gelişmiş" kalır. Böylece tuval hiçbir alanı sessizce kaybetmez; bilinmeyen alanlar (`retry`, `timeout`) korunur.
  - `FlowGraph` (YAML → graf), `FlowEditor` (işlemler: update_settings, insert, delete, move, update/insert/delete_trigger), `ErrorLocator` (doğrulayıcı hatalarını `task_id`'ye bağlar), `ExecutionTimeline`.
- Tüm kaydetme yolları `FlowService::recordEdit` → `FlowValidator`: tuval, YAML editörü, geri yükleme, chat önerisi. `base_version` eskiyse 409.
- `flow_versions.source` (chat/canvas/yaml/restore) ve `created_by`; `flow_proposals` tablosu; `conversations.model` ve `kind` (`editor` sohbetleri listede görünmez); `ai_calls.source/flow_id/execution_id`; `settings` tablosu; `executions.is_test`.
- Test çalıştır: `DryRun` e-posta/Teams/Slack/GET dışı istek/alt akışı aynı id'li Log'a çevirir, tetikleyicileri kaldırır, `fortis.chat.test` namespace'ine yükler (kopya da yükleme modunda doğrulanır).
- Çalışmalar: liste Kestra'dan (`PREFIX` filtresi), Kestra id'siyle açılan zamanlanmış çalışmalar içe aktarılır. Görev çıktıları saklanmaz, sadece özet ("37 kayıt", "HTTP 200"). Kestra 1.3 yanıtı çıktıları (kişisel veri) içeriyor; bu yüzden önemli.
- Hata açıklama (`ExecutionExplainer`): Claude'a sadece görev adı ve o görevin son ERROR log mesajı gider; test gönderilen içerikte diğer log satırları ve çıktılar olmadığını doğruluyor.
- Model: `config/ai.php` (fiyatlar Anthropic tablosuyla doğrulandı; maliyet çağrı anında yazılır). Sohbet bazında model, genel varsayılan `PUT /api/settings/ai`; izinli liste dışı 422.
- Bütçe: %80'de `FORTIS_ADMIN_EMAILS`'e ayda bir e-posta (boşsa log uyarısı), %100'de yeni sohbet ve hata açıklama 402.
- Akışlar listesine kaynaklar, taslak durumu ve bu ayki maliyet eklendi.

**Frontend** (React 19 + Vite, Tailwind v4): `@xyflow/react`, `@tanstack/react-query`, `@monaco-editor/react` (Monaco CDN'den değil paketten), `recharts`. Hash yönlendirme: `#/chat`, `#/flows`, `#/flows/:id`, `#/runs/:id`, `#/usage`. Eski akışlar sayfasındaki işlemler editörün `⋯` menüsünde (durdur/aktif yap, sil); İstek listesi Akışlar sayfasında sekme.

**Testler**
- Backend 256 test (yeni: `FlowEditorTest` 30 (11 fixture'ın gidiş-dönüşü dahil), `UsageTest` 7, `RunsTest` 5). Fixture'lar `tests/fixtures/flows/`.
- Frontend: `npm test` (Vitest, 11 test), `npm run e2e` (Playwright, sahte API ile dokümandaki senaryo; sistem Chrome'u kullanır).

**Dokümandan sapmalar ve nedenleri**
- `/api/internal/*` uçları yok: Kestra Laravel'e erişemiyor (Laravel bu Mac'te, tünel sadece Kestra → IBS/APM yönünde). Veri ve bildirim blokları mevcut gerçek görev kalıplarına (APM token + Bearer, SuccessFactors OData, IBS JDBC, MailSend, Teams/Slack webhook) bağlandı. **AI adımı blokları pasif**; açmak için Kestra → Laravel tüneli (ör. `-R 172.17.0.1:18000:127.0.0.1:8000`) ve iç uç gerekir.
- Bağlantılar: OAuth / `app_connections` yapılmadı. "Bağlı" = gerekli Kestra secret'ları `KESTRA_SECRETS`'ta tanımlı. "Bağla" yöneticinin eklemesi gereken secret adlarını gösterir. Microsoft Graph aksiyonları (kişiye mesaj, onay kartı) Entra ID uygulaması gelene kadar pasif.
- Yerleşim dagre yerine deterministik özyinelemeli fonksiyonla (`editor/layout.ts`): konteynerler grup kutusu ve dikey ağaç; dagre'nin compound modunda grup düğümleri kenar alamıyor. Dagre paketi kurulu ama kullanılmıyor.
- Sürükleme sırayı değiştirir (en yakın `+` noktasına bırakma); serbest konum ve `flow_versions.layout` kolonu yok. Klavye için panelde "Yukarı/Aşağı taşı".
- Formlar `@rjsf` yerine JSON Schema'dan kendi küçük form bileşeniyle (`SchemaForm.tsx`).
- Testler Pest değil PHPUnit (projenin mevcut düzeni). `ai_calls` önbellek kolonları mevcut adlarıyla (`cache_creation_input_tokens`, `cache_read_input_tokens`).

**Bilinen sınırlar**
- ~~Akışlar sayfası ~5-9 sn~~ ve ~~Kestra 1.3 tarih filtresini yok sayıyor~~: düzeltildi, bkz. "Akışlar ve çalışmalar ekranlarının hızı".
- Çalışma ayrıntısındaki adım-blok eşleşmesi, zamanlanmış çalışmalarda Kestra revision'ı eşleşen sürümle; test çalışmalarında o anki son sürümle.
- Bu Mac'te `127.0.0.1:5173`'te başka bir projenin Vite'ı da çalışıyor; tarayıcı `localhost:5173`'te ona gidebiliyor. Fortis için `http://[::1]:5173` kullanın.
- Tarayıcı denemesinde oluşturulan demo akış (`editor-demo-delays`) ve test çalışmaları Kestra'dan silindi.

---

## Rapor dosyaları (Excel / CSV / PDF) ve sonuçların Claude'dan ayrılması (2026-10-06)

Plan: [fortis-rapor-excel-pdf.md](fortis-rapor-excel-pdf.md). Mevcut yapıya uyarlandı: Kestra Laravel'e ulaşamadığı için rapor **Kestra bloğu değil, Claude aracı** (`create_report`) ile üretiliyor. Veriyi Laravel kendisi çekiyor (IBS, SuccessFactors, APM), dosyayı Laravel üretiyor.

**Doğrulama (tarayıcıda, gerçek Claude ve APM ile):** "5 Ekim'deki uçuşları Excel olarak ver" → Claude `list_report_sources` + `create_report` çağırdı, 56 satırlık Excel kartı çıktı, Claude sadece toplamlarla özetledi ("20'si AYT'den"). Araç sonucunda uçuş numarası yok (veritabanından kontrol edildi). İmzalı indirme Vite proxy'si üzerinden çalıştı; başka sohbetten bağlantı 403.

**Sonuçların Claude'dan ayrılması (bölüm 5)**
- `App\Services\Claude\ExecutionSummaryForAi`: Claude'a giden çalışma ve rapor sonuçlarının tek dönüşüm noktası.
- `get_execution` artık sadece `status`, `duration_seconds`, `row_count`, `report_id`, `aggregates` ve temizlenmiş `error` döndürüyor. **Loglar, girdiler, döngü değerleri (`taskrun.value`) ve çıktılar gitmiyor** (önceden son 50 log satırı, girdiler ve döngü değerleri gidiyordu; isimler bu yoldan sızabiliyordu).
- Hata: görev adı + tür (`HTTP_400`, `CONNECTION`, `QUERY`, `EXPRESSION`, `TRANSFORM`, `FAILED_BY_FLOW`, `KILLED`, `UNKNOWN`) + Fortis'in yazdığı sabit açıklama. Ham Kestra metni gitmiyor. Sadece yapısal parçalar desenle süzülerek ekleniyor: HTTP kodu, `validationErrors[].field` adları, SQLSTATE, değişken adı.
- "AI'a hatayı açıklat" da aynı temizlenmiş hatayı gönderiyor.
- `preview_apm` / `preview_successfactors` (akış tasarlarken örnek kayıt gösteren araçlar) değişmedi; 5 Ekim kararı geçerli.

**Rapor üretimi**
- Kaynak kataloğu `resources/reports/sources.json` (13 kaynak). APM uçuşlar, IBS hazır sorguları (doluluk, satış, kanal, acente, ödeme, bilet), SuccessFactors hazır istekleri (izinler, işe girenler, ayrılanlar, aktif çalışanlar, belge bitişleri, boş pozisyonlar). Her alanın Türkçe başlığı, tipi ve `aggregatable` işareti var. TC kimlik, telefon ve doğum tarihi yok. Kişi adı, sicil ve uçuş no hiçbir zaman toplanabilir değil.
- `App\Services\Reports\ReportSources` (doğrulama, çekme, sayfalama, alan yolları, tarih dönüşümü), `ReportService`, `Generators/{Xlsx,Csv,Pdf}Generator`, `ReportTable`, `App\Jobs\GenerateReportJob`.
- Excel (PhpSpreadsheet): kalın başlık, filtre, sabit ilk satır, otomatik genişlik, gerçek Excel tarihleri (`dd.mm.yyyy hh:mm`, Türkiye saati). CSV: UTF-8 BOM, `;`. PDF (dompdf, `resources/views/reports/pdf.blade.php`): DejaVu Sans, yatay A4, `group_by` özet kutusu, sayfa numarası, 6'dan fazla sütunda küçük punto.
- Claude'a dönen: `report_id`, durum, satır sayısı, `aggregates` (toplam, kategori sayımları en fazla 10 + "Diğer", sayı toplamları).
- Sınırlar: `REPORT_MAX_ROWS` (50.000) aşılırsa rapor üretilmez, mesajda daraltma önerilir. 5.000 satırın üstü kuyruğa gider (`QUEUE_CONNECTION=database`; **`php artisan queue:work` çalışmıyorsa büyük raporlar "Hazırlanıyor"da kalır**).
- Dosyalar `storage/app/private/reports/{yyyy}/{mm}/{id}.{ext}`. 7 gün sonra `reports:prune` (günlük zamanlanmış) siler ve kaydı `expired` yapar; kartta "Yeniden oluştur".
- Tablolar: `reports` (id `rpt_<ulid>`), `report_downloads`.
- Uçlar: `GET /api/reports/{id}`, `POST /api/reports/{id}/download-link` (sohbet kontrolü), `GET /api/reports/{id}/download` (imzalı, 15 dk, isteyen IP'ye bağlı, her indirme kayıtlı), `POST /api/reports/{id}/regenerate`.
- Chat'te rapor kartı (`ReportCardView`): Hazırlanıyor (3 sn yenileme), Hazır (İndir), Hata (Tekrar dene), Süresi doldu (Yeniden oluştur).
- Paketler: `phpoffice/phpspreadsheet`, `dompdf/dompdf`; geliştirme için `smalot/pdfparser`.

**Testler:** backend 269 (yeni `ExecutionSummaryForAiTest` 3, `ReportTest` 10; testler 1-9 ve 11 karşılığı), Vitest 13. `phpunit.xml`'de bellek sınırı 512M'e çıkarıldı (tam paket 128M'e takılıyordu).

**Dokümandan sapmalar**
- `POST /api/internal/reports`, `X-Fortis-Key` ve Kestra rapor bloğu yok: Kestra Laravel'e erişemiyor. Bu yüzden test 10 uygulanmadı. Zamanlanmış akışta dosya üretme, e-posta/Teams'e ek gönderme ve "Bunu her gün otomatik al" bağlantısı da yok. Açmak için Kestra'dan Laravel'e tünel ve `FORTIS_INTERNAL_KEY` secret'ı gerekiyor.
- Kullanıcı sistemi yok: `user_id` yerine sahiplik sohbete bağlı. İndirme bağlantısı imzalı, kısa süreli ve isteyen IP'ye bağlı. Test 7'deki "başka kullanıcı" durumu "başka sohbet" ve "başka istemci (IP)" olarak test ediliyor. Laravel bir ters proxy arkasına alınırsa `TrustProxies` ayarlanmalı.
- Klasör `app/Reports` yerine projenin düzenine uygun olarak `app/Services/Reports`. Excel için maatwebsite/excel yerine doğrudan PhpSpreadsheet, PDF için doğrudan dompdf.
- Testler PHPUnit. Test 1, Anthropic SDK Laravel `Http`'sini kullanmadığı için `Http::fake` ile değil, Claude'a giden `messages` gövdesi `ConversationHistory` ile kurularak kontrol ediliyor.

---

## Akışlar ve çalışmalar ekranlarının hızı (2026-10-06)

**Neden:** Kestra 1.3'ün çalışma araması her çalışmayı görev çıktılarıyla birlikte döndürüyor. Örneğin SuccessFactors yanıt gövdeleri bunun içinde: 20 çalışma 4,4 MB, 200 çalışma 6 MB, Mac'e taşınması 3-6 sn. Kestra'nın CSV dışa aktarması da görev listesini içeriyor; 1.3'te çıktısız listeleme yolu yok (2.0'da çıktılar yanıttan kaldırılmış).

| Uç | Önce | Sonra | Nasıl |
|---|---|---|---|
| `GET /api/flows` | 3,9 sn | 0,5 sn | Son çalışmalar `POST executions/latest` ucundan (14 akış için 2 KB) |
| `GET /api/usage/summary` (kenar çubuğu her sayfada) | 4,4 sn | 0,15 sn | Aylık çalışma sayısı tek sorgu: `size=1`, `filters[startDate][GTE]` + `filters[endDate][LTE]` |
| `GET /api/executions` | 3,4 sn | 0,2 sn | Yerel dizin (`RunsIndex`) |

- **`RunsIndex`:** Çalışmalar `executions` tablosunda tutuluyor.
  - İlk açılışta geçmiş bir kez tamamen alınıyor (yaklaşık 5 sn, `settings.runs_index.backfilled_at`).
  - Sonraki eşitlemeler en fazla 10 sn'de bir yapılıyor: son kayıttan sonra başlayan çalışmalar ve bitmemiş en fazla 20 çalışmanın durumu çekiliyor.
  - Liste, filtre sayıları ve sayfalama yerel tablodan geliyor; dizine sadece özet yazılıyor (durum, zaman, süre, tetikleyici).
  - Kestra'ya ulaşılamazsa son bilinen liste uyarıyla gösteriliyor.
- **Düzeltilen yanlış not:** Kestra 1.3 tarih filtresini destekliyor; eski denemede başlangıç tarihi tüm çalışmalardan önce olduğu için fark görünmemişti. Aynı alana iki filtre (`startDate` GTE + LT) birleşmiyor; aralığın sonu `endDate` ile veriliyor.
- **Değişen davranış:** Listede sadece Fortis'in bildiği akışların çalışmaları var; silinmiş eski test akışlarının 18 çalışması artık görünmüyor. Akışlar listesindeki son çalışmada tetikleyici bilgisi yok (latest ucu döndürmüyor).
- **Kalan:** Çıktısı büyük tek bir çalışmanın ayrıntısını açmak hâlâ o çalışmanın boyutuna bağlı. Kestra 2.0.4'e geçişte bu da kalkar.

---

## Sesli komut (Whisper ile ses → metin) (2026-10-07)

Plan: [fortis-sesli-komut.md](fortis-sesli-komut.md). V1–V5 tamam.

| Faz | Durum | Nasıl doğrulandı |
|---|---|---|
| V1 Whisper servisi + `/health` | Tamam | `curl` ile Türkçe `.wav` → `{"text":"bunu 180 dakika yap", ...}`; pytest 11 test |
| V2 Laravel ucu, doğrulama, silme, hız sınırı, ölçüm tablosu | Tamam | PHPUnit `VoiceTest` 10 test; wav/webm/mp4 Laravel üzerinden gerçek Whisper'a gitti, `storage/app/tmp/voice/` boş |
| V3 Mikrofon butonu, durumlar, metnin kutuya yazılması | Tamam | Chrome'da gerçek MediaRecorder (webm/opus) → kutuda "Bunu son altmış güne çıkar." ve "Sesten çevrildi" etiketi; gönderilmedi |
| V4 Seçili blok bağlamı | Tamam | Editörde `leavers` seçiliyken sesli "Bunu son 60 güne çıkar" → Claude doğru bloğu (ve log mesajını) değiştiren öneri üretti (~$0.09). Öneri geri alındı, akış v1'de kaldı |
| V5 Bas-konuş, sessizlikte durma, otomatik gönder | Tamam | Sessizlikte durma tarayıcıda; bas-konuş ve otomatik gönder Vitest'te (tarayıcı otomasyonu tuşu basılı tutamıyor) |

**Whisper servisi** (`infra/whisper/`: `app.py`, `Dockerfile`, `docker-compose.yml`, `tests/`)
- FastAPI + faster-whisper, `small`, CPU, int8, `language=tr`, `beam_size=1`, `vad_filter`, sözlük `initial_prompt` olarak. Model açılışta bir kez yüklenir; eşzamanlı çeviri `WHISPER_MAX_CONCURRENT` (2) ile sınırlı.
- ffmpeg ile 16 kHz mono'ya çevrilir; 30 sn veya 5 MB üstü 413, çözülemeyen ses 422. Geçici dosya `finally`'de silinir. Logda sadece `duration_s` ve `processing_ms`.
- `faster-whisper` 1.1.1 `requests`'i bağımlılık olarak tanımlamıyor; `requirements.txt`'e eklendi.
- **small / medium karşılaştırması** (aynı kayıtlar, bu Mac, Intel CPU): small ~2,5 sn, medium ~7,5 sn; medium bu kayıtlarda daha doğru değil. Sözlük (prompt) small'da "rötarlı", "e-posta" gibi kelimeleri düzeltiyor. Karar: small. 6,2 sn'lik komut 2,6 sn'de çevriliyor.
- Test kayıtları (`tests/fixtures/voice/`) şimdilik macOS sentetik sesi (Yelda) ile üretildi; sentetik ses İngilizce kelimeleri Türkçe okuyor ("Teams'e" → "timze", "Excel" → "e-gizel"). Ortalama kelime hata oranı %15,5 (<%20). **Ekip kendi sesiyle kaydedip değiştirmeli.**

**Laravel**
- `POST /api/voice/transcribe` (`VoiceController`, `App\Services\Voice\{WhisperClient, TranscriptFilter, VoiceException}`, `config/voice.php`): tür (dosya içeriğinden), 5 MB, `throttle:voice` (dakikada 20). Ses `storage/app/tmp/voice/`'a taşınır, Whisper'a gider, `finally`'de silinir. Halüsinasyon listesi → `{ "text": "", "reason": "no_speech" }`. Whisper kapalı/hatalı → 503 "Sesli komut şu an kullanılamıyor, yazarak devam edebilirsin".
- `GET /api/voice/config` → `{enabled, max_seconds}`; `VOICE_ENABLED=false` iken buton görünmez, uç 404.
- `voice_transcriptions`: `duration_s`, `processing_ms`, `char_count`, `was_edited`, `created_at`. Metin yok. `was_edited` mesaj gönderilirken (`voice: {transcription_id, edited}`) yazılır.
- Mesaj isteğinde `context.selected_task_id`: editör sohbetinde blok güncel taslak sürümden bulunur, Claude'a `[Kullanıcı tuvalde şu bloğu seçmiş: leavers (Veri · SuccessFactors)]` notu gider (başlık varsa `, "Başlık"`). Yazılı mesajlar için de geçerli.

**Arayüz** (`src/voice/`)
- `MicButton` (hazır / kayıt: kırmızı nokta, seviyeye göre büyüyen halka, `0:04 / 0:30` / "Metne çevriliyor…" / hata 3 sn), `useVoiceRecorder` (webm/opus, Safari'de mp4; 30 sn sınırı; konuştuktan sonra 1,5 sn sessizlikte durur), `useVoiceDraft` (kutuya yazar, imleç sonda, "🎤 Sesten çevrildi, kontrol et", otomatik gönder geri sayımı), `VoiceHint`.
- Ana chat ve editördeki "AI ile düzenle" panelinde. Bas-konuş: metin kutusu/buton odakta değilken boşluk basılı tutulur. Esc kaydı ve çeviriyi iptal eder.
- Editörde "Seçili: <blok> ✕" çipi; ✕ bağlamı kaldırır (başka blok seçilince çip geri gelir).

**Testler:** backend 279, Vitest 20 (yeni 7: durum sırası, kutuya yazılıp gönderilmemesi, Esc, bas-konuş, odaktayken boşluk, otomatik gönder ve iptali, kapalıyken buton yok, seçili blok çipi ve bağlamı), Playwright 1, Whisper pytest 11.

**Dokümandan sapmalar ve nedenleri**
- Klasör `services/whisper/` yerine `infra/whisper/` (altyapı `infra/` altında). PHP sınıfları `app/Voice` yerine `app/Services/Voice`.
- Geliştirmede Whisper Docker'sız, yerel venv ile çalışıyor (Laravel ve Vite gibi). Compose dosyası sunucu için hazır, **denenmedi**. Laravel host'ta çalıştığı için compose portu sadece `127.0.0.1:9000`'e açıyor; Laravel aynı Docker ağına girerse `ports` kaldırılıp `WHISPER_URL=http://whisper:9000` yapılmalı.
- Kullanıcı sistemi yok: hız sınırı IP başına, tabloda `user_id` yok.
- Seçili bloğun türü ve başlığı istemciden alınmıyor; Laravel akıştan okuyor (istemci sadece `selected_task_id` gönderiyor).
- İzinli türlere içerikten algılanan karşılıklar eklendi: MediaRecorder webm'i `video/webm`, Safari mp4'ü `video/mp4`/`audio/x-m4a`, wav `audio/x-wav` olarak algılanıyor.
- "Otomatik gönder" ayarı tarayıcıda (`localStorage`) tutuluyor; kişi bazlı ayar yeri yok. Ana chat'in yazma alanının altında.
- Testler Pest değil PHPUnit. Whisper doğruluk eşiği kayıt başına değil ortalama üzerinden.
- Editördeki chat paneli, bas-konuş her sekmede çalışsın diye gizli olarak bağlı kalıyor; kayıt başlayınca "AI ile düzenle" sekmesi açılıyor. Bu yüzden Playwright senaryosunda bir seçici `getByRole`'a çevrildi.

**Açık sorular (dokümandan)**
- Canlıda Whisper hangi sunucuda: karar bekliyor.
- Mobil tarayıcı: denenmedi. `getUserMedia` sadece HTTPS'te (veya localhost'ta) çalışır; canlıda Fortis HTTPS arkasında olmalı.

---

## Agentlar ekranı (2026-10-12)

İstek: yöneticinin agentları Gumloop'taki gibi görebildiği bir ekran. `#/agents` (menüde "Sohbet"in altında).

- **Agent = Fortis'teki bir akış.** Yeni bir agent kavramı veya tablo yok; ekran mevcut akış verisini Gumloop'un agent düzeniyle gösteriyor: talimat (akış özeti), araçlar (kaynaklar ve kanallar: APM, IBS, SuccessFactors, E-posta, Teams...), görevler (tetikleyiciler; cron "Her pazartesi 08:00" gibi Türkçe), geçmiş (son çalışmalar).
- Üstte: yayındaki agent sayısı, son 30 gün çalışma, başarı oranı, agentların bu ayki AI maliyeti. Durum filtresi (Tümü / Yayında / Hatalı / Taslak / Durduruldu) ve arama (başlık ve açıklamada).
- Kart → sağda panel (`#/agents/:id`, Esc kapatır): talimat, araçlar, görevler, 30 günlük sayılar, son 8 çalışma; "Düzenle" editörü, "Oluşturulduğu sohbet" chat'i açar.
- Backend: `GET /api/flows` yanıtına `runs_30d` {total, success, failed} eklendi; yerel çalışma dizininden tek sorgu, test çalıştırmaları hariç.
- "Akışlar" sayfası olduğu gibi duruyor.
- Testler: backend 280, Vitest 31 (yeni: `AgentsPage.test.tsx` 3, `schedule.test.ts` 2), Playwright 1. Tarayıcıda gerçek veriyle bakıldı (14 agent, 49 çalışma).
- Not: akışlar sabit adımlı Kestra iş akışları; Gumloop'taki gibi kendi karar veren agentlar değil. Akış içindeki AI adımları açılınca kartlarda gösterilebilir.

---

---

## Fortis Aero — Faz A0: Temel (2026-10-12)

Plan: [docs/fortis-aero-faz-a0.md](docs/fortis-aero-faz-a0.md), yol haritası: [docs/fortis-aero-yol-haritasi.md](docs/fortis-aero-yol-haritasi.md). Tasarım PDF'i `docs/design/fortis-aero-product-screens.pdf` altına kopyalandı.

**Giriş ve kullanıcılar (backend)**
- Paketler: `laravel/sanctum` (SPA çerez oturumu), `laravel/socialite` + `socialiteproviders/microsoft` (Entra iskeleti).
- `AUTH_DRIVER=password|entra` (`config/fortis.php`). Kayıt yok; kullanıcıyı admin ekler.
- Tablolar: `departments`; `users`'a `department_id`, `role`, `title`, `avatar_color`, `is_active`, `must_change_password`, `theme`, `last_login_at`, `entra_object_id`; `audit_logs`. Şifre nullable (Entra kullanıcıları).
- Sahiplik: `conversations.user_id`, `flows.owner_id/department_id`, `executions.triggered_by_user_id`, `ai_calls.user_id/department_id`, `reports.user_id`, `artifacts.user_id`. Yeni kayıtlarda `HasOwner` doldurur (oturumdaki kullanıcı, yoksa sohbetin sahibi). Eski kayıtlar ilk admin'e atandı (`Ownership::backfill`; akış adımı AI çağrıları hariç).
- Uçlar: `GET /api/auth/config` (açık), `POST /api/auth/login` (açık), `POST /api/auth/logout`, `GET /api/auth/me`, `PATCH /api/me/preferences`, `POST /api/me/password`, `GET/POST/PATCH /api/admin/users`, `GET/POST/PATCH /api/admin/departments`, `GET /api/auth/entra/{redirect,callback}` (routes/web.php, oturum gerektiği için).
- Diğer bütün `/api/*` uçları `auth:sanctum` + `active` (pasif kullanıcının oturumu kapanır) + `password.changed` (geçici şifre değişmeden 403) arkasında.
- Yetki route seviyesinde Gate/Policy ile: `can:admin` (ayarlar/bütçe güncelleme, kullanıcı ve departman yönetimi), `can:build` (akış yükleme, düzenleme, yayınlama, test çalıştırma, açma/kapama, silme, öneri uygulama), `ConversationPolicy`/`ReportPolicy`/`ArtifactPolicy` (sahibi ve admin; başkasınınki 404).
- Giriş: 5 hatalı denemede e-posta+IP için 60 sn kilit (429); mesaj hangi alanın yanlış olduğunu veya hesabın pasif olduğunu söylemez. Oturum çerezi HttpOnly, SameSite=Lax, canlıda Secure (`SESSION_SECURE_COOKIE`, varsayılan APP_ENV=production).
- Sohbet listesi kullanıcının kendi sohbetleri; admin `?all=1` ile hepsini alır. Editör sohbeti artık kullanıcı başına.
- Denetim kaydı (`App\Services\Auth\Audit`): giriş, çıkış, başarısız giriş, akış yayınlama (editör + chat kartı), çalışma onay/ret, rapor indirme (`report_downloads` korunuyor), ayar değişikliği, kullanıcı ve departman ekleme/değiştirme, şifre değiştirme. `AuditLog` güncellenemez/silinemez.
- `php artisan fortis:user:create <e-posta> --role= --department= --name= --title= [--password=]`; `php artisan db:seed` departmanları ve `FORTIS_ADMIN_EMAIL` admin'ini oluşturur, geçici şifreyi konsola yazar.
- Yerel ortamda: `FORTIS_ADMIN_EMAIL=admin@fortis.local` ile admin oluşturuldu (geçici şifre seed çıktısında, ilk girişte değiştirilecek). `APP_LOCALE=tr`.

**Kabuk ve tema (frontend)**
- Kenar çubuğu tasarımdaki gibi: Yeni sohbet, Sohbetler, Gelen kutusu, Yapılacaklar, Agentlar, Oluştur (builder/admin), Bağlantılar, Çıktılar; Çalışma alanı: Paylaşılan agentlar, Yönetişim, Çalışmalar, Bellek, Bilgi, Beceriler; son 5 sohbet; bu ayın harcaması/bütçe kutusu; Açık/Koyu anahtarı; kullanıcı menüsü (Profil, Tema: sistem/açık/koyu, admin için Kullanıcılar/Departmanlar/Ayarlar, Çıkış). Daraltılabilir (tercih `localStorage`), editörde kendiliğinden dar, telefonda çekmece.
- Yeni rotalar: `#/login`, `#/build` (eski `#/flows`), `#/governance` (`#/usage` yönlenir), `#/inbox`, `#/actions`, `#/connectors`, `#/artifacts`, `#/shared`, `#/memory`, `#/knowledge`, `#/skills` ("Yakında" boş durum), `#/profile`, `#/admin/users`, `#/admin/departments`. Girişsiz her rota `#/login?next=…`'e gider, girişten sonra geri döner. Geçici şifreli kullanıcı önce şifre değiştirme ekranını görür.
- Tema token'ları `src/index.css`'te (renkler PDF'ten ölçüldü; açık `#B42A3F`, koyu `#D2404F` dolgu / `#EC6A7C` metin). Metin token'ları iki temada ≥ 4.5:1. Bileşenlerde sabit renk kodu kalmadı (blok tonları, grafik ve durum renkleri dahil). React Flow `colorMode`, Monaco `vs-dark` ile uyumlu. İlk çizimde yanıp sönme olmasın diye `index.html`'de tema script'i.
- Yazı tipleri: Source Serif 4 (başlıklar), Geist (gövde), Geist Mono (sayı/saat). Logo: `src/assets/logo.svg` (favicon da).
- İstekler `src/http.ts` → `apiFetch`: çerez, `X-XSRF-TOKEN`, 401'de girişe dönüş.
- i18n: `src/i18n/{tr,en}.json` + `t()`. Dil `/api/auth/config`'ten (`config('app.locale')`).

**Testler:** backend 316 (296 geçti, 20 atlandı: ambar/VPN); yeni: `AuthTest` (rota taraması dahil), `RolesTest`, `OwnershipTest`, `EntraTest` ve denetim testleri (`FlowEditorTest`, `ExecutionTest`, `ReportTest`). Var olan testler `Tests\Concerns\SignsIn` (admin `actingAs`) ile, mantıkları değişmeden. Vitest 36 (yeni `App.test.tsx` 5). Playwright 5 (yeni `shell.spec.ts`: giriş→sohbet→agentlar→koyu→çıkış, açık/koyu 6 ekran görüntüsü, telefon çekmecesi). Tarayıcıda gerçek backend ile denendi: giriş, zorunlu şifre değiştirme, agentlar, yönetişim, editör ve kullanıcılar, iki temada.

**Dokümandan sapmalar ve nedenleri**
- **Mevcut ekranların metinleri henüz `tr.json`/`en.json`'a taşınmadı.** Kabuk, giriş, profil, "Yakında" ve admin ekranları i18n'de; Sohbet, Agentlar, Akışlar, editör, Çalışmalar, Kullanım metinleri hâlâ bileşenlerin içinde (Türkçe). Arayüz dili açık soru olduğu için (doküman §11) toplu çeviri ondan sonra yapılmalı.
- "Çalışmalar" tasarımın menüsünde yok; mevcut ekran kaybolmasın diye Çalışma alanı bölümüne eklendi.
- "Credits left" kutusu kredi yerine bu ayın harcamasını ve bütçeyi gösteriyor (kredi Faz A3).
- Testler Pest değil PHPUnit (proje böyle). `/api/internal/*` henüz yok; rota taraması onları muaf tutuyor.
- Rozetler (Gelen kutusu, Yapılacaklar) veri olmadığı için gösterilmiyor; sahte sayı yok.
- Admin kendini pasif yapamaz ve rolünü düşüremez (sistemde admin kalmaması riskine karşı, dokümanda yoktu).
- Entra iskeleti sahte sağlayıcıyla test edildi; gerçek deneme IT uygulama kaydını açınca (`ENTRA_*` env).
- Bilinen eski davranış: olmayan akışın editörü (`#/flows/<yok>`) "Yükleniyor"da kalıyor (bu faz değiştirmedi).

---

## Fortis Aero — Faz A1: Akıllı cevaplar (2026-10-13)

Plan: [docs/fortis-aero-faz-a1.md](docs/fortis-aero-faz-a1.md).

**Durum: açık (2026-10-13).** Veri erişimi Kerem Bey'in sözlü onayıyla açıldı (2026-10-13). Kapsam: operasyonel veri Claude'a gidebilir, kişisel veri AiDataGate ile maskelenir. Yerel ortamda `ai.data_access = masked` yapıldı ve `audit_logs`'a `settings.update` olarak yazıldı. Çalışan adı/soyadı istisnası açık. `ai.data_access` varsayılanı `off` olarak kalıyor.

**Backend**
- `App\Ai\AiDataGate`: Claude'a giden dış sistem verisinin tek kapısı. Sıra: alan adına göre maskeleme (`config/ai-data.php` → `masked_columns`; camelCase ve önek/sonek türevleri dahil, `pnr_count` gibi sayım sütunları hariç), içeriğe göre maskeleme (TC kimlik, e-posta, telefon, PNR, 13 haneli bilet no, pasaport), SuccessFactors çalışan adı/soyadı istisnası (`ai.allow_employee_names`, varsayılan açık), 200 satır / 40.000 karakter sınırı (`AI_QUERY_MAX_ROWS`, `AI_QUERY_MAX_CHARS`), sayım.
- `FlowTools::EXTERNAL_DATA_TOOLS`: `query_*` ve mevcut `preview_apm` / `preview_successfactors` sonuçları `execute()` içinde tek noktada kapıdan geçer. **Değişen davranış:** önizleme araçlarının örnek kayıtları da artık maskeli (yapıyı görmek için yeterli).
- `App\Ai\DataQueryTools`:
  - `query_warehouse` (IBS): SqlGuard (tek SELECT, yasaklı kelimeler, gizli sütunlar), LIMIT zorunlu ≤ 1.000, süre ipucu yoksa 30 sn eklenir, EXPLAIN tam tarama uyarısında çalışmaz. Yolcu düzeyindeki tablolar (`warehouse.ai_blocked_tables`) sadece COUNT/SUM/AVG ile; `*`, MIN/MAX/GROUP_CONCAT ve kişi kimliğine/adına göre GROUP BY reddedilir.
  - `query_apm`: sadece `ApmResultShaper`'daki 6 uç (legs, leglogs, iata-delay-codes, company-aircrafts, iki OTP raporu); yanıt izin listesindeki alanlarla düz satırlara çevrilir. `includeCrewMembers` her zaman çıkarılır.
  - `query_successfactors`: izin listesi alanları (ad, soyad, departman, pozisyon, lokasyon, tarihler…); TC kimlik, telefon, adres, doğum tarihi, maaş isteyen yollar reddedilir, kayıttan da seçilmez. E-posta `e***@alan.com`. `/$count` desteklenir.
  - Her sonuçta `query_id`; cevap başına en fazla 8 sorgu (9.'su "sınır doldu" hatası). Her sorgu `audit_logs`'a `ai.query` olarak yazılır (araç, amaç, SQL/parametreler veya yol, satır sayısı, maskelenen alanlar); dönen veri yazılmaz. Reddedilenler `blocked`, sistem hataları `failed`.
- `ApmApi::read()`, `Warehouse::select()` / `aiGuard()` eklendi.
- Model: bir cevapta `query_*` çağrılınca sonraki turlar `config('ai.data_question_model')` (varsayılan `claude-opus-5-5`, `ANTHROPIC_DATA_MODEL`) ile; kullanıcı sohbette modeli seçtiyse değişmez. Sonnet'in düşünme blokları Opus'ta yok sayılır (hata vermez). `ClaudeChat::MAX_ROUNDS` 10 → 14.
- Sistem promptu: `{{#if data_access}}…{{else}}…{{/if}}` (`SystemPrompt::conditionals`). Kapalıyken metin aynen eskisi. Açıkken: veri kuralı, "Soru mu, otomasyon mu?", sorgulama ipuçları, cevap düzeni (sonuç, kanıt tablosu, olası nedenler, sonraki adımlar, kaynak satırı), şirket tanımları (rötar ≥ 15 dk, OTP, yük faktörü) ve 3 örnek.
- Ayarlar: `GET/PUT /api/settings/ai` → `ai_data_access` (`off`/`masked`), `ai_allow_employee_names` (admin).
- Mesaj API'si: `queries` (araç, kaynak, amaç, satır sayısı, maskeli mi). SSE `tool` olayına `purpose`, `rows`, `total_rows`, `masked` eklendi.
- Değerlendirme: `tests/eval/questions.json` (18 soru: APM 7, IBS 5, SuccessFactors 3, otomasyon 2, dosya 1) ve `php artisan fortis:eval [--only=id,id]`. Ayar kapalıysa çalışmaz. Rapor `storage/app/eval/<tarih>.json` (geçen, doğru araç oranı, maliyet). Gerçek API çağrısı yapar.

**Arayüz**
- Araç etiketleri ("IBS sorgulanıyor"…), sohbette sorgu adımları: kaynak, amaç, satır sayısı ("512 satırın 200'ü"), maskelendiyse "Kişisel bilgiler gizlendi". Canlı akışta ve sayfa yenilenince görünür.
- Markdown tablolar yatay kayar; `---:` ile sağa hizalanan sütunlar sağa ve tabular rakamla.
- Yönetişim ekranında (admin) "AI veri erişimi: Kapalı / Maskeli" ve "Çalışan adı ve soyadı AI'a gidebilir".

**Testler:** backend 361 (341 geçti, 20 atlandı). Yeni:
- `AiDataGateTest`: alan adı ve içerik maskeleme, sayım sütunları, operasyonel kodların maskelenmemesi, personel istisnası, 500→200 satır, 40.000 karakter.
- `DataQueryToolsTest`: yazma/çoklu ifade/LIMIT'siz ret, yolcu tablolarında sadece sayım, APM izin listesi ve dönüştürücü, SuccessFactors yasaklı alanlar, `$count`, her veri aracının kapıdan geçtiği.
- `DataAccessTest`: gerçek ClaudeChat + Anthropic SDK, sahte HTTP taşıyıcısı (`tests/Support/FakeAnthropic`). Araçlar off'ta yok, masked'da var; yedi veri çağrısından sonra fixture'daki yolcu adı, e-posta, PNR, telefon, bilet no, TC kimlik, mürettebat adı, pasaport ve adres istek gövdesinde yok; denetim kaydında sorgu var veri yok; model geçişi; 9. sorgu reddi.
- `RunEvalTest`.
- Vitest 40 (yeni `QuerySteps.test.tsx` 4), Playwright 5 (Yönetişim görüntüleri yeni kartla güncellendi).

**Dokümandan sapmalar ve nedenleri**
- Testler Pest değil PHPUnit. "Http::fake ile Anthropic'e giden gövde" yerine SDK'ya sahte PSR-18 taşıyıcı verildi (SDK Laravel Http kullanmıyor); gerçek SDK ve gerçek araç döngüsü çalışıyor.
- Serbest metin alanları (remark, comment, memo) alan adından maskeleniyor: içlerinde yolcu adı geçebiliyor ve ad içerikten tanınamıyor (test bunu yakaladı).
- İçerik maskelemede PNR: 6 karakter, en az 2 harf ve 1 rakam; uçuş no (FH1256) ve çağrı adı (FHY123) biçimleri hariç. Sadece harften oluşan PNR'ler metin içinde yakalanmaz (PNR sütunu alan adından maskelenir).
- APM'de sorgulanabilen uçlar 6 ile sınırlı; diğerleri için dönüştürücü yazılınca açılır.
- `query_warehouse` LIMIT ≤ 1.000 satırı çeker, Claude'a ≤ 200 gider; `total_rows` çekilen satır sayısıdır (LIMIT'e takılan sorguda gerçek toplam daha büyük olabilir; Claude'a toplulaştırması söyleniyor).
- Değerlendirme komutu ayar kapalıyken çalışmıyor (gerçek veri gönderir, aynı onaya tabi).

**Açık konular**
- [x] Veri erişimi onayı (2026-10-13), `ai_data_access = masked`.
- [x] `fortis:eval` çalıştırıldı (2026-10-13, gerçek veri, `masked`): **16/18 geçti (%89), doğru araç oranı %100, toplam maliyet $6,50** (soru başına $0,08–0,54). Hedef (en az %80 doğru araç) karşılandı. Kalanlar: `sf-department-sizes` (tablo ve kaynak satırı yok; model `ask_user` ile soru sordu), `automation-morning` (akış önerilmedi; hiç araç çağırmadı). Rapor: `backend/storage/app/private/eval/2026-10-12_224627.json`.
- [x] SuccessFactors gruplanmış sayım (2026-10-13): `query_successfactors`'a `group_by` (ör. `department`, `departmentNav/name`); filtreye uyan kayıtlardan sadece o alan sayfa sayfa çekilir, Laravel'de sayılır, Claude'a grup + sayı gider (kişisel alana göre gruplanamaz, en fazla 20.000 kayıt). Prompt: "sayım sorularında önce gruplanmış sayımı kullan". `sf-department-sizes` yeniden: **geçti, 0,41 $**. Model bütün departmanları tek sorguda aldı, şirket kırılımı için iki gruplanmış sorgu daha yaptı (toplam 4 sorgu; önceki çalıştırmada 8 sorgu sınırına takılıp departmanların bir kısmını sayabilmişti). Rapor: `backend/storage/app/private/eval/2026-10-12_231831.json`.
- [x] `KULLANICI_EPOSTASI` sadece alıcı alanlarında çevrilir: akış YAML'ında sadece `MailSend` görevlerinin `to`/`cc`/`bcc` alanları (sohbet sahibi), agent çalışmasında `send_email`/`deliver_report`'un `to`/`cc` alanları (çalışmayı başlatan, yoksa agent sahibi), Architect taslağında `constraints.recipients` (agent sahibi). Konu, gövde, talimat ve açıklamada olduğu gibi kalır. Agent'ın sistem promptunda izinli alıcılar listelenir; başlatanın adresi Claude'a yer tutucu olarak gider. Testler: `FlowToolsTest` 2, `AgentRunTest` 1, `ArchitectTest` 1.
- [x] AiDataGate içerik maskelemesi bir sefer numarasını (`FH489P`) kişisel veri sandı: PNR kuralı. Operasyonel kod sütunları bu kuraldan muaf tutuldu (bkz. "A3.5 öncesi düzeltmeler").
- [x] Eval düzeltmeleri (2026-10-13): prompt'a "makul varsayımla cevaplanabiliyorsa `ask_user` yerine önce cevap ver, varsayımı belirt" ve "tekrar eden isteklerde soru sorup bırakma, varsayımlarla akış öner" kuralları; "bana" denince Claude alıcıya `KULLANICI_EPOSTASI` yazar, Laravel kartta sohbet sahibinin adresini koyar (adres Claude'a gitmez, `get_flow`'da yeniden yer tutucuya döner). `fortis:eval`'in doğru araç oranı artık beklenen aracı olan her soruyu sayar (akış sorusunda `validate_flow`/`propose_flow`; hiç araç çağırmayan soru başarısız). Sadece iki soru yeniden çalıştırıldı: **2/2 geçti, doğru araç %100, maliyet $0,67** (`sf-department-sizes` $0,45, `automation-morning` $0,22). Rapor: `backend/storage/app/private/eval/2026-10-12_225300.json`. Not: `sf-department-sizes` cevabı en büyük departmanları sayıyor, hepsini değil (sohbette 8 sorgu sınırı); "Diğer" satırıyla açıkça söylüyor. Tam setin yeni ölçüye göre sonucu (önceki çalıştırmadan): 18 soruda doğru araç 17/18.
- Opus maliyeti (veri sorusu başına tahmini $0.05–0.30) değerlendirmeden sonra netleşir.

---

## Fortis Aero — Faz A2: Home ve konuşma ekranları (2026-10-13)

Plan: [docs/fortis-aero-faz-a2.md](docs/fortis-aero-faz-a2.md). Kararlar (kullanıcı): hızlı model ayrı config (`ai.fast_model`, varsayılan Sonnet; Haiku eklenmedi); sohbet başlığı ilk cevaptan sonra hızlı modelle üretilir; sohbet içindeki liste kaldırıldı, yerine `#/chats` sayfası.

**Backend**
- **Bağlantılar:** `connector_checks` tablosu, `php artisan connectors:check` (zamanlayıcıda 10 dakikada bir; IBS `SELECT 1`, APM token, SuccessFactors tek kayıt), `GET /api/connectors/status` sadece son kontrolü okur. connected = son 15 dk'da başarılı; attention = başarısız/eski/canlı kontrol edilemiyor (Teams webhook'u mesaj göndermeden denenemez); not_configured = `.env`'de yok. Outlook entegrasyonu olmadığı için her zaman not_configured. `config/connectors.php`.
- **Home:** `GET /api/home`: selamlama adı, öneriler (`config/home.php`, departmana göre; sadece bağlı kaynaklarla cevaplanabilenler; veri erişimi kapalıyken veri soruları yok; en fazla 4), bekleyenler (PAUSED çalışmalar; kullanıcının kendi akışları, admin hepsi), bugünkü etkinlik (Türkiye saatiyle bugün başlayan, en fazla 5). Çalışma dizini en fazla 10 sn'de bir Kestra ile eşitlenir.
- **`App\Ai\ModelRouter`:** Auto (`conversations.model` boş veya `auto`): ilk tur genel varsayılan, `query_*` sonrası `ai.data_question_model`, başlık `ai.fast_model`. Elle seçilen model her turda.
- **`App\Ai\AnswerTrace`:** canlı iz. Adımlar: izin kapsamı (rol + bağlı kaynaklar + kapatılanlar + veri erişimi), model seçimi (ilk tur ve değişince), her araç (sorgu: kaynak, amaç, satır, maskelendi mi), soru, hata. SSE `step` olayı; son cevabın `meta.answer`'ına iz, sorgular, modeller, süre yazılır. Sorgu metni (SQL/yol) sadece admin ve builder'a gider (SSE ve API'de süzülür).
- **`ask_user` aracı:** en fazla 3 soru × 5 seçenek (fazlası araç hatası olarak döner). Çağrılınca tur durur, mesaj `messages.status = awaiting_input`; diğer araçların sonuçları bekletilir. Cevap (`POST …/messages` → `answer`) ask_user'ın `tool_result`'ı olarak gider ("Cevaplarım: …" görünür); Atla'da "varsayımla devam et"; kart yerine yazılan mesaj da sonuç olarak gider. Devam eden cevap önceki izi ve sorguları devralır (kaynak numaraları sorudan önceki sorgulara da verilebilir).
- **Kaynak numaraları:** prompt `[^q:QUERY_ID]` ister; son cevapta bu cevapta çalışmamış kimlik varsa `ai_calls.warning = citation_mismatch: …` ve `meta.answer.citation_mismatch`.
- **Kaynak seçici:** `conversations.disabled_sources`; kapatılan kaynağın `query_*`, `describe_*`, `preview_*` araçları Claude'a sunulmaz, çağrılırsa reddedilir.
- **Başlık:** `TitleGenerator`, ilk cevaptan sonra bir kez (hızlı model, `ai_calls.purpose = title`). Hata veya bütçe dolu → ilk 60 karakter kalır. Kullanıcı yeniden adlandırdıysa dokunulmaz (`title_generated_at`).
- **Geri bildirim:** `message_feedback`, `POST /api/messages/{id}/feedback` (sadece sohbet sahibi). **Denetim:** `GET /api/messages/{id}/audit` (admin ve sohbet sahibi; sorgu metni admin/builder).
- **Prompt:** `ask_user` kuralı (her zaman); veri erişimi açıkken kaynak gösterme, tablo başlığı ve `:::actions` bloğu.
- Ayarlar ucu modellerin güçlü yönünü ve politika notunu döndürür (`config/ai.php` → `model_strengths`, `policy_note`; veri erişimine göre "Kişisel bilgiler / Şirket verisi AI'a gönderilmez").
- `DemoSeeder` (sadece `APP_ENV=local`): `fortis.demo` namespace'inde 4 akış ve bugünkü çalışmaları, kaynaklı bir demo sohbeti. Demo çalışmaları (`demo-…`) Kestra eşitlemesine girmez. Yerel veritabanına yüklendi; demo akışları Agentlar listesinde taslak olarak görünür.
- **Testlerde gerçek API kapalı:** `TestCase` varsayılan Anthropic istemcisini ağa çıkmayan bir taşıyıcıyla bağlar. (Başlık üretimi eklenirken bir test çalıştırması gerçek API'ye bir kez gitti; bu düzeltmeyle engellendi.)

**Arayüz** (`src/chat/`)
- **Home** (`#/chat`): saate göre selamlama (Türkiye saati), soru kutusu (kaynak seçici, mikrofon, model seçici), bağlantı çipleri (bağlı değilse soluk), 2×2 öneriler, "Seni bekleyenler" ve "Bugünkü etkinlik" (boş durumlar). Telefonda tek sütun, çipler yatay kayar.
- **Model seçici:** arama, Auto ("Seçili"), Anthropic sütunu ve izinli modeller, politika notu. Ekranın altındayken yukarı açılır.
- **Canlı iz:** süre ve adım sayısı canlı, son 4 adım kareleri, bitince daralır ("31,4 sn düşündü · N adım · Denetim kaydı"). Soru beklerken açık kalır.
- **Soru kartı:** bağlam şeridi, serif soru, onay kutusu/radyo, "Başka bir şey…", sayfa sayacı, Atla/Devam. Sohbet yeniden açılınca kart görünür.
- **Kaynaklı cevap:** serif sonuç paragrafı; tablo kartı (başlık = tablonun üstündeki italik satır; kopyala TSV, CSV tarayıcıda üretilir, büyüt, 10 satır + "Tümünü göster"; kod/rota mono, negatif farklar kırmızı); `[1]` kaynak numaraları (aynı sorgu aynı numara, bilinmeyen gösterilmez) ve açılan kaynak penceresi; aksiyon kartları (tıklayınca sadece mesaj gönderir; `write` "onay gerekir"); bilgi satırı (N kaynak, Auto · modeller, salt okunur); kopyala, 👍/👎 (👎'de yorum), yeniden üret.
- **Sohbet üst çubuğu:** başlık menüsü (yeniden adlandır, sil). "Agent olarak kaydet" ve "Paylaş" gizli.
- **`#/chats`:** arama, Bugün/Dün/Bu hafta/Daha eski, yeniden adlandır, sil. Kenar çubuğundaki "Sohbetler" buraya gider; "Son sohbetler" (5) aynı.
- `ConversationList` ve A1'deki `QuerySteps` kaldırıldı (iz ve sohbetler sayfası yerini aldı).

**Testler:** backend 379 (hepsi geçti; VPN açıktı). Yeni: `HomeScreenTest` 6, `ConversationScreensTest` 12. A1'in `DataAccessTest`/`DataQueryToolsTest`'i değişmeden geçiyor; `FlowToolsTest`'te beklenen araç listesine `ask_user` eklendi. Vitest 49 (yeni `chat.test.tsx` 13; `QuerySteps.test.tsx` kaldırıldı, kontrolleri iz testlerine taşındı). Playwright 8 (yeni `conversation.spec.ts`: Home → öneri → iz → soru kartı → Devam → tablolu/kaynaklı cevap → aksiyon → 👍, açık/koyu görüntüler). Tarayıcıda gerçek backend ve demo verisiyle bakıldı (Home, kaynaklı cevap, iz, model seçici).

**Dokümandan sapmalar ve nedenleri**
- `+` (dosya ekle) gizli: uygulamada dosya ekleme yok.
- "Agent olarak kaydet" ve "Paylaş" gizli (A3, A7).
- Bilgi satırındaki "salt okunur" şu an her zaman doğru: Claude'un araçlarından hiçbiri dış sisteme yazmıyor (yazma A3'te onayla gelecek); `writes` alanı hazır.
- Home bekleyenleri sadece onay bekleyen çalışmalar (agent soruları A3'te Inbox ile).
- Teams kontrolü canlı yapılamadığı için yapılandırılmışsa bile "attention".

## Fortis Aero — Faz A3.1: Agent çalıştırma, yazma araçları ve Inbox (2026-10-13)

Plan: [docs/fortis-aero-faz-a3.md](docs/fortis-aero-faz-a3.md), sadece A3.1 (A3.2 ve sonrası yapılmadı). Bitti ölçütü: seed'li agent elle çalışıyor; e-posta Inbox'tan onaylanınca gidiyor (`AgentRunTest`, `InboxTest` ve e2e ile doğrulandı).

**Backend**
- **Tablolar:** `agents`, `agent_versions`, `agent_triggers`, `agent_runs` (çalışma sohbeti, notlar, araç çağrısı sayısı, maliyet, test mi), `agent_actions` (pending/approved/rejected/executed/failed/preview, gerekçe), `inbox_items`, `agent_feedback`, `agent_evaluations`, `agent_run_scores`; `ai_calls.agent_run_id`. `config/agents.php`: araç kataloğu (okuma/yazma), izinli alıcı alan adları (`AGENT_ALLOWED_RECIPIENT_DOMAINS`, varsayılan freebird.com.tr, gozendigital.com), şirket dışı alıcı kapalı, Teams kanalları `.env`'den, sınırlar (20 araç çağrısı, 2 USD, 600 sn), kuyruk `agents`.
- **`App\Agents\AgentRunner`:** çalışmayı başlatır (agent türünde sohbet + `RunAgentJob`, `agents` kuyruğu, 3 deneme), Claude'u agent'ın talimatı ve sadece izinli araçlarıyla çalıştırır. İzinsiz araç → hata + `agent.tool_blocked` denetim kaydı. Sınır aşılırsa çalışma durur, sahibine Inbox uyarısı. Soru (`ask_user`) → Inbox'a soru, cevapla kaldığı yerden devam.
- **Yazma araçları** (`send_email`, `post_teams`, `deliver_report`) doğrudan çalışmaz, `AgentActions::request` ile aksiyon olur. Onay politikası: test ve pilotta her zaman, şirket dışı alıcı her zaman; canlıda agent ayarına göre. Onay atomik ve tek seferlik (iki kez onaylanamaz). Ret gerekçesi çalışmanın notlarına yazılır. Pilotta alıcı agent sahibine çevrilir (asıl alıcı `payload.intended`'da), Teams mesajı sahibine e-postayla gelir. Test çalıştırmasında aksiyon `preview` olur, hiçbir şey gönderilmez.
- **Inbox API:** `GET /api/inbox?tab=all|action|mentions|updates|done` (+ sayaçlar), `GET /api/inbox/count`, detay (aksiyon önizlemesi, "Onaylarsan ne olur", karar yetkisi, izinli alıcılar), onayla, reddet (gerekçe), soruyu cevapla, taslağı düzenle (alıcı sadece izinli alan adı ve agent'ın alıcı listesi; pilotta alıcı değişmez), tamamlandı, ertele (1 sa / 4 sa / yarın), toplu (tamamlandı, ertele, hepsini onayla: sadece hepsi açık onaysa ve hepsine yetki varsa). Kullanıcı sadece kendi öğelerini görür (başkasınınki 404).
- **Akış duraklamaları Inbox'a düşer:** çalışma PAUSED olunca akış sahibine onay öğesi (aynı duraklama için bir kez); onay = devam, ret = durdur (`ExecutionService::resume`); PAUSED'dan çıkınca öğe kendiliğinden kapanır.
- **Home "Seni bekleyenler"** artık Inbox'tan (açık onay + soru; bağlantı `#/inbox/{id}`). Admin başkalarının duraklamalarını burada görmez (her duraklama sahibinin kutusuna düşer).
- Agentlar: `GET /api/agents`, `POST /api/agents/{agent}/runs` (canlı/pilot herkes; test sadece builder), `GET /api/agent-runs/{run}`; komut `php artisan fortis:agent:run {slug} [--input=] [--test]`. Çalışma sohbetinde yazılan mesaj ("Agent'a sor") çalışmaya gider; agent bekleyen taslağı değiştirebilir.
- `DemoSeeder`: pilot agent "Sabah Operasyon Özeti" (`sabah-operasyon-ozeti`; `query_apm`, `describe_apm`, `send_email` en fazla 1).
- `Audit::record` aktör türü/etiketi alıyor (agent adına yapılanlar ayırt edilir). `AskAnswer::blocks()` ortak sınıfa taşındı.

**Arayüz**
- **`#/inbox`:** sekmeler ve sayaçlar, Bugün/Dün/Daha eski, satırda seçim kutusu, okunmadı noktası, 2 harfli ikon, başlık + tür etiketi (Onay/Soru/Güncelleme/Uyarı…), açıklama, kaynak, saat (mono), satır içi Ertele ve İncele/Cevapla/Aç; toplu işlem çubuğu (Tamamlandı, Ertele, Hepsini onayla — sadece seçilenlerin hepsi açık onaysa görünür).
- **`#/inbox/{id}`:** onayda iki sütun: gerekçe, önizleme kartı (Alıcı/Kopya/Kanal/Konu, markdown gövde, ek, pilot uyarısı), "Onaylarsan ne olur", Onayla ve gönder / Taslağı düzenle (izinli olmayan alıcıda kaydet kapalı) / Agent'a sor (çalışma sohbeti) / Reddet (gerekçeli). Soruda soru kartı; akış duraklamasında Onayla ve devam et / Reddet.
- Kenar çubuğunda "Gelen kutusu" artık gerçek ekran; bekleyen sayısı rozette (dakikada bir yenilenir).

**Testler:** backend 398 (hepsi geçti). Yeni: `AgentRunTest` 13, `InboxTest` 6; `HomeScreenTest` Inbox'a göre güncellendi. Vitest 54 (yeni `InboxPage.test.tsx` 5; `App.test` "Yakında" örneği Yapılacaklar'a taşındı, rozet kontrolü eklendi). Playwright 9 (yeni: Home → bekleyen → onay detayı → Onayla ve gönder → kutu boşalır; Gelen kutusu ve onay detayı görsel temelleri, açık/koyu). Gerçek Anthropic çağrısı yapılmadı.

**Dokümandan sapmalar ve nedenleri**
- Çalışma geçmişi `agent_runs` içinde ayrı bir `messages` json'u yerine çalışmanın kendi sohbetinde (agent türü) tutuluyor: iz, kaynaklar, "Agent'a sor" ve denetim mevcut sohbet altyapısını kullanıyor.
- Test çalıştırmasının yazma aksiyonları `preview` durumuyla kaydediliyor (Inbox'a düşmez, gönderilmez).
- `ask_user` her agentta açık (araç listesinde seçilmese de); agent'ın belirsizlikte sorabilmesi için.
- Pilotta Teams mesajı Teams'e değil sahibine e-postayla gidiyor (pilot sadece sahibine gönderir kuralı).
- Agent sayfası ve çalıştırma arayüzü A3.4'te; şimdilik API ve komut.
- Yerelde agent çalışması için kuyruk işçisi gerekir (`queue:work --queue=agents`); yerelde `MAIL_MAILER=log` olduğu için e-postalar log'a yazılır.

## Fortis Aero — Faz A3.2: Tetikleyiciler (2026-10-13)

Plan: [docs/fortis-aero-faz-a3.md](docs/fortis-aero-faz-a3.md) §5, §9 (A3.2). Bitti ölçütü: zamanlanmış agent Kestra'dan tetikleniyor, Kestra kapalıyken hata Inbox'a uyarı olarak düşüyor. Sahte Kestra ile testlerde doğrulandı; gerçek Kestra ile uçtan uca denenmedi (aşağıya bakın).

**Backend**
- **Zamanlama (`App\Agents\AgentSchedules`):** tetikleyici kaydedilince Laravel YAML'ı kendisi üretir: `fortis.agents` namespace'i, akış adı `agent-{slug}-{tetikleyici id}`, tek görev (`io.kestra.plugin.core.http.Request`, POST `{FORTIS_INTERNAL_URL}/api/internal/agents/{id}/runs`, `X-Fortis-Key: {{ secret('FORTIS_INTERNAL_KEY') }}`, gövde `{"trigger_id": …}`), `Schedule` tetikleyicisi (cron, Europe/Istanbul). YAML `FlowValidator`'dan (yükleme modu, Kestra'nın kendi doğrulaması dahil) geçer ve Kestra'ya yüklenir.
  - Agent pilot/canlı değilse veya tetikleyici kapalıysa akış `disabled: true` olarak yüklenir. Agent durumu değişince (model olayı) bütün zamanlamaları yeniden yüklenir.
  - Tetikleyici silinince akış Kestra'dan silinir; Kestra'ya ulaşılamazsa tetikleyici silinmez (502). Agent silinince akışları da silinir.
  - **Kestra kapalı/hata:** tetikleyici yine kaydedilir, hata `agent_triggers.sync_error`'da kalır, agent sahibinin Inbox'ına uyarı düşer (aynı tetikleyici için tek uyarı, metni güncellenir). `fortis:agent:sync-triggers` (zamanlayıcıda 15 dakikada bir) hatalı olanları yeniden dener; başarılı olunca uyarı kapanır.
  - Agent akışları Akışlar ve Çalışmalar listelerine girmez (`fortis.chat` ön ekiyle filtreleniyor).
- **`FlowValidator`:** Laravel'in ürettiği zamanlama akışı için dar istisna: sadece verilen tam iç uç adresine, sadece POST ve anahtar başlığı sadece `secret(...)` ifadesi. Claude'un önerdiği akışlarda bu istisna hiçbir zaman verilmez (Laravel'in kendi sunucusuna istek yine engelli).
- **İç uç:** `POST /api/internal/agents/{agent}/runs` (`internal.key` ara katmanı, `X-Fortis-Key` sabit zamanlı karşılaştırma; anahtar tanımlı değilse uç kapalı; dakikada 60 istek). Agent pilot/canlı değilse `409 inactive`; aynı tetikleyicinin önceki çalışması `queued`/`running` ise `409 busy` (kilitli kontrol). Başlatılmayan her istek `agent.run.skipped` denetim kaydı.
- **Olaylar (`App\Agents\FlightEvents`, `fortis:agent:watch-flights`, 5 dakikada bir):** sadece açık olay tetikleyicisi varsa APM `/api/legs` okunur (şimdi −6 sa … +18 sa), uçuşların son hali `flight_watch` tablosunda tutulur (3 günden eskiler silinir).
  - `flight.delayed`: kalkış rötarı arttığında; tetikleyici, eşiği (`min_delay_minutes`, varsayılan 60) bu taramada ilk kez geçtiyse çalışır.
  - `flight.cancelled`: sefer iptal olunca.
  - İlk kez görülen uçuş sadece kaydedilir (ilk taramada bütün rötarlı uçuşlar için çalışma açılmaz).
  - Olay bilgisi (sefer, kalkış/varış, planlı saat, rötar ve önceki rötar, kodlar, uçak) görev şablonundaki `{{event}}` yerine konur; şablonda yoksa sona eklenir. Sadece operasyonel veri.
  - Olay tetikleyicisi saatte en fazla `AGENT_MAX_EVENT_RUNS_PER_HOUR` (10) çalışma başlatır.
  - APM'ye ulaşılamazsa olay tetikleyicisi sahiplerine tek uyarı; düzelince kapanır.
- **Tetikleyici API'si** (agent sahibi ve admin): `GET/POST /api/agents/{agent}/triggers`, `PATCH/DELETE /api/agent-triggers/{trigger}`. Tür `schedule` (cron doğrulanır) veya `event` (`flight.delayed`, `flight.cancelled`); elle çalıştırma her agentta var, ayrıca eklenmez. Arayüzü A3.4'te (Agent sayfası → Tetikleyiciler).
- `AgentRunner::start` tetikleyiciyi kaydeder (`agent_runs.trigger_id`, denetimde tetikleyici türü).
- `.env`: `FORTIS_INTERNAL_KEY` (yerelde rastgele üretildi), `FORTIS_INTERNAL_URL` (boşsa `APP_URL`).

**Testler:** backend 405 (hepsi geçti). Yeni `AgentTriggerTest` 7: Kestra'ya giden YAML (namespace, cron, iç uç, secret, doğrulama), duraklatınca/kapatınca devre dışı, silince akış siliniyor, Kestra kapalıyken kayıt + tek uyarı + yeniden deneme, cron/olay doğrulaması ve yetki, iç uç (anahtarsız/yanlış anahtar 401, taslak 409, çalışan varken 409 ve kayıt), rötar eşiği geçilince çalışma / altında ve önceden geçilmişse yok / iptal, saatlik sınır ve APM kesintisi uyarısı. Vitest 54, değişiklik yok (sadece tip).

**Dokümandan sapmalar ve nedenleri**
- Akış adı `agent_{slug}_{id}` yerine `agent-{slug}-{id}`: Fortis'in akış kimliği kuralı alt çizgiye izin vermiyor.
- APM tarafında hazır bir "değişiklik olayı" üreten senkronizasyon yoktu; olaylar bu fazda eklenen periyodik tarama ve `flight_watch` tablosuyla üretiliyor. `aircraft.changed` yok (doküman en az rötar ve iptal istiyor).
- Gerçek Kestra ile uçtan uca denenmedi (adımlar: `infra/kestra/README.md` → "Agent zamanlamalarını gerçek Kestra ile denemek"): Kestra sunucusu yerel Laravel'e ulaşamıyor (tünel gerekir) ve `FORTIS_INTERNAL_KEY` secret'ı Kestra'da tanımlı değil; sunucu değişiklikleri beklemede. Canlıya alırken: Kestra'ya `FORTIS_INTERNAL_KEY` secret'ı, `.env`'de aynı değer ve `KESTRA_SECRETS`'e adı, `FORTIS_INTERNAL_URL` Kestra'nın ulaşabildiği adres.
- 409 (çalışma sürüyor) Kestra'da o zamanlama çalışmasını "başarısız" gösterir; bilinçli bırakıldı (atlanan tetikleme Kestra'da da görünür).

## Fortis Aero — Faz A3.3: Oluştur, Architect, şablonlar (2026-10-13)

Plan: [docs/fortis-aero-faz-a3.md](docs/fortis-aero-faz-a3.md) §7.3–7.6, §9 (A3.3). Bitti ölçütü: sohbetle sıfırdan bir agent kurulup test edilip pilota alınabiliyor. Sahte Claude ile backend testlerinde ve sahte backend ile Playwright'ta doğrulandı; gerçek Claude ile Architect sohbeti denenmedi (ücretli).

**Backend**
- **Architect sohbeti** (`conversations.kind = architect`, `agents.conversation_id`): mesaj ucu bu türde `ClaudeChat::forArchitect()` ile çalışır. Sistem promptuna `resources/prompts/architect.md` eklenir: nasıl çalışacağı (önce makul varsayımla taslak, kararlarda `ask_user`, her değişiklikte taslağın tamamıyla yeniden kayıt), araç kataloğu, olaylar, izinli alan adları ve Teams kanalları, şu anki taslak (JSON). Araçlar `App\Agents\Architect::TOOLS` ile sınırlı: `propose_agent`, `ask_user`, `get_queries`, `describe_*`, `list_report_sources`, `query_*` (veri erişimine bağlı). Akış araçları yok.
- **`propose_agent` → `App\Agents\AgentDrafts`:** doğrular ve kaydeder (her kayıt `agent_versions`'ta yeni sürüm, `source = architect`). Kurallar: araçlar katalogda; e-posta alıcıları izinli alan adlarında; Teams kanalı tanımlı; `max_per_run` 1–20; cron geçerli; olay bilinen; tetikleyici en fazla 5; onay modu `always` / `internal_only_auto` / `never` (`never` sadece admin); onaylayıcılar `owner` veya etkin kullanıcı; plan 2–12 adım, türler trigger/read/lookup/decide/draft/approve/act; yazan araç varsa planda onay adımı zorunlu. Hatalar Claude'a döner, düzeltip yeniden çağırır. Taslak adı değişince slug güncellenir (sadece taslakta). Tetikleyiciler her kayıtta yeniden kurulur (elle çalıştırma her zaman var).
- **"Bana":** Architect alıcıya `KULLANICI_EPOSTASI` yazar; kayıtta agent sahibinin adresi olur, Claude'a giden taslakta yine yer tutucuya döner.
- **Taslak agentın zamanlaması Kestra'ya yüklenmez**; pilota alınınca yüklenir (A3.2'deki model olayı).
- `agents.plan` (json) ve `agents.template_key` kolonları.
- **Uçlar** (builder/admin; agent'ı sahibi veya admin görür): `GET /api/build` (örnekler: bağlantısı yapılandırılmış olanlar; şablonlar ve kurulabilir mi; departmanlar; kopyalanabilecek agentlar), `POST /api/agents` (`blank` / `template` / `copy`; her biri sahibine taslak + Architect sohbeti), `POST /api/agents/from-conversation` (boş taslak + Architect'e ilk mesaj `seed`: sohbette sorulanlar ve kullanılan `query_*` amaçları), `GET /api/agents/{agent}/architect` (taslak, plan, okuduğu/yazdığı sistemler, tahmini maliyet = biten çalışmaların ortalaması, son test çalışması, canlıya alınabilir mi), `POST /api/agents/{agent}/status`.
- **Durum geçişleri:** Pilota al: taslak/duraklatılmış → pilot; kaydedilmiş ve geçerli taslak şart. Canlıya al: sadece builder/admin, pilot/duraklatılmış → canlı, en az bir başarılı test dışı çalışma şart. Duraklat: pilot/canlı → duraklatıldı. Her geçiş `agent.status` denetim kaydı.
- **Son veriyle test:** mevcut `POST /api/agents/{id}/runs` (`test: true`); yazan aksiyonlar `preview` kalır. Biten test çalışmasının özeti Architect'e sonraki mesajda sistem notu olarak gider.
- **Şablonlar** (`AgentTemplateSeeder`, `DatabaseSeeder`'a eklendi, tekrar çalıştırılabilir): Sabah Operasyon Özeti (her gün 06:00), Rötar Nedeni Analizi (pazartesi 08:00), Uzun Rötar Bildirimi (`flight.delayed` ≥ 120 dk, Teams), İK Değişiklik Özeti (cuma 16:00). Talimat, araçlar, plan ve onay politikasıyla. Şablon sadece okuduğu sistemler bu ortamda yapılandırılmışsa kurulabilir görünür. Yerel veritabanına yüklendi.

**Arayüz**
- **Oluştur (`#/build`):** siyah kare ikon, serif "Yeni agent'ın ne yapsın?", metin kutusu; altında departman seçici, Boş / Şablondan / Var olan agent'ı kopyala, gönder. "Bir örnek dene" (3), "Havacılık şablonları" (3 kart + "N şablona göz at"; kartla tek tıkla kurulur). Sağ üstte "Akışlar →".
- **Akışlar `#/build/flows`**, editör `#/build/flows/{id}`. Eski `#/flows/…` adresleri yönlendiriliyor.
- **Architect (`#/build/agents/{id}`):** üst çubukta geri, "Architect", ad · durum, "Otomatik kaydedildi HH:MM", **Son veriyle test et**, **Pilota al** / **Canlıya al** (başarılı pilot çalışması yoksa kapalı, açıklamalı) / **Duraklat**.
  - Solda sohbet: canlı iz ("8,1 sn düşündü · N adım"), soru kartı, "Bir değişiklik tarif et…". Oluştur ekranındaki metin veya sohbetten agent'a özeti ilk mesaj olarak kendiliğinden gider.
  - Sağda noktalı zeminde plan: dikey kartlar (2 harf ikon, TETİKLEYİCİ/OKU/ARA/KARAR/TASLAK/ONAY/GÖNDER, başlık, ayrıntı); karar adımında yanda "Hayır → …" dalı; onay kartı vurgulu. Karta tıklayınca ilgili ayar özeti (tetikleyiciler, okuma araçları, onay modları, gönderim kısıtları). Altta Okuduğu sistemler / Yazdıkları (onaydan sonra) / Tahmini maliyet.
  - Test sonucu kartı: durum, çıktı (markdown), "Bu çalışma şunları gönderecekti (test, gönderilmedi)", maliyet. Bitene kadar 2 saniyede bir yenilenir.
- **Sohbetten agent'a:** sohbet başlığında **Agent olarak kaydet** ve cevaptaki **Düzenli hale getir** aksiyonu (builder/admin) Architect'i açar. Diğer roller için aksiyon eskisi gibi mesaj gönderir.

**Testler:** backend 413 (hepsi geçti). Yeni `ArchitectTest` 6:
- Architect araç listesi ve sistem promptu; `propose_agent` kaydı, sürüm, tetikleyiciler, plan; "bana" yer tutucusu; taslak zamanlaması Kestra'ya gitmiyor.
- Katalog dışı araç, izinsiz alıcı, tanımsız kanal, geçersiz cron/olay/tür, builder'ın `never` seçmesi ve eksik onay adımı reddediliyor.
- Pilota alma taslak ister ve zamanlamayı Kestra'ya yükler; canlıya alma başarılı test dışı çalışma ister (test çalışması sayılmaz); user rolü 403, başkasının agentı 404.
- Şablon ve kopya sahibine taslak oluyor, şablondan kurulan doğrudan pilota alınabiliyor.
- Sohbetten agent'a özeti; Architect'ten test çalışması.

Vitest 58 (yeni `architect.test.tsx` 4: plan adım türleri, karar dalı, özet kutuları, ayar özeti; Oluştur örnek → boş taslak + ilk mesaj; şablon kartı). Playwright 10:
- Yeni senaryo: Oluştur → şablondan kur → Architect planı → son veriyle test → pilota al. Architect görüntüsü açık/koyu temada.
- Oluştur ekranı ve Akışlar için yeni görsel temeller.
- Görüntülere bakıldı.

**Gerçek Claude ile deneme (2026-10-13)** — "sohbetle sıfırdan kur → test et → pilota al", bütçe 2 $. Arayüzün yaptığı adımları sırayla yapan bir betikle; yerel builder test kullanıcısı `architect.test@gozendigital.com` (izinli alan adı; e-postalar `MAIL_MAILER=log`).
- **İstek:** "Her sabah 07.30'da dünün 15 dk üzeri rötarlarını nedenleriyle özetleyip bana mail at."
- **Architect'in soruları:** Soru sormadı. İlk turda taslağı kaydetti ve varsayımlarını yazdı: hafta sonu dahil her gün, iptaller rötar sayılmaz, APM test ortamında veri boşsa uydurmaz.
- **Taslak (v1):** "Dünün Rötar Özeti" (RÖ).
  - Araçlar: `query_apm`, `describe_apm`, `send_email` (alıcı: sahibin adresi, "bana" yer tutucusuyla; en fazla 1).
  - Tetikleyici: `30 7 * * *`.
  - Plan (7 adım): Her gün 07:30 → APM: dünün seferleri → Rötar kodlarını eşleştir → KARAR "15 dk ve üzeri rötar var mı?" (Hayır: "rötar yok" e-postası) → Özet e-postasını hazırla → Gönderim onayı → E-postayı gönder.
  - Talimat: tarih aralığı TSİ→UTC, sayfalama, rötar tanımı, e-posta biçimi (özet, en uzun 10, nedene göre kırılım, kaynak).
- **Test çalışması:** başarılı, 0,30 $.
  - APM test ortamında dünün 56 seferinde gerçekleşen kalkış saati ve rötar kodu yok; agent bunu açıkça yazdı ve uydurmadı. 6 iptali tablo halinde verdi.
  - E-posta taslağı `preview` olarak kaldı (gönderilmedi): alıcı test kullanıcısı, konu "Dünün rötar özeti – 12 Ekim 2026".
- **Pilota alma:** başarılı.
  - Zamanlama Kestra'ya aktarılmadı: `FORTIS_INTERNAL_KEY` secret'ı Kestra'da tanımlı değil (beklenen).
  - Hata tetikleyicide kaldı, sahibin Inbox'ına "zamanlama Kestra'ya aktarılamadı" uyarısı düştü (A3.2 davranışı doğrulandı).
- **Toplam maliyet: 0,54 $** (Architect 0,24 + test 0,30).
- **Bulgular:**
  - (1) Architect onay modunu kendiliğinden "iç alıcıya onaysız" (`internal_only_auto`) seçti. Prompt'a "kullanıcı açıkça istemedikçe `always`" kuralı eklendi; pilotta zaten her gönderim onaylıydı.
  - (2) Test çıktısında bir sefer numarası "(sefer no maskeli)" göründü: AiDataGate'in içerik maskelemesi bir sefer numarasını kişisel veri sandı (yanlış pozitif); açık konulara eklendi.
  - (3) Talimatta APM'nin ham alan yolları (`delaysInfo.departureDelay1…`) geçiyor, oysa `query_apm` düzleştirilmiş alan (`dep_delay_min`) döndürüyor. Agent yine doğru çalıştı, ama ileride Architect'e düzleştirilmiş alan adları verilmeli.

**Dokümandan sapmalar ve nedenleri**
- Plan görünümü React Flow yerine HTML/CSS: plan salt okunur, doğrusal ve tek seviye dallı; erişilebilir liste, testte ölçüm gerektirmiyor, editörün tuval altyapısına bağımlı değil. Görünüm tasarımdaki gibi (dikey kartlar, yan dal).
- Plan kartına tıklayınca ayarın kendisi değil özeti açılıyor; düzenleme Agent sayfası → Yapılandırma'da (A3.4).
- Şablonların "1 tıkla kur"u şablondan taslak kurup Architect'i açıyor; kullanıcı test edip pilota alıyor (doğrudan pilota almıyor).
- Architect'e ilk mesaj tarayıcıda tutuluyor; Architect açıldıktan sonra sayfa yenilenirse ilk mesaj gönderilmez (kullanıcı yazabilir).
- Tasarımdaki "Credits" yok (A4).

## Fortis Aero — Faz A3.4: Agent Kaydı ve agent sayfası (2026-10-13)

Plan: [docs/fortis-aero-faz-a3.md](docs/fortis-aero-faz-a3.md) §7.1–7.2, §9 (A3.4). Bitti ölçütü: tasarımdaki ekranlar gerçek veriyle. Ekranlar backend'in gerçek uçlarından besleniyor; ölçüler yerel veritabanındaki gerçek agent ve çalışmalarla da hesaplatıldı (hata yok, test çalışmaları ölçülere girmiyor). Playwright sahte backend'le.

**Backend**
- **`GET /api/agents` (Agent Kaydı):** sayılar (canlı, pilot, taslak, duraklatılmış, şablon), agent kartları ve ayrı şablon listesi. Kart: sistemler (araç kataloğundan), departman, sahip (ad, unvan), tetikleyici (Türkçe: "Her gün 07:30", "Uçuş rötarı ≥ 120 dk"), son 7/30 gün çalışma, % faydalı (son 30 gün geri bildirim; yoksa `null`), son 30 günde onaylanan aksiyon. Test çalışmaları sayılmaz. Herkes görür.
- **`GET /api/agents/{id}` (agent sayfası):** başlık bilgileri, sürüm, yetkiler (`can_manage`: sahibi/admin; `can_chat`).
  - Genel bakış: 6 ölçü (çalışma · 30 gün ve yeniden denenen, başarı oranı, ortalama süre ve önceki 30 güne farkı, maliyet ve çalışma başına, faydalı oranı, onaylanan/önerilen aksiyon).
  - 30 günlük günlük dağılım (başarılı / yeniden denendi / hata / diğer, Türkiye saatiyle).
  - Yapılandırma özeti (tetikleyici, teslim, okuduğu, yazdığı, model, bellek "A5'te").
  - Son 10 çalışma: sahibi/admin hepsini, diğerleri kendi başlattıklarını görür.
  - Yapılandırma taslağı sadece sahibi/admin'e gider.
- **Çalışmalar:** `GET /api/agents/{id}/runs?status=&origin=manual|schedule|event|chat&test=0|1|all`. Çalışma ayrıntısında kaynak, deneme sayısı, süre, iz (çalışma sohbetinin cevap izi) ve geri bildirim.
- **`agent_runs.source`** (sohbet) ve **`agent_runs.attempts`** (kuyruk denemesi; 1'den fazlaysa "yeniden denendi"). Önceden yeniden deneme kaydı yoktu; bundan sonraki çalışmalarda sayılır.
- **Sohbet:** `POST /api/agents/{id}/chats` kuyruğa gitmeyen bir sohbet çalışması açar (`AgentRunner::startChat`); mesajlar mevcut mesaj ucundan (SSE) agent'ın talimat ve araçlarıyla gider. `GET …/chats`: kullanıcının sohbetleri. Pilot/canlıda herkes; taslak/duraklatılmışta sadece sahibi/admin (builder), test olarak (yazan aksiyonlar önizlenir).
- **Yapılandırma:**
  - `GET /api/agents/catalog`: araçlar, olaylar, modeller, izinli alan adları, Teams kanalları, departmanlar, sahip adayları (admin).
  - `PUT /api/agents/{id}`: Architect'le aynı doğrulama (`AgentDrafts`), her kayıt yeni sürüm (`source = manual`).
  - `base_version` eşleşmezse 409 (arada başkası kaydettiyse üzerine yazılmaz).
  - Sahibi sadece admin değiştirir.
  - Doğrulama geçmezse sahip ve departman da değişmez.
- **Sürümler:** `GET /api/agents/{id}/versions` (snapshot'larla) ve `POST …/versions/{n}/restore`: eski sürüm yeni sürüm olarak kaydedilir, not "vN geri yüklendi"; geçmiş silinmez.
- **Geri bildirim:** `agent_feedback` (çalışma + kullanıcı başına bir kayıt), `POST /api/agent-runs/{id}/feedback` (`useful` / `not_useful`, yorum). Çalışmayı görebilen verir.
- `App\Agents\AgentStats`, `App\Agents\CronText` (arayüzdeki `describeCron` ile aynı kurallar).

**Arayüz**
- **Agent Kaydı (`#/agents`)** eski akış tabanlı Agentlar ekranının yerini aldı.
  - Üstte "N canlı · N pilotta · N şablon", "Freebird Airlines" ve serif "Agent Kaydı", **+ Yeni agent** (builder).
  - Arama (ad, açıklama, sistem, sahip, departman), departman çipleri (sayılarıyla), "N agent", sıralama (en çok kullanılan / en yeni / ada göre), "Akışları da göster" (Kestra akışları, editöre bağlantı).
  - Kart: 2 harf ikon, serif ad, durum rozeti (Canlı yeşil, Pilot kırmızı, Taslak gri, Duraklatıldı sarı, Şablon mavi), "Departman · sahibi unvan", açıklama, sistem etiketleri.
  - Kart alt şeridi (mono): "N çalışma/hafta · %N faydalı" (geri bildirim yoksa sadece çalışma), yoksa "N onay", yoksa "henüz çalışmadı".
  - Kartta Sohbet / İzleme / İzinler düğmeleri. Şablonlar ayrı bölümde, **Kur** (Architect'te açılır).
- **Agent sayfası (`#/agents/{id}[/chat|/runs[/{runId}]|/config|/versions]`):**
  - Kırıntı, ikon, serif ad, durum, sürüm, açıklama.
  - Başlık düğmeleri: **Duraklat / Devam ettir**, **Architect'te düzenle**, **Şimdi çalıştır**.
  - **Genel bakış:** 6 ölçü kutusu; recharts ile 30 günlük yığılmış çubuk grafik (başarılı / yeniden denendi / hata); son çalışmalar tablosu (başlangıç mono, durum, kaynak, süre, maliyet, aksiyonlar, İz); sağda Yapılandırma özeti ve geri bildirim dağılımı.
  - **Sohbet:** sohbet listesi + yeni sohbet; önerilen sorular; Architect'teki sohbet bileşeni (canlı iz, soru kartı).
  - **Çalışmalar:** durum/kaynak/test filtreleri. Ayrıntıda görev, iz, çıktı, aksiyonlar ve durumları, süre, maliyet, **Faydalı / Faydalı değil** (+ yorum), **Agent'a sor**.
  - **Yapılandırma:**
    - Agent: model, talimat, okuma araçları, yazan araçlar ve kısıtları (alıcılar, kanallar, en fazla / çalışma), önerilen sorular.
    - Tetikleyiciler: zamanlama için Her gün / Hafta içi / Haftada bir + gün + saat ya da gelişmiş cron, Türkçe önizlemeyle; olay için tür + rötar eşiği; görev metni.
    - Onaylar: araç başına mod ("onaysız" sadece admin'e sunulur) ve onaylayanlar.
    - Ayarlar: ad, ikon, açıklama, departman, sahip (admin).
    - Kaydedilmemiş değişiklik çubuğu (Vazgeç / Kaydet (vN+1)); sekme değiştirirken ve sayfadan çıkarken uyarı; sunucu hataları listelenir.
  - **Sürümler:** liste (kaynak: Architect / Yapılandırma / Geri yükleme / Şablon / Kopya, kişi, zaman, not). Seçilen sürümle güncel arasındaki fark: araçlar, tetikleyiciler, talimat satır farkı. **Geri yükle**.

**Testler:** backend 424 (hepsi geçti). Yeni `AgentPageTest` 7:
- Kayıt sayıları, kart ölçüleri, şablonlar, % faydalı.
- Genel bakış ölçüleri ve grafik; başkasının çalışmaları ve taslağı görünmüyor.
- Çalışma filtreleri; sohbet çalışması ve mesajın agent'tan geçmesi; taslakla sohbet yetkisi.
- Yapılandırma sürümü, 409 çakışma, izinsiz alıcı, sahip değişikliği yetkisi.
- Sürüm listesi ve geri yükleme; geri bildirim yetkisi.

Vitest 61:
- Yeni `agentpage.test.tsx` 6: kart rozetleri, sistem etiketleri, % faydalı yoksa gizli, alt şerit, bağlantılar; cron ↔ seçici; satır/küme farkı; yapılandırmada kaydedilmemiş değişiklik uyarısı, Türkçe önizleme, yeni sürümle kayıt, "onaysız" builder'a kapalı, Vazgeç.
- Eski `AgentsPage.test.tsx` kaldırıldı (ekran değişti).

Playwright 11:
- Yeni senaryo: Agent Kaydı → departman filtresi → İzleme → ölçüler → Şimdi çalıştır → Çalışmalar → ayrıntı: Başarılı, maliyet, gönderildi → Faydalı.
- Agent Kaydı ve agent sayfası görsel temelleri, açık/koyu. Görüntülere bakıldı.

**Dokümandan sapmalar ve nedenleri**
- Tasarımdaki "Departmana göre benimsenme" ve "açılma oranı" yok (e-posta açılma takibi A6); yerine geri bildirim dağılımı (dokümanda da böyle).
- "Yeniden denendi" sadece bu fazdan sonraki çalışmalarda ölçülüyor (deneme sayısı yeni kolon).
- Karttaki **İzinler** düğmesi Yapılandırma sekmesini açıyor (araç izinleri ve onaylar orada); kişiye göre erişim/paylaşım A7'de.
- **Değerlendirme** sekmesi A3.5'te.
- Eski akış tabanlı Agentlar ekranı (akış başına ayrıntı paneli) kaldırıldı; akışlar "Akışları da göster" ile listeleniyor, ayrıntıları editör ve Çalışmalar ekranında.
- Canlı Architect denemesi için yerelde builder test kullanıcısı `architect.test@gozendigital.com` oluşturuldu (izinli alan adı gerekiyordu; şifresi sohbette gösterilmedi). Kullanıcı kararıyla kalıyor; sadece yerel veritabanında, hiçbir seeder'da yok (prod seed'ine girmez).

## Fortis Aero — A3.5 öncesi düzeltmeler (2026-10-13)

- **Onay kuralı kodda zorunlu:**
  - `propose_agent`'ta Architect'in önerdiği onay modu tamamen yok sayılır; yazan her araç "her zaman onay" ile kaydedilir. Sohbet admin'in olsa da geçerli.
  - Architect onaysız bir mod istediyse araç sonucunda `approval_note` döner ("Yapılandırma > Onaylar'dan değiştirilebilir"); Claude bunu kullanıcıya söyler.
  - Onaysız modlar sadece Yapılandırma'dan elle seçilir: "iç alıcılara onaysız" builder/admin, "onaysız" (`never`) sadece admin.
  - İnsanın elle seçtiği mod, Architect taslağı sonradan yeniden kaydettiğinde korunur.
  - Ayarlardaki varsayılan politikada `post_teams` "iç alıcılara onaysız" idi; bu yüzden Architect hiçbir şey söylemese de Teams agentları onaysız kurulurdu. Varsayılan "her zaman onay" yapıldı.
  - Architect promptunda onay modu artık yok, sadece onaylayanlar.
  - Test: `ArchitectTest`.
- **Yanlış maskeleme:**
  - Yakalayan kural, içerik maskelemesindeki **PNR kuralı** (6 karakter, en az 2 harf ve 1 rakam). Canlı denemede ECN–AYT seferinin numarası `FH489P` (sonu harfli operasyonel sefer kodu) bu kurala takıldı; mevcut istisna sadece `FH1234` ve `FHY123` biçimlerini koruyordu.
  - `config/ai-data.php` → `operational_columns` eklendi: sefer no, leg id, çağrı adı, kuyruk tescili ve türevleri; APM'nin iç içe ham alanları da (`flightNumber`, `designator`, `legId`, `actualAircraftRegistration`) kapsanıyor.
  - Bu sütunlarda PNR ve pasaport kuralları uygulanmaz. E-posta, telefon, TC kimlik ve bilet no kuralları uygulanmaya devam eder. Adı maskelenen bir sütuna da uyuyorsa maskeleme önceliklidir.
  - Diğer sütunlarda (ör. açıklama) aynı değer yine maskelenir.
  - Testler: `AiDataGateTest` 2 yeni. `DataAccessTest`, `DataQueryToolsTest` ve `AgentRunTest`'teki kişisel veri testleri geçiyor.
- **Architect'e APM alanları:**
  - `ApmResultShaper::fields()` her APM ucu için `query_apm`'in döndürdüğü düz alanları dönüştürücünün kendisinden türetir (elle tutulmaz). Architect promptunda listelenir (`GET /api/legs` → `leg_id`, `flight_no`, …, `dep_delay_min`, `delay_codes`, `cancelled`…).
  - Talimatta veya görev metninde ham APM yolu (`delaysInfo…`, `flightTimes…`, `statusInfo…` vb.) geçerse `propose_agent` taslağı kaydetmez ve düz alanlarla yeniden yazmasını ister.
  - Test: `ArchitectTest`.
  - Not: Canlı denemede kurulan yerel agent (#5) eski talimatıyla duruyor.

## Fortis Aero — Faz A3.5: Değerlendirme ve puanlama (2026-10-13)

Plan: [docs/fortis-aero-faz-a3.md](docs/fortis-aero-faz-a3.md) §7.2 (5. Değerlendirme), §9 (A3.5), test 17. Bitti ölçütü: puanlar çalışmalarda ve genel bakışta görünüyor. Sahte Claude ile testlerde; puanlama isteği gerçek modelle de bir kez denendi (aşağıda).

**Backend**
- **Kurallar:** `agent_evaluations` (agent başına; en fazla 10 kural, 5–200 karakter; açık/kapalı). `GET/PUT /api/agents/{id}/evaluation`: herkes görür, sahibi/admin (builder) düzenler; kuralsız açılamaz; `agent.evaluation.update` denetim kaydı.
- **`App\Agents\RunScorer`:**
  - Çalışma bitince (başarılı veya onay bekliyor, çıktı var, sohbet çalışması değil, değerlendirme açık) `ScoreAgentRunJob` `agents` kuyruğuna düşer (1 kez yeniden dener).
  - Hızlı model (`ai.fast_model`) çıktıyı kurallara göre geçti/kaldı + bir cümle gerekçeyle değerlendirir; puan = geçen kural oranı (0–100). `agent_run_scores`'a yazılır (çalışma başına bir kayıt).
  - **Claude'a giden tek mesaj:** agent adı ve açıklaması, kurallar, çıktı metni (en fazla 12.000 karakter). Sorgu sonuçları, araç girdileri ve çalışma konuşması gitmez.
  - Maliyet `ai_calls`'a `purpose = evaluation` ve `agent_run_id` ile yazılır. Bütçe doluysa puanlanmaz. Cevap kural sayısıyla uyuşmazsa puan yazılmaz.
- **Yapılandırılmış çıktı:** Puanlama yapılandırılmış çıktıyla (`output_config.format = json_schema`) alınıyor. İlk sürüm zorunlu araç seçimi (`tool_choice: tool`) kullanıyordu; gerçek API bu modelde bunu reddetti (400). Sahte istemciyle yapılan testler bunu yakalayamazdı; gerçek denemeyle bulundu ve değiştirildi.
- **`POST /api/agent-runs/{id}/score`:** sahibi/admin bir çalışmayı şimdi (yeniden) puanlatır.
- **Puanların görüldüğü yerler:**
  - Çalışma ayrıntısı: puan, kural başına sonuç ve gerekçe, model.
  - Çalışmalar tablosu: puan sütunu.
  - Genel bakış: ortalama puan ve puanlanan çalışma sayısı.
  - Agent Kaydı kartı: geri bildirim yoksa ve değerlendirme açıksa ortalama puan.
  - Değerlendirme sekmesi: 30 günlük ortalama, kural başına geçme oranı, son 10 puan.
- Test sahte istemcisi (`FakeAnthropic`) yapılandırılmış çıktı isteyen akışsız isteklere sıradaki yanıtı veriyor.

**Arayüz**
- **Değerlendirme sekmesi** (`#/agents/{id}/evaluation`, Yapılandırma ile Sürümler arasında):
  - Numaralı kural listesi (ekle/kaldır, en fazla 10), "Her çalışmadan sonra puanla", Kaydet.
  - Puanlamaya sadece çıktı metninin gittiği açıklaması.
  - Sağda ortalama puan (30 gün), kural başına geçme çubukları, son puanlar (çalışmaya bağlantı).
  - Sahibi/admin dışındakiler sadece görür.
- **Çalışma ayrıntısı:** Değerlendirme bölümü: puan/100 (80+ yeşil, 50–79 sarı, altı kırmızı), kural başına ✓/✗ ve gerekçe, **Puanla / Yeniden puanla**.
- **Genel bakış:** puanlanmış çalışma varsa "Değerlendirme puanı" kutusu. **Çalışmalar tablosu:** Puan sütunu. **Kart:** "N çalışma/hafta · puan N" (geri bildirim yokken).

**Gerçek modelle deneme:** canlı Architect denemesindeki test çalışması (#1) 3 kuralla ("Veri eksikse açıkça söylüyor, sayı uydurmuyor", "Kişisel veri içermiyor", "Kaynak satırı var") puanlandı: **100**. Gerekçeler çıktıyla tutarlı. Maliyet **0,005 $**.

**Testler:** backend 433 (hepsi geçti). Yeni `AgentEvaluationTest` 5:
- Puanlamaya sadece çıktı ve kurallar gidiyor: ham sorgu sonucu, araç girdisi ve konuşma gitmiyor, tek mesaj (test 17); puan hesabı; `ai_calls`; uyuşmayan cevap.
- Puanlama işi sadece değerlendirme açıkken ve sohbet dışı çalışmalarda kuyruğa giriyor.
- Kural ayarı yetkisi ve doğrulaması.
- Puanların çalışmada, tabloda, genel bakışta, kartta ve sekmede görünmesi.
- Elle yeniden puanlama.

Vitest 62 (yeni: değerlendirme sekmesi kural ekleme, açma ve kaydetme, özet/geçme/son puanlar; kartta puan). Playwright 11: agent senaryosu çalışma ayrıntısında puan ve gerekçe, Değerlendirme sekmesi; yeni Değerlendirme görsel temeli açık/koyu, görüntüye bakıldı.

**Dokümandan sapmalar ve nedenleri**
- Puanlama sohbet çalışmalarında yapılmıyor (her mesajda bir puanlama maliyet ve gürültü olur); zamanlama, olay ve elle çalıştırmalar ile test çalışmaları puanlanıyor. Ortalamalara test çalışmaları girmiyor.
- "Rakamlar kaynak sorgularla tutarlı" gibi veriye bakan kurallarda model veriyi görmediği için çıktının kendi içindeki tutarlılığına bakıyor ve bunu gerekçede belirtiyor (puanlamaya ham veri göndermeme kuralı gereği).
- Geliştirme sırasında bulunan bir hata: değerlendirme formu kaydedince yeniden kurulup "kaydedildi" mesajını siliyordu; Vitest'te yakalanıp düzeltildi.

## Fortis Aero — A3 kapanış düzeltmeleri (2026-10-13)

- **Yerel agent #5 Architect'ten yeniden kaydedildi** (gerçek Claude): Architect'e "talimatı düz alan adlarıyla yeniden yaz, davranışı değiştirme" dendi.
  - v1 → v2. Talimattaki ham APM yolları (`delaysInfo…`, `flightTimes…`, `statusInfo.cancelled`, `totalNumberOfPages`) gitti; yerine `dep_delay_min`, `delay_codes`, `delay_reasons`, `cancelled`, `std_utc`, `atd_utc`, `total_rows` kullanılıyor.
  - Agent'ın onay politikasında kural öncesinden kalan, Architect'in kendi seçtiği "iç alıcıya onaysız" vardı. Yeni kural elle seçimi koruduğu için önce "her zaman onay"a çekildi; Architect de cevabında onaysız gönderimin sadece Yapılandırma'dan açılabileceğini söyledi.
  - **Test çalışması #2:** başarılı. 56 sefer, 6 iptal; gerçekleşen saat/rötar verisi APM test ortamında boş olduğu için rötar hesaplanmadı ve agent bunu açıkça yazdı. E-posta `preview`.
  - Değerlendirme puanı **100** (3 kural).
  - **Maliyet:** Architect 0,27 $ + test 0,31 $ (puanlama dahil) = 0,58 $.
- **Değerlendirme kurallarında veri uyarısı:** "kaynak / veri / sorgu / tutarlı / doğrulama" geçen kuralların altında uyarı: puanlayan model veriyi görmez, sadece çıktının kendi içindeki tutarlılığına bakılır (toplamlar, yüzdeler, tablo ile metin). `needsData()` + Vitest.
- **Veri cevaplarında biçim soruya göre** (prompt "Cevap düzeni"):
  - Tek sayı veya kısa durum: 1–2 cümle + bağlam, tablo yok; sayının arkasındaki kayıtlar da tabloya dökülmez, gerekirse liste aksiyon olarak önerilir.
  - 3 ve daha fazla satırlık liste veya karşılaştırma: tablo.
  - Zaman serisi: kısa tablo + trend yorumu.
  - "Neden" soruları: yorum öncelikli, tablo sadece kanıt gerekiyorsa.
  - Olası nedenler ve sonraki adımlar sadece anlamlıysa.
  - `[^q:ID]` kaynak numarası her durumda; uzun cevaplarda kaynak satırı.
  - Örneklere tek cümlelik bir cevap eklendi.
- **Eval:**
  - Yeni kontroller `expect_no_table` ("Gereksiz tablo") ve `expect_citation` ("Kaynak numarası yok").
  - Yeni sorular: `apm-flights-yesterday` ("Dün kaç sefer vardı?"), `apm-cancel-count` ("Dün kaç sefer iptal edildi?").
  - Etkilenen (tek sayılık) sorular `apm-ayt-delays-week`, `apm-otp-ayt`, `ibs-sales-yesterday`, `ibs-pax-count`, `sf-headcount` bu kontrollere alındı; kısa cevapta kaynak satırı beklenmiyor, kaynak numarası bekleniyor.
  - İlk çalıştırma **5/7** (2,55 $): `apm-cancel-count` 6 iptali, `ibs-sales-yesterday` iki para birimini tabloya döktü. Sayı soruları kuralı netleştirildi ("kayıtları tabloya dökme, listeyi aksiyon olarak öner").
  - İki soru yeniden çalıştırıldı: **2/2** (0,63 $).
  - Sonuç: 7/7, doğru araç %100, toplam 3,18 $. Raporlar: `backend/storage/app/private/eval/2026-10-13_081300.json`, `…_081411.json`.

## Faz A3 tamam (2026-10-13)

Agentlar ve Inbox (docs/fortis-aero-faz-a3.md) beş alt fazıyla bitti; git etiketi `a3-tamam`.

| Alt faz | Ne geldi |
|---|---|
| A3.1 | Agent tabloları, `AgentRunner` (A1'in araç döngüsüyle), elle çalıştırma, yazma araçları (`send_email`, `post_teams`, `deliver_report`) ve `agent_actions`, Inbox (liste, onay detayı, soru, toplu işlem), akış Pause'larının Inbox'a düşmesi |
| A3.2 | Zamanlama tetikleyicileri (Laravel'in ürettiği Kestra akışı + `X-Fortis-Key` iç uç), eşzamanlılık ve saatlik sınır, APM uçuş olayları (`flight.delayed`, `flight.cancelled`), Kestra kapalıyken Inbox uyarısı ve yeniden deneme |
| A3.3 | Oluştur ekranı, Architect (sohbet + `propose_agent` + plan görünümü), son veriyle test, pilota/canlıya alma, 4 havacılık şablonu, sohbetten agent'a |
| A3.4 | Agent Kaydı, agent sayfası (genel bakış, sohbet, çalışmalar, yapılandırma, sürümler), geri bildirim |
| A3.5 | Değerlendirme kuralları ve hızlı modelle puanlama (sadece çıktı metni) |

**Kabul kriterleri (§11):**
- [x] Agent tanımı kaydediliyor ve sürümleniyor.
- [x] Elle, zamanlamayla ve olayla çalışıyor (zamanlama gerçek Kestra ile uçtan uca henüz denenmedi; README'de adımlar var).
- [x] Yazan her aksiyon politikaya göre Inbox'a düşüyor; pilotta her gönderim onaylı ve sadece sahibine. Onaysız modlar sadece Yapılandırma'dan elle (never: admin).
- [x] Inbox agent sorularını ve akış onaylarını tek yerde topluyor.
- [x] Architect ile sohbetten agent kurulup test edildi ve pilota alındı (gerçek Claude, 0,54 $).
- [x] Kişisel veri agent çalışmalarında ve puanlamada Claude'a gitmiyor. Operasyonel kodlar yanlışlıkla maskelenmiyor.
- [x] Tasarımdaki Agent Kaydı, Agent sayfası, Oluştur, Architect ve Inbox ekranları gerçek uçlardan besleniyor.
- [x] Testler: backend 433, Vitest 62, Playwright 11, hepsi geçiyor.

**Gerçek Claude harcaması (A1 eval dahil, 2026-10-13):**
- Tam eval 6,50 $.
- Eval yeniden çalıştırmaları: 0,67 + 0,41 + 2,55 + 0,63 $.
- Architect canlı denemesi 0,54 $, #5 yeniden kayıt ve test 0,58 $, puanlama denemesi 0,005 $.
- Toplam ≈ 11,9 $.

**Açık kalanlar:**
- Zamanlamanın gerçek Kestra ile uçtan uca denenmesi (tünel, `GatewayPorts`, `FORTIS_INTERNAL_KEY` secret'ı).
- E-posta gönderen adres ve SMTP/Graph kararı (§13).
- APM'nin canlı ortamı: test ortamında gerçekleşen saat ve rötar verisi yok; rötar agentları canlı veride doğrulanmalı.
- Agent başına aylık maliyet sınırı (A4 kredileriyle).

## Fortis Aero — Faz A4.1: Bağlantılar (2026-10-13)

Plan: [docs/fortis-aero-faz-a4.md](docs/fortis-aero-faz-a4.md) §3, §4, §6, §7 (A4.1). Bitti ölçütü: admin APM'yi kapatınca sohbette APM araçları yok, APM kullanan agentların sahiplerine uyarı düşüyor. Testlerde (`ConnectorsTest`) ve Playwright'ta doğrulandı.

**§11 açık sorularında varsayımlar** (kullanıcı kararıyla varsayımla ilerlendi; A4.3'te uygulanacak):
1. Başlangıçta sadece şirket bütçesi tanımlı; departman bütçesi yok, `hard_stop` kapalı.
2. "Departman yöneticisi" kavramı yok: bütçe uyarısı sadece admin(ler)e gider (kullanıcı kararı).
3. Kredi gösterimi kapalı başlar, sadece dolar.

**Backend**
- **`connectors` tablosu** (`key`, `enabled`, `scopes`, `disabled_reason/by/at`; kimlik bilgisi yok). `ConnectorRegistry::sync()` katalogdaki her bağlantı için satır açar ve kapsamı günceller; `ConnectorSeeder` (`DatabaseSeeder`'a eklendi) aynı işi yapar. Yerelde yüklendi.
- **Katalog (`config/connectors.php`):** 11 bağlantı.
  - Veri: IBS, APM, SuccessFactors.
  - Kanal: E-posta, Teams.
  - Kurulum gerekli: Outlook, SharePoint, AirRM, Oracle, SkyBreathe, Octopus.
  - Her birinde kategori, açıklama, araçlar, agent kataloğundaki sistem adı ve `.env` değişken adı → config anahtarı eşlemesi. Kurulum gerekenlerde "Ne gerekiyor?" listesi.
  - Home çipleri ve kaynak seçici eskisi gibi beş kaynak (`chip`).
- **Kapsam etiketleri araç kataloğundan türetiliyor**, elle yazılmıyor:
  - `read`: okuma aracı var.
  - `write·approval`: yazma aracı var (e-posta, Teams).
  - `events`: `config/agents.php` → `events.*.source` (APM).
- **Durum:**
  - Önceki `connected` / `attention` / `not_configured` durumlarına `disabled` (yönetici kapattı) eklendi.
  - E-posta: `MAIL_MAILER` `log`/`array` iken "kurulum gerekli" (yerel; e-postalar log'a yazılıyor), aksi halde canlı kontrol olmadığı için "dikkat". Hiçbir kanal "Connected" görünmüyor.
  - Teams: kanal webhook'u veya Kestra secret'ı varsa yapılandırılmış sayılıyor.
- **Şirket genelinde kapatma:**
  - Kapalı bağlantının araçları (sohbet araçları + agent kataloğundaki aynı sistemin yazma araçları) `FlowTools::disabledTools()`'a eklenir: sohbette, agentta ve Architect'te Claude'a sunulmaz.
  - Çağrılırsa (agent'ta da) reddedilir ve `connector.tool_blocked` denetim kaydı düşer.
  - Sohbet izindeki kapsam adımında kaynak kapsam dışı görünür; APM kapalıyken uçuş olayları üretilmez.
  - Kapalı listesi 1 dk önbellekte; değişince temizlenir.
- **Uyarı:** kapatınca bağlantıyı kullanan agentların (şablonlar hariç, taslaklar dahil) sahiplerine sahip başına tek Inbox uyarısı (etkilenen agentların adlarıyla). Tekrar kapatmak yeni uyarı açmaz; açınca uyarılar kapanır. `connector.disable` / `connector.enable` denetim kaydı önceki/yeni değer ve gerekçeyle.
- **Gizli bilgi:**
  - Yanıtlarda `.env` için sadece değişken adı ve "tanımlı mı" var; sadece admin'e, config'ten bakılarak (config önbelleğinde `env()` boş döner).
  - Kontrol hataları `Secrets::scrub` ile temizleniyor: katalogdaki bütün `.env` değerleri, Anthropic anahtarı, iç anahtar, adres içindeki kullanıcı:şifre, Bearer/Basic başlıkları → `[gizli]`. Hata kaydedilirken ve gösterilirken iki kez temizleniyor.
- **API:**
  - `GET /api/connectors` (herkes): durum, kapsam, son kontrol, bu hafta kullanım = `ai.query` kayıtları + gönderilen aksiyonlar; özet sayılar.
  - `GET /api/connectors/{key}`: 24 saatlik kontrol geçmişi, ortalama yanıt, son hata, araçlar ve açıklamaları, kullanan agentlar, son 7 gün sohbet sorgusu, veri koruması, `.env` adları (admin), "Ne gerekiyor?", etkilenecek agent sayısı.
  - Veri koruması: `config/ai-data.php` → `masked_groups` grup adları ve kaynağa özel kurallar; değer yok.
  - Sadece admin: `POST …/check` (bağlantı başına dakikada 1, 429 + kalan süre; `connector.check` kaydı), `POST …/disable` (gerekçe zorunlu), `POST …/enable`.
- `Connectors::check($key)` tek bağlantı kontrolü (zamanlayıcıdaki `checkAll` bunu kullanıyor).

**Arayüz**
- **`#/connectors`** ("Yakında" listesinden çıktı):
  - Başlık ve açıklama, "N bağlı · N dikkat · N kurulum gerekli (· N kapalı)".
  - Sekmeler Tümü / Veri kaynakları / Kanallar / MCP; arama.
  - Gruplar: Havayolu sistemleri, Teslim kanalları, Kurulabilir.
  - Kart: 2 harf ikon, ad, açıklama, durum rozeti (Bağlı / Dikkat / Kurulum gerekli / Kapalı), kapsam etiketleri (okuma, yazma · onaylı, olaylar), son kontrol (göreli, mono), haftalık kullanım.
  - MCP sekmesi: "A6'da gelecek" boş durumu; sahte bağlantı yok.
- **`#/connectors/{key}`:**
  - Durum: 24 saatlik başarılı/başarısız şerit, son kontrol, ortalama yanıt, son hata (temizlenmiş).
  - Ne yapabilir, Kim kullanıyor (agent bağlantıları), Veri koruması, Yapılandırma (.env adları ✓/✗, sadece admin), Ne gerekiyor + "IT'ye iletmek için kopyala".
  - Admin: **Şimdi kontrol et**, **Kapat** (diyalog: etkilenecek agent sayısı + zorunlu gerekçe) / **Aç**.
- Home çiplerinde ve kaynak seçicide kapalı kaynak soluk, "Yönetici kapattı"; çip bağlantının detayına gider.

**Testler:** backend 438 (hepsi geçti). Yeni `ConnectorsTest` 5:
- Liste, detay ve durum yanıtlarında bilinen 7 gizli değerin hiçbiri yok (kontrol hatasına gömülü şifre dahil); `.env` adları sadece admin'e.
- Durum, kapsam ve kurulum kartları; yerelde e-posta Connected değil.
- Sadece admin kapatır/kontrol eder, gerekçe zorunlu.
- APM kapatılınca sohbette ve agentta araçlar yok, çağrı reddedilip kaydediliyor; sahip başına tek uyarı (şablon hariç); denetim önceki/yeni; açınca geri geliyor.
- "Şimdi kontrol et" hız sınırı.

Vitest 65 (yeni `connectors.test.tsx` 3: liste rozetleri/kapsam/sekmeler/MCP; detay ve kapatma diyaloğu; kullanıcıda admin düğmesi ve `.env` yok; IT metni). Test kurulumuna jsdom için `<dialog>` dolgusu eklendi. Playwright 12 (yeni: Bağlantılar → APM → Kapat (gerekçe) → Home'da APM çipi soluk → Aç; Bağlantılar ve APM detayı görsel temelleri açık/koyu, görüntülere bakıldı).

**Dokümandan sapmalar ve nedenleri**
- Kapsam etiketlerinde "widget" yok (tasarımda var): Fortis'te widget/gösterge kavramı yok; etiketler sadece araç kataloğundan türetiliyor.
- Kurulum gerekli kartlarında tasarımdaki "Crew & ops scheduling, MRO, Baggage" yerine görev dosyasındaki liste (Outlook, SharePoint, AirRM, Oracle, SkyBreathe, Octopus).
- `.env` "tanımlı mı" bilgisi config'teki etkin değere bakıyor: varsayılanı olan değişkenler (ör. `APM_GRANT_TYPE`, `MAIL_PORT`) `.env`'de yazmasa da tanımlı görünür.
- Bağlantıyı kapatmak zamanlanmış kontrolleri durdurmuyor (sağlık bilgisi görünmeye devam etsin diye); sadece kullanım kapanıyor.

## "Dün" tarih düzeltmesi (2026-10-13)

**Sorun:** Sohbet promptunda şimdiki zaman yoktu; model tarihi veriden veya tahminle çıkarıyordu. Önceki eval'de 13 Ekim'de sorulan "Dün AYT'den zamanında kalkış oranı" sorusu "Dünkü (11 Ekim 2026)" diye cevaplanmıştı. Sadece agent bölümünde "Bugün: …" satırı vardı.

**Düzeltme:**
- **`App\Ai\PromptClock`:** sistem promptunun **en sonunda** "Şimdiki zaman" bloğu.
  - Türkiye saatiyle şu an (gün adıyla) ve UTC karşılığı.
  - Göreli tarihler Türkiye saatine göre: bugün, dün, yarın, bu hafta (Pzt–Paz), geçen hafta, bu ay, geçen ay. Her biri için Türkçe tarih ve sorguda kullanılacak UTC aralığı [başlangıç, bitiş).
  - Kurallar: APM `dateFrom`/`dateTo` bu UTC aralıklarıyla; IBS ve SuccessFactors'ta gün sütunlarında Türkiye tarihi, zaman damgasında UTC; cevapta tarih açıkça ("Dün (12 Ekim 2026)").
- **Promptta yeri:** Sistem promptu iki blok olarak gidiyor. Sabit kısım `cache_control` ile önbelleğe alınıyor; saat bloğu en sonda.
  - Blok tur başında bir kez üretiliyor: aynı turdaki araç döngüsünde değişmiyor, önbellek bozulmuyor.
  - Sohbet, agent ve Architect aynı yoldan geçiyor. Agent bölümündeki "Bugün:" satırı kaldırıldı; agent da aynı bloğu alıyor.
- `system.md`'deki APM tarih kuralı "UTC aralıklarını Şimdiki zaman bölümünden al, kendin hesaplama" olarak güncellendi.
- **Eval:**
  - Soru başına sabit "şimdi" (`now`) desteği.
  - Yeni kontroller: cevapta beklenen Türkiye tarihi (`expect_date`); sorguda doğru aralık (`expect_utc_from`: APM'de UTC başlangıcı, IBS'te Türkiye'deki ilk gün).
  - 4 yeni soru: `date-yesterday-ayt`, `date-today`, `date-last-week-cancels`, `date-midnight-yesterday` (şimdi = 13 Ekim 00:30 TR).
- **Sonuç (gerçek model):** 4/4.
  - Gece 00:30 sorusu "Dün (12 Ekim 2026)" dedi ve `2026-10-11T21:00Z` başlangıcıyla sorguladı.
  - "Geçen hafta" sorusu IBS'ten `flight_date` 5–11 Ekim ile cevapladı. İlk çalıştırmada beklenen araç sadece APM olduğu için kalmıştı; kontrol IBS'teki Türkiye tarihini de kabul edecek şekilde genişletildi.
  - Bir çalıştırma, düzenleme betiği yarıda kalınca eski kontrolle tekrarlandı (0,13 $ boşa).
  - Maliyet: 0,52 + 0,13 + 0,14 = 0,79 $.
  - Raporlar: `backend/storage/app/private/eval/2026-10-13_084655.json`, `…_084823.json`.
- İki bloklu sistem promptu ve önbellek ayarı gerçek API'de sorunsuz çalıştı.

**Testler:** backend 443 (hepsi geçti). Yeni `PromptClockTest` 3:
- Gece 00:30'da "dün" Türkiye'deki önceki gün ve doğru UTC aralığı (23:59 da aynı gün).
- Ay ve hafta sınırları (1 Aralık 00:10).
- Saat bloğu sohbet ve agent isteklerinde son sistem bloğu, sabit blokta `cache_control`.

`RunEvalTest` +2: sabit 00:30 saatle doğru tarih/aralık geçer, UTC'ye göre "dün" kalır; saat sabitlemesi soru sonrası kalkar.

## Fortis Aero — Faz A4.2: Yönetişim Genel bakış (2026-10-13)

Plan: [docs/fortis-aero-faz-a4.md](docs/fortis-aero-faz-a4.md) §5.1, §6, §7 (A4.2). Bitti ölçütü: sınırı aşan test çalışması "Dikkat gerekenler"de görünüyor; admin durdurabiliyor. `GovernanceTest` (gerçek `AgentRunner` + sahte Claude ile araç sınırı aşımı) ve Playwright'ta doğrulandı.

**Backend**
- **`governance_alerts`** (tür, önem, hedef, mesaj, meta, çözüldü/çözen) ve **`App\Services\Governance\GovernanceAlerts`**. Aynı konu (tür + hedef) için açık uyarı varken yenisi açılmaz, mesajı güncellenir.
  - **Bağlantı:** canlı kontrol edilebilen bağlantı 30 dakikadan uzun süredir başarılı kontrol almadı. Düzelince kendiliğinden kapanır.
  - **Bütçe:** şirket bütçesinin `ai.budget_alert_ratio`'su (%80) aşıldı → uyarı; doldu → kritik (uyarı kapanır). Mevcut şirket bütçesiyle; departman bütçeleri A4.3'te.
  - **Agent sınır aşımı** (araç sayısı, maliyet, süre; test çalışmaları dahil): kritik. Sınır aşıldığı anda açılır (`agent.run.limit` denetim kaydıyla), tarama da yakalar. Olay uyarısı: admin "Çözüldü" deyince kapanır ve tarama yeniden açmaz.
  - **Üst üste 3 başarısız** çalışma (test hariç). Başarılı çalışmayla kapanır.
  - **Düşük puan:** son 10 puanlanmış çalışmanın ortalaması 60'ın altında. Yükselince kapanır.
  - **İzinsiz araç denemesi** (`agent.tool_blocked`, son 24 saat, agent başına sayıyla). Olay uyarısı.
  - **24 saatten uzun bekleyen onay** (Inbox onay öğeleri: agent aksiyonları ve akış Pause'ları). Onaylanınca kapanır.
- **`php artisan governance:scan`**, zamanlayıcıda 5 dakikada bir.
- **Çalışma durdurma** (`POST /api/governance/runs/{id}/stop`, `kind` agent/flow, gerekçe zorunlu):
  - Agent çalışması iptal olur, bekleyen aksiyonları reddedilir, Inbox öğeleri kapanır.
  - Çalışıyorsa ClaudeChat bir sonraki turda durumu görüp durur. Kuyruk işi sonradan hata verse de çalışma "başarısız"a dönmez, sahibine ayrıca uyarı gitmez.
  - Akış çalışması Kestra'da durdurulur (`ExecutionService::kill`).
  - `governance.run.stop` denetim kaydı.
- **`GET /api/governance/overview`** (admin):
  - **Canlı:** kuyrukta/çalışan/soru veya onay bekleyen agent çalışmaları ve bitmemiş akış çalışmaları (son 24 saat). Başlatan, süre, araç çağrısı, anlık maliyet (`ai_calls`).
  - **Bu ay** (Türkiye ayı): harcama ve bütçe oranı; geçen ayın aynı dönemiyle fark; sohbet soruları; agent çalışmaları (test hariç); onaylanan/reddedilen aksiyonlar.
  - **Dikkat gerekenler:** önem sırasıyla, "Git" bağlantısıyla.
  - **Kalite:** setin en az %70'ini kapsayan son tam eval çalıştırması, sonrasında yapılan kısmi çalıştırma ayrıca; agentların 30 günlük ortalama puanı, en düşük 5 agent.
- `GET /api/governance/alerts` (`?resolved=1` son çözülenler), `POST …/alerts/{id}/resolve` (`governance.alert.resolve` kaydı). Hepsi sadece admin.
- `Execution::triggeredBy` ilişkisi eklendi.

**Arayüz**
- **Yönetişim** sekmeli: **Genel bakış** (admin) ve **Kullanım** (mevcut kullanım ve maliyet ekranı, `#/governance/usage`). Admin olmayan kullanıcı doğrudan Kullanım'ı görür. Eski `#/usage` yeni adrese yönleniyor. Politikalar ve Denetim kaydı A4.4'te.
- **Genel bakış:**
  - "Canlı · HH:MM" etiketi; 10 saniyede bir yenilenir.
  - 6 kutu: harcama + bütçe çubuğu, geçen aya göre, şu an çalışan (kaçı kişi bekliyor), soru, agent çalışması, onaylanan/reddedilen.
  - Canlı çalışmalar tablosu: ad (çalışmaya bağlantı), tür · başlatan · test, durum, süre, araç, maliyet, **Durdur** (gerekçeli diyalog).
  - Dikkat gerekenler: önem noktası, mesaj, ne zaman, **Git**, **Çözüldü**.
  - Kalite şeridi.

**Yerel veriyle kontrol:**
- `governance:scan` 3 bağlantı uyarısı üretti (yerelde zamanlayıcı çalışmadığı için son kontroller 11 saat önce; doğru).
- Canlı'da DemoSeeder'ın iki duraklatılmış demo akışı görünüyor.
- Kalite son tam çalıştırma olarak A1'deki 18 soruluk eval'i gösteriyor.
- Bu kontrolde iki düzeltme yapıldı: bağlantı mesajındaki çift nokta; kalite kutusunun son *kısmi* eval'i (1/1) tam sonuç gibi göstermesi.

**Testler:** backend 449 (hepsi geçti). Yeni `GovernanceTest` 6:
- Sadece admin.
- Canlı agent/akış satırları; bu ayın harcaması `ai_calls` ile aynı (geçen ay hariç).
- Agent ve akış durdurma: iptal, aksiyon reddi, Inbox kapanışı, denetim kaydı, ikinci durdurma 409, sonradan gelen hata iptali bozmaz.
- Araç sınırını aşan test çalışması anında kritik uyarı; admin çözer, tarama yeniden açmaz.
- 7 uyarı türünün her biri bir kez üretiliyor; bütçe dolunca kritik; koşullar düzelince olay dışı uyarılar kapanıyor.
- Kalite: son tam eval + kısmi not.

Vitest 67 (yeni `governance.test.tsx` 2). Playwright 13 (yeni: Yönetişim → sınır uyarısı ve Git bağlantısı → Durdur (gerekçe) → Çözüldü → Kullanım sekmesi; Yönetişim ve Kullanım görsel temelleri açık/koyu, görüntüye bakıldı).

**Dokümandan sapmalar ve nedenleri**
- Bütçe uyarısı şimdilik mevcut şirket bütçesi ve `ai.budget_alert_ratio` ile; `budgets` tablosu, departman bütçeleri ve admin'e Inbox uyarısı A4.3'te (§11 varsayımı: uyarı admine).
- 24 saatlik onay uyarısının "Git" bağlantısı yok: onay, sahibinin Inbox'ında; mesaj kimin onayı olduğunu söylüyor.
- Canlı listede akışların araç sayısı yok (Kestra adımlarında araç çağrısı kavramı yok).

## Fortis Aero — Faz A4.3: Kullanım ve bütçeler (2026-10-13)

Plan: [docs/fortis-aero-faz-a4.md](docs/fortis-aero-faz-a4.md) (A4.3). Bitti ölçütü: departman bütçesi dolunca o departmandan yeni soru başlamıyor, admin kullanabiliyor. `BudgetTest` (gerçek mesaj ve agent uç noktalarıyla) ve Playwright'ta (bütçe kaydetme) doğrulandı.

**Backend**
- **`budgets` tablosu** (`App\Models\BudgetLimit`): kapsam şirket/departman/kullanıcı, aylık limit ($), uyarı eşiği (%80), "dolunca durdur" (`hard_stop`), bu ay gönderilen uyarılar. Eski `budget.monthly_usd` ayarı şirket satırına taşındı. Limit varsa `hard_stop` açık geliyor; eskiden şirket bütçesi dolunca sohbet duruyordu, davranış aynı kalıyor.
- **`ai_calls.department_id`**: çağrı anındaki kullanıcının departmanı. Eski kayıtlar için `fortis:usage:backfill-departments` var (yerelde eski kayıtların hiçbirinde departman yoktu, 0 kayıt güncellendi).
- **`App\Services\Usage\Budget`** yeniden yazıldı:
  - Ay Türkiye saatine göre.
  - Kapsam başına harcama 1 dakika önbellekte; her `AiCall` kaydında önbellek artırılıyor, böylece her soruda toplama sorgusu çalışmıyor.
  - **Dolunca durdur:** kullanıcının departman, kişisel veya şirket bütçesinden biri dolmuşsa yeni soru 402 ile reddedilir ("Departman bütçesi bu ay doldu; yöneticinize başvurun."). Adminler muaf.
  - **Agent:** elle başlatma 402. Tetikleyici çalışmayı atlar (`agent.run.skipped`, sebep `budget`). Kuyrukta bekleyen çalışma başlamadan "başarısız" olur. Başlamış çalışma bitirilir. Kimin bütçesine bakılacağı: çalışmayı başlatan; yoksa agent sahibi.
  - **Akış içindeki AI adımı:** şirket bütçesi dolduysa ve eski ayar açıksa ya da şirket bütçesinde "dolunca durdur" açıksa hata verir.
  - **Uyarılar:** eşik aşılınca ve bütçe dolunca bütün adminlere Inbox uyarısı gider, her bütçe için ayda bir. Şirket eşiğinde ayrıca eski `BudgetAlert` e-postası gider. Yönetişim'deki bütçe uyarıları artık bütün bütçe satırlarını kapsıyor.
- **`App\Services\Usage\UsageExplorer`**: filtreler tarih aralığı (Türkiye günü), departman, kullanıcı, model ve tür (sohbet, agent, başlık, puanlama, eval, akış adımı). Çıktısı: toplamlar, gün×tür, tür/departman/model kırılımı, en çok harcayan 10 agent ve 10 kullanıcı, son 200 satır.
- **Uç noktalar** (hepsi sadece admin):
  - `GET /api/governance/usage`: rapor. `?format=csv` ile en çok 50.000 satır CSV indirilir (`usage.export` denetim kaydı; kredi tanımlıysa kredi sütunu da eklenir).
  - `GET/PUT /api/governance/budgets`: bütçeler ve kredi değeri.
    - Limiti boş bırakılan departman/kullanıcı bütçesi silinir.
    - Her değişiklik `settings.update` kaydına önceki ve yeni değeriyle yazılır.
    - Limit veya eşik değişince o ayın uyarıları yeniden gönderilebilir.
- Ayarlar ekranındaki "aylık bütçe" artık şirket bütçe satırını okuyup yazıyor.

**Arayüz**
- **Yönetişim > Kullanım** (admin) yeniden yapıldı:
  - Filtreler: tarih aralığı, departman, kullanıcı, model, tür.
  - 4 kutu: toplam maliyet (kredi tanımlıysa "≈ N kredi"), çağrı (çağrı başına maliyet), giriş token, çıkış token.
  - Türe göre yığılmış günlük harcama grafiği.
  - Departman ve model kırılımı; en çok harcayan 10 agent (agent sayfasına bağlantılı) ve 10 kullanıcı.
  - Son 200 AI çağrısı tablosu; filtreyle aynı **CSV indir**.
- **Bütçeler** bölümü (Kullanım'ın altında): şirket ve her departman için bu ayki harcama ve oran (eşikte sarı, dolunca kırmızı), aylık limit, uyarı %, "dolunca durdur", kredi değeri. **Kaydet** önce değişiklik listesini onay diyaloğunda gösterir.
- Admin olmayan kullanıcı sekmeleri görmez; kendi Kullanım ekranını (eski ekran) görür.
- Bütçe dolunca sohbet ve agent başlatma ekranlarında sunucunun 402 mesajı gösteriliyor.

**Yerel veriyle kontrol:** bu ayın toplamı Kullanım'da ve Genel bakış'ta aynı: 20,1994 $. Yerelde şirket limiti tanımsız.

**Testler:** backend 456 (hepsi geçti). Yeni `BudgetTest` 5:
- Eşik ve dolma uyarıları sadece admine, ayda bir.
- Dolunca durdur: sadece o departmanın soruları ve agent çalışmaları duruyor; diğer departman ve aynı departmandaki admin etkilenmiyor; tetikleyici atlanıp kayda geçiyor.
- Kuyruktaki çalışma başlamıyor, başlamış çalışma bitiyor.
- Önbellekteki aylık toplam veritabanıyla aynı (Türkiye ayı sınırı dahil).
- Bütçe API'si: sadece admin, doğrulama, önceki/yeni değer kaydı, boş limitle silme, kredi değeri.

Yeni `GovernanceUsageTest` 2 (filtreler, kırılımlar, CSV ve denetim kaydı). `UsageTest` ve `GovernanceTest` yeni tabloya uyarlandı.

Vitest 70 (yeni `usage.test.tsx` 3: kutular, kırılımlar, filtrenin sorguya ve CSV'ye geçmesi; kredi gösterimi; bütçe kaydetmenin onaydan sonra gitmesi). Playwright 13: Yönetişim senaryosu Kullanım → departman filtresi → Operasyon'a dolunca duran bütçe → onay → kaydedildi şeklinde uzatıldı; Kullanım görsel temeli açık/koyu yeniden üretildi ve görüntüye bakıldı.

**Dokümandan sapmalar ve nedenleri**
- Taşınan eski şirket bütçesinde "dolunca durdur" açık geliyor (eski davranış korunsun diye). Yeni bütçelerde varsayılan kapalı.
- Bütçe uyarısı sadece adminlere gidiyor (§11 varsayımı; departman yöneticisi rolü yok).
- Kredi gösterimi kapalı başlıyor; admin kredi değerini girince açılıyor. Hesap her zaman dolardan.
- Departman bütçeleri şimdilik Kullanım sekmesinde yönetiliyor; Politikalar sekmesi A4.4'te. Kişisel (kullanıcı) bütçe API'de ve uygulamada var, arayüzde henüz yok.
- Bütçe dolunca başlamış agent çalışmaları ve sohbetteki devam eden tur kesilmiyor; sadece yenileri başlamıyor.
- Önbellek 1 dakika: aynı anda çalışan birkaç sunucu işçisi bütçeyi birkaç sent aşabilir.

## Çalıştırma

```sh
# Backend (http://127.0.0.1:8000). --no-reload: .env'deki PHP_CLI_SERVER_WORKERS=4 ile aynı anda birden fazla istek
cd backend && php artisan serve --no-reload

# Frontend (http://localhost:5173)
cd frontend && npm run dev

# Kestra bağlantısı
cd backend && php artisan kestra:ping && php artisan kestra:smoke-test --cleanup

# Katalog ve örnek akışların doğrulaması (Kestra sürümü değişince)
cd backend && php artisan kestra:check-catalog

# İlk kurulum: departmanlar ve admin (FORTIS_ADMIN_EMAIL); geçici şifre konsola yazılır
cd backend && php artisan migrate && php artisan db:seed
cd backend && php artisan fortis:user:create ayse@freebird.example --role=builder --department=Finance

# Bağlantı kontrolü (zamanlayıcıda 10 dakikada bir) ve yerel demo verisi
cd backend && php artisan connectors:check
cd backend && php artisan db:seed --class=DemoSeeder   # sadece APP_ENV=local

# Değerlendirme seti (gerçek API, ücretli; ai.data_access = masked gerekir)
cd backend && php artisan fortis:eval --only=apm-top-delays

# Testler
cd backend && php artisan test
cd frontend && npm test && npm run e2e

# Büyük raporlar (5.000 satır üstü) için kuyruk işçisi
cd backend && php artisan queue:work

# Agent çalışmaları (A3.1): kuyruk işçisi ve elle çalıştırma
cd backend && php artisan queue:work --queue=agents
cd backend && php artisan fortis:agent:run sabah-operasyon-ozeti --test

# Zamanlayıcı (bağlantı kontrolü, uçuş olayları, Kestra'ya aktarılamayan zamanlamaların yeniden denenmesi)
cd backend && php artisan schedule:work
cd backend && php artisan fortis:agent:sync-triggers --all   # bütün zamanlamaları Kestra'ya yeniden yükle

# Eski ai_calls kayıtlarına departman yaz (A4.3; bir kez)
cd backend && php artisan fortis:usage:backfill-departments
cd backend && php artisan governance:scan                    # Yönetişim "Dikkat gerekenler" (zamanlayıcıda 5 dk)

# Sesli komut: Whisper servisi (http://127.0.0.1:9000). İlk açılışta model (~500 MB) iner.
cd infra/whisper && python3 -m venv .venv && .venv/bin/pip install -r requirements-dev.txt   # bir kez
cd infra/whisper && .venv/bin/uvicorn app:app --host 127.0.0.1 --port 9000 --no-access-log
cd infra/whisper && .venv/bin/pytest -q tests && WHISPER_REAL=1 .venv/bin/pytest -s tests/test_accuracy.py
```

## Açık konular

- [ ] Kestra'yı internete kapatma ve 2.0.4-slim'e geçiş (`infra/kestra/README.md`)
- [ ] Kestra'da kurulacak eklentiler, bildirim kanallarına göre (SMTP / Slack / Teams). Sunucu şu an tam kurulum; 2.0.4-slim'e geçerken sadece katalogdaki eklentiler kurulmalı.
- [ ] Kestra'ya secret'ların eklenmesi (SMTP_HOST, SMTP_PORT, SMTP_USERNAME, SMTP_PASSWORD, SMTP_FROM, SLACK_WEBHOOK_URL, TEAMS_WEBHOOK_URL) ve isimlerinin `backend/.env` → `KESTRA_SECRETS` satırına yazılması
- [x] Uygulamaya erişim kısıtı: Faz A0'da giriş, roller ve yetki eklendi
- [x] YAML değişmeden yapılan güncellemede Kestra yeni revision oluşturmuyor; aynı YAML tekrar önerilince yeni sürüm açılmıyor.
