# CLAUDE.md

このファイルは Claude Code (claude.ai/code) がこのリポジトリで作業する際のガイダンスを提供します。

## プロジェクト概要

EC-CUBE 4.2/4.3系管理画面向け EcAuth B2Bパスキー認証プラグイン（EcAuthLogin43）。
EcAuth Identity Provider と連携し、管理画面にパスキー（WebAuthn/FIDO2）認証を追加する。

## 注意事項

プラグインインストール後に docker 側で何らかの修正をしたり、 EC-CUBEコア側にパッチをあてるのは本来の EC-CUBE プラグインの開発要件から大きく逸脱するので絶対にしないでください。
EC-CUBEプラグインは、EC-CUBE管理画面の Webインストーラーからインストール可能なことが絶対条件です。
現在は開発用途で composer ローカルリポジトリを使用しています

## 開発コマンド

### Docker 環境

```bash
# 起動
docker compose up -d --build

# ログ確認
docker compose logs ec-cube

# 停止
docker compose down
```

### プラグインのインストール元（ローカルソース / package-api）

`docker-entrypoint.sh` は `ECCUBE_AUTHENTICATION_KEY` の有無でインストール元を切り替える。

| `ECCUBE_AUTHENTICATION_KEY` | インストール元 | コマンド | 用途 |
|---|---|---|---|
| 未設定（既定） | `/plugin`（ワーキングツリー） | `eccube:composer:require ec-cube/ecauthlogin43 --from=/plugin` | 日常の開発・PR CI |
| 設定済 | オーナーズストアの package-api | `eccube:composer:require ec-cube/ecauthlogin43 [version]` | 申請中パッケージの検証 |

`eccube:composer:require` は `--from` を付けると path リポジトリを追加し、**そのパッケージを
package-api リポジトリから exclude する**（`ComposerApiService::init()`）。したがって両立せず、
どちらか一方になる。

```bash
# 既定（ローカルソース）
op run --env-file=.env.tpl -- docker compose up -d --build

# 検証キーで package-api から「申請中のパッケージ」を入れる
op run --env-file=.env.tpl --env-file=.env.verify.tpl -- docker compose up -d --build

# バージョンを固定する場合（非秘密なのでインラインで渡す）
# 値は検証したいバージョンに読み替える。省略すると最新が入る
ECAUTH_PLUGIN_VERSION=1.0.5 \
  op run --env-file=.env.tpl --env-file=.env.verify.tpl -- docker compose up -d --build
```

検証キー（`X-ECCUBE-KEY`）はオーナーズストアにリリース申請すると発行される。
`ComposerApiService` は package-api へのリクエストに
`X-ECCUBE-KEY: {dtb_base_info.authentication_key}` を付けるため、entrypoint は
composer require の前にこの値を DB へ書き込む。管理画面「オーナーズストア > 認証キー設定」で
人が入力するのと同じ場所であり、EC-CUBE コアへのパッチではない。

**キーの扱い**: 値はコマンド引数に載せず、標準入力で渡した PHP スクリプトが `$_SERVER` から
読む。`ps` や docker のコマンドラインに現れないようにするため。ログにも出力しない。
（環境変数の参照に `getenv()` を使わないのは、スレッドセーフでなく Symfony でも非推奨のため。
`$_ENV` は `variables_order` に `E` が無いと空になるが、`$_SERVER` は `EGPCS` / `GPCS` の
どちらでも CLI SAPI が populate する。）

CI では `workflow_dispatch` の `install_source` を `package-api` にしたときだけ
1Password から読み込む（fork の PR には secrets が無いため、既定はローカルソース）。

### 静的解析

```bash
# PHPStan
composer phpstan

# Rector (dry-run)
composer rector

# PHP CS Fixer (dry-run)
composer cs-check
```

**重要**: 静的解析のために `composer install` を実行すると、リポジトリ直下に `vendor/` が生成される。
`docker-compose.override.yml` はリポジトリ直下を `/plugin` にマウントし、`docker-entrypoint.sh` の
`eccube:composer:require ec-cube/ecauthlogin43 --from=/plugin` がその `vendor/` ごとプラグインを取り込むため、
EC-CUBE 本体のオートローダーと衝突して起動に失敗する。

```
PHP Fatal error: Cannot declare class Composer\Autoload\ClassLoader, because the name is already in use
                 in /plugin/vendor/composer/ClassLoader.php
```

