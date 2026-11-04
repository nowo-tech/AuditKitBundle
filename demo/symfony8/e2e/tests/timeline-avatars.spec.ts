import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

/**
 * AuditKit 1.11 (/timeline): actor and subject avatars in the action log timeline from one resolver call. The demo
 * resolver gives Ada a picture, Grace a broken URL, the anonymised account none and Alan an http: URL the policy drops.
 * The demo does not install UiKit, so this checks the bundle's own markup and its no-UiKit degradation: the initials
 * stay in the DOM next to the picture, a broken picture (alt="") paints nothing, and axe stays clean.
 */
test.describe('action log timeline avatars (1.11)', () => {
  test('pictures for actor and subject, a broken URL degrades, rejected and anonymised accounts get none', async ({ page }) => {
    const requested: string[] = [];
    page.on('request', (request) => requested.push(request.url()));
    await page.goto('/timeline');

    await expect(page.getByTestId('resolver-calls')).toHaveText('Resolver calls for this timeline: 1');
    const entries = page.getByTestId('action-log-entry');
    await expect(entries).toHaveCount(4);

    // Entry 1: Ada (picture) acts on Grace (broken URL).
    const first = entries.nth(0).locator('.nowo-audit-timeline__avatar');
    await expect(first).toHaveCount(2);
    await expect(first.nth(0)).toHaveAttribute('aria-hidden', 'true');
    await expect(first.nth(0)).toContainText('AL');
    const ada = first.nth(0).locator('img');
    await expect(ada).toHaveAttribute('alt', '');
    await expect(ada).toHaveAttribute('referrerpolicy', 'same-origin');
    await expect(ada).toHaveAttribute('width', '24');
    await expect.poll(() => ada.evaluate((img: HTMLImageElement) => img.complete && img.naturalWidth > 0)).toBe(true);
    const grace = first.nth(1).locator('img');
    await expect.poll(() => grace.evaluate((img: HTMLImageElement) => img.complete && img.naturalWidth === 0)).toBe(true);
    await expect(first.nth(1)).toContainText('GH');

    // Entry 2: Grace (broken) acts on Alan (http: rejected → no avatar at all).
    await expect(entries.nth(1).locator('.nowo-audit-timeline__avatar')).toHaveCount(1);
    expect(requested.filter((url) => url.includes('example.com'))).toEqual([]);

    // Entry 3: the anonymised actor has no picture; entry 4: no actor ("by system"), Ada as the subject.
    await expect(entries.nth(2).locator('.nowo-audit-timeline__avatar')).toHaveCount(0);
    await expect(entries.nth(3).locator('.nowo-audit-timeline__avatar')).toHaveCount(1);

    expect(await page.locator('[onerror], [onload], .nowo-audit-timeline [style]').count()).toBe(0);
  });

  test('axe: no violation on the timeline with a picture, a broken URL and no picture', async ({ page }) => {
    await page.goto('/timeline');
    await expect(page.getByTestId('action-log-entry')).toHaveCount(4);
    const scoped = await new AxeBuilder({ page })
      .include('[data-nowo-audit-timeline]')
      .withRules(['image-alt', 'role-img-alt', 'aria-allowed-role', 'aria-hidden-focus', 'color-contrast'])
      .analyze();
    expect(scoped.violations).toEqual([]);
    const full = await new AxeBuilder({ page }).include('[data-nowo-audit-timeline]').analyze();
    expect(full.violations).toEqual([]);
  });
});
