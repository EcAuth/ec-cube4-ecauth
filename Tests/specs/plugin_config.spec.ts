import { test, expect } from '@playwright/test';

const ADMIN_URL = '/admin';
const LOGIN_ID = process.env.ADMIN_LOGIN_ID || 'admin';
const PASSWORD = process.env.ADMIN_PASSWORD || 'password';

test.describe('プラグイン設定画面', () => {
  test.beforeEach(async ({ page }) => {
    // 管理画面ログイン
    await page.goto(`${ADMIN_URL}/login`);
    await page.fill('input[name="login_id"]', LOGIN_ID);
    await page.fill('input[name="password"]', PASSWORD);
    await page.click('button[type="submit"]');
    await page.waitForURL(`**${ADMIN_URL}/**`);
  });

  test('設定画面にアクセスできる', async ({ page }) => {
    await page.goto(`${ADMIN_URL}/ecauth_login43/config`);
    await expect(page.locator('text=EcAuth 接続設定')).toBeVisible();
  });

  test('設定を保存できる', async ({ page }) => {
    await page.goto(`${ADMIN_URL}/ecauth_login43/config`);

    await page.fill('input[name="config[ecauth_base_url]"]', 'https://auth.example.com');
    await page.fill('input[name="config[client_id]"]', 'test-client-id');
    await page.fill('input[name="config[client_secret]"]', 'test-client-secret');

    await page.click('button[type="submit"]');

    // 保存成功メッセージ確認
    await expect(page.locator('.alert-success')).toBeVisible();

    // 値が永続化されていることを確認
    await page.goto(`${ADMIN_URL}/ecauth_login43/config`);
    await expect(page.locator('input[name="config[ecauth_base_url]"]')).toHaveValue('https://auth.example.com');
    await expect(page.locator('input[name="config[client_id]"]')).toHaveValue('test-client-id');
  });
});
