# EcAuthLogin43 - EC-CUBE 4.3系 EcAuth 認証プラグイン

EC-CUBE 4.3系管理画面向けの EcAuth B2Bパスキー認証プラグインです。

## 機能

- 管理画面へのパスキー（WebAuthn/FIDO2）ログイン
- パスキー管理画面（登録・一覧・削除）
- EcAuth Identity Provider との連携

## 要件

- EC-CUBE 4.3系
- PHP 8.3以上
- HTTPS環境（WebAuthn必須）

## インストール

### EC-CUBEオーナーズストアから

1. オーナーズストアからプラグインをダウンロード
2. 管理画面 > オーナーズストア > プラグイン > プラグイン一覧 からインストール
3. プラグインを有効化

### Composerから

```bash
bin/console eccube:composer:require ecauth/ec-cube4-ecauth
bin/console eccube:plugin:enable --code=EcAuthLogin43
```

## 設定

1. 管理画面 > 設定 > EcAuth 設定 を開く
2. EcAuth Base URL、Client ID、Client Secret を入力して保存

## 開発環境

```bash
# Docker環境起動
docker compose up -d --build

# 管理画面: https://localhost:4430/admin
# デフォルトID: admin / password
```

## ライセンス

LGPL-2.1-or-later
