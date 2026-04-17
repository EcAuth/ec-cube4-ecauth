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

  test('高度な設定がデフォルトで折りたたまれている', async ({ page }) => {
    await page.goto(`${ADMIN_URL}/ecauth_login43/config`);

    // Client ID / Client Secret はメインカードに表示
    await expect(page.locator('input[name="config[client_id]"]')).toBeVisible();
    await expect(page.locator('input[name="config[client_secret]"]')).toBeVisible();

    // 高度な設定は折りたたまれている
    const advanced = page.locator('#ecauth-advanced-settings');
    await expect(advanced).not.toBeVisible();
    await expect(page.locator('input[name="config[ecauth_base_url]"]')).not.toBeVisible();
    await expect(page.locator('input[name="config[rp_id]"]')).not.toBeVisible();

    // トグルをクリックすると展開される
    await page.click('a[data-toggle="collapse"][href="#ecauth-advanced-settings"]');
    await expect(advanced).toBeVisible();
    await expect(page.locator('input[name="config[ecauth_base_url]"]')).toBeVisible();
  });

  test('高度な設定で URL を直接指定して保存できる', async ({ page }) => {
    await page.goto(`${ADMIN_URL}/ecauth_login43/config`);

    await page.fill('input[name="config[client_id]"]', 'test-client-id');
    await page.fill('input[name="config[client_secret]"]', 'test-client-secret');

    // 高度な設定を展開して URL を入力（resolve をスキップ）
    await page.click('a[data-toggle="collapse"][href="#ecauth-advanced-settings"]');
    await page.fill('input[name="config[ecauth_base_url]"]', 'https://auth.example.com');

    await page.click('button[type="submit"]');

    // 保存成功メッセージ確認
    await expect(page.locator('.alert-success')).toBeVisible();

    // 値が永続化されていることを確認
    await page.goto(`${ADMIN_URL}/ecauth_login43/config`);
    await expect(page.locator('input[name="config[client_id]"]')).toHaveValue('test-client-id');
    await page.click('a[data-toggle="collapse"][href="#ecauth-advanced-settings"]');
    await expect(page.locator('input[name="config[ecauth_base_url]"]')).toHaveValue('https://auth.example.com');
  });
});
