import { test, expect } from '@playwright/test';

const ADMIN_URL = '/admin';
const LOGIN_ID = process.env.ADMIN_LOGIN_ID || 'admin';
const PASSWORD = process.env.ADMIN_PASSWORD || 'password';

// 環境変数 ECAUTH_DISABLE_ADMIN_PASSWORD_LOGIN=1 で起動した EC-CUBE でのみ意味を持つ。
// 他の spec はパスワードで管理画面にログインするため同じコンテナでは同居できず、
// CI では専用ジョブ (.github/workflows/playwright.yml の e2e-password-login-disabled)
// がクリーンな環境を立てて、この spec だけを流す。
const ENABLED = process.env.E2E_ADMIN_PASSWORD_LOGIN_DISABLED === '1';

/**
 * 管理画面のパスワード認証を無効化した状態の検証。
 *
 * 守りの本体は Security/AdminPasswordLoginListener（Symfony の CheckPassportEvent）で、
 * ログイン画面の見た目は案内でしかない。したがって「入力欄が隠れていること」より
 * 「正しい ID とパスワードでも認証が通らないこと」の方が重要なテストになる。
 * フォームを経由しない POST も併せて確認する。
 *
 * ログイン試行は EC-CUBE の login_throttling（既定 5 回 / 30 分、login_id + IP 単位）を
 * 消費する。リトライ込みでも上限に当たらないよう、失敗させる管理者ログインは
 * 2 回までに抑えている。増やすときは throttling に当たって別のエラーになる点に注意。
 */
test.describe('パスワード認証の無効化', () => {
  test.skip(!ENABLED, 'ECAUTH_DISABLE_ADMIN_PASSWORD_LOGIN=1 で起動した環境でのみ実行する');

  test('ログイン画面から ID・パスワードの入力欄と送信ボタンが消え、案内が表示される', async ({ page }) => {
    await page.goto(`${ADMIN_URL}/login`);

    const notice = page.locator('#ecauth-password-login-disabled');
    await expect(notice).toBeVisible();
    await expect(notice).toContainText('パスワード認証は無効化されています');

    // 要素は DOM に残す（隠すだけ）。隠したフォームがそれでも送信されたときに、
    // サーバー側が「パスワード認証は無効」と返せるようにするため。
    await expect(page.locator('input[name="login_id"]')).toBeHidden();
    await expect(page.locator('input[name="password"]')).toBeHidden();
    await expect(page.locator('form[action*="login"] button[type="submit"]')).toBeHidden();
  });

  test('パスキーでのログイン導線は残る', async ({ page }) => {
    await page.goto(`${ADMIN_URL}/login`);

    const passkeyBtn = page.locator('#ecauth-passkey-login');
    await expect(passkeyBtn).toBeVisible();
    await expect(passkeyBtn).toHaveText(/パスキーでログイン/);
  });

  test('正しい ID とパスワードでもログインできない', async ({ page }) => {
    await page.goto(`${ADMIN_URL}/login`);

    // 入力欄は隠れているので Playwright の fill() は使えない。値を直接入れて
    // submit() する＝「JS による非表示化を回避された」状況を再現する。
    await page.evaluate(
      ({ id, pw }) => {
        const form = document.querySelector('form[action*="login"]') as HTMLFormElement;
        (form.querySelector('[name="login_id"]') as HTMLInputElement).value = id;
        (form.querySelector('[name="password"]') as HTMLInputElement).value = pw;
        form.submit();
      },
      { id: LOGIN_ID, pw: PASSWORD },
    );

    await page.waitForURL(`**${ADMIN_URL}/login**`);
    // 管理画面には入れていない
    expect(page.url()).toContain(`${ADMIN_URL}/login`);
    await expect(page.locator('text=管理画面のパスワード認証は無効化されています')).toBeVisible();
  });

  test('フォームを経由しない POST でもログインできない', async ({ page }) => {
    await page.goto(`${ADMIN_URL}/login`);
    const csrfToken = await page.getAttribute('input[name="_csrf_token"]', 'value');
    expect(csrfToken).toBeTruthy();

    // page.request はページと同じ Cookie を共有するので、CSRF トークンが対応する
    // セッションのまま送れる。ここが通ってしまうと、画面の作り込みに関係なく
    // 認証が突破されることになる。
    const response = await page.request.post(`${ADMIN_URL}/login`, {
      form: {
        login_id: LOGIN_ID,
        password: PASSWORD,
        _csrf_token: csrfToken as string,
      },
    });

    // 成功していれば admin_homepage へ、失敗していれば admin_login へ戻される
    expect(response.url()).toContain(`${ADMIN_URL}/login`);
    expect(await response.text()).toContain('管理画面のパスワード認証は無効化されています');

    // セッションが確立していないことを、管理画面トップへの遷移でも確認する
    await page.goto(`${ADMIN_URL}/`);
    await page.waitForURL(`**${ADMIN_URL}/login**`);
  });

  // Symfony は CheckPassportEvent のグローバルリスナーを全ファイアウォールの
  // ディスパッチャに複製する（RegisterGlobalSecurityEventListenersPass）。
  // admin ファイアウォールで絞り損ねると、EC サイトの会員が巻き添えでログイン
  // できなくなる。ここはその回帰テスト。
  test('EC サイトのフロント会員ログインは影響を受けない', async ({ page }) => {
    await page.goto('/mypage/login');

    // 会員ログインのパスワード欄は隠されていない
    await expect(page.locator('input[name="login_email"]')).toBeVisible();
    await expect(page.locator('input[name="login_pass"]')).toBeVisible();

    await page.fill('input[name="login_email"]', 'ecauth-e2e-not-exists@example.com');
    await page.fill('input[name="login_pass"]', 'wrong-password-for-e2e');
    await page.click('#login_mypage button[type="submit"]');

    // 通常どおり「認証情報が違う」で弾かれる。プラグインの文言が出るなら、
    // customer ファイアウォールまで塞いでしまっている。
    await expect(page.locator('.ec-errorMessage')).toBeVisible();
    await expect(page.locator('text=管理画面のパスワード認証は無効化されています')).toHaveCount(0);
  });
});
