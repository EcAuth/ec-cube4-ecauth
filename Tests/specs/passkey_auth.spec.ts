import { test, expect, BrowserContext, Page, CDPSession } from '@playwright/test';

const ADMIN_URL = '/admin';
const LOGIN_ID = process.env.ADMIN_LOGIN_ID || 'admin';
const PASSWORD = process.env.ADMIN_PASSWORD || 'password';

const ECAUTH_BASE_URL = process.env.ECAUTH_BASE_URL || '';
const CLIENT_ID = process.env.CLIENT_ID || '';
const CLIENT_SECRET = process.env.CLIENT_SECRET || '';
const RP_ID = process.env.RP_ID || 'localhost';

const ADVANCED_TOGGLE = 'button[data-bs-toggle="collapse"][data-bs-target="#ecauth-advanced-settings"]';

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

    try {
      await page.goto(`${ADMIN_URL}/login`);

      const passkeyBtn = page.locator('#ecauth-passkey-login');
      await passkeyBtn.click();

      // ボタンが disabled になることを確認（認証中状態）
      await expect(passkeyBtn).toBeDisabled();
    } finally {
      // クリーンアップ
      await cdpSession.send('WebAuthn.removeVirtualAuthenticator', { authenticatorId });
    }
  });
});

/**
 * EcAuth ステージング環境に対する、プラグイン UI を通したパスキー認証フローの
 * エンドツーエンド検証。
 *
 * 前提:
 *   - Docker で EC-CUBE が https://localhost:8081 で起動していること
 *   - ECAUTH_BASE_URL / CLIENT_ID / CLIENT_SECRET に ecauth-staging-app の値が設定されていること
 *   - EcAuth 側の b2b_allowed_rp_ids に `localhost` が含まれていること
 *
 * フロー:
 *   1. 管理者ログイン → プラグイン設定画面でステージング接続情報を保存
 *   2. パスキー管理画面から新規パスキー登録（ecauth_subject を JIT 生成）
 *   3. 管理画面からログアウト
 *   4. 管理ログイン画面でパスキーボタンをクリックし、コールバック経由で /admin/ までリダイレクトされることを検証
 *   5. 登録したパスキーを削除してクリーンアップ
 */
