import { test, expect, Page } from '@playwright/test';

const ADMIN_URL = '/admin';
const LOGIN_ID = process.env.ADMIN_LOGIN_ID || 'admin';
const PASSWORD = process.env.ADMIN_PASSWORD || 'password';

const ADVANCED_TOGGLE = 'button[data-toggle="collapse"][data-target="#ecauth-advanced-settings"]';
const ADVANCED_PANEL = '#ecauth-advanced-settings';

// 「警告フラッシュが出ていないこと」を見るためのセレクタ。
// 設定画面にはパスワード認証カードの常設注意書き（.alert-warning）もあるため、
// 素の .alert-warning では常に 1 件ヒットしてしまう。フラッシュだけを対象にする。
// EC-CUBE 本体の @admin/alert.twig は必ず alert-dismissible を付けて描画する。
const FLASH_WARNING = '.alert-warning.alert-dismissible';

// 導線の URL は services.yaml の parameters が既定値で、環境変数で上書きできる。
// テスト側も同じ解決順にしておく（CI では環境変数未設定なので既定値が使われる）。
const SIGNUP_URL = process.env.ECAUTH_SIGNUP_URL || 'https://ec-auth.io/signup/';
const MYPAGE_URL = process.env.ECAUTH_MYPAGE_URL || 'https://ec-auth.io/mypage/';

// EcAuth URL は許可リスト（ECAUTH_ALLOWED_HOSTS、既定は .ec-auth.io）を通るホストしか
// 保存できない（EcAuthDocs #101）。保存できる例として ec-auth.io のサブドメインを使う。
const ALLOWED_BASE_URL = 'https://e2e-tenant.ec-auth.io';

/**
 * ダイアログのメッセージを収集しつつ、指定どおり accept / dismiss する。
 * リスナー未登録だと Playwright が自動で dismiss してしまい、
 * 「確認が出たか」と「何と出たか」を区別できない。
 */
const captureDialogs = (page: Page, action: 'accept' | 'dismiss'): string[] => {
  const messages: string[] = [];
  page.on('dialog', async (dialog) => {
    messages.push(dialog.message());
    if (action === 'accept') {
      await dialog.accept().catch(() => {});
    } else {
      await dialog.dismiss().catch(() => {});
    }
  });

  return messages;
};

