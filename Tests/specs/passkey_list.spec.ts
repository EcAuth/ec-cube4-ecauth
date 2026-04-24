import { test, expect, BrowserContext, Page, CDPSession } from '@playwright/test';

const ADMIN_URL = '/admin';
const LOGIN_ID = process.env.ADMIN_LOGIN_ID || 'admin';
const PASSWORD = process.env.ADMIN_PASSWORD || 'password';

const ECAUTH_BASE_URL = process.env.ECAUTH_BASE_URL || '';
const CLIENT_ID = process.env.CLIENT_ID || '';
const CLIENT_SECRET = process.env.CLIENT_SECRET || '';
const RP_ID = process.env.RP_ID || 'localhost';

const ADVANCED_TOGGLE = 'button[data-bs-toggle="collapse"][data-bs-target="#ecauth-advanced-settings"]';

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

/**
 * パスキーログイン後の一覧画面を検証する E2E。
 *
 * 既存 passkey_auth.spec.ts と同じ virtual authenticator 方式でステージング
 * 環境にパスキーを登録・ログインし、セッションに access_token が入った状態で
 * /admin/ecauth/passkey/ を開く。そこで 6 コミット分の変更点をまとめて検証する:
 *   - 一覧がテーブル形式でレンダリングされること (e412669)
 *   - device_name が UA 由来の "Browser on OS" 形式で自動付与されていること (8cc3aad)
 *   - created_at / last_used_at が "YYYY/MM/DD HH:mm:ss" 形式 (ECCUBE_TIMEZONE 適用) (671b475)
 *   - 現在ログインに使用中のパスキー行が table-active + "ログイン中" バッジで強調されること (f9bda98)
 *   - 削除ボタンが CSRF エラーを起こさず 200/3xx で完了すること (980e95d)
 */
