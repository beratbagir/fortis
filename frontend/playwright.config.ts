import { defineConfig } from '@playwright/test'

// Uçtan uca senaryo. API sahte (e2e/fakeBackend.ts); gerçek Kestra veya Claude çağrılmaz.
// Tarayıcı indirmemek için sistemdeki Chrome kullanılır.
export default defineConfig({
  testDir: './e2e',
  timeout: 60_000,
  use: {
    baseURL: 'http://localhost:5174',
    channel: 'chrome',
    viewport: { width: 1440, height: 900 },
  },
  webServer: {
    command: 'npx vite --port 5174 --strictPort',
    url: 'http://localhost:5174',
    reuseExistingServer: true,
  },
})
