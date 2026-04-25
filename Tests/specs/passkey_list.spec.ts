import { test, expect } from '@playwright/test';

const ADMIN_URL = '/admin';
const LOGIN_ID = process.env.ADMIN_LOGIN_ID || 'admin';
const PASSWORD = process.env.ADMIN_PASSWORD || 'password';

// パスキーログインを経た状態 (session に access_token / current_credential_id
// あり) での一覧画面検証は passkey_auth.spec.ts の describe.serial 末尾に
// 統合済み。本ファイルにはパスワードログインだけで成立する案内表示の検証を
// 残す。両方を独立 spec として持つと staging EcAuth の admin
// (ecauth_subject UNIQUE) を共有して並列実行で flaky になるため。
test.describe('パスキー一覧: パスワードログイン時の案内表示', () => {
  test('access token 不在時は login_required の案内が表示され一覧テーブルは出ない', async ({ page }) => {
    // パスワードログイン（パスキー認証を経ないため ecauth_access_token は無い）
    await page.goto(`${ADMIN_URL}/login`);
    await page.fill('input[name="login_id"]', LOGIN_ID);
    await page.fill('input[name="password"]', PASSWORD);
    await page.click('button[type="submit"]');
    await page.waitForURL(`**${ADMIN_URL}/**`);

    await page.goto(`${ADMIN_URL}/ecauth/passkey/`);

    // 案内文 (ecauth_login43.admin.passkey.login_required) が alert-warning で表示される
    const alert = page.locator('.card-body .alert-warning');
    await expect(alert).toBeVisible();
    await expect(alert).toContainText('パスキー一覧を表示するには');
    await expect(alert).toContainText('パスキーでログイン');

    // 一覧テーブルも「登録済みパスキーはありません」も出ない（error 分岐に入るため）
    await expect(page.locator('.card-body table.table-sm')).toHaveCount(0);
  });
});
