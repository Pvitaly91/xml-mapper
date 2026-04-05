import { test, expect } from '@playwright/test';
import { loadDemoManifest } from './support/demo-manifest.js';
import { loginWithMfa, switchShop } from './support/auth.js';
import { createFreezeApproval } from './support/governance.js';

test('@smoke reviewer can open approval queue and approve an executed request', async ({ browser }) => {
    const manifest = loadDemoManifest();
    const platformContext = await browser.newContext();
    const platformPage = await platformContext.newPage();

    await loginWithMfa(platformPage, manifest.users.platform_admin, manifest.paths.login);
    await switchShop(platformPage, manifest.shops.main.id);
    const approvalUrl = await createFreezeApproval(platformPage, manifest);
    await platformContext.close();

    const reviewerContext = await browser.newContext();
    const reviewerPage = await reviewerContext.newPage();

    await loginWithMfa(reviewerPage, manifest.users.reviewer, manifest.paths.login);
    await switchShop(reviewerPage, manifest.shops.main.id);
    await reviewerPage.goto(approvalUrl);
    await reviewerPage.getByTestId('approval-approve-submit').click();

    await expect(reviewerPage.getByTestId('flash-status')).toContainText('Approval executed.');
    await expect(reviewerPage.locator('body')).toContainText('already in terminal or executed state');

    await reviewerContext.close();
});
