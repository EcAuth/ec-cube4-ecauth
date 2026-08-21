# EcAuthLogin43 - EC-CUBE 4.2/4.3系 EcAuth 認証プラグイン

EC-CUBE 4.2/4.3系管理画面向けの EcAuth B2Bパスキー認証プラグインです。

## 機能

- 管理画面へのパスキー（WebAuthn/FIDO2）ログイン
- パスキー管理画面（登録・一覧・削除）
- 管理画面のパスワード認証の無効化（パスキーへの一本化）
- EcAuth Identity Provider との連携

## 要件

- EC-CUBE 4.2/4.3系
- PHP 7.4以上
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

## 管理画面のパスワード認証を無効化する

管理画面のログインをパスキーだけに絞り、ID とパスワードによるログインを受け付けなくします。
管理者アカウントを不正に作成される脆弱性を踏んでも、作られたアカウントには EcAuth 側に
パスキーが登録されていないため、**そのアカウントで管理画面にログインされることはありません**。

### 設定方法

環境変数 `ECAUTH_DISABLE_ADMIN_PASSWORD_LOGIN` で切り替えます。`1` / `true` / `on` / `yes` の
いずれかで無効化され、未設定・`0`・空文字なら従来どおりパスワードでログインできます。

EC-CUBE の `.env` に追記する方法が最も手軽です。

```bash
echo 'ECAUTH_DISABLE_ADMIN_PASSWORD_LOGIN=1' >> .env
```

Web サーバーやコンテナの環境変数として設定しても構いません（Docker なら
`docker-compose.yml` の `environment:`、Apache なら `SetEnv` など）。

> **注意**: EC-CUBE の `index.php` はサーバー側に `APP_ENV` が設定されている場合、`.env` を
> 読み込みません。`APP_ENV` を渡している環境（Docker など）では `.env` ではなく
> 環境変数として設定してください。

現在の状態は「管理画面 > 設定 > EcAuth > EcAuth 設定」で確認できます。
**この設定は管理画面からは変更できません**。管理画面から戻せるようにすると、乗っ取られた
時点でパスワード認証を復活させられてしまい、対策になっていないためです。

### 無効化する前に

**少なくとも 1 人の管理者がパスキーを登録済みであることを必ず確認してください。**
誰も登録していない状態で無効化すると、誰も管理画面にログインできなくなります。

### パスキーを紛失した場合

`ECAUTH_DISABLE_ADMIN_PASSWORD_LOGIN` を `0` にする（または設定ごと削除する）と、
パスワードでログインできる状態に戻ります。これが唯一の復旧手段です。プラグイン設定を
消しても、DB を書き換えても、パスワード認証は戻りません。

### 無効化中の制限

- **新しく作成した管理者はログインできません**。パスキーの登録には一度管理画面へ
  ログインする必要があるためです。メールログインなどの導線は別途検討中です。
- 2 個目以降のパスキーを登録する際の本人確認は、従来どおりパスワードの再入力です
  （ログインではなくログイン済みセッションの再確認のため、無効化の対象外）。

## 開発環境

```bash
# Docker環境起動
docker compose up -d --build

# 管理画面: https://localhost:4430/admin
# デフォルトID: admin / password
```

## ライセンス

LGPL-2.1-or-later
