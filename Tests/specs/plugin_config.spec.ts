import { test, expect } from '@playwright/test';

const ADMIN_URL = '/admin';
const LOGIN_ID = process.env.ADMIN_LOGIN_ID || 'admin';
const PASSWORD = process.env.ADMIN_PASSWORD || 'password';

const ADVANCED_TOGGLE = 'button[data-bs-toggle="collapse"][data-bs-target="#ecauth-advanced-settings"]';
const ADVANCED_PANEL = '#ecauth-advanced-settings';

// 導線の URL は services.yaml の parameters が既定値で、環境変数で上書きできる。
// テスト側も同じ解決順にしておく（CI では環境変数未設定なので既定値が使われる）。
const SIGNUP_URL = process.env.ECAUTH_SIGNUP_URL || 'https://ec-auth.io/signup/';
const MYPAGE_URL = process.env.ECAUTH_MYPAGE_URL || 'https://ec-auth.io/mypage/';

// EcAuth URL は許可リスト（ECAUTH_ALLOWED_HOSTS、既定は .ec-auth.io）を通るホストしか
// 保存できない（EcAuthDocs #101）。保存できる例として ec-auth.io のサブドメインを使う。
const ALLOWED_BASE_URL = 'https://e2e-tenant.ec-auth.io';

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
    // 部分一致だと導線カードの説明文（「下の『EcAuth 接続設定』に入力してください」）にも
    // マッチして strict mode 違反になるため、カード見出しに完全一致させる
    await expect(page.getByText('EcAuth 接続設定', { exact: true })).toBeVisible();
  });

  test('申込・マイページへの導線が表示される', async ({ page }) => {
    await page.goto(`${ADMIN_URL}/ecauth_login43/config`);

    // 導線カードは接続設定カードより前（未設定の管理者が最初に目にする位置）に置く
    await expect(page.locator('.c-primaryCol .card-header').first()).toContainText(
      'Client ID / Client Secret の取得方法',
    );
    // はじめて利用する管理者向けに、申込〜設定までの手順を明示する
    await expect(page.locator('text=はじめて EcAuth をご利用の場合')).toBeVisible();
    await expect(page.locator('text=すでにお申し込み済みの場合')).toBeVisible();

    const signup = page.locator('#ecauth-signup-link');
    await expect(signup).toBeVisible();
    await expect(signup).toHaveAttribute('href', SIGNUP_URL);
    // 管理画面を離脱させないよう別タブで開く。target=_blank には rel の付与が必須
    await expect(signup).toHaveAttribute('target', '_blank');
    await expect(signup).toHaveAttribute('rel', /noopener/);

    const mypage = page.locator('#ecauth-mypage-link');
    await expect(mypage).toBeVisible();
    await expect(mypage).toHaveAttribute('href', MYPAGE_URL);
    await expect(mypage).toHaveAttribute('target', '_blank');
    await expect(mypage).toHaveAttribute('rel', /noopener/);
  });

  test('高度な設定がデフォルトで折りたたまれている', async ({ page }) => {
    await page.goto(`${ADMIN_URL}/ecauth_login43/config`);

    // Client ID / Client Secret はメインカードに表示
    await expect(page.locator('input[name="config[client_id]"]')).toBeVisible();
    await expect(page.locator('input[name="config[client_secret]"]')).toBeVisible();

    // 高度な設定は折りたたまれている
    await expect(page.locator(ADVANCED_PANEL)).not.toHaveClass(/show/);
    await expect(page.locator('input[name="config[ecauth_base_url]"]')).not.toBeVisible();
    await expect(page.locator('input[name="config[rp_id]"]')).not.toBeVisible();

    // トグルをクリックすると展開される
    await page.click(ADVANCED_TOGGLE);
    await expect(page.locator(ADVANCED_PANEL)).toHaveClass(/show/);
    await expect(page.locator('input[name="config[ecauth_base_url]"]')).toBeVisible();
  });

  test('高度な設定で URL を直接指定して保存できる', async ({ page }) => {
    await page.goto(`${ADMIN_URL}/ecauth_login43/config`);

    await page.fill('input[name="config[client_id]"]', 'test-client-id');
    await page.fill('input[name="config[client_secret]"]', 'test-client-secret');

    // 高度な設定を展開して URL を入力（resolve をスキップ）
    await page.click(ADVANCED_TOGGLE);
    await expect(page.locator(ADVANCED_PANEL)).toHaveClass(/show/);
    await page.fill('input[name="config[ecauth_base_url]"]', ALLOWED_BASE_URL);

    await page.click('button[type="submit"]');

    // 保存成功メッセージ確認
    await expect(page.locator('.alert-success')).toBeVisible();

    // 値が永続化されていることを確認
    await page.goto(`${ADMIN_URL}/ecauth_login43/config`);
    await expect(page.locator('input[name="config[client_id]"]')).toHaveValue('test-client-id');
    await page.click(ADVANCED_TOGGLE);
    await expect(page.locator(ADVANCED_PANEL)).toHaveClass(/show/);
    await expect(page.locator('input[name="config[ecauth_base_url]"]')).toHaveValue(ALLOWED_BASE_URL);
  });

  // EcAuthDocs #101: Base URL はトークン交換先かつ JWKS 取得先になるため、
  // 許可リスト外のホストは保存段階で弾く。
  test('#101: 許可されていないホストの EcAuth URL は保存できない', async ({ page }) => {
    await page.goto(`${ADMIN_URL}/ecauth_login43/config`);

    await page.fill('input[name="config[client_id]"]', 'test-client-id');
    await page.fill('input[name="config[client_secret]"]', 'test-client-secret');

    await page.click(ADVANCED_TOGGLE);
    await expect(page.locator(ADVANCED_PANEL)).toHaveClass(/show/);
    await page.fill('input[name="config[ecauth_base_url]"]', 'https://auth.example.com');

    await page.click('button[type="submit"]');

    await expect(page.locator('.alert-success')).not.toBeVisible();
    await expect(page.locator('text=許可されていないホスト')).toBeVisible();

    // 拒否された値が保存されていないこと
    await page.goto(`${ADMIN_URL}/ecauth_login43/config`);
    await page.click(ADVANCED_TOGGLE);
    await expect(page.locator('input[name="config[ecauth_base_url]"]')).not.toHaveValue(
      'https://auth.example.com',
    );
  });
});
