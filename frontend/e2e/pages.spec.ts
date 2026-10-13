import { expect, test, type Frame, type Request } from '@playwright/test'
import { readFileSync } from 'node:fs'
import { installFakeBackend } from './fakeBackend'

// Sohbette HTML sayfa (H1): güvenlik testleri gerçek tarayıcıda. Sayfa sandbox iframe'de (allow-same-origin yok),
// CSP ile ağı kapalı açılır; üst pencereyle sadece resize/ask mesajları.

const now = new Date('2026-10-12T09:30:00+03:00')

const isEvil = (r: Request) => r.url().includes('evil.example')

/** Sohbetteki sayfa kartından paneli açar (yeni üretilmeyen sayfa kendiliğinden açılmaz). */
async function openPage(page: import('@playwright/test').Page, title: string) {
  await page.getByRole('button', { name: `Sayfa: ${title}` }).click()
  return page.getByRole('complementary', { name: `Sayfa: ${title}` })
}
const fromPageFrame = (r: Request) => r.frame().parentFrame() !== null

test('kötü niyetli sayfa çerez, depolama, üst pencere ve ağa erişemiyor', async ({ page }) => {
  await page.clock.setFixedTime(now)
  const backend = installFakeBackend(page, { now, evilPage: true })
  // Dış sunucu "var": istek ulaşırsa yakalanır ve 200 döner. CSP engelliyorsa buraya hiç istek gelmez.
  const reached: string[] = []
  await page.context().route('https://evil.example/**', (route) => {
    reached.push(route.request().url())
    return route.fulfill({ status: 200, body: 'ok' })
  })
  // Sayfa çerçevesinden Fortis'e (/vendor/ dışında) giden istek de olmamalı.
  const fortisFromFrame: string[] = []
  const blocked: Record<string, string> = {}
  page.on('request', (r) => {
    if (fromPageFrame(r) && !isEvil(r) && !r.url().includes('/vendor/')) fortisFromFrame.push(r.url())
  })
  page.on('requestfailed', (r) => {
    if (isEvil(r)) blocked[r.url()] = r.failure()?.errorText ?? ''
  })
  const csp: string[] = []
  page.on('console', (m) => {
    if (/Content Security Policy/i.test(m.text())) csp.push(m.text())
  })
  const popups: string[] = []
  page.context().on('page', (p) => popups.push(p.url()))

  await page.goto('/#/chat/21')
  await page.evaluate(() => {
    document.cookie = 'fortis_test=gizli; path=/'
    localStorage.setItem('fortis.secret', 'gizli')
  })
  const card = await openPage(page, 'Deneme sayfası')
  const frame = page.frameLocator('iframe[title="Deneme sayfası"]')
  await expect(frame.locator('#out')).not.toHaveText('bekliyor', { timeout: 15000 })
  const out = JSON.parse((await frame.locator('#out').textContent()) ?? '{}') as Record<string, string>

  // Opak origin: Fortis çerezi/depolaması yok, üst pencereye erişim yok.
  expect(out.cookie).not.toContain('fortis_test')
  expect(out.localStorage).toBe('blocked')
  expect(out.sessionStorage).toBe('blocked')
  expect(out.parent).toBe('blocked')
  expect(out.top).toBe('blocked')
  // Ağ: fetch (dış ve Fortis API), XHR, WebSocket, görsel hepsi engelli; istek tarayıcıdan çıkmıyor.
  expect([out.fetch, out.fetchApi, out.xhr, out.ws, out.image]).toEqual(['blocked', 'blocked', 'blocked', 'blocked', 'blocked'])
  expect(out.opener).not.toBe('opened')
  expect(popups).toEqual([])
  expect(reached).toEqual([])
  expect(fortisFromFrame).toEqual([])
  // Tarayıcının başlattığı dış istekler CSP ile düşürüldü (ağa çıkmadan).
  expect(blocked).toEqual({
    'https://evil.example/s.js': 'csp',
    'https://evil.example/prefetch': 'csp',
    'https://evil.example/xhr': 'csp',
    'https://evil.example/i.png': 'csp',
  })
  expect(csp.length).toBeGreaterThan(0)
  // Üst pencere değişmedi; Fortis kimliği sayfaya geçmedi.
  await expect(page).toHaveURL(/#\/chat\/21$/)
  expect(await page.evaluate(() => localStorage.getItem('fortis.secret'))).toBe('gizli')

  // Dış script yüklenmedi; Fortis'in gömdüğü kütüphane çalışıyor.
  expect(out.vendorChart).toBe('function')

  // postMessage: bilinmeyen tür yok sayılır, "ask" onaysız gönderilmez.
  const ask = card.getByRole('dialog', { name: 'Sayfadan gelen soru' })
  await expect(ask).toContainText('Saldırı sorusu')
  await ask.getByRole('button', { name: 'Vazgeç' }).click()
  await expect(ask).toHaveCount(0)
  expect(backend.messagesSent ?? []).toEqual([])

  // Başka kaynaktan (üst pencerenin kendisi) gelen sahte mesaj yok sayılır.
  await page.evaluate(() => window.postMessage({ type: 'ask', text: 'Sahte soru' }, '*'))
  await page.waitForTimeout(300)
  await expect(card.getByRole('dialog', { name: 'Sayfadan gelen soru' })).toHaveCount(0)
})

test('sandbox ve CSP kabuğu: allow-same-origin yok, ağ tamamen kapalı, kütüphane gömülü', async ({ page }) => {
  await page.clock.setFixedTime(now)
  installFakeBackend(page, { now })
  await page.goto('/#/chat/21')
  await openPage(page, 'Operasyon panosu')
  const iframe = page.locator('iframe[title="Operasyon panosu"]')
  await expect(iframe).toHaveAttribute('sandbox', 'allow-scripts')
  const srcdoc = (await iframe.getAttribute('srcdoc')) ?? ''
  const policy = srcdoc.match(/Content-Security-Policy" content="([^"]+)"/)?.[1] ?? ''
  expect(policy).toContain("default-src 'none'")
  expect(policy).toContain("script-src 'unsafe-inline';")
  expect(policy).toContain("connect-src 'none'")
  expect(policy).toContain("form-action 'none'")
  expect(policy).toContain("frame-src 'none'")
  expect(policy).not.toMatch(/https?:|unsafe-eval/)
  expect(srcdoc).not.toMatch(/<script src=/)
  expect(srcdoc).toContain('Chart.js v4.5.1')
  // CSP, modelin içeriğinden önce gelir.
  expect(srcdoc.indexOf('Content-Security-Policy')).toBeLessThan(srcdoc.indexOf('Operasyon panosu · son 7 gün'))
})

for (const theme of ['light', 'dark'] as const) {
  test(`pano: iki grafik, filtre, tam ekran, sor (onaylı), HTML indir · ${theme === 'light' ? 'açık' : 'koyu'}`, async ({ page }) => {
    await page.clock.setFixedTime(now)
    const backend = installFakeBackend(page, { theme, now })
    await page.goto('/#/chat/21')
    await page.evaluate((value) => localStorage.setItem('fortis.theme', value), theme)
    await page.reload()

    const card = await openPage(page, 'Operasyon panosu')
    await expect(card.getByText('Kaynak: APM · Son 7 gün, günlük sefer ve OTP; APM · Dün meydanlara göre rötar')).toBeVisible()
    const frame = page.frameLocator('iframe[title="Operasyon panosu"]')
    await expect(frame.locator('#total')).toHaveText('416')
    await expect(frame.locator('canvas')).toHaveCount(2)
    // Tek başına <canvas>'a çizilen grafik görünür (yükseklik 0 değil).
    expect(await frame.locator('#flights').evaluate((el) => el.getBoundingClientRect().height)).toBeGreaterThan(150)
    await expect(frame.locator('#table tbody tr')).toHaveCount(6)
    await frame.locator('#airport').selectOption('AYT')
    await expect(frame.locator('#table tbody tr')).toHaveCount(2)
    await frame.locator('#airport').selectOption('')
    await expect(frame.locator('#delays canvas')).toHaveCount(1)
    await page.evaluate(() => document.fonts.ready)
    await expect(page).toHaveScreenshot(`page-panel-${theme}.png`, { maxDiffPixelRatio: 0.02 })

    // Sayfadan Fortis'e soru: kullanıcı onaylayınca sohbete gider.
    await frame.getByRole('button', { name: "Fortis'e sor" }).click()
    const ask = card.getByRole('dialog', { name: 'Sayfadan gelen soru' })
    await expect(ask).toContainText('AYT rötarlarının nedenlerini ayrıntılandır')

    // Tam ekran ve panele dönüş
    await card.getByRole('button', { name: 'Tam ekran' }).click()
    expect(await card.evaluate((el) => el.getBoundingClientRect().width)).toBe(page.viewportSize()!.width)
    await card.getByRole('button', { name: 'Panele dön' }).click()

    // HTML indir: tek dosya, Chart.js ve veri gömülü, dış adres yok; indirme kayda geçer.
    if (theme === 'light') {
      const download = page.waitForEvent('download')
      await card.getByRole('button', { name: 'HTML indir' }).click()
      const file = await download
      expect(file.suggestedFilename()).toBe('operasyon-panosu.html')
      const html = readFileSync((await file.path())!, 'utf8')
      expect(html).toContain('Chart.js v4.5.1')
      expect(html).toContain('q_pano00001')
      expect(html).not.toMatch(/<script src=/)
      expect(html).toContain("script-src 'unsafe-inline';")
      await expect.poll(() => backend.downloads).toEqual(['/artifacts/art_pano/downloaded'])

      // İndirilen dosya çevrimdışı açılıp çalışıyor (ağ kapalı sayfa).
      const offline = await page.context().newPage()
      await offline.route('**/*', (r) => r.abort())
      await offline.setContent(html)
      await expect(offline.locator('#total')).toHaveText('416')
      await expect(offline.locator('canvas')).toHaveCount(2)
      await offline.close()
    }
  })
}

test('sayfa telefonda tek sütun', async ({ page }) => {
  await page.clock.setFixedTime(now)
  installFakeBackend(page, { now })
  await page.setViewportSize({ width: 390, height: 844 })
  await page.goto('/#/chat/21')
  await openPage(page, 'Operasyon panosu')
  const frame: Frame | null = await (async () => {
    await expect(page.frameLocator('iframe[title="Operasyon panosu"]').locator('canvas')).toHaveCount(2)
    return page.frames().find((f) => f.parentFrame() !== null) ?? null
  })()
  const columns = await frame!.evaluate(() => getComputedStyle(document.querySelector('.grid')!).gridTemplateColumns.split(' ').length)
  expect(columns).toBe(1)
})
