import { test, expect } from '@playwright/test';

const ADMIN_URL = '/admin';

test.describe('パスキーログインフロー', () => {
  test('ログイン画面にパスキーボタンが表示される（HTTPS時）', async ({ page }) => {
    await page.goto(`${ADMIN_URL}/login`);

    // パスキーボタンの存在確認
    const passkeyBtn = page.locator('#ecauth-passkey-login');
    await expect(passkeyBtn).toBeVisible();
    await expect(passkeyBtn).toHaveText(/パスキーでログイン/);
  });

  test('パスキーボタンクリックで認証フローが開始される', async ({ page, context }) => {
    // Virtual Authenticator を設定
    const cdpSession = await context.newCDPSession(page);
    await cdpSession.send('WebAuthn.enable');
    const { authenticatorId } = await cdpSession.send('WebAuthn.addVirtualAuthenticator', {
      options: {
        protocol: 'ctap2',
        transport: 'internal',
        hasResidentKey: true,
        hasUserVerification: true,
        isUserVerified: true,
      },
    });

    await page.goto(`${ADMIN_URL}/login`);

    const passkeyBtn = page.locator('#ecauth-passkey-login');
    await passkeyBtn.click();

    // ボタンが disabled になることを確認（認証中状態）
    await expect(passkeyBtn).toBeDisabled();

    // クリーンアップ
    await cdpSession.send('WebAuthn.removeVirtualAuthenticator', { authenticatorId });
  });
});
