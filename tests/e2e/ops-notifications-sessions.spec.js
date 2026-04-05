import { test, expect } from '@playwright/test';
import { loadDemoManifest } from './support/demo-manifest.js';
import { loginWithMfa, switchShop } from './support/auth.js';

test('notification center test delivery and session governance flows are operator-usable', async ({ browser, page }) => {
    const manifest = loadDemoManifest();

    await loginWithMfa(page, manifest.users.platform_admin, manifest.paths.login);
    await switchShop(page, manifest.shops.main.id);

    const extraContext = await browser.newContext();
    const extraPage = await extraContext.newPage();
    await loginWithMfa(extraPage, manifest.users.platform_admin, manifest.paths.login);
    await switchShop(extraPage, manifest.shops.main.id);

    await page.goto(manifest.paths.sessions);
    await page.getByTestId('sessions-revoke-other-submit').click();
    await expect(page.getByTestId('flash-status')).toContainText('session(s) revoked');

    await extraContext.close();

    await page.goto(manifest.paths.notifications);
    await page.getByTestId('notification-route-name').fill('E2E Failing Webhook');
    await page.getByTestId('notification-route-scope').selectOption('shop');
    await page.getByTestId('notification-route-channel').selectOption('webhook');
    await page.getByTestId('notification-route-target').fill(manifest.fixtures.mock_webhook_fail);
    await page.getByTestId('notification-route-submit').click();
    await expect(page.getByTestId('flash-status')).toContainText('Notification route saved.');

    const routeRow = page.getByRole('row', { name: /E2E Failing Webhook/ });
    await routeRow.getByRole('button', { name: 'Test' }).click();
    await expect(page.getByTestId('flash-status')).toContainText('Test delivery recorded');

    const retryButton = page.getByRole('button', { name: 'Retry' }).first();
    await retryButton.click();
    await expect(page.getByTestId('flash-status')).toContainText('Delivery retried.');
});
