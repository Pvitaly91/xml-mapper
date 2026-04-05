import { test, expect } from '@playwright/test';
import { loadDemoManifest } from './support/demo-manifest.js';
import { loginWithMfa, switchShop } from './support/auth.js';
import { createFreezeApproval } from './support/governance.js';

test('@smoke dangerous release action requires step-up re-authentication and then queues approval', async ({ page }) => {
    const manifest = loadDemoManifest();

    await loginWithMfa(page, manifest.users.platform_admin, manifest.paths.login);
    await switchShop(page, manifest.shops.main.id);

    const approvalUrl = await createFreezeApproval(page, manifest);

    await expect(page).toHaveURL(approvalUrl);
    await expect(page.locator('body')).toContainText('Approval #');
    await expect(page.locator('body')).toContainText('E2E Production Mirror');
});
