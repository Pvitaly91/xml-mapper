import { test, expect } from '@playwright/test';
import { loadDemoManifest } from './support/demo-manifest.js';
import { loginWithMfa, switchShop } from './support/auth.js';

test('@smoke source connection test works and secret-governed edit queues approval without exposing raw values', async ({ page }) => {
    const manifest = loadDemoManifest();

    await loginWithMfa(page, manifest.users.platform_admin, manifest.paths.login);
    await switchShop(page, manifest.shops.main.id);

    await page.goto(manifest.paths.source_connection_show);
    await expect(page.locator('body')).not.toContainText('demo-merchant-password');
    await page.getByTestId('source-connection-test').click();
    await expect(page.getByTestId('flash-status')).toBeVisible();

    await page.goto(manifest.paths.source_connection_edit);
    await page.getByTestId('source-connection-credentials-json').fill('{"login":"demo-merchant-login","password":"rotated-e2e-secret"}');
    await page.getByTestId('source-connection-submit').click();

    await expect(page.getByTestId('flash-governance')).toContainText('Approval');
    await expect(page.locator('body')).not.toContainText('rotated-e2e-secret');
});
