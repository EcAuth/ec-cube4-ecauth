# CLAUDE.md

このファイルは Claude Code (claude.ai/code) がこのリポジトリで作業する際のガイダンスを提供します。

## プロジェクト概要

EC-CUBE 4.3系管理画面向け EcAuth B2Bパスキー認証プラグイン（EcAuthLogin43）。
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

### 静的解析

```bash
# PHPStan
composer phpstan

# Rector (dry-run)
composer rector

# PHP CS Fixer (dry-run)
composer cs-check
```

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
├── Service/
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

## セキュリティ注意事項

- client_secret はサーバーサイドのみ。JS に渡さない
- CSRF トークンはフォームと AJAX 両方で送信
- state パラメータは hash_equals() で検証、使い捨て削除
- WebAuthn は HTTPS 必須。HTTP 時はボタン非表示
- デプロイ先 URL を issue/PR/README に含めないこと
