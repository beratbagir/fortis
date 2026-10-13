import { expect, test, type Page } from '@playwright/test'
import { installFakeBackend } from './fakeBackend'

test('giriş → sohbet → yeni sohbet → agentlar → koyu tema → çıkış', async ({ page }) => {
  installFakeBackend(page, { signedIn: false })

  await page.goto('/#/chat')
  await expect(page).toHaveURL(/#\/login\?next=/)
  await page.getByLabel('E-posta').fill('kerem@freebird.test')
  await page.getByLabel('Şifre').fill('dogru-sifre-123')
  await page.getByRole('button', { name: 'Giriş' }).click()

  const menu = page.getByRole('navigation', { name: 'Ana menü' })
  await expect(page).toHaveURL(/#\/chat$/)
  await expect(page.getByRole('heading', { name: /, Kerem$/ })).toBeVisible()

  await menu.getByRole('link', { name: 'Yeni sohbet' }).click()
  await expect(page.getByPlaceholder('Fortis sana nasıl yardım edebilir?')).toBeVisible()

  await menu.getByRole('link', { name: /Agentlar/ }).click()
  await expect(page.getByRole('heading', { name: 'Agent Kaydı' })).toBeVisible()
  await expect(menu.getByRole('link', { name: /Agentlar/ })).toHaveAttribute('aria-current', 'page')

  await menu.getByRole('group', { name: 'Tema' }).getByRole('button', { name: /Koyu/ }).click()
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark')

  await menu.getByRole('button', { name: 'Kullanıcı menüsü' }).click()
  await page.getByRole('menuitem', { name: 'Çıkış' }).click()
  await expect(page.getByRole('button', { name: 'Giriş' })).toBeVisible()
})

const SCREENS = [
  { name: 'sohbet', hash: '#/chat', heading: 'Günaydın, Kerem' },
  { name: 'agentlar', hash: '#/agents', heading: 'Agent Kaydı' },
  { name: 'agent-sayfasi', hash: '#/agents/7', heading: 'Sabah Operasyon Özeti' },
  { name: 'degerlendirme', hash: '#/agents/7/evaluation', heading: 'Sabah Operasyon Özeti' },
  { name: 'olustur', hash: '#/build', heading: "Yeni agent'ın ne yapsın?" },
  { name: 'akislar', hash: '#/build/flows', heading: 'Akışlar' },
  { name: 'calismalar', hash: '#/runs', heading: 'Çalışmalar' },
  { name: 'yonetisim', hash: '#/governance', heading: 'Yönetişim' },
  { name: 'kullanim', hash: '#/governance/usage', heading: 'Kullanım ve maliyet' },
  { name: 'politikalar', hash: '#/governance/policies', heading: 'Politikalar' },
  { name: 'denetim-kaydi', hash: '#/governance/audit', heading: 'Denetim kaydı' },
  { name: 'gelen-kutusu', hash: '#/inbox', heading: 'Gelen kutusu' },
  { name: 'baglantilar', hash: '#/connectors', heading: 'Bağlantılar' },
  { name: 'baglanti-detay', hash: '#/connectors/apm', heading: 'APM' },
  { name: 'onay-detayi', hash: '#/inbox/1', heading: 'Sigorta bildirimi' },
  { name: 'yakinda', hash: '#/actions', heading: 'Yapılacaklar' },
]

async function setTheme(page: Page, theme: 'light' | 'dark') {
  await page.evaluate((value) => localStorage.setItem('fortis.theme', value), theme)
}

for (const theme of ['light', 'dark'] as const) {
  test(`ana ekranlar, ${theme === 'light' ? 'açık' : 'koyu'} tema (görsel regresyon temeli)`, async ({ page }) => {
    // Sabit saat: ekrandaki zamanlar her çalıştırmada aynı olsun.
    const now = new Date('2026-10-12T09:30:00+03:00')
    await page.clock.setFixedTime(now)
    installFakeBackend(page, { theme, now })
    await page.goto('/#/chat')
    await setTheme(page, theme)

    for (const screen of SCREENS) {
      await page.goto(`/${screen.hash}`)
      await page.reload()
      await expect(page.locator('html')).toHaveAttribute('data-theme', theme)
      await expect(page.getByRole('heading', { name: screen.heading })).toBeVisible()
      await expect(page.getByText('Yükleniyor…')).toHaveCount(0)
      await page.evaluate(() => document.fonts.ready)
      await expect(page).toHaveScreenshot(`${screen.name}-${theme}.png`, { fullPage: true, maxDiffPixelRatio: 0.02 })
    }
  })
}

test('telefon genişliğinde menü çekmeceye dönüşür', async ({ page }) => {
  installFakeBackend(page)
  await page.setViewportSize({ width: 390, height: 844 })
  await page.goto('/#/chat')

  const menu = page.getByRole('navigation', { name: 'Ana menü' })
  await expect(menu).not.toBeInViewport()
  await page.getByRole('button', { name: 'Menüyü aç' }).click()
  await expect(menu).toBeInViewport()
  await menu.getByRole('link', { name: /Agentlar/ }).click()
  await expect(page.getByRole('heading', { name: 'Agent Kaydı' })).toBeVisible()
  await expect(menu).not.toBeInViewport()
})
