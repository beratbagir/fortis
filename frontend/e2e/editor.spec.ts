import { expect, test } from '@playwright/test'
import { installFakeBackend } from './fakeBackend'

test('akış aç → eşiği 240\'tan 180\'e çek → Teams bildirimi ekle → yayınla → test çalıştır → çalışmalarda başarılı', async ({ page }) => {
  const backend = installFakeBackend(page)

  await page.goto('/#/flows/1')
  await expect(page.getByRole('heading', { name: 'Uzun rötar bildirimi' })).toBeVisible()
  await expect(page.getByText('Taslak · v1')).toBeVisible()

  // Eşik: 240 → 180 (600 ms sonra tek update_settings)
  await page.getByRole('button', { name: /Rötarlı uçuşları getir, Veri · APM/ }).click()
  const threshold = page.getByLabel('Parametreler: min_delay_minutes değeri')
  await threshold.fill('180')
  await expect(page.getByText('Taslak · v2')).toBeVisible()
  expect(backend.operations).toEqual([{ op: 'update_settings', task_id: 'get_delays', settings: { params: { min_delay_minutes: '180' } } }])

  // Döngüye Teams bildirimi
  await page.getByRole('button', { name: '"OCC nöbetçiye e-posta" adımından sonra blok ekle' }).click()
  await page.getByRole('button', { name: /Microsoft Teams/ }).click()
  await page.getByRole('button', { name: /Kanala mesaj gönder Workflows/ }).click()
  await page.getByRole('textbox', { name: /Mesaj/ }).fill('{{ taskrun.value }} rötarlı')
  await page.getByRole('button', { name: 'Ekle', exact: true }).click()
  await expect(page.getByText('Taslak · v3')).toBeVisible()
  expect(backend.operations.at(-1)).toMatchObject({ op: 'insert', parent_id: 'notify_each', branch: 'tasks', after_id: 'send_mail', kind: 'notify_teams' })

  // Yayınla ve test çalıştır
  await page.getByRole('button', { name: 'Yayınla' }).click()
  await expect(page.getByText('Yayında · v3')).toBeVisible()
  await page.getByRole('button', { name: /Test çalıştır/ }).click()
  await expect(page.getByText(/Gönderilmeyen adımlar: notify_teams, send_mail/)).toBeVisible()
  await page.getByRole('link', { name: 'Çalışmayı izle' }).click()

  // Çalışmalar ekranı
  await expect(page.getByRole('heading', { name: 'Çalışmalar' })).toBeVisible()
  const detail = page.getByRole('region', { name: 'Çalışma ayrıntısı' })
  await expect(detail.getByText('Başarılı', { exact: true })).toBeVisible()
  await expect(detail.getByText(/HTTP 200/)).toBeVisible()
  await expect(detail.getByText(/Test \(kuru çalıştırma\)/)).toBeVisible()
})
