import { defineConfig, devices } from '@playwright/test';
import { existsSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/** Resolve the demo PORT from the sibling .env / .env.example (demo/symfony8). */
function resolvePort(fallback = 8013): number {
  for (const file of ['.env', '.env.example']) {
    const path = resolve(__dirname, '..', file);
    if (!existsSync(path)) {
      continue;
    }
    const m = readFileSync(path, 'utf8').match(/^PORT=(\d+)/m);
    if (m) {
      return Number(m[1]);
    }
  }
  return fallback;
}

const baseURL = process.env.DEMO_BASE_URL || `http://127.0.0.1:${Number(process.env.DEMO_PORT || resolvePort())}`;

export default defineConfig({
  testDir: './tests',
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  workers: 1,
  reporter: [['list']],
  use: {
    baseURL,
    trace: 'on-first-retry',
    viewport: { width: 1280, height: 900 },
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
});
