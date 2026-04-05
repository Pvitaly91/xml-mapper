import { test, expect } from '@playwright/test';
import { loadDemoManifest } from './support/demo-manifest.js';
import { logout } from './support/auth.js';
import { totpCode } from './support/totp.js';

test.use({
    screenshot: 'off',
    trace: 'off',
    video: 'off',
});

test('@smoke invite acceptance completes password setup, MFA enrollment, and later login challenge', async ({ page }) => {
    const manifest = loadDemoManifest();
    const invitePassword = 'InvitedShopAdminPass123!';

    await page.goto(manifest.users.invited_shop_admin.accept_url);
    await page.getByTestId('invite-name').fill('Invited Shop Admin');
    await page.getByTestId('invite-password').fill(invitePassword);
    await page.getByTestId('invite-password-confirmation').fill(invitePassword);
    await page.getByTestId('invite-accept').click();

    await expect(page).toHaveURL(/\/admin\/security\/mfa\/setup$/);

    const secret = (await page.getByTestId('mfa-secret').textContent()).trim();
    await page.getByTestId('mfa-setup-code').fill(totpCode(secret));
    await page.getByTestId('mfa-enable-submit').click();

    await expect(page.getByTestId('mfa-recovery-codes')).toBeVisible();
    await page.getByTestId('mfa-continue-dashboard').click();
    await expect(page).toHaveURL(/\/admin$/);

    await logout(page);

    await page.goto(manifest.paths.login);
    await page.getByTestId('login-email').fill(manifest.users.invited_shop_admin.email);
    await page.getByTestId('login-password').fill(invitePassword);
    await page.getByTestId('login-submit').click();
    await expect(page).toHaveURL(/\/admin\/security\/mfa\/challenge$/);
    await page.getByTestId('mfa-challenge-code').fill(totpCode(secret));
    await page.getByTestId('mfa-challenge-submit').click();
    await expect(page).toHaveURL(/\/admin$/);
});
