import { test, expect } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * REQ-DEMO-013 — full demo frame: UiKit demo platform shell + AuditKit UI (the Symfony WebProfiler toolbar is hidden).
 * Playwright clips to the viewport, so the viewport is enlarged before capture.
 */
const outDir = process.env.SCREENSHOT_DIR
  ? resolve(process.env.SCREENSHOT_DIR)
  : resolve(__dirname, '../../../../docs/images/demo');

type Box = { x: number; y: number; width: number; height: number };
type Page = import('@playwright/test').Page;

async function boxOf(page: Page, selector: string): Promise<Box | null> {
  const loc = page.locator(selector).first();
  if ((await loc.count()) === 0) {
    return null;
  }
  return loc.boundingBox();
}

async function clipDemoFrame(page: Page): Promise<Box> {
  const shell = await boxOf(page, '[data-testid="demo-platform"]');
  const main = await boxOf(page, 'main');
  if (!shell || !main) {
    throw new Error('Missing demo platform shell or main for the AuditKit screenshot clip');
  }
  const boxes = [shell, main] as Box[];
  const x = Math.min(...boxes.map((b) => b.x));
  const y = Math.min(...boxes.map((b) => b.y));
  const right = Math.max(...boxes.map((b) => b.x + b.width));
  const bottom = Math.max(...boxes.map((b) => b.y + b.height));
  const pad = 8;
  return {
    x: Math.max(0, x - pad),
    y: Math.max(0, y - pad),
    width: right - x + pad * 2,
    height: bottom - y + pad * 2,
  };
}

async function hideProfiler(page: Page) {
  await page.addStyleTag({ content: '.sf-toolbar,.sf-minitoolbar,[id^="sfwdt"]{display:none!important}' });
}

async function capture(page: Page, file: string) {
  await page.waitForLoadState('networkidle', { timeout: 20000 }).catch(() => {});
  await page.evaluate(() => document.fonts.ready).catch(() => {});
  await hideProfiler(page);
  await page.setViewportSize({ width: 1280, height: 720 });
  const height = await page.evaluate(() => document.documentElement.scrollHeight);
  await page.setViewportSize({ width: 1280, height: Math.min(Math.max(height, 720), 1800) });
  await page.screenshot({ path: resolve(outDir, file), clip: await clipDemoFrame(page) });
}

test.beforeAll(() => {
  mkdirSync(outDir, { recursive: true });
});

test.describe('AuditKit screenshots (full demo context)', () => {
  test('timeline — action log with actor and subject avatars', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 1000 });
    await page.goto('/timeline');
    await expect(page.getByTestId('action-log-entry')).toHaveCount(4);
    await capture(page, 'timeline.png');
  });

  test('audit fields — blame and timestamps on the demo entity', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 1000 });
    await page.goto('/');
    await expect(page.locator('main')).toBeVisible();
    await capture(page, 'audit-fields.png');
  });
});
