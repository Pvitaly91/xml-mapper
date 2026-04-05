import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { defineConfig, devices } from '@playwright/test';
import { baseUrl, buildE2eEnv } from './tests/e2e/support/e2e-env.js';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const e2eEnv = buildE2eEnv(__dirname);

export default defineConfig({
    testDir: './tests/e2e',
    timeout: 120_000,
    expect: {
        timeout: 15_000,
    },
    fullyParallel: false,
    workers: 1,
    reporter: [
        ['list'],
        ['html', { open: 'never', outputFolder: 'playwright-report' }],
    ],
    use: {
        baseURL: baseUrl,
        trace: 'retain-on-failure',
        video: 'retain-on-failure',
        screenshot: 'only-on-failure',
        actionTimeout: 15_000,
    },
    projects: [
        {
            name: 'chromium',
            use: {
                ...devices['Desktop Chrome'],
            },
        },
    ],
    webServer: {
        command: 'php -r "if (!is_dir(\'database\')) { mkdir(\'database\', 0777, true); } if (!file_exists(\'database/e2e.sqlite\')) { touch(\'database/e2e.sqlite\'); }" && php artisan demo:bootstrap-e2e --fresh --json && php artisan serve --host=127.0.0.1 --port=8101',
        url: `${baseUrl}/admin/login`,
        cwd: __dirname,
        env: e2eEnv,
        reuseExistingServer: !process.env.CI,
        timeout: 180_000,
    },
});
