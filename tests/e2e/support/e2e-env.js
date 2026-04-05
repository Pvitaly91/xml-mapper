import path from 'node:path';

export const baseUrl = process.env.E2E_BASE_URL ?? 'http://127.0.0.1:8101';

export function buildE2eEnv(repoRoot) {
    return {
        ...process.env,
        APP_ENV: 'e2e',
        APP_URL: baseUrl,
        DB_CONNECTION: 'sqlite',
        DB_DATABASE: path.join(repoRoot, 'database', 'e2e.sqlite'),
        CACHE_STORE: 'array',
        QUEUE_CONNECTION: 'sync',
        SESSION_DRIVER: 'database',
        MAIL_MAILER: 'log',
        LOG_CHANNEL: process.env.LOG_CHANNEL ?? 'stack',
        FEED_MEDIATOR_ENV_CLASS: 'production',
        FEED_MEDIATOR_ENV_LABEL: 'E2E Production Mirror',
        FEED_MEDIATOR_AUTH_MFA_NON_PROD: 'true',
        FEED_MEDIATOR_AUTH_MFA_ROLES: 'platform_admin,shop_admin',
        FEED_MEDIATOR_AUTH_REAUTH_MFA_ACTIONS: 'release.freeze_toggle,source.secret_rebind',
        FEED_MEDIATOR_NOTIFY_MAIL_ENABLED: 'false',
        FEED_MEDIATOR_NOTIFY_DB_ENABLED: 'true',
        FEED_MEDIATOR_NOTIFY_LOG_ENABLED: 'true',
    };
}
