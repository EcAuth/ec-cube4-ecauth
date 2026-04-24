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
 *
 * 登録したパスキーは afterAll で best-effort 削除する（テスト失敗で skip されないよう
 * 独立 test ではなく fixture teardown 側に置く）。
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

      // EcAuth.webauthn.{register,authenticate} をラップし、呼び出しと結果/例外を console に流す。
      // UMD スクリプトは後から読み込まれるため、グローバルに出現したタイミングでラップする。
      const wrapEcAuth = () => {
        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const ec = (window as any).EcAuth;
        if (!ec || !ec.webauthn) {
          return false;
        }
        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        if ((ec.webauthn as any).__e2eWrapped) return true;
        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        (ec.webauthn as any).__e2eWrapped = true;
        const origReg = ec.webauthn.register.bind(ec.webauthn);
        const origAuth = ec.webauthn.authenticate.bind(ec.webauthn);
        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        ec.webauthn.register = async (opts: any) => {
          console.log('[E2E] EcAuth.webauthn.register called');
          try {
            const r = await origReg(opts);
            console.log('[E2E] EcAuth.webauthn.register resolved: ' + JSON.stringify(r).substring(0, 500));
            return r;
          } catch (e) {
            const err = e as Error;
            console.log('[E2E] EcAuth.webauthn.register rejected: name=' + err.name + ' message=' + err.message + ' stack=' + (err.stack || '').substring(0, 800));
            throw e;
          }
        };
        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        ec.webauthn.authenticate = async (opts: any) => {
          console.log('[E2E] EcAuth.webauthn.authenticate called');
          try {
            const r = await origAuth(opts);
            console.log('[E2E] EcAuth.webauthn.authenticate resolved: ' + JSON.stringify(r).substring(0, 500));
            return r;
          } catch (e) {
            const err = e as Error;
            console.log('[E2E] EcAuth.webauthn.authenticate rejected: name=' + err.name + ' message=' + err.message + ' stack=' + (err.stack || '').substring(0, 800));
            throw e;
          }
        };
        return true;
      };
      if (!wrapEcAuth()) {
        const iv = setInterval(() => {
          if (wrapEcAuth()) {
            clearInterval(iv);
          }
        }, 50);
        setTimeout(() => clearInterval(iv), 10000);
      }
    });

    page = await context.newPage();

    // ブラウザ console と pageerror を Playwright 側のログに流す。
    page.on('console', (msg) => console.log('[browser:' + msg.type() + '] ' + msg.text()));
    page.on('pageerror', (err) => console.log('[pageerror] ' + err.message));
    // EcAuth エンドポイントのレスポンス本文をダンプ（リクエスト時のタイミングで取得）。
    page.on('response', async (res) => {
      const u = res.url();
      if (
        u.includes('/ecauth/passkey/authenticate/options') ||
        u.includes('/ecauth/passkey/authenticate/verify') ||
        u.includes('/ecauth/passkey/register/options') ||
        u.includes('/ecauth/passkey/register/verify') ||
        u.includes('/admin/ecauth/passkey/verify-password') ||
        u.includes('/ecauth/callback')
      ) {
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
    // Best-effort で登録したパスキーを削除し、staging に残骸を残さない。
    // describe.serial では先行テストが失敗すると後続テストが skip されるため、
    // クリーンアップは独立 test ではなく afterAll に置く。
    // ただしログイン test が失敗している場合、session に access_token が無いので
    // /admin/ecauth/passkey/ は /admin/login にリダイレクトされて削除 UI に到達できない。
    // その場合はスキップし、エラーで test 全体を fail させない。
    try {
      if (page && !page.isClosed()) {
        await page.goto(`${ADMIN_URL}/ecauth/passkey/`);
        if (!page.url().includes('/admin/login')) {
          const rows = page.locator('.card-body table.table-sm tbody tr');
          const count = await rows.count();
          if (count > 0) {
            // 削除ボタンの confirm() ダイアログは「パスキーを新規登録する」test 内で
            // 仕込んだ永続リスナー (page.on('dialog', ...)) がログ出力 + accept を
            // 担当するため、ここで追加リスナーを置くと二重登録になる。
            await rows.first().locator('button[type="submit"]').click();
            await page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => {});
          }
        }
      }
    } catch (e) {
      console.log('[afterAll cleanup] failed: ' + (e as Error).message);
    }

    if (authenticatorId) {
      await cdpSession?.send('WebAuthn.removeVirtualAuthenticator', { authenticatorId }).catch(() => {});
    }
    await cdpSession?.detach().catch(() => {});
    await context?.close().catch(() => {});
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

    // 登録成功/失敗時の alert ダイアログをすべて取得しつつテキストをログに流す
    page.on('dialog', (dialog) => {
      console.log('[dialog] ' + dialog.type() + ': ' + dialog.message());
      dialog.accept().catch(() => {});
    });

    await page.click('#ecauth-passkey-add');
    await expect(page.locator('#ecauth-password-modal')).toBeVisible();

    await page.fill('#ecauth-password-input', PASSWORD);

    // register/verify が 200 で返るまで待つ。パスキー一覧自体は session の access_token が
    // 無いと取得できず（パスキーログイン成功後に初めて token が入る）、登録直後の一覧は
    // 常に空表示になるため、ここではサーバー側の登録完了だけを検証する。
    const verifyPromise = page.waitForResponse(
      (res) => res.url().includes('/ecauth/passkey/register/verify') && res.request().method() === 'POST',
      { timeout: 30000 },
    );
    await page.click('#ecauth-password-confirm');
    const verifyRes = await verifyPromise;
    expect(verifyRes.status()).toBe(200);
    const verifyBody = await verifyRes.json();
    expect(verifyBody.success).toBe(true);
    expect(typeof verifyBody.credential_id).toBe('string');
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

  // パスキーログイン直後 (session に access_token / current_credential_id が
  // 揃った状態) で /admin/ecauth/passkey/ を開き、一覧画面の各機能を検証する。
  // 別 spec として独立させると同一 staging admin (ecauth_subject UNIQUE) を
  // 共有して並列実行で flaky になるため、本 describe.serial の末尾に統合する。
  test('パスキー一覧画面: テーブル描画 / JST 日時 / UA 由来 device_name / ログイン中強調 / CSRF なし削除', async () => {
    test.setTimeout(60000);

    await page.goto(`${ADMIN_URL}/ecauth/passkey/`);

    // login_required の alert は出ず、テーブルが描画される
    await expect(page.locator('.card-body .alert-warning')).toHaveCount(0);
    const table = page.locator('.card-body table.table-sm');
    await expect(table).toBeVisible();
    await expect(table.locator('tbody tr')).not.toHaveCount(0);

    // 自身でログイン中のパスキー行 (table-active クラス) が 1 行ある
    const currentRow = table.locator('tr.table-active');
    await expect(currentRow).toHaveCount(1);

    // バッジは "ログイン中"
    const badge = currentRow.locator('span.badge');
    await expect(badge).toBeVisible();
    await expect(badge).toHaveText('ログイン中');

    // device_name が UA 由来 ("Browser on OS")
    const deviceText = (await currentRow.locator('td').first().innerText()).trim();
    expect(deviceText).toMatch(/^[A-Za-z]+ on [A-Za-z]+/);

    // 日時 ("YYYY/MM/DD HH:mm:ss" 形式 / UTC ISO 文字列でないこと)
    const cells = currentRow.locator('td');
    const createdAt = (await cells.nth(1).innerText()).trim();
    const lastUsedAt = (await cells.nth(2).innerText()).trim();
    const jstPattern = /^(\d{4}\/\d{2}\/\d{2} \d{2}:\d{2}:\d{2}|-)$/;
    expect(createdAt).toMatch(jstPattern);
    expect(lastUsedAt).toMatch(jstPattern);
    expect(createdAt).not.toContain('T');
    expect(createdAt).not.toContain('+00:00');

    // 削除フォームが CSRF エラーなく完了する
    const deleteResponsePromise = page.waitForResponse(
      (res) => res.url().includes('/admin/ecauth/passkey/') && res.request().method() === 'POST',
      { timeout: 15000 },
    );
    await currentRow.locator('button[type="submit"]').click();
    const deleteResponse = await deleteResponsePromise;
    expect(deleteResponse.status()).not.toBe(403);

    await page.waitForURL(/\/admin\/ecauth\/passkey\/?$/, { timeout: 15000 });
    await expect(page.locator('body')).not.toContainText('CSRF token is invalid');
  });
});
