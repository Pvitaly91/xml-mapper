import { test, expect } from '@playwright/test';
import { loadDemoManifest } from './support/demo-manifest.js';
import { loginWithMfa, switchShop } from './support/auth.js';

test('@smoke release center, acceptance visibility, and launch center critical happy path work with demo data', async ({ page }) => {
    const manifest = loadDemoManifest();

    await loginWithMfa(page, manifest.users.platform_admin, manifest.paths.login);
    await switchShop(page, manifest.shops.main.id);

    await page.goto(manifest.paths.release_center);
    await expect(page.getByText('Go-Live Checklist')).toBeVisible();
    await page.getByRole('link', { name: 'Acceptance screen' }).click();
    await expect(page).toHaveURL(new RegExp(`/admin/feed-profiles/${manifest.entities.feed_profile.id}/acceptance`));
    await expect(page.locator('body')).toContainText('Acceptance');

    await page.goto(manifest.paths.launch_center);
    await page.getByTestId('launch-feed-profile').selectOption(String(manifest.entities.feed_profile.id));
    await page.getByTestId('launch-start-submit').click();

    await expect(page).toHaveURL(/\/admin\/merchant-launches\/\d+$/);
    await expect(page.locator('body')).toContainText('Launch Check');
    await page.locator('#handover_reason').fill('E2E guardrail check for launch handover.');
    await page.getByTestId('launch-handover-submit').click();
    await expect(page.getByTestId('flash-error')).toContainText('Launch handover is blocked');
});
