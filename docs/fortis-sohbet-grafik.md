# Görev: Sohbette grafik desteği

Önce `ILERLEME.md` ve `docs/fortis-aero-faz-a2.md`'nin (kaynaklı cevap, tablo kartı, `:::actions`) ilgili bölümlerini oku. A4.4 bittiyse başla; bitmediyse önce onu bitir.

## 1. Amaç

"Son 30 günün OTP'si", "Rötar nedenlerinin dağılımı", "Meydanlara göre iptal sayısı" gibi sorularda cevap sadece tablo değil, **grafik + kısa yorum** olsun.

Model HTML ya da kod **yazmaz**. Cevaba yapılandırılmış bir `:::chart` bloğu (JSON) koyar, React bunu hazır bileşenle çizer. Güvenlik riski yok, görünüm tutarlı.

---

## 2. Kesin kurallar

1. **Grafik verisi uydurulamaz.** Her `:::chart` bloğu bu cevapta çalışmış bir sorguya bağlıdır (`source: "q:ID"`). Kimlik bu cevapta yoksa grafik çizilmez, yerine "Grafik kaynağı doğrulanamadı" notu çıkar ve `ai_calls.warning`'e `chart_source_mismatch` yazılır (A2'deki `citation_mismatch` ile aynı mekanizma).
2. **Grafikteki her değer o sorgunun sonucundan gelmelidir.** Backend, bloktaki değerlerin sorgu sonucundaki (maskelenmiş, Claude'a giden) satırlarda bulunduğunu kontrol eder. Toplama/oran gibi türetilmiş değerler için `derived: true` işaretlenir ve kontrol sadece satır sayısı/etiketler için yapılır; yorumda "hesaplandı" denir.
3. **Kişisel veri yok.** Etiketler `AiDataGate`'ten geçmiş veriden gelir; ek maskeleme gerekmez ama test edilir.
4. **HTML/JS/SVG metni kabul edilmez.** Blok sadece şemaya uyan JSON. Şemaya uymayan blok çizilmez, ham metin olarak da gösterilmez (sadece "Grafik gösterilemedi").
5. **Sınırlar:** en fazla 6 seri, seri başına en fazla 400 nokta, cevap başına en fazla 3 grafik.

---

## 3. Blok biçimi

````
:::chart
{
  "type": "line",                 // line | bar | hbar | stacked_bar | donut yok (bkz. 5)
  "title": "Günlük OTP, son 30 gün",
  "source": "q:7f3a",
  "x": { "field": "date", "type": "date", "label": "Tarih" },
  "y": { "unit": "%", "label": "OTP", "min": 0 },
  "series": [
    { "name": "OTP", "field": "otp_pct" }
  ],
  "data": [ { "date": "2026-09-14", "otp_pct": 82.4 }, ... ],
  "reference": { "y": 85, "label": "Hedef" },   // opsiyonel, tek yatay çizgi
  "derived": false,
  "note": "Türkiye saatine göre günlük."        // opsiyonel
}
:::
````

- Sayılar sayı olarak, tarihler ISO (`YYYY-MM-DD` veya `YYYY-MM-DDTHH:mm`) olarak.
- `y.unit`: `%`, `dk`, `adet`, `$`, `₺` veya boş.
- Backend bloğu ayrıştırır, doğrular, mesajın `meta.answer.charts[]` alanına normalleştirilmiş olarak yazar. Ön yüz metindeki bloğu değil `meta`'daki doğrulanmış veriyi çizer.

---

## 4. Ne zaman grafik? (sistem promptuna)

| Soru / veri | Biçim |
|---|---|
| Tek sayı | Grafik yok (cümle) |
| Zamana göre değişim, 7+ nokta | `line` |
| Kategorilere göre karşılaştırma, ≤ 12 kategori | `bar` (kısa etiket) / `hbar` (uzun etiket: meydan, neden) |
| Parçaların toplamı + zaman | `stacked_bar` (en fazla 5 parça, gerisi "Diğer") |
| 12+ kategori | En büyük 10 + "Diğer" ya da tablo |
| İki farklı ölçü (ör. sefer sayısı ve OTP %) | **İki ayrı grafik**, asla çift eksen |

Prompt kuralları:
- Grafik varsa altına 1–3 cümle yorum: ne görülüyor, dikkat çeken nokta, olası neden. Grafik metni tekrar etmez.
- Grafiğin verisini ayrıca tablo olarak yazma (kartta "Tablo" görünümü zaten var).
- Kaynak numarası `[^q:ID]` yorumda da kalır.

---

## 5. Görünüm (grafik kartı)

Kütüphane: **Recharts** (React, yerel paket; CDN yok).

**Kart:** A2'deki tablo kartıyla aynı çerçeve. Başlık (serif değil, kart başlığı stili), sağ üstte: Grafik / Tablo geçişi, CSV indir, PNG indir, büyüt. Altta kaynak numarası ve `note`.

**Renk kuralları:**
- Tek seri: marka rengi (açıkta `#B42A3F`, koyuda `#D2404F`). Legend yok, başlık seriyi adlandırır.
- 2+ seri: tema dosyasında **sabit sıralı** kategorik palet (en fazla 6). Renk seriye adıyla bağlıdır, sırasına değil: filtre/gizleme diğer serilerin rengini değiştirmez.
- Paleti açık ve koyu tema için ayrı tanımla (koyu tema otomatik ters çevirme değil). Renk körlüğünde komşu renkler ayırt edilebilir olmalı; seçilen paleti bir CVD simülatörüyle kontrol et, sonucu ILERLEME'ye yaz.
- Durum renkleri (yeşil/sarı/kırmızı) seri rengi olarak **kullanılmaz**. `reference` çizgisi nötr gri kesikli.
- Metin (etiket, değer, legend yazısı) hiçbir zaman seri renginde değil; tema metin renklerinde.

**İşaretler:**
- Çizgi 2px, nokta işaretçisi sadece hover'da (≥ 8px). Çubuk köşesi üstte 4px yuvarlak, taban düz; çubuklar arası 2px boşluk.
- Izgara çizgileri soluk, sadece yatay. Y ekseni 0'dan başlar (`bar`), `line`'da `y.min` varsa ona göre.
- Sayı biçimi Türkçe (`82,4 %`, `1.250`), tarih Türkçe (`14 Eyl`).

**Etiket ve legend:**
- 2+ seride legend her zaman var; ≤ 4 seride ayrıca çizgi sonuna doğrudan etiket.
- Her noktaya değer yazılmaz; sadece son nokta ve en yüksek/en düşük işaretlenebilir.

**Etkileşim:**
- `line`: dikey imleç + tüm serilerin değerleri tek tooltip'te.
- `bar`: çubuk üzerine gelince tooltip; tıklama alanı çubuktan geniş.
- Klavye ile erişilebilir (tab ile grafiğe, ok tuşlarıyla noktalar arası).
- Ekran okuyucu: `aria-label` = başlık + kısa özet; Tablo görünümü her zaman erişilebilir.

**Telefon:** tam genişlik, yükseklik 220px; x etiketleri seyreltilir; legend grafiğin altında.

---

## 6. Diğer yerler

- **Sohbetler geçmişi, paylaşım, denetim:** grafikler `meta`'dan yeniden çizilir.
- **Excel/PDF indirme** (cevaptan): PDF'te grafik görüntü olarak (sunucu tarafında aynı veriden çizilmiş PNG veya istemcinin ürettiği PNG), Excel'de veri sayfası + yerel Excel grafiği yoksa sadece veri. Basit olanı seç, ILERLEME'ye yaz.
- **Agent e-postaları:** bu görevde grafik yok; e-postada tablo kalır (kapsam dışı).

---

## 7. Testler

Backend:
- `ChartBlockTest`: geçerli blok `meta.answer.charts`'a yazılıyor; şemaya uymayan, HTML/script içeren, 6+ seri, 400+ nokta, 3+ grafik reddediliyor; bu cevapta olmayan `source` → `chart_source_mismatch`; sorgu sonucunda olmayan değer → reddediliyor; `derived: true` kontrolü; maskelenmiş etiketler maskeli kalıyor.
- Mevcut testler değişmeden geçmeli.

Frontend (Vitest):
- `line`, `bar`, `hbar`, `stacked_bar` çiziliyor; tek seride legend yok, 2+ seride var; Grafik/Tablo geçişi; CSV içeriği doğru; Türkçe sayı/tarih biçimi; geçersiz grafikte "Grafik gösterilemedi".
- Seri gizlenince diğer serilerin rengi değişmiyor.

Playwright:
- Demo sohbetine bir `line` ve bir `bar` grafikli cevap ekle (DemoSeeder); açık/koyu görsel temeller; telefon genişliği.

Eval (gerçek model, sadece bu sorular, bütçe 3 $):
- "Son 14 günün günlük sefer sayısı" → `line`, kaynaklı.
- "Dün rötar nedenlerinin dağılımı" → `hbar` veya `bar`.
- "Dün kaç sefer vardı" → **grafik yok**.
- "Son 7 günde sefer sayısı ve OTP" → iki ayrı grafik veya grafik + cümle, **çift eksen yok**.

---

## 8. Kabul kriterleri

1. Zaman serisi sorusunda grafik + kısa yorum geliyor; tek sayı sorusunda grafik yok.
2. Grafikteki her değer kaynak sorgudan; kaynaksız/uydurma grafik çizilmiyor.
3. Açık ve koyu temada okunur; renk körlüğü kontrolü yapıldı.
4. Grafik/Tablo geçişi, CSV ve PNG indirme çalışıyor.
5. Testler ve 4 eval sorusu geçiyor.

## 9. Kapsam dışı

- Pasta/donut, harita, ısı haritası, çift eksen, serbest HTML panolar (A5 canlı sayfalar).
- Agent e-postalarında grafik.
- Kullanıcının grafiği sohbette düzenlemesi (renk, tür değiştirme).