**Docker で動かす前に `rm -rf vendor` すること**（`vendor/` は `.gitignore` 済みで、生成物以外は失われない）。
CI では静的解析ジョブと E2E ジョブが別コンテナのため、この衝突は起きない。

同じ理由（リポジトリ直下がコンテナにマウントされている）で、`docker compose up` すると
プラグインインストーラが **`composer.json` を minify した 1 行 JSON に書き戻す**。追跡ファイルなので
`git status` に差分として現れる。コミットに混入させないよう `git checkout -- composer.json` で戻すこと。

### E2E テスト

```bash
pnpm install
pnpm exec playwright test
```

## ディレクトリ構成

```
ec-cube4-ecauth/
├── composer.json                    # type: eccube-plugin, code: EcAuthLogin43
├── PluginManager.php                # enable() でデフォルト Config 作成
├── EcAuthLoginEvent.php             # TemplateEvent サブスクライバ
├── EcAuthLoginNav.php               # 管理画面ナビゲーション
├── Controller/
│   ├── Admin/
│   │   ├── ConfigController.php     # プラグイン設定画面
│   │   └── PasskeyController.php    # パスキー管理画面
│   ├── EcAuthCallbackController.php # 認証コールバック（認証不要）
│   └── PasskeyAuthController.php    # パスキー認証/登録 API 中継
├── Entity/
│   ├── Config.php                   # plg_ecauth_login43_config
│   └── MemberTrait.php              # dtb_member に ecauth_subject 追加
├── Form/Type/Admin/
│   └── ConfigType.php
├── Repository/
│   └── ConfigRepository.php
├── Security/
│   └── AdminPasswordLoginListener.php # 管理画面のパスワード認証を拒否する
├── Service/
│   ├── AdminPasswordLoginPolicy.php # パスワード認証を無効化するかの判定
│   ├── EcAuthApiClient.php          # EcAuth API HTTP クライアント
│   └── PasskeyAuthService.php       # パスキー認証ビジネスロジック
├── Resource/
│   ├── config/services.yaml
│   ├── locale/messages.ja.yaml
│   ├── template/admin/
│   │   ├── config.twig
│   │   ├── passkey_list.twig
│   │   └── login_passkey.twig
│   └── assets/js/
│       └── ecauth-auth.umd.js       # @ecauth/auth-js ビルド成果物（gitignore）
├── Tests/specs/                     # Playwright E2E テスト
├── Dockerfile
├── docker-compose.yml
├── docker-compose.override.yml
└── docker-entrypoint.sh
```

## EcAuth API エンドポイント（本プラグインが呼び出す）

| エンドポイント | 認証方式 | 用途 |
|----------------|----------|------|
| `POST /v1/b2b/passkey/authenticate/options` | client_id | チャレンジ取得 |
| `POST /v1/b2b/passkey/authenticate/verify` | client_id | 署名検証→認可コード |
| `POST /v1/b2b/passkey/register/options` | client_id + client_secret | 登録オプション |
| `POST /v1/b2b/passkey/register/verify` | client_id + client_secret | 登録完了 |
| `GET /v1/b2b/passkey/list` | Bearer Token | 一覧取得 |
| `DELETE /v1/b2b/passkey/{credentialId}` | Bearer Token | 削除 |
| `POST /v1/token` | client_id + client_secret | トークン交換 |

## コーディング規約

- **Entity プロパティ名は snake_case** を使用する（EC-CUBE 本体の規約に準拠）。PSR-12 の camelCase 推奨よりも EC-CUBE 本体との一貫性を優先する
- EC-CUBE 本体のコーディングスタイルに従う
- **関数定義（パラメータ）の末尾カンマは禁止**。PHP 8.0+ の構文であり、PHP 7.4 で動作しなくなるため。配列リテラルと関数呼び出しの末尾カンマ（PHP 7.3+ で可）はそのまま使ってよい
  - `Tests/.php-cs-fixer.dist.php` の `trailing_comma_in_multiline` から `parameters` を除外済み（`arrays` / `arguments` のみ対象）
  - `Tests/rector.php` の `phpVersion` は `PHP_74` に固定（7.4 互換のリファクタのみ適用）

## HTTP クライアント (PSR-18)

プラグイン内の HTTP 通信は **PSR-18 (`Psr\Http\Client\ClientInterface`)** の抽象に依存する。`GuzzleHttp\Client` を直接 `new` したり `use` したりしない。

- DI するインタフェース:
  - `Psr\Http\Client\ClientInterface` — HTTP 送信
  - `Psr\Http\Message\RequestFactoryInterface` — PSR-7 Request 生成
  - `Psr\Http\Message\StreamFactoryInterface` — PSR-7 Body 生成
