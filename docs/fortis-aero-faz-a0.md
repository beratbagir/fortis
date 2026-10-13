# Görev: Fortis Aero — Faz A0: Temel (Giriş, Kullanıcılar, Kabuk, Tema)

Önce `docs/fortis-aero-yol-haritasi.md` dosyasını oku. Tasarım referansı: `docs/design/fortis-aero-product-screens.pdf`.

## 1. Amaç

Tasarımdaki bütün ekranların dayandığı zemini kurmak:

1. **Giriş ve kullanıcılar:** Uygulamaya sadece giriş yapan kişiler erişir. Her kullanıcının departmanı ve rolü olur.
2. **Yeni kabuk:** Tasarımdaki kenar çubuğu, menü ve sayfa düzeni.
3. **Tema:** Fortis Aero görsel dili. Açık ve koyu mod.

Bu fazda **yeni özellik ekranı yapılmaz.** Var olan ekranlar (Sohbet, Agentlar, Akışlar, editör, Çalışmalar, Kullanım) yeni kabuğa ve temaya taşınır. Tasarımda olup henüz yapılmamış menü öğeleri "Yakında" olarak görünür.

---

## 2. Kesin kurallar

1. **Hiçbir API uç noktası girişsiz çalışmaz.** `/api/internal/*` uçları (Kestra'nın kullandığı, `X-Fortis-Key` ile korunan) hariç. Bunun için bir test yazılır: route listesi taranır, `auth` middleware'i olmayan `/api/*` rotası varsa test kırılır.
2. **Var olan davranış bozulmaz.** Mevcut 280 backend testi, Vitest ve Playwright testleri geçmeye devam eder. Testler yeni girişe göre güncellenir (`actingAs`), mantıkları değiştirilmez.
3. **Şifreler düz metin tutulmaz,** Laravel'in varsayılan hash'i kullanılır. Oturum çerezi `HttpOnly`, `SameSite=Lax`, canlıda `Secure`.
4. **Renkler tek yerde.** Bütün renkler CSS değişkeni (tema token'ı) olarak tanımlanır. Bileşenlerde sabit renk kodu (`#...`) kullanılmaz.

---

## 3. Giriş ve kullanıcılar

### 3.1 Yöntem

İki sürücü desteklenir, `.env` ile seçilir:

```env
AUTH_DRIVER=password        # password | entra
```

- **`password` (şimdilik varsayılan):** E-posta + şifre. Laravel Sanctum, SPA oturum (cookie) modu. Kayıt ekranı yok; kullanıcıları admin oluşturur (`php artisan fortis:user:create` komutu ve admin ekranı).
- **`entra` (sonra):** Microsoft Entra ID ile OpenID Connect (Laravel Socialite + `socialiteproviders/microsoft`). Bu fazda kodun iskeleti ve yapılandırması hazırlanır. Gerçek test, IT Entra uygulamasını açınca yapılır. İlk girişte kullanıcı yoksa oluşturulmaz, "Erişimin yok, yöneticine başvur" mesajı gösterilir. Sadece admin'in eklediği e-postalar girebilir.

### 3.2 Tablolar

| Tablo | Kolonlar |
|---|---|
| `departments` | `id`, `name` (Commercial, Finance, Revenue Mgmt, Fuel Efficiency, Network Planning, Customer Relations, Flight Ops, Ground Ops), `slug`, `created_at` |
| `users` (mevcut tabloya ek) | `department_id`, `role` (`admin` / `builder` / `user`), `title` (görev unvanı), `avatar_color`, `is_active`, `last_login_at`, `entra_object_id` (nullable, unique) |

Roller:

| Rol | Yapabilir |
|---|---|
| `user` | Sohbet eder, kendisiyle paylaşılan agentları kullanır, kendi Inbox'ını görür |
| `builder` | `user` + agent (akış) oluşturur, düzenler, yayınlar |
| `admin` | `builder` + kullanıcı ve departman yönetimi, ayarlar, bütçe, bağlantılar, Governance |

Yetki kontrolü Laravel **Policy** ve **Gate** ile yapılır, controller'lara dağıtılmaz. Bu fazda:
- Ayarlar, bütçe, kullanıcı yönetimi: sadece `admin`.
- Akış oluşturma/düzenleme/yayınlama: `builder` ve `admin`.
- Diğer her şey: giriş yapmış herkes.

### 3.3 Sahiplik

Var olan tablolara sahip bilgisi eklenir (nullable, mevcut kayıtlar için migration'da ilk admin'e atanır):

| Tablo | Ek kolon |
|---|---|
| `conversations` | `user_id` |
| `flows` | `owner_id`, `department_id` |
| `executions` | `triggered_by_user_id` (zamanlanmış çalışmalarda null) |
| `ai_calls` | `user_id`, `department_id` |
| `reports`, `artifacts` | `user_id` (yoksa) |

- Sohbet listesi sadece kullanıcının kendi sohbetlerini gösterir. Admin hepsini görebilir.
- Agent ve akış listesi şimdilik herkese açık kalır (paylaşım Faz A6'da).

### 3.4 Uç noktalar

| Uç | Ne yapar |
|---|---|
| `GET /sanctum/csrf-cookie` | Sanctum |
| `POST /api/auth/login` | E-posta + şifre. 5 hatalı denemede 1 dakika kilit (`throttle`). |
| `POST /api/auth/logout` | |
| `GET /api/auth/me` | Kullanıcı, departman, rol, tema tercihi |
| `GET /api/auth/entra/redirect`, `GET /api/auth/entra/callback` | Entra iskeleti |
| `GET/POST/PATCH /api/admin/users` | Kullanıcı yönetimi (admin) |
| `GET/POST/PATCH /api/admin/departments` | Departman yönetimi (admin) |
| `PATCH /api/me/preferences` | Tema (`light` / `dark` / `system`) |

### 3.5 Denetim kaydı (temel)

`audit_logs` tablosu bu fazda açılır, çünkü Governance (Faz A3) buna dayanacak:

`id`, `user_id` (nullable), `actor_type` (`user` / `agent` / `system`), `actor_label`, `action` (örn. `auth.login`, `flow.publish`, `execution.resume`, `report.download`), `subject_type`, `subject_id`, `system` (APM, IBS, Outlook…), `cost_usd` (nullable), `result` (`allowed` / `blocked` / `awaiting_approval` / `failed`), `meta` (json), `ip`, `created_at`.

- Kayıtlar **güncellenmez ve silinmez.** Model'de `update` ve `delete` exception fırlatır.
- Bu fazda şu olaylar yazılır: giriş, çıkış, başarısız giriş, akış yayınlama, çalışma onaylama/reddetme, rapor indirme, ayar değişikliği, kullanıcı ekleme/değiştirme.
- Var olan `report_downloads` kaydı korunur, ayrıca `audit_logs`'a da yazılır.

---

## 4. Kabuk (layout)

Tasarımdaki sol kenar çubuğu, birebir:

```
[logo] fortis                         [daralt]
(+) New chat

Chats
Inbox                 (rozet)  → Yakında
Required actions      (rozet)  → Yakında
Agents
Build
Connectors                     → Yakında
Artifacts                      → Yakında

WORKSPACE
Shared agents                  → Yakında
Governance                     → şimdilik Kullanım ve maliyet ekranı
Memory                         → Yakında
Knowledge                      → Yakında
Skills                         → Yakında

RECENTS
(kullanıcının son 3–5 sohbeti)

┌ Credits left        [sayı] ┐
│ [$ harcanan]   of [kota]   │  → şimdilik bütçe verisinden
└────────────────────────────┘
[ ☀ Light ][ ☾ Dark ]
(avatar) Ad Soyad
         Freebird Airlines · Rol
```

- Menü dili: Tasarım İngilizce. **Arayüz dili `config/app.php` → `locale` ile seçilir** (`tr` / `en`), metinler `frontend/src/i18n/{tr,en}.json` dosyalarına alınır. Varsayılan `tr`. Var olan Türkçe metinler bu dosyalara taşınır. Hazır bir i18n kütüphanesi yerine küçük bir `t('key')` yardımcı fonksiyonu yeterli.
- "Yakında" öğeleri tıklanabilir ama boş bir durum sayfası açar: başlık, bir cümle açıklama, "Bu ekran sonraki fazda gelecek".
- Kenar çubuğu daraltılabilir (sadece ikonlar). Tercih tarayıcıda saklanır.
- Telefon genişliğinde kenar çubuğu çekmeceye (drawer) dönüşür.
- Kullanıcı menüsü (avatar): Profil, Tema, Çıkış. Admin için: Kullanıcılar, Departmanlar, Ayarlar.
- Router: mevcut hash router'a yeni rotalar eklenir (`#/inbox`, `#/actions`, `#/connectors`, `#/artifacts`, `#/shared`, `#/governance`, `#/memory`, `#/knowledge`, `#/skills`, `#/build`, `#/admin/users`, `#/admin/departments`, `#/login`). `#/usage` → `#/governance`'a yönlenir.
- Giriş yapılmamışsa her rota `#/login`'e gider, girişten sonra istenen sayfaya döner.

---

## 5. Tema ve görsel dil

PDF'teki ekranlardan alınacak. Değerler PDF'ten ölçülerek kesinleştirilmeli; aşağıdakiler başlangıç noktası:

| Token | Açık | Koyu | Not |
|---|---|---|---|
| `--brand` | `#B4233A` (crimson) | `#E0475E` | Koyuda kontrast için açılmış (tasarım notu) |
| `--bg` | `#FFFFFF` | `#141012` | |
| `--bg-subtle` (kenar çubuğu) | `#F6F5F5` | `#1B1618` | |
| `--surface` (kart) | `#FFFFFF` | `#1F1A1C` | |
| `--border` | `#E8E5E5` | `#2E272A` | |
| `--text` | `#1A1517` | `#F2EEEF` | |
| `--text-muted` | `#6B6266` | `#A79CA1` | |
| `--success` / `--warning` / `--danger` / `--info` | yeşil / amber / kırmızı / mavi | açılmış tonları | Rozetler: Live, Pilot, Draft, Template, Connected, Attention |

- **Tipografi:** Başlıklar serif (tasarımdaki "Good morning, Kerem", "Agent Registry" gibi), gövde sans-serif, sayı ve saatler monospace. Google Fonts'tan benzerleri seçilir (örneğin serif için Source Serif 4, gövde için Geist veya IBM Plex Sans, mono için Geist Mono veya JetBrains Mono). Lisansı açık fontlar kullanılır.
- **Logo:** Tasarımdaki dört köşeli yıldız. Basit bir SVG olarak çizilir (`frontend/src/assets/logo.svg`). Gradyan crimson tonlarında.
- Tailwind v4 kullanılıyor: token'lar `@theme` içinde CSS değişkeni olarak tanımlanır, koyu mod `[data-theme="dark"]` seçicisiyle değişkenleri değiştirir.
- Tema tercihi: `system` (varsayılan), `light`, `dark`. Kullanıcı tercihine (`/api/me/preferences`) kaydedilir. Sayfa ilk yüklenirken yanıp sönme olmaması için `index.html` içinde küçük bir script tema niteliğini önceden ayarlar.
- Var olan ekranlarda sabit renkler token'lara çevrilir. Görsel akış editörü (React Flow) ve Monaco editör de koyu temaya uyar.
- Erişilebilirlik: metin kontrastı en az 4.5:1 (her iki temada), odak halkası görünür, ikon butonlarda `aria-label`.

---

## 6. Giriş ekranı

- Ortada logo, "Fortis" serif başlık, kısa alt başlık.
- `AUTH_DRIVER=entra` ise tek buton: "Microsoft ile giriş yap". `password` ise e-posta, şifre, Giriş.
- Hatalı girişte genel mesaj ("E-posta veya şifre hatalı"), hangi alanın yanlış olduğu söylenmez.

---

## 7. Admin ekranları (basit)

- **Kullanıcılar:** Liste (ad, e-posta, departman, rol, son giriş, aktif). Ekle, düzenle, pasif yap. Silme yok.
- **Departmanlar:** Liste, ekle, yeniden adlandır.
- Seed: Tasarımdaki departmanlar ve bir admin kullanıcı (`.env` → `FORTIS_ADMIN_EMAIL`, şifre ilk çalıştırmada konsola yazılır ve ilk girişte değiştirilmesi istenir).

---

## 8. Testler

**Backend (Pest):**

1. Route taraması: `/api/internal/*` dışındaki her `/api/*` rotasında `auth:sanctum` var.
2. Girişsiz istek `401`. Yanlış şifre `422`, 6. denemede `429`.
3. Rol kontrolü: `user` akış yayınlayamaz (`403`), `builder` yayınlayabilir. `user` ve `builder` ayar ve bütçe değiştiremez.
4. Sohbet görünürlüğü: Kullanıcı başkasının sohbetini açamaz (`404`), admin açabilir.
5. Sahiplik: Yeni sohbet, akış, rapor ve `ai_calls` kaydı oturumdaki kullanıcıyla ilişkilendiriliyor.
6. Denetim kaydı: Giriş, başarısız giriş, yayınlama, onaylama ve rapor indirme kayıt düşüyor. `AuditLog` güncellenemiyor ve silinemiyor.
7. Pasif kullanıcı giriş yapamıyor.
8. Entra iskeleti: Callback'te admin'in eklemediği e-posta reddediliyor (Socialite sahte sağlayıcıyla).
9. Var olan bütün testler `actingAs` ile güncellenmiş halde geçiyor.

**Frontend (Vitest):**

10. Girişsiz kullanıcı `#/login`'e yönleniyor, girişten sonra istediği sayfaya dönüyor.
11. Tema geçişi `data-theme` niteliğini değiştiriyor ve tercihi kaydediyor.
12. "Yakında" öğeleri boş durum sayfasını açıyor.
13. `user` rolünde "Build" ve admin menüsü görünmüyor.

**Playwright:**

14. Giriş → Sohbet → yeni sohbet → Agentlar → koyu tema → çıkış.
15. Açık ve koyu temada ana ekranların ekran görüntüsü (görsel regresyon için temel).

---

## 9. Kabul kriterleri

- [ ] Girişsiz hiçbir ekran ve API (iç uçlar hariç) açılmıyor.
- [ ] Var olan bütün ekranlar yeni kabukta ve yeni temada çalışıyor.
- [ ] Açık ve koyu tema her ekranda okunaklı (kontrast kontrolü yapıldı).
- [ ] Admin kullanıcı ve departman ekleyebiliyor.
- [ ] Bütün testler geçiyor.
- [ ] `ILERLEME.md` güncellendi.

---

## 10. Kapsam dışı

- Inbox, Required actions, Connectors, Artifacts, Memory, Skills, Knowledge, Shared agents ekranlarının içi (sonraki fazlar)
- Home ekranının yeni tasarımı (Faz A1)
- Agent paylaşımı ve kişiye özel izin kapsamı (Faz A6)
- Kredi hesabı (Faz A3). Kenar çubuğundaki kutu bu fazda mevcut bütçe verisini gösterir.

---

## 11. Açık sorular

- Giriş Microsoft Entra ID ile mi olacak? IT'den uygulama kaydı ne zaman alınabilir?
- Arayüz dili Türkçe mi, İngilizce mi, ikisi birden mi? (Tasarım İngilizce.)
- Departman listesi tasarımdaki gibi mi, yoksa Freebird'ün gerçek organizasyonundan mı alınacak? (SuccessFactors'tan senkronize edilebilir.)