test.describe('プラグイン設定画面', () => {
  test.beforeEach(async ({ page }) => {
    // #52 で Client ID の変更時に confirm() を挟むようにしたため、保存を伴うテストは
    // 直前の DB 状態次第で確認ダイアログに当たる。ハンドラを置かないと Playwright が
    // 自動 dismiss して送信自体が止まり、実行順や前回の残骸で結果が変わってしまう。
    captureDialogs(page, 'accept');

    // 管理画面ログイン
    await page.goto(`${ADMIN_URL}/login`);
    await page.fill('input[name="login_id"]', LOGIN_ID);
    await page.fill('input[name="password"]', PASSWORD);
    await page.click('button[type="submit"]');
    await page.waitForURL(`**${ADMIN_URL}/**`);
  });

  test('設定画面にアクセスできる', async ({ page }) => {
    await page.goto(`${ADMIN_URL}/ecauth_login40/config`);
    // 部分一致だと導線カードの説明文（「下の『EcAuth 接続設定』に入力してください」）にも
    // マッチして strict mode 違反になるため、カード見出しに完全一致させる
    await expect(page.getByText('EcAuth 接続設定', { exact: true })).toBeVisible();
  });

  test('申込・マイページへの導線が表示される', async ({ page }) => {
    await page.goto(`${ADMIN_URL}/ecauth_login40/config`);

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

  // パスワード認証の無効化は環境変数でしか切り替えられない（管理画面から戻せると、
  // 乗っ取られた時点でパスワード認証を復活させられてしまうため）。設定画面は状態と
  // 切り替え方を表示するだけで、フォーム項目は持たない。
  // 無効化した状態そのものの検証は Tests/specs/disable_admin_password.spec.ts 側。
  test('管理画面のパスワード認証の状態が表示される（既定は有効）', async ({ page }) => {
    await page.goto(`${ADMIN_URL}/ecauth_login40/config`);

    const status = page.locator('#ecauth-password-login-status');
    await expect(status).toBeVisible();
    await expect(status).toHaveAttribute('data-status', 'enabled');

    // 切り替えに使う環境変数名が画面に出ていること（README を見に行かなくても分かる）
    await expect(page.locator('text=ECAUTH_DISABLE_ADMIN_PASSWORD_LOGIN').first()).toBeVisible();

    // 管理画面から切り替えられないので、入力欄やトグルは存在しない
    await expect(page.locator('input[name*="password_login"]')).toHaveCount(0);
  });

  test('高度な設定がデフォルトで折りたたまれている', async ({ page }) => {
    await page.goto(`${ADMIN_URL}/ecauth_login40/config`);

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
    await page.goto(`${ADMIN_URL}/ecauth_login40/config`);

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
    await page.goto(`${ADMIN_URL}/ecauth_login40/config`);
    await expect(page.locator('input[name="config[client_id]"]')).toHaveValue('test-client-id');
    await page.click(ADVANCED_TOGGLE);
    await expect(page.locator(ADVANCED_PANEL)).toHaveClass(/show/);
    await expect(page.locator('input[name="config[ecauth_base_url]"]')).toHaveValue(ALLOWED_BASE_URL);
  });

  // EcAuthDocs #101: Base URL はトークン交換先かつ JWKS 取得先になるため、
  // 許可リスト外のホストは保存段階で弾く。
  test('#101: 許可されていないホストの EcAuth URL は保存できない', async ({ page }) => {
    await page.goto(`${ADMIN_URL}/ecauth_login40/config`);

    await page.fill('input[name="config[client_id]"]', 'test-client-id');
    await page.fill('input[name="config[client_secret]"]', 'test-client-secret');

    await page.click(ADVANCED_TOGGLE);
    await expect(page.locator(ADVANCED_PANEL)).toHaveClass(/show/);
    await page.fill('input[name="config[ecauth_base_url]"]', 'https://auth.example.com');

    await page.click('button[type="submit"]');

    await expect(page.locator('.alert-success')).not.toBeVisible();
    await expect(page.locator('text=許可されていないホスト')).toBeVisible();

    // 拒否された値が保存されていないこと
    await page.goto(`${ADMIN_URL}/ecauth_login40/config`);
    await page.click(ADVANCED_TOGGLE);
    await expect(page.locator('input[name="config[ecauth_base_url]"]')).not.toHaveValue(
      'https://auth.example.com',
    );
  });
});

/**
 * #52: 接続先テナント (client_id) を差し替えたときの扱い。
 *
 * EcAuth の B2BUser.Subject は Organization をまたいでグローバル一意なため、
 * 旧テナントで発番済みの dtb_member.ecauth_subject を残したまま client_id を
 * 差し替えると、新テナントへの登録が一意制約に阻まれ register/options が必ず
 * 400 になる。設定画面がその後始末（subject のクリア）を担う。
 *
 * ecauth_subject の中身は管理画面から見えないため、ここでは「後始末が起動した
 * こと」を警告フラッシュで検証する。どういう条件で起動するかの分岐そのものは
 * Tests/Unit/TenantChangePolicyTest.php が網羅している。
 *
 * 本 describe は設定を書き換えるので、他 spec に影響しないよう
 * ファイル順で最後になる plugin_config.spec.ts の末尾に置く。
 */
test.describe.serial('#52: 接続先テナントの切り替え', () => {
  const CLIENT_ID_INPUT = 'input[name="config[client_id]"]';
  // serial なので、各 test は「直前の test が保存した状態」から始まる。
  // client_id を A → B → C と一方向に進めることで、どの test でも
  // 「保存済みと違う値を入れる＝テナント変更」が成立するようにしている。
  const TENANT_A = 'ecauth-e2e-tenant-a';
  const TENANT_B = 'ecauth-e2e-tenant-b';
  const TENANT_C = 'ecauth-e2e-tenant-c';
  const TENANT_A_URL = 'https://e2e-tenant-a.ec-auth.io';
  const TENANT_C_URL = 'https://e2e-tenant-c.ec-auth.io';

  test.beforeEach(async ({ page }) => {
    await page.goto(`${ADMIN_URL}/login`);
    await page.fill('input[name="login_id"]', LOGIN_ID);
    await page.fill('input[name="password"]', PASSWORD);
    await page.click('button[type="submit"]');
    await page.waitForURL(`**${ADMIN_URL}/**`);
    await page.goto(`${ADMIN_URL}/ecauth_login40/config`);
  });

  // 先行 describe が別の client_id を保存しているため、この保存自体がテナント変更に
  // なりうる（＝警告が出ることもある）。ここは以降のテストの基準点を作るのが目的なので
  // 警告の有無は問わない。「変更していないのに警告が出ない」ことは末尾のテストで見る。
  test('前提: テナント A の接続設定を保存する', async ({ page }) => {
    captureDialogs(page, 'accept');

    await page.fill(CLIENT_ID_INPUT, TENANT_A);
    await page.fill('input[name="config[client_secret]"]', 'secret-for-tenant-a');
    await page.click(ADVANCED_TOGGLE);
    await expect(page.locator(ADVANCED_PANEL)).toHaveClass(/show/);
    await page.fill('input[name="config[ecauth_base_url]"]', TENANT_A_URL);

    await page.click('button[type="submit"]');
    await expect(page.locator('.alert-success')).toBeVisible();

    // 保存済み値がテンプレートに載り、次回以降の比較対象になる
    await expect(page.locator(CLIENT_ID_INPUT)).toHaveAttribute('data-saved', TENANT_A);
  });

  test('Client ID を変更して送信すると確認ダイアログが出る（キャンセルすれば保存されない）', async ({ page }) => {
    const dialogs = captureDialogs(page, 'dismiss');

    await page.fill(CLIENT_ID_INPUT, TENANT_B);
    await page.click('button[type="submit"]');

    await expect.poll(() => dialogs.length, { timeout: 5000 }).toBeGreaterThan(0);
    expect(dialogs[0]).toContain('接続先のテナントが変わる');

    // キャンセルしたので送信されない
    await expect(page.locator('.alert-success')).toHaveCount(0);

    await page.goto(`${ADMIN_URL}/ecauth_login40/config`);
    await expect(page.locator(CLIENT_ID_INPUT)).toHaveValue(TENANT_A);
  });

  test('Client ID 変更時に Client Secret が空なら保存できない', async ({ page }) => {
    // 空のまま通すと「新しい client_id + 前のテナントの client_secret」という
    // どこにも通らない組み合わせが保存成功として残ってしまう。
    captureDialogs(page, 'accept');

    await page.fill(CLIENT_ID_INPUT, TENANT_B);
    await page.click('button[type="submit"]');

    await expect(page.locator('text=新しい接続先の Client Secret も入力してください')).toBeVisible();
    await expect(page.locator('.alert-success')).toHaveCount(0);

    await page.goto(`${ADMIN_URL}/ecauth_login40/config`);
    await expect(page.locator(CLIENT_ID_INPUT)).toHaveValue(TENANT_A);
  });

  // 事前入力の Base URL は「まず捨てて client_id から解決し直す」が、解決できなければ
  // 捨てた値に戻す。ここで弾いてしまうと、同じ EcAuth を複数テナントで共有し URL を
  // 手動指定している staging / 開発環境で接続先を切り替える手段が無くなる（#59 レビュー指摘）。
  //
  // 引き継いだこと自体は警告で可視化する。この警告が出ること＝「入力を鵜呑みにせず
  // 再解決を試みたうえで諦めた」ことの証拠になる（鵜呑みなら警告は出ない）。
  test('Client ID 変更時に再解決できなければ、既存の Base URL を引き継いで警告する', async ({ page }) => {
    // client-resolve は実ネットワークを叩くため、失敗時のタイムアウトを見込む
    test.setTimeout(90000);
    captureDialogs(page, 'accept');

    // Base URL 欄には保存済みの値（テナント A の URL）が事前入力されている。
    // ここを触らずに Client ID だけ変えるのが #52 の再現操作。
    await page.fill(CLIENT_ID_INPUT, TENANT_B);
    await page.fill('input[name="config[client_secret]"]', 'secret-for-tenant-b');
    await page.click('button[type="submit"]');

    await expect(page.locator('.alert-success')).toBeVisible();
    await expect(
      page.locator(FLASH_WARNING, { hasText: 'EcAuth URL を解決できなかったため' }),
    ).toBeVisible();
    // 行き止まりにしない。以前は client_resolve.failed で弾いており、しかもその文言は
    // 「高度な設定で URL を直接指定してください」と、既に指定済みの操作を案内していた。
    await expect(page.locator('text=Client ID に対応するテナントが見つかりませんでした')).toHaveCount(0);

    await page.goto(`${ADMIN_URL}/ecauth_login40/config`);
    await expect(page.locator(CLIENT_ID_INPUT)).toHaveValue(TENANT_B);
    await page.click(ADVANCED_TOGGLE);
    await expect(page.locator('input[name="config[ecauth_base_url]"]')).toHaveValue(TENANT_A_URL);
  });

  // 引き継ぎ先が無い（Base URL 未設定）ときは従来どおり弾く。他に採れる候補が無く、
  // 解決できない client_id をそのまま保存しても動かないため。
  test('Base URL 未設定で Client ID を解決できなければ保存できない', async ({ page }) => {
    test.setTimeout(90000);
    captureDialogs(page, 'accept');

    await page.click(ADVANCED_TOGGLE);
    await expect(page.locator(ADVANCED_PANEL)).toHaveClass(/show/);
    await page.fill('input[name="config[ecauth_base_url]"]', '');
    await page.fill(CLIENT_ID_INPUT, TENANT_C);
    await page.fill('input[name="config[client_secret]"]', 'secret-for-tenant-c');

    await page.click('button[type="submit"]');

    await expect(page.locator('text=Client ID に対応するテナントが見つかりませんでした')).toBeVisible();
    await expect(page.locator('.alert-success')).toHaveCount(0);

    // 弾かれた以上、副作用も残っていないこと
    await page.goto(`${ADMIN_URL}/ecauth_login40/config`);
    await expect(page.locator(CLIENT_ID_INPUT)).toHaveValue(TENANT_B);
    await page.click(ADVANCED_TOGGLE);
    await expect(page.locator('input[name="config[ecauth_base_url]"]')).toHaveValue(TENANT_A_URL);
  });

  test('Client ID を変更して保存すると、パスキー紐付け解除の警告が出る', async ({ page }) => {
    captureDialogs(page, 'accept');

    await page.fill(CLIENT_ID_INPUT, TENANT_C);
    await page.fill('input[name="config[client_secret]"]', 'secret-for-tenant-c');
    await page.click(ADVANCED_TOGGLE);
    await expect(page.locator(ADVANCED_PANEL)).toHaveClass(/show/);
    await page.fill('input[name="config[ecauth_base_url]"]', TENANT_C_URL);

    await page.click('button[type="submit"]');

    await expect(page.locator('.alert-success')).toBeVisible();
    // 対象 0 件なら「接続先のテナントが変わりました。」、1 件以上なら
    // 「接続先のテナントが変わったため、…紐付けを解除しました。」。
    // 先行 spec がパスキーを登録しているかで件数が変わるため共通部分で見る。
    await expect(page.locator(FLASH_WARNING, { hasText: '接続先のテナントが変わ' })).toBeVisible();
    // URL は明示指定したので、引き継ぎの警告は出ない
    await expect(
      page.locator(FLASH_WARNING, { hasText: 'EcAuth URL を解決できなかったため' }),
    ).toHaveCount(0);

    await page.goto(`${ADMIN_URL}/ecauth_login40/config`);
    await expect(page.locator(CLIENT_ID_INPUT)).toHaveValue(TENANT_C);
    await page.click(ADVANCED_TOGGLE);
    await expect(page.locator('input[name="config[ecauth_base_url]"]')).toHaveValue(TENANT_C_URL);
  });

  test('Client ID を変えずに保存したときは確認も警告も出ない', async ({ page }) => {
    // rp_id だけ変えて保存するような通常の操作で全管理者のパスキーを
    // 巻き添えにしないことの確認。
    const dialogs = captureDialogs(page, 'accept');

    await page.click(ADVANCED_TOGGLE);
    await expect(page.locator(ADVANCED_PANEL)).toHaveClass(/show/);
    await page.fill('input[name="config[rp_id]"]', 'localhost');

    await page.click('button[type="submit"]');

    await expect(page.locator('.alert-success')).toBeVisible();
    await expect(page.locator(FLASH_WARNING)).toHaveCount(0);
    expect(dialogs).toHaveLength(0);
  });
});