- 実装バインドは `Resource/config/services.yaml` の以下 3 エントリで一元管理:
  ```yaml
  Psr\Http\Client\ClientInterface:
      class: GuzzleHttp\Client
      arguments:
          - { timeout: 30, http_errors: false }
  Psr\Http\Message\RequestFactoryInterface:
      class: GuzzleHttp\Psr7\HttpFactory
  Psr\Http\Message\StreamFactoryInterface:
      class: GuzzleHttp\Psr7\HttpFactory
  ```
- EC-CUBE 4.2+ は本体が `guzzlehttp/guzzle:^7` を依存として持つため Guzzle を利用。実装を差し替える場合は本エントリの class のみ変更する
- `composer.json` は `psr/http-client` / `psr/http-factory` / `psr/http-message` のみを require し、Guzzle は直接 require しない（本体経由で解決）
- 例外捕捉は `Psr\Http\Client\ClientExceptionInterface` を使う（Guzzle 固有の例外型には依存しない）

## 環境変数の取り扱い

**コード内で `getenv()` / `$_ENV` を直接参照しない**。Symfony の env プロセッサ (`%env(...)%`) を経由して DI で注入する。

- 環境変数名は `services.yaml` の `bind:` または個別サービスの `arguments:` で `%env(...)%` 展開してパラメータとしてサービスに渡す
- デフォルト値 (env 未設定時のフォールバック) は `parameters:` に定義し、`%env(default:<param名>:<ENV名>)%` で参照する
  ```yaml
  parameters:
      ecauth_default_discovery_url: 'https://api.ec-auth.io'

  services:
      Plugin\EcAuthLogin43\:
          # ...
          bind:
              $discoveryUrl: '%env(default:ecauth_default_discovery_url:ECAUTH_CLIENT_RESOLVE_URL)%'
  ```
- メリット:
  - モックテストで env を書き換えずにコンストラクタ引数で値を注入できる
  - `bin/console debug:container --env-vars` で env 使用箇所を一覧できる
  - env 未設定時のフォールバック値がコードではなく config に集約される

## EcAuth 連携で踏みやすい罠

### ecauth_subject は接続先テナント（client_id）を変えたらクリアが要る

`PasskeyAuthService::ensureB2BUser()` は `b2b_subject` を `dtb_member.ecauth_subject` に
永続化し、値があれば**無条件に再利用**する。一方 EcAuth 側の `B2BUser.Subject` は
Organization をまたいでグローバル一意なので、**テスト用テナントの `client_id` で試した後に
本番用へ差し替えると、別 Organization に同じ subject を登録しようとして必ず失敗する**
（`register/options` が 400、EcAuth 側ログに `Failed to create or retrieve B2BUser: <uuid>`）。

`reconcileEcauthSubjectFromOptions()` は救ってくれない。あれは `register/options` が 200 を
返した後の突き合わせなので、400 で弾かれるこのケースでは呼ばれる前に return する。

そのため `ConfigController::index()` が保存前後の `client_id` を比較し、変わっていれば
`PasskeyAuthService::clearAllEcauthSubjects()` で一括クリアする（#52）。テナントを移す以上、
旧 subject に紐づくパスキーはどのみち使えないため実害はない。設定画面は送信前に
`confirm()` を挟むが、**確認はあくまで UI 上の保険**で、クリアの判断はサーバー側の
新旧比較が行う（`curl` 等 JS を経由しない送信でも整合する）。

`dtb_customer` 側は触らない。あちらは B2C の `sub` で、発番するのはプラグインではなく
EcAuth 側であり、テナントが変われば別の値が降ってきて衝突しないため。

判定条件（初回登録・同値・空白差ではクリアしない等）は EC-CUBE 非依存の
`Service/TenantChangePolicy` に切り出してある。`phpunit.xml.dist` は EC-CUBE のカーネルを
起動しないため、コントローラやサービスに直接書くとユニットテストで固定できない。

### フォームは「管理対象エンティティ」に直接バインドされる

`ConfigController` は `configRepository->get()` が返す managed entity をそのまま
`createForm()` に渡す。したがって **`handleRequest()` を通した時点で `$Config` の値は
入力値で上書きされている**。「保存前の値」と比較したい場合は `handleRequest()` より前に
退避しておくこと。2 系（配列で設定を持つ）から移植するときに最も間違えやすい点。

