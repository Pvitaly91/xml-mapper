import { expect } from '@playwright/test';
import { totpCode } from './totp.js';

export async function loginWithMfa(page, user, loginUrl) {
    await page.goto(loginUrl);
    await page.getByTestId('login-email').fill(user.email);
    await page.getByTestId('login-password').fill(user.password);
    await page.getByTestId('login-submit').click();

    if (user.totp_secret) {
        await expect(page).toHaveURL(/\/admin\/security\/mfa\/challenge$/);
        await page.getByTestId('mfa-challenge-code').fill(totpCode(user.totp_secret));
        await page.getByTestId('mfa-challenge-submit').click();
    }

    await expect(page).toHaveURL(/\/admin$/);
}

export async function logout(page) {
    await page.getByRole('button', { name: 'Logout' }).click();
    await expect(page).toHaveURL(/\/admin\/login$/);
}

export async function switchShop(page, shopId) {
    const switcher = page.getByTestId('layout-shop-switch');

    if (await switcher.count()) {
        await switcher.selectOption(String(shopId));
        await page.waitForLoadState('networkidle');
    }
}
