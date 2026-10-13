# Kestra kurulumu

`docker-compose.yml` hem sunucuda hem yerelde kullanılır. Özellikleri:

- Sabit sürüm (`KESTRA_VERSION`), `-slim` imaj
- `KESTRA_PLUGINS_AUTO_INSTALL_ENABLED=false`: slim imaj bu ayar olmadan eksik eklentileri Maven'dan kendisi indirir. Bu durumda izin listesi dışındaki görevler (Python, Shell vb.) de çalışabilir hale gelir.
- Basic auth açık. Kullanıcı adı geçerli bir e-posta olmalı; şifre en az 8 karakter olmalı, büyük harf ve rakam içermeli.
- PostgreSQL backend, Docker soketi bağlı değil, Kestra root olarak çalışmıyor
- Port sadece `127.0.0.1`'e açık

API referansı: `../kestra-api/api-1.3.41.yml` (sunucudan alındı), `../kestra-api/api-2.0.4.yml` (imajdan çıkarıldı).

## Sunucu: mevcut durum (2026-10-01)

| | |
|---|---|
| Adres | `68.183.66.112:8080` |
| Sürüm | 1.3.41 OSS |
| Basic auth | Açık |
| **Sorun** | **İnternete düz HTTP ile açık.** Şifre şifrelenmeden gidiyor. Faz 0 çıkış kriterini karşılamıyor. |

## Sunucu: 2.0.4-slim'e geçiş ve kapatma (henüz uygulanmadı)

1. **Yedek al.** 2.0 geçişi geri alınamaz; basic auth şifresi yeniden hash'lendiği için 1.x'e dönülemez.
   `docker compose exec postgres pg_dump -U kestra kestra > kestra-1.3.41.sql` ve storage volume'u.
2. Mevcut akışları dışa aktar ve uyumluluğu kontrol et: `kestra-migrate --check ./flows/`
   (2.0'da kaldırılanlar: `Count`, `Resume`, `trigger.Toggle`, `log.Fetch`, `pluginDefaults`; `CANCELED` → `CANCELLED`).
3. Bu klasörü sunucuya kopyala, `.env`'i doldur (`KESTRA_VERSION=v2.0.4`, mevcut DB şifresi, güçlü bir basic auth şifresi, `KESTRA_PUBLIC_URL`).
4. `docker compose up -d`. OSS'de veritabanı geçişleri açılışta otomatik çalışır.
5. Gerekli eklentileri tek tek, sabit sürümle kur (bildirim kanalları netleşince, açık soru 3).
6. **8080'i dışarıya kapat:** DigitalOcean Cloud Firewall'da 8080 için gelen trafik kuralını kaldır; sadece 22 (SSH) açık kalsın.
7. Dışarıdan kontrol et: `curl -m 5 http://68.183.66.112:8080` zaman aşımına düşmeli.
8. Laravel'de `KESTRA_VERSION=2.0.4` yap.

## Kapatıldıktan sonra erişim

Laravel şimdilik yerelde çalıştığı için Kestra'ya SSH tüneliyle bağlanılır:

```sh
ssh -N -L 8080:127.0.0.1:8080 <kullanıcı>@68.183.66.112
```

Laravel `.env`: `KESTRA_URL=http://127.0.0.1:8080`. Kestra arayüzü de aynı tünelden `http://localhost:8080` adresinde açılır.

Laravel sunucuya taşındığında: aynı VPC'deyse Kestra'nın özel IP'si kullanılır. Başka bir yerdeyse Kestra'nın önüne Caddy (TLS) konur ve firewall sadece Laravel sunucusunun IP'sine izin verir.

## Agent zamanlamalarını gerçek Kestra ile denemek (A3.2)

Zamanlanmış agent akışı (`fortis.agents` namespace'i) zamanı gelince Laravel'in iç ucuna istek atar:
`POST {FORTIS_INTERNAL_URL}/api/internal/agents/{id}/runs`, başlıkta `X-Fortis-Key`. Laravel yerelde çalışırken
Kestra'nın ona ulaşması için ters tünel gerekir.

1. **Ters tünel** (Kestra konteyneri sunucuda Docker köprüsünden `172.17.0.1` adresini görür; Laravel yerelde `:8000`'de):

   ```sh
   ssh -N -L 8080:127.0.0.1:8080 -R 172.17.0.1:18000:localhost:8000 <kullanıcı>@68.183.66.112
   ```

   `-R` ile belirli bir adrese bağlanmak için sunucudaki `sshd_config`'te `GatewayPorts clientspecified` (veya `yes`)
   olmalı; değilse tünel sadece `127.0.0.1`'e bağlanır ve konteyner ulaşamaz. Kontrol (sunucuda):
   `docker exec <kestra konteyneri> curl -s -o /dev/null -w '%{http_code}' -X POST http://172.17.0.1:18000/api/internal/agents/1/runs`
   → `401` (anahtarsız) dönmeli.

2. **Laravel `.env`:**

   ```
   FORTIS_INTERNAL_URL=http://172.17.0.1:18000
   FORTIS_INTERNAL_KEY=<rastgele uzun değer>   # yerel .env'de üretilmiş bir değer var
   KESTRA_SECRETS=...,FORTIS_INTERNAL_KEY       # mevcut listeye adını ekle (değerini değil)
   ```

   Sonra `php artisan config:clear`.

3. **Kestra secret'ı:** aynı değeri Kestra'ya `FORTIS_INTERNAL_KEY` adıyla ekle. OSS sürümde secret'lar ortam
   değişkeniyle verilir: `docker-compose.yml`'de Kestra servisine
   `SECRET_FORTIS_INTERNAL_KEY: <değerin base64 hali>` (`printf '%s' "$DEGER" | base64`) ekleyip konteyneri yeniden başlat.

4. **Zamanlamaları yükle ve dene:**

   ```sh
   cd backend && php artisan fortis:agent:sync-triggers --all
   cd backend && php artisan queue:work --queue=agents
   ```

   Kestra arayüzünde `fortis.agents` altındaki akışı elle çalıştır (veya cron'u yakın bir dakikaya çek). Beklenen:
   Laravel'de yeni `agent_runs` kaydı (`trigger_id` dolu), Kestra'da görev `202`. Çalışma sürerken ikinci tetikleme `409`
   döner ve Kestra'da o çalışma başarısız görünür (bilinçli). Kestra'ya ulaşılamazsa agent sahibinin Inbox'ına uyarı
   düşer; `fortis:agent:sync-triggers` yeniden dener.

## Yerel Kestra (isteğe bağlı)

Sunucuya dokunmadan 2.0.4'ü denemek için: `cp .env.example .env`, değerleri doldur, `docker compose up -d`.
