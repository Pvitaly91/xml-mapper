import { expect } from '@playwright/test';
import { totpCode } from './totp.js';

async function submitFreeze(page, reason) {
    await page.getByTestId('release-freeze-reason').fill(reason);
    await page.getByTestId('release-freeze-submit').click();
}

async function followFlashAction(page, expectedLabel) {
    const flash = page.getByTestId('flash-governance');
    await expect(flash).toBeVisible();

    if (expectedLabel) {
        await expect(flash).toContainText(expectedLabel);
    }

    await flash.getByRole('link').click();
}

export async function expireStepUp(page) {
    const response = await page.request.post('/__e2e/security/expire-step-up');
    expect(response.ok()).toBeTruthy();
}

export async function createFreezeApproval(page, manifest) {
    await page.goto(manifest.paths.release_center);
    await expireStepUp(page);

    await submitFreeze(page, 'E2E freeze request requiring step-up.');
    await followFlashAction(page, 'Confirm password');
    await expect(page).toHaveURL(/\/admin\/security\/reauth\/password$/);
    await page.getByTestId('reauth-password-input').fill(manifest.users.platform_admin.password);
    await page.getByTestId('reauth-password-submit').click();
    await expect(page).toHaveURL(new RegExp(`/admin/feed-profiles/${manifest.entities.feed_profile.id}/release-center$`));

    await submitFreeze(page, 'E2E freeze request requiring MFA step-up.');
    await followFlashAction(page, 'Confirm MFA');
    await expect(page).toHaveURL(/\/admin\/security\/reauth\/mfa$/);
    await page.getByTestId('reauth-mfa-input').fill(totpCode(manifest.users.platform_admin.totp_secret));
    await page.getByTestId('reauth-mfa-submit').click();
    await expect(page).toHaveURL(new RegExp(`/admin/feed-profiles/${manifest.entities.feed_profile.id}/release-center$`));

    await submitFreeze(page, 'E2E freeze request requiring approval.');
    const flash = page.getByTestId('flash-governance');
    await expect(flash).toContainText('Approval');
    await flash.getByRole('link', { name: 'Open approval request' }).click();
    await expect(page).toHaveURL(/\/admin\/access\/approvals\/\d+$/);

    return page.url();
}
