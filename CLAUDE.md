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

## セキュリティ注意事項

- client_secret はサーバーサイドのみ。JS に渡さない
- CSRF トークンはフォームと AJAX 両方で送信
- state パラメータは hash_equals() で検証、使い捨て削除
- WebAuthn は HTTPS 必須。HTTP 時はボタン非表示
- デプロイ先 URL を issue/PR/README に含めないこと