なお `flush()` を呼ばずに return する経路では、managed entity を書き換えていても
永続化されない（`TransactionListener` は commit するだけで flush はしない）。
バリデーションエラーで抜ける経路が副作用を残さないのはこのため。

`update_date` は EC-CUBE 本体の `SaveEventSubscriber::preUpdate()` が自動更新するので
明示設定は不要。ただし**これは UnitOfWork 経由のときだけ効く**。DQL の bulk UPDATE で
書き換えると発火しないので、件数が小さいならエンティティを load して書き換える。

### form_widget の attr に id を渡しても上書きされない

`{{ form_widget(form.foo, { attr: { id: 'my-id' } }) }}` と書くと、Symfony が出力する
`id="form_foo"` は消えず **`id` 属性が 2 つ並ぶ**。HTML パーサは先勝ちなので後ろは無視され、
`getElementById('my-id')` は `null` を返す（JS が静かに何もしなくなる）。
テンプレート側から要素を掴むときは `{{ form.foo.vars.id }}` で本来の id を引くこと。

## 管理画面のパスワード認証の無効化

環境変数 `ECAUTH_DISABLE_ADMIN_PASSWORD_LOGIN` を有効にすると、管理画面の ID / パスワードに
よるログインを受け付けなくなる（パスキー一本化）。管理者アカウントを不正に作成される
脆弱性を踏んでも、EcAuth 側にパスキーが無いアカウントではログインできない、という狙い。
利用者向けの説明は README.md を参照。

### 切り替えを DB ではなく環境変数に置いている理由

プラグイン設定（`plg_ecauth_login43_config`）に持たせると、**管理画面を乗っ取られた時点で
パスワード認証を戻されてしまい、対策として成立しない**。環境変数はアプリケーションの外側に
あり管理画面から触れないため、乗っ取り後の復帰手段にならない。

同じ理由で「プラグイン未設定ならパスワード認証を許す」といったフォールバックも持たない。
DB を書ける攻撃者が設定を消すだけでパスワード認証を復活できてしまうため。通常の復旧手段は
**「環境変数を無効に戻す」**で、README に明記してある。

設定画面（`config.twig`）はこの状態を**表示するだけ**。フォーム項目を足さないこと。

### 保証の範囲 — プラグインを無効化されると迂回される