test.describe.serial('E2E: パスキー登録からログイン完了までのフロー', () => {
  test.skip(
    !ECAUTH_BASE_URL || !CLIENT_ID || !CLIENT_SECRET,
    'ECAUTH_BASE_URL / CLIENT_ID / CLIENT_SECRET が未設定のためスキップ（1Password: ecauth-staging-app 参照）',
  );

  let context: BrowserContext;
  let page: Page;
  let cdpSession: CDPSession;
  let authenticatorId: string;

  test.beforeAll(async ({ browser }) => {
    context = await browser.newContext({ ignoreHTTPSErrors: true });

    // WebAuthn の timeout=0 をサーバーから返されるケースに備えて、
    // ページ遷移後も常に有効な timeout に上書きする。
    // 併せて navigator.credentials.get の解決値/エラーを console にダンプして
    // CI で WebAuthn 側の失敗原因を可視化する（login_passkey.twig 側の catch は
    // NotAllowedError を握り潰すためログに出てこない）。
    await context.addInitScript(() => {
      const originalCreate = navigator.credentials.create.bind(navigator.credentials);
      navigator.credentials.create = async (options?: CredentialCreationOptions) => {
        if (options?.publicKey && (!options.publicKey.timeout || options.publicKey.timeout === 0)) {
          options.publicKey.timeout = 60000;
        }
        try {
          const cred = await originalCreate(options);
          console.log('[E2E] credentials.create resolved: id=' + (cred as PublicKeyCredential | null)?.id);
          return cred;
        } catch (e) {
          const err = e as Error;
          console.log('[E2E] credentials.create rejected: name=' + err.name + ' message=' + err.message);
          throw e;
        }
      };
      const originalGet = navigator.credentials.get.bind(navigator.credentials);
      navigator.credentials.get = async (options?: CredentialRequestOptions) => {
        if (options?.publicKey && (!options.publicKey.timeout || options.publicKey.timeout === 0)) {
          options.publicKey.timeout = 60000;
        }
        try {
          const ac = options?.publicKey?.allowCredentials;
          const summary = ac
            ? ac.map((c) => ({ idLen: c.id instanceof ArrayBuffer ? c.id.byteLength : (c.id as ArrayBufferView).byteLength, type: c.type, transports: c.transports ?? [] }))
            : [];
          console.log('[E2E] credentials.get rpId=' + options?.publicKey?.rpId + ' uv=' + options?.publicKey?.userVerification + ' allowCount=' + (ac?.length ?? 0) + ' allow=' + JSON.stringify(summary));
          const cred = await originalGet(options);
          console.log('[E2E] credentials.get resolved: id=' + (cred as PublicKeyCredential | null)?.id);
          return cred;
        } catch (e) {
          const err = e as Error;
          console.log('[E2E] credentials.get rejected: name=' + err.name + ' message=' + err.message);
          throw e;
        }
      };
      window.addEventListener('unhandledrejection', (e) => {
        console.log('[E2E] unhandledrejection: ' + String((e as PromiseRejectionEvent).reason));
      });
      window.addEventListener('error', (e) => {
        console.log('[E2E] window.error: ' + ((e as ErrorEvent).message || ''));
      });
    });

    page = await context.newPage();

    // ブラウザ console と pageerror を Playwright 側のログに流す。
    page.on('console', (msg) => console.log('[browser:' + msg.type() + '] ' + msg.text()));
    page.on('pageerror', (err) => console.log('[pageerror] ' + err.message));
    // EcAuth エンドポイントのレスポンス本文をダンプ（リクエスト時のタイミングで取得）。
    page.on('response', async (res) => {
      const u = res.url();
      if (u.includes('/ecauth/passkey/authenticate/options') || u.includes('/ecauth/passkey/authenticate/verify') || u.includes('/ecauth/callback')) {
        try {
          const body = await res.text();
          console.log('[response ' + res.status() + '] ' + u + ' -> ' + body.substring(0, 2000));
        } catch {
          // body 取得失敗は無視
        }
      }
    });

    // CDP セッション作成前にページを 1 度開いておく
    await page.goto(`${ADMIN_URL}/login`);
    await page.waitForLoadState('domcontentloaded');

    cdpSession = await context.newCDPSession(page);
    await cdpSession.send('WebAuthn.enable');
    const result = await cdpSession.send('WebAuthn.addVirtualAuthenticator', {
      options: {
        protocol: 'ctap2',
        transport: 'internal',
        hasResidentKey: true,
        hasUserVerification: true,
        isUserVerified: true,
        automaticPresenceSimulation: true,
      },
    });
    authenticatorId = result.authenticatorId;
  });

  test.afterAll(async () => {
    if (authenticatorId) {
      await cdpSession?.send('WebAuthn.removeVirtualAuthenticator', { authenticatorId });
    }
    await cdpSession?.detach();
    await context?.close();
  });

  test('管理者ログインとプラグイン設定', async () => {
    await page.goto(`${ADMIN_URL}/login`);
    await page.fill('input[name="login_id"]', LOGIN_ID);
    await page.fill('input[name="password"]', PASSWORD);
    await Promise.all([
      page.waitForURL(`**${ADMIN_URL}/**`),
      page.click('button[type="submit"]'),
    ]);

    await page.goto(`${ADMIN_URL}/ecauth_login43/config`);
    await page.fill('input[name="config[client_id]"]', CLIENT_ID);
    await page.fill('input[name="config[client_secret]"]', CLIENT_SECRET);

    // 高度な設定を展開し、base_url / rp_id を明示的に指定する
    await page.click(ADVANCED_TOGGLE);
    await expect(page.locator('#ecauth-advanced-settings')).toHaveClass(/show/);
    await page.fill('input[name="config[ecauth_base_url]"]', ECAUTH_BASE_URL);
    await page.fill('input[name="config[rp_id]"]', RP_ID);

    await page.click('button[type="submit"]');
    await expect(page.locator('.alert-success')).toBeVisible();
  });

  test('パスキーを新規登録する', async () => {
    test.setTimeout(60000);

    await page.goto(`${ADMIN_URL}/ecauth/passkey/`);
    await expect(page.locator('.card-header:has-text("登録済みパスキー")')).toBeVisible();

    // 登録成功後の alert ダイアログを自動クローズ
    page.once('dialog', (dialog) => dialog.accept());

    await page.click('#ecauth-passkey-add');
    await expect(page.locator('#ecauth-password-modal')).toBeVisible();

    await page.fill('#ecauth-password-input', PASSWORD);
    await page.click('#ecauth-password-confirm');

    // 登録完了後 window.location.reload() が走って一覧に 1 件以上表示される
    await expect(page.locator('table tbody tr')).toHaveCount(1, { timeout: 30000 });
  });

  test('管理画面からログアウトする', async () => {
    await page.goto(`${ADMIN_URL}/logout`);
    // セッションが無効化されログイン画面に戻ること
    await expect(page).toHaveURL(/\/admin\/login/);
    await expect(page.locator('input[name="login_id"]')).toBeVisible();
  });

  test('パスキーボタンクリックでログインが完了し管理画面に遷移する', async () => {
    test.setTimeout(60000);

    await page.goto(`${ADMIN_URL}/login`);
    const passkeyBtn = page.locator('#ecauth-passkey-login');
    await expect(passkeyBtn).toBeVisible();

    // ボタンクリック → options → assertion → verify → redirect_url → callback → /admin/
    await Promise.all([
      page.waitForURL(/\/admin\/?$/, { timeout: 30000 }),
      passkeyBtn.click(),
    ]);

    // ログイン画面の入力が消えていることを基本確認
    await expect(page.locator('input[name="login_id"]')).toHaveCount(0);

    // 管理画面ホームの見出し <h2>ホーム</h2> が表示されることでログイン完了を DOM ベースで検証
    await expect(page.locator('h2', { hasText: 'ホーム' })).toBeVisible();
  });

  test('クリーンアップ: 登録したパスキーを削除する', async () => {
    await page.goto(`${ADMIN_URL}/ecauth/passkey/`);
    await expect(page.locator('.card-header:has-text("登録済みパスキー")')).toBeVisible();

    const rows = page.locator('table tbody tr');
    const count = await rows.count();
    if (count === 0) {
      return;
    }

    // 削除確認の confirm() を自動で OK する
    page.on('dialog', (dialog) => dialog.accept());

    await rows.first().locator('button[type="submit"]').click();
    await expect(page.locator('.alert-success')).toBeVisible();
  });
});
