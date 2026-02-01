import { test, expect } from '@playwright/test';

const ADMIN_URL = '/admin';
const LOGIN_ID = process.env.ADMIN_LOGIN_ID || 'admin';
const PASSWORD = process.env.ADMIN_PASSWORD || 'password';

test.describe('パスキー管理画面', () => {
  test.beforeEach(async ({ page }) => {
    // 管理画面ログイン
    await page.goto(`${ADMIN_URL}/login`);
    await page.fill('input[name="login_id"]', LOGIN_ID);
    await page.fill('input[name="password"]', PASSWORD);
    await page.click('button[type="submit"]');
    await page.waitForURL(`**${ADMIN_URL}/**`);
  });

  test('パスキー管理画面にアクセスできる', async ({ page }) => {
    await page.goto(`${ADMIN_URL}/ecauth/passkey/`);
    await expect(page.locator('text=登録済みパスキー')).toBeVisible();
  });

  test('パスキー追加ボタンが表示される', async ({ page }) => {
    await page.goto(`${ADMIN_URL}/ecauth/passkey/`);
    const addBtn = page.locator('#ecauth-passkey-add');
    await expect(addBtn).toBeVisible();
  });

  test('パスキー追加クリックでパスワード確認モーダルが表示される', async ({ page }) => {
    await page.goto(`${ADMIN_URL}/ecauth/passkey/`);
    const addBtn = page.locator('#ecauth-passkey-add');
    await addBtn.click();

    const modal = page.locator('#ecauth-password-modal');
    await expect(modal).toBeVisible();

    const passwordInput = page.locator('#ecauth-password-input');
    await expect(passwordInput).toBeVisible();
  });

  test('キャンセルボタンでモーダルが閉じる', async ({ page }) => {
    await page.goto(`${ADMIN_URL}/ecauth/passkey/`);
    await page.locator('#ecauth-passkey-add').click();

    const modal = page.locator('#ecauth-password-modal');
    await expect(modal).toBeVisible();

    await page.locator('#ecauth-password-cancel').click();
    await expect(modal).not.toBeVisible();
  });
});