test.describe.serial('E2E: パスキーログイン後の一覧表示', () => {
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

    // virtual authenticator が timeout=0 で失敗しないよう option.timeout を上書き
    await context.addInitScript(() => {
      const originalCreate = navigator.credentials.create.bind(navigator.credentials);
      navigator.credentials.create = async (options?: CredentialCreationOptions) => {
        if (options?.publicKey && (!options.publicKey.timeout || options.publicKey.timeout === 0)) {
          options.publicKey.timeout = 60000;
        }
        return originalCreate(options);
      };
      const originalGet = navigator.credentials.get.bind(navigator.credentials);
      navigator.credentials.get = async (options?: CredentialRequestOptions) => {
        if (options?.publicKey && (!options.publicKey.timeout || options.publicKey.timeout === 0)) {
          options.publicKey.timeout = 60000;
        }
        return originalGet(options);
      };
    });

    page = await context.newPage();
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

    // 登録時の confirm()/alert() を自動受諾
    page.on('dialog', (dialog) => dialog.accept().catch(() => {}));
  });

  test.afterAll(async () => {
    // afterAll で登録パスキーを best-effort で削除（staging に残骸を残さない）
    try {
      if (page && !page.isClosed()) {
        await page.goto(`${ADMIN_URL}/ecauth/passkey/`);
        if (!page.url().includes('/admin/login')) {
          const rows = page.locator('.card-body table.table-sm tbody tr');
          const count = await rows.count();
          for (let i = 0; i < count; i++) {
            await rows.first().locator('button[type="submit"]').click();
            await page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => {});
          }
        }
      }
    } catch {
      // cleanup 失敗は握りつぶす
    }

    if (authenticatorId) {
      await cdpSession?.send('WebAuthn.removeVirtualAuthenticator', { authenticatorId }).catch(() => {});
    }
    await cdpSession?.detach().catch(() => {});
    await context?.close().catch(() => {});
  });

  test('セットアップ: 設定保存 → パスキー登録 → ログアウト → パスキーログイン', async () => {
    test.setTimeout(120000);

    // 1. 管理者ログインして接続設定を保存
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
    await page.click(ADVANCED_TOGGLE);
    await expect(page.locator('#ecauth-advanced-settings')).toHaveClass(/show/);
    await page.fill('input[name="config[ecauth_base_url]"]', ECAUTH_BASE_URL);
    await page.fill('input[name="config[rp_id]"]', RP_ID);
    await page.click('button[type="submit"]');
    await expect(page.locator('.alert-success')).toBeVisible();

    // 2. パスキー登録
    await page.goto(`${ADMIN_URL}/ecauth/passkey/`);
    await page.click('#ecauth-passkey-add');
    await page.fill('#ecauth-password-input', PASSWORD);
    const verifyPromise = page.waitForResponse(
      (res) => res.url().includes('/ecauth/passkey/register/verify') && res.request().method() === 'POST',
      { timeout: 30000 },
    );
    await page.click('#ecauth-password-confirm');
    const verifyRes = await verifyPromise;
    expect(verifyRes.status()).toBe(200);

    // 3. ログアウト → パスキーログイン
    await page.goto(`${ADMIN_URL}/logout`);
    await page.goto(`${ADMIN_URL}/login`);
    await Promise.all([
      page.waitForURL(/\/admin\/?$/, { timeout: 30000 }),
      page.locator('#ecauth-passkey-login').click(),
    ]);
    await expect(page.locator('h2', { hasText: 'ホーム' })).toBeVisible();
  });

  test('一覧がテーブル形式で描画され、日時が ECCUBE_TIMEZONE 書式で表示される', async () => {
    await page.goto(`${ADMIN_URL}/ecauth/passkey/`);

    // login_required の alert は出ず、テーブルが描画される
    await expect(page.locator('.card-body .alert-warning')).toHaveCount(0);
    const table = page.locator('.card-body table.table-sm');
    await expect(table).toBeVisible();

    const rows = table.locator('tbody tr');
    await expect(rows).not.toHaveCount(0);

    const firstRow = rows.first();
    const cells = firstRow.locator('td');

    // created_at / last_used_at が "YYYY/MM/DD HH:mm:ss" 形式でレンダリングされる
    // ("-" もしくは JST 書式。少なくとも UTC 生文字列 "2026-04-24T..." であってはならない)
    const createdAt = (await cells.nth(1).innerText()).trim();
    const lastUsedAt = (await cells.nth(2).innerText()).trim();
    const jstPattern = /^(\d{4}\/\d{2}\/\d{2} \d{2}:\d{2}:\d{2}|-)$/;
    expect(createdAt).toMatch(jstPattern);
    expect(lastUsedAt).toMatch(jstPattern);
    expect(createdAt).not.toContain('T');
    expect(createdAt).not.toContain('+00:00');
  });

  test('device_name が UA 由来の "Browser on OS" 形式で自動付与される', async () => {
    await page.goto(`${ADMIN_URL}/ecauth/passkey/`);

    // 本テストで登録した行（=ログイン中の行）のデバイス名を取得する
    const currentRow = page.locator('.card-body table.table-sm tbody tr.table-active');
    await expect(currentRow).toHaveCount(1);

    const deviceNameCell = currentRow.locator('td').first();
    const deviceText = (await deviceNameCell.innerText()).trim();
    // 例: "Chrome on Linux\nログイン中" 等。先頭行に "xxx on yyy" を期待
    expect(deviceText).toMatch(/^[A-Za-z]+ on [A-Za-z]+/);
  });

  test('現在ログインに使用中のパスキー行が強調表示される', async () => {
    await page.goto(`${ADMIN_URL}/ecauth/passkey/`);

    // table-active クラスが付いた行がちょうど 1 行存在する
    const currentRow = page.locator('.card-body table.table-sm tbody tr.table-active');
    await expect(currentRow).toHaveCount(1);

    // その行に "ログイン中" バッジがある
    const badge = currentRow.locator('span.badge');
    await expect(badge).toBeVisible();
    await expect(badge).toHaveText('ログイン中');
  });

  test('削除フォームが CSRF エラーを起こさず実行できる', async () => {
    await page.goto(`${ADMIN_URL}/ecauth/passkey/`);

    const rows = page.locator('.card-body table.table-sm tbody tr');
    const countBefore = await rows.count();
    expect(countBefore).toBeGreaterThanOrEqual(1);

    // 先頭行（=ログイン中の行）を削除し、サーバーが 403 (CSRF) を返さず
    // リダイレクト後にも AccessDeniedHttpException のページが表示されないこと
    const deleteResponsePromise = page.waitForResponse(
      (res) => res.url().includes('/admin/ecauth/passkey/') && res.request().method() === 'POST',
      { timeout: 15000 },
    );
    await rows.first().locator('button[type="submit"]').click();
    const deleteResponse = await deleteResponsePromise;
    expect(deleteResponse.status()).not.toBe(403);

    // 最終的にパスキー管理画面に戻って、CSRF エラー画面でないことを確認
    await page.waitForURL(/\/admin\/ecauth\/passkey\/?$/, { timeout: 15000 });
    await expect(page.locator('body')).not.toContainText('CSRF token is invalid');
  });
});
