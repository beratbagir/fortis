import { expect, test } from '@playwright/test'
import { installFakeBackend } from './fakeBackend'

const now = new Date('2026-10-12T09:30:00+03:00')

test('Home → örnek soru → canlı iz → soru kartı → Devam → tablolu, kaynaklı cevap → aksiyon → 👍', async ({ page }) => {
  await page.clock.setFixedTime(now)
  const backend = installFakeBackend(page, { now })
  await page.goto('/#/chat')

  await expect(page.getByRole('heading', { name: 'Günaydın, Kerem' })).toBeVisible()
  await expect(page.getByRole('link', { name: 'Outlook: kurulum bekliyor' })).toBeVisible()
  await expect(page.getByText('Seni bekleyenler · 1')).toBeVisible()
  await expect(page.getByText('Sigorta bildirimi')).toBeVisible()

  await page.getByRole('button', { name: 'Dün yük faktörü en düşük rotalar hangileriydi?' }).click()

  // Canlı iz ve soru kartı
  await expect(page).toHaveURL(/#\/chat\/10$/)
  await expect(page.getByText('IBS sorgulanıyor · Dünkü seferler, rota bazında yük faktörü')).toBeVisible()
  await expect(page.getByText('212 satır döndü')).toBeVisible()
  const card = page.getByRole('region', { name: "Fortis'in sorusu" })
  await expect(card.getByText('"Kötü performans" nasıl tanımlansın?')).toBeVisible()
  await card.getByLabel('Yük faktörü %82 hedefinin altında').check()
  await card.getByRole('button', { name: 'Devam' }).click()

  // Kaynaklı cevap
  const answer = page.getByRole('article', { name: "Fortis'in cevabı" })
  await expect(answer.getByRole('figure', { name: 'Hedefin altındaki rotalar · 11 Eki 2026 · tarifeli' })).toBeVisible()
  await expect(answer.getByRole('button', { name: /^Kaynak 1:/ })).toBeVisible()
  await expect(answer.getByRole('button', { name: /^Kaynak 2:/ })).toBeVisible()
  await expect(answer.getByText('Auto · Claude Sonnet 5.5 → Claude Opus 5.5')).toBeVisible()
  await expect(answer.getByText('Teams · onay gerekir')).toBeVisible()
  await expect(card).toBeHidden()
  expect(backend.answers).toEqual([{ content: '', answer: { answers: [{ question: '"Kötü performans" nasıl tanımlansın?', selected: ['Yük faktörü %82 hedefinin altında'], other: null }] } }])

  await answer.getByRole('button', { name: 'Faydalı', exact: true }).click()
  await expect.poll(() => backend.feedback).toEqual([{ rating: 'up', comment: null }])
})

for (const theme of ['light', 'dark'] as const) {
  test(`konuşma ekranları, ${theme === 'light' ? 'açık' : 'koyu'} tema`, async ({ page }) => {
    await page.clock.setFixedTime(now)
    installFakeBackend(page, { theme, now })
    await page.goto('/#/chat')
    await page.evaluate((value) => localStorage.setItem('fortis.theme', value), theme)
    await page.reload()

    await expect(page.getByRole('heading', { name: 'Günaydın, Kerem' })).toBeVisible()
    await page.evaluate(() => document.fonts.ready)
    await expect(page).toHaveScreenshot(`home-${theme}.png`, { fullPage: true, maxDiffPixelRatio: 0.02 })

    await page.getByRole('button', { name: 'Dün yük faktörü en düşük rotalar hangileriydi?' }).click()
    await expect(page.getByRole('region', { name: "Fortis'in sorusu" })).toBeVisible()
    await expect(page).toHaveScreenshot(`trace-${theme}.png`, { maxDiffPixelRatio: 0.02 })

    await page.getByLabel('Yük faktörü %82 hedefinin altında').check()
    await page.getByRole('button', { name: 'Devam' }).click()
    await expect(page.getByRole('figure')).toBeVisible()
    await page.mouse.move(0, 0)
    await expect(page).toHaveScreenshot(`answer-${theme}.png`, { maxDiffPixelRatio: 0.02 })
  })
}

test('Home → Seni bekleyenler → Inbox onay detayı → Onayla ve gönder → kutu boşalır', async ({ page }) => {
  await page.clock.setFixedTime(now)
  installFakeBackend(page, { now })
  await page.goto('/#/chat')

  await page.getByRole('link', { name: /Sigorta bildirimi/ }).click()
  await expect(page).toHaveURL(/#\/inbox\/1$/)
  const preview = page.getByRole('region', { name: 'Önizleme' })
  await expect(preview.getByText('occ@freebird.com.tr')).toBeVisible()
  await expect(preview.getByText('Sigorta bildirimi · 11 Eki')).toBeVisible()
  await expect(page.getByText('Bu işlem senin adınla denetim kaydına yazılır.')).toBeVisible()
  await expect(page.getByRole('link', { name: "Agent'a sor" })).toHaveAttribute('href', '#/chat/10')

  await page.getByRole('button', { name: 'Onayla ve gönder' }).click()
  await expect(page.getByText('· Gönderildi')).toBeVisible()
  await expect(page.getByRole('button', { name: 'Onayla ve gönder' })).toHaveCount(0)

  await page.getByRole('link', { name: '← Gelen kutusu' }).click()
  await expect(page.getByText('Her şey yolunda; bekleyen bir şey yok.')).toBeVisible()
  await page.getByRole('tab', { name: /Tamamlananlar/ }).click()
  await expect(page.getByText('Sigorta bildirimi')).toBeVisible()
})

test('Oluştur → şablondan kur → Architect planı → son veriyle test → pilota al (açık ve koyu tema)', async ({ page }) => {
  await page.clock.setFixedTime(now)
  installFakeBackend(page, { now })
  await page.goto('/#/build')

  await expect(page.getByRole('heading', { name: "Yeni agent'ın ne yapsın?" })).toBeVisible()
  await page.getByRole('button', { name: /Sabah Operasyon Özeti/ }).click()
  await expect(page).toHaveURL(/#\/build\/agents\/7$/)

  const plan = page.getByRole('list', { name: 'Agent planı' })
  await expect(plan.getByText('APM · dünkü uçuşlar ve rötar kodları')).toBeVisible()
  await expect(page.getByText('Özete dahil etme')).toBeVisible()
  await expect(page.getByRole('region', { name: 'Tahmini maliyet' })).toContainText('Test sonrası hesaplanır')
  for (const theme of ['light', 'dark'] as const) {
    await page.evaluate((value) => document.documentElement.setAttribute('data-theme', value), theme)
    await expect(page).toHaveScreenshot(`architect-${theme}.png`, { fullPage: true, maxDiffPixelRatio: 0.02 })
  }

  await page.getByRole('button', { name: 'Son veriyle test et' }).click()
  const result = page.getByRole('region', { name: 'Test sonucu' })
  await expect(result.getByText('Bitti · gönderimler önizlendi')).toBeVisible()
  await expect(result.getByText('Bu çalışma şunları gönderecekti (test, gönderilmedi)')).toBeVisible()
  await expect(result.getByText(/kerem@freebird.com.tr · Operasyon özeti/)).toBeVisible()
  await expect(page.getByRole('region', { name: 'Tahmini maliyet' })).toContainText('$0,214')

  await page.getByRole('button', { name: 'Pilota al' }).click()
  await expect(page.locator('[data-status="pilot"]')).toBeVisible()
  await expect(page.getByRole('button', { name: 'Canlıya al' })).toBeDisabled()
})

test('Agent Kaydı → agent sayfası → Şimdi çalıştır → Çalışmalar: başarılı, maliyet, geri bildirim', async ({ page }) => {
  await page.clock.setFixedTime(now)
  installFakeBackend(page, { now })
  await page.goto('/#/agents')

  const card = page.getByRole('article', { name: 'Sabah Operasyon Özeti' }).first()
  await expect(card.getByText('7 çalışma/hafta · %96 faydalı')).toBeVisible()
  await expect(page.getByRole('article', { name: 'Uzun Rötar Bildirimi' }).getByText('3 çalışma/hafta', { exact: true })).toBeVisible()
  await page.getByRole('button', { name: /OCC/ }).click()
  await expect(page.getByRole('article', { name: 'Sabah Operasyon Özeti' })).toHaveCount(1) // şablon kartı da aynı adda
  await page.getByRole('button', { name: /Tümü/ }).click()
  await card.getByRole('link', { name: 'Sabah Operasyon Özeti: izleme' }).click()

  await expect(page).toHaveURL(/#\/agents\/7$/)
  await expect(page.getByRole('region', { name: 'Başarı oranı' })).toContainText('%100')
  await expect(page.getByRole('region', { name: 'Maliyet · 30 gün' })).toContainText('$6,48')
  await page.getByRole('button', { name: 'Şimdi çalıştır' }).click()
  await expect(page.getByText('Çalışma kuyruğa alındı')).toBeVisible()

  await page.getByRole('tab', { name: 'Çalışmalar' }).click()
  await page.getByRole('region', { name: 'Çalışmalar' }).getByRole('link', { name: 'Gör' }).first().click()
  await expect(page).toHaveURL(/#\/agents\/7\/runs\/32$/)
  const run = page.getByRole('article', { name: 'Çalışma #32' })
  await expect(run.getByText('Başarılı')).toBeVisible()
  await expect(run.getByText('$0,226')).toBeVisible()
  await expect(run.getByText(/Gönderildi/)).toBeVisible()
  await run.getByRole('button', { name: 'Faydalı', exact: true }).click()
  await expect(run.getByRole('button', { name: 'Faydalı', exact: true })).toHaveAttribute('aria-pressed', 'true')
  const score = run.getByRole('region', { name: 'Değerlendirme' })
  await expect(score.getByText('100/100')).toBeVisible()
  await expect(score.getByText(/En uzun rötarların hepsinde kod var/)).toBeVisible()

  await page.getByRole('tab', { name: 'Değerlendirme' }).click()
  await expect(page).toHaveURL(/#\/agents\/7\/evaluation$/)
  await expect(page.getByRole('region', { name: 'Puan özeti' })).toContainText('89')
  await expect(page.getByRole('textbox', { name: 'Kural 3' })).toHaveValue('Rakamlar kaynak sorgularla tutarlı')
  await expect(page.getByRole('region', { name: 'Kural başına geçme' })).toContainText('%73')
})

test('Admin: Bağlantılar → APM detay → Kapat (gerekçe) → Home ve sohbette APM soluk → Aç', async ({ page }) => {
  await page.clock.setFixedTime(now)
  installFakeBackend(page, { now })
  await page.goto('/#/connectors')

  await expect(page.getByRole('region', { name: 'Havayolu sistemleri' }).getByText('· 2 bağlı')).toBeVisible()
  await page.getByRole('link', { name: 'APM: Bağlı' }).click()
  await expect(page).toHaveURL(/#\/connectors\/apm$/)
  await expect(page.getByRole('region', { name: 'Yapılandırma (.env)' })).toContainText('APM_CLIENT_SECRET')
  await expect(page.getByRole('region', { name: 'Kim kullanıyor' })).toContainText('Sabah Operasyon Özeti')

  await page.getByRole('button', { name: 'Kapat', exact: true }).click()
  const dialog = page.getByRole('dialog', { name: 'APM kapatılsın mı?' })
  await expect(dialog.getByText('2 agent')).toBeVisible()
  await dialog.getByLabel(/Gerekçe/).fill('APM bakımda')
  await dialog.getByRole('button', { name: 'Bağlantıyı kapat' }).click()
  await expect(page.getByText(/Şirket genelinde kapalı: APM bakımda/)).toBeVisible()

  await page.goto('/#/chat')
  const chip = page.getByRole('link', { name: 'APM: kapalı' })
  await expect(chip).toBeVisible()
  await expect(chip).toHaveClass(/opacity-50/)

  await page.goto('/#/connectors/apm')
  await page.getByRole('button', { name: 'Aç', exact: true }).click()
  await expect(page.getByText('APM açıldı.')).toBeVisible()
})

test('Admin: Yönetişim → sınırı aşan test çalışması → durdur → çözüldü → kullanım → politikalar → denetim kaydı', async ({ page }) => {
  await page.clock.setFixedTime(now)
  installFakeBackend(page, { now })
  await page.goto('/#/governance')

  await expect(page.getByRole('heading', { name: 'Yönetişim' })).toBeVisible()
  await expect(page.getByRole('region', { name: 'Harcama · Ekim 2026' })).toContainText('$18,42')
  const attention = page.getByRole('region', { name: /Dikkat gerekenler/ })
  await expect(attention.getByText(/#31 \(test\): Araç çağrısı sınırı/)).toBeVisible()
  await expect(attention.getByRole('link', { name: 'Git' }).first()).toHaveAttribute('href', '#/agents/7/runs/31')

  await page.getByRole('button', { name: 'Sabah Operasyon Özeti çalışmasını durdur' }).click()
  const dialog = page.getByRole('dialog', { name: 'Sabah Operasyon Özeti durdurulsun mu?' })
  await dialog.getByLabel(/Gerekçe/).fill('Araç sınırını aşıyor')
  await dialog.getByRole('button', { name: 'Çalışmayı durdur' }).click()
  await expect(page.getByText('Sabah Operasyon Özeti durduruldu.')).toBeVisible()
  await expect(page.getByRole('button', { name: 'Sabah Operasyon Özeti çalışmasını durdur' })).toHaveCount(0)

  await attention.getByRole('button', { name: 'Çözüldü' }).first().click()
  await expect(attention.getByText(/#31 \(test\)/)).toHaveCount(0)

  await page.getByRole('link', { name: 'Kullanım' }).click()
  await expect(page.getByRole('heading', { name: 'Kullanım ve maliyet' })).toBeVisible()
  await expect(page.getByRole('region', { name: 'Toplam maliyet' })).toContainText('$18,42')
  await page.getByLabel('Departman', { exact: true }).selectOption('1')
  await expect(page.getByRole('link', { name: 'CSV indir' })).toHaveAttribute('href', /department_id=1&format=csv$/)

  // Politikalar: Operasyon'a dolunca duran 15 $ bütçe ve araç sınırı; ikisi de onaydan sonra kaydedilir.
  await page.getByRole('main').getByRole('link', { name: 'Politikalar' }).click()
  const budgets = page.getByRole('region', { name: /Bütçeler/ })
  await budgets.getByLabel('Operasyon aylık limit').fill('15')
  await budgets.getByLabel('Operasyon dolunca durdur').check()
  await budgets.getByRole('button', { name: 'Bütçeleri kaydet' }).click()
  const confirm = page.getByRole('dialog', { name: 'Bütçe değişiklikleri kaydedilsin mi?' })
  await expect(confirm).toContainText('Operasyon: $15,00 / ay, uyarı %80, dolunca durdur')
  await confirm.getByRole('button', { name: 'Onayla ve kaydet' }).click()
  await expect(page.getByText('Bütçeler kaydedildi.')).toBeVisible()
  await expect(budgets.getByText('%81')).toBeVisible()

  await page.getByRole('region', { name: 'Agentlar' }).getByLabel(/araç çağrısı sınırı/).fill('12')
  await page.getByRole('button', { name: 'Kaydet', exact: true }).click()
  const policyConfirm = page.getByRole('dialog', { name: 'Politika değişiklikleri kaydedilsin mi?' })
  await expect(policyConfirm).toContainText('Çalışma başına araç çağrısı sınırı: 20 → 12')
  await policyConfirm.getByRole('button', { name: 'Onayla ve kaydet' }).click()
  await expect(page.getByText('Politikalar kaydedildi.')).toBeVisible()

  // Denetim kaydı: değişiklik önceki/yeni değeriyle görünür.
  await page.getByRole('link', { name: 'Denetim kaydı' }).click()
  await page.getByLabel('Olay').selectOption('settings.update')
  await page.getByRole('button', { name: /ayrıntısı$/ }).first().click()
  await expect(page.getByText(/"setting": "max_tool_calls"/)).toBeVisible()
  await expect(page.getByText(/"before": 20,\s*"after": 12/)).toBeVisible()
})