**「DB を書き換えてもパスワード認証は戻らない」と書いてはいけない。**プラグインを無効化
できる者は迂回できる（[#61 レビュー指摘](https://github.com/EcAuth/ec-cube4-ecauth/pull/61#discussion_r3828919202)）。

`Eccube\Kernel::configureContainer()` はプラグインの `services.yaml` を有効・無効に関係なく
glob で読むが、`Eccube\DependencyInjection\Compiler\PluginPass` が**無効プラグインの
`Plugin\<Code>\` 名前空間のサービスから全タグを剥がす**（`doctrine.repository_service` のみ例外）。
`kernel.event_subscriber` も剥がれるため、リスナーが登録されなくなる。

環境変数を `1` のまま固定して実測した結果:

| 操作 | POST /admin/login | /admin/ |
|---|---|---|
| プラグイン有効 | 302 → `/admin/login`（拒否） | 302 → login |
| `dtb_plugin.enabled = false` のみ | 302 → `/admin/login`（拒否） | 302 → login |
| 上記 + `cache:clear` | 302 → `/admin/`（ログイン成立） | 200 |

**DB 書き換え単独では迂回できない**（有効・無効はコンテナのコンパイル時に解決されるため）。
迂回には DB 書き込みに加えてキャッシュ再構築＝ファイルシステム / CLI アクセスが要る。
本来の脅威（不正な管理者アカウント作成）への防御は成立しているが、断定表現は使わないこと。

プラグインは Web インストーラーからインストールできることが要件でコア改変も禁止のため、
**この経路はプラグイン内では塞げない。ドキュメント化が正しい対処**であり、「プラグイン外で
強制する」方向へ実装を広げないこと。

### 塞いでいる場所は `CheckPassportEvent`（`Security/AdminPasswordLoginListener`）

ログイン画面のテンプレート（`login_passkey.twig`）が入力欄を隠すのは案内でしかない。
実際に拒否しているのは Symfony の認証パイプラインで、`curl` 等でフォームを経由せずに
POST されても同じように弾く。ルートやパスで判定していないのは、`%eccube_admin_route%` が
サイトごとに変更できるため（パス判定はカスタマイズ済みサイトで素通りする）。

#### 優先度 300 の理由（Symfony 5.4 / 6.4 / 7.x で並びは同じ）

```
2080 LoginThrottlingListener   総当たり制限は従来どおり先に効かせる
1024 UserProviderListener      UserBadge に user loader を差すだけ
 512 CsrfProtectionListener    CSRF 検証も先に通す
→300 AdminPasswordLoginListener
 256 UserCheckerListener       ここで初めて $passport->getUser() が実行される
   0 CheckCredentialsListener  パスワードのハッシュ検証
```

**`UserCheckerListener` より前**に置くのが要点。ユーザー解決の後に拒否すると、存在しない
`login_id` は `UserNotFoundException`（表示は「Bad credentials」）、存在する `login_id` は
「パスワード認証は無効です」となり、**応答の差から login_id の存在を判別できてしまう**
（ユーザー列挙）。解決前に一律で拒否すればどの `login_id` でも同じ応答になり、パスワードの
ハッシュ計算も走らない。

#### ファイアウォール名で絞るのは必須

Symfony は**グローバルに登録された `CheckPassportEvent` リスナーを全ファイアウォールの
ディスパッチャへ複製する**（SecurityBundle の `RegisterGlobalSecurityEventListenersPass`）。
つまり EC サイトのフロント会員ログイン（`customer` ファイアウォール）でも本リスナーが動く。
`admin` で絞り損ねると**会員が誰もログインできなくなる**。リグレッションテストは
`Tests/specs/disable_admin_password.spec.ts` の「EC サイトのフロント会員ログインは影響を
受けない」。

判定表そのものは EC-CUBE 非依存の `Service/AdminPasswordLoginPolicy` に切り出してあり、
`Tests/Unit/AdminPasswordLoginPolicyTest.php` が固定している。

### 認証失敗の文言は `validators` ドメインに置く

`@admin/login.twig` は `{{ error.messageKey|trans(error.messageData, 'validators') }}` で
描画する。`messages.ja.yaml` に書いてもキーがそのまま画面に出るだけなので、
`Resource/locale/validators.ja.yaml` 側に置くこと。

### E2E は専用ジョブで動かす

他の E2E はパスワードで管理画面にログインするため、無効化状態と同じコンテナには同居
できない。env だけ差し替えてコンテナを作り直す手も使えない（DB は volume で残るのに
プラグインの導入状態はコンテナ側にしか無く、`docker-entrypoint.sh` の
`eccube:plugin:enable` が「既に有効」で落ちて Apache が起動しない）。CI では
`.github/workflows/playwright.yml` の `e2e-password-login-disabled` ジョブが
クリーンな環境を立てて `disable_admin_password.spec.ts` だけを流す。

ローカルで再現する場合:

```bash
docker compose down -v
ECAUTH_DISABLE_ADMIN_PASSWORD_LOGIN=1 docker compose up -d --build
E2E_ADMIN_PASSWORD_LOGIN_DISABLED=1 pnpm exec playwright test Tests/specs/disable_admin_password.spec.ts
```

なお EC-CUBE の `login_throttling` は既定で 5 回 / 30 分（`login_id` + IP 単位）。
spec の中で管理者ログインを失敗させる回数を増やすと、リトライ込みで上限に当たり
「パスワード認証は無効」ではなく試行制限のエラーになるので注意。

## セキュリティ注意事項

- client_secret はサーバーサイドのみ。JS に渡さない
- CSRF トークンはフォームと AJAX 両方で送信
- state パラメータは hash_equals() で検証、使い捨て削除
- WebAuthn は HTTPS 必須。HTTP 時はボタン非表示
- デプロイ先 URL を issue/PR/README に含めないこと
- パスワード認証の無効化は環境変数のみで切り替える。設定画面（DB）に移さないこと
  （上記「管理画面のパスワード認証の無効化」参照）
- **コールバックで `TokenStorage::setToken()` を使わない**（#45）。`/ecauth/callback` は
  admin firewall の pattern (`^/%eccube_admin_route%/`) にマッチせず customer firewall (`^/`)
  配下で処理されるため、TokenStorage に Member を載せると customer firewall の
  `ContextListener` がレスポンス時に `_security_customer` へ書き出し、会員がマイページに
  入れなくなる。管理者セッションは `$session->set('_security_admin', serialize($token))` で
  確立する（リダイレクト先の管理画面リクエストで admin firewall が復元する）。
  「Symfony の作法に合わせる」等の理由で `setToken()` に戻さないこと。
  リグレッションテスト: `Tests/specs/passkey_auth.spec.ts` の `#45:` で始まる test
