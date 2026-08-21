# EcAuthLogin40 - EC-CUBE 4.0/4.1系 EcAuth 認証プラグイン

EC-CUBE 4.0/4.1系管理画面向けの EcAuth B2Bパスキー認証プラグインです。

4.2/4.3 系をお使いの場合は、同じリポジトリの `main` ブランチで開発している
EcAuthLogin43 (`ec-cube/ecauthlogin43`) を利用してください。

## 機能

- 管理画面へのパスキー（WebAuthn/FIDO2）ログイン
- パスキー管理画面（登録・一覧・削除）
- 管理画面のパスワード認証の無効化（パスキーへの一本化）
- EcAuth Identity Provider との連携

## 要件

- EC-CUBE 4.0系 / 4.1系
- PHP 7.1 以上
- HTTPS環境（WebAuthn必須）

## インストール

### EC-CUBEオーナーズストアから

1. オーナーズストアからプラグインをダウンロード
2. 管理画面 > オーナーズストア > プラグイン > プラグイン一覧 からインストール
3. プラグインを有効化

### コマンドラインから（推奨）

EC-CUBE 4.0 系は Composer v1 を使いますが、packagist.org は 2025-08-01 に
Composer v1 向けメタデータの提供を終了しました。管理画面からのプラグイン操作は
composer を経由することがあるため失敗しやすく、EC-CUBE 公式もコマンドラインでの
操作を推奨しています（[EC-CUBE4.0系(Composer v1)利用時の注意点](https://doc4.ec-cube.net/plugin_eccube40)）。

```bash
# 配布アーカイブ (tar.gz) からインストールする
bin/console eccube:plugin:install --path=/path/to/ec-cube4-ecauth-4.0-1.1.0.tar.gz
bin/console eccube:plugin:enable --code=EcAuthLogin40
```

本プラグインは `ec-cube/plugin-installer` 以外の依存を持ちません。そのため、上記
ドキュメントにある「依存パッケージを本体の composer.json に vcs リポジトリとして
書き足す」対応は不要です。

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
パスワードでログインできる状態に戻ります。**通常はこの方法で復旧してください。**

なお、**本プラグインを無効化・アンインストールしても**パスワード認証は復活します。
無効化されたプラグインのサービスは EC-CUBE 本体がタグごと無効化するため、環境変数を
有効にしたままでも本プラグインのリスナーが動かなくなるためです。緊急時の復旧経路として
使えますが、パスキーログインも同時に使えなくなります。

### この機構で防げること / 防げないこと

管理者アカウントを不正に作成されても、**そのアカウントでのログインは防げます**。
プラグイン設定（`plg_ecauth_login40_config`）を消しても、`dtb_member` を書き換えても、
パスワード認証は戻りません。切り替えを DB ではなく環境変数に置いているのはこのためです。

一方、**プラグインを無効化できる者は迂回できます**。具体的には次の両方が必要です。

1. `dtb_plugin.enabled` を `false` にする（または `bin/console eccube:plugin:disable`）
2. DI コンテナの再構築（`bin/console cache:clear`、デプロイ、管理画面からのプラグイン操作など）

**1 だけでは迂回できません。** プラグインの有効・無効はコンテナのコンパイル時に解決される
ため、キャッシュを再構築するまで反映されないからです。したがって迂回には、DB 書き込みに
加えてファイルシステムまたは CLI へのアクセスが必要になります。また、管理画面 UI からの
プラグイン無効化には管理画面へのログインが必要なので、無効化中は使えません。

プラグインは EC-CUBE の Web インストーラーからインストールできることが要件で、コア側に
パッチを当てないため、この迂回経路をプラグイン内で塞ぐことはできません。**本番運用では
`app/Plugin/` と `var/cache/` への書き込み権限を、デプロイ経路以外から与えないでください。**

### 無効化中の制限

- **新しく作成した管理者はログインできません**。パスキーの登録には一度管理画面へ
  ログインする必要があるためです。メールログインなどの導線は別途検討中です。
- 2 個目以降のパスキーを登録する際の本人確認は、従来どおりパスワードの再入力です
  （ログインではなくログイン済みセッションの再確認のため、無効化の対象外）。

## 4.2/4.3 版（EcAuthLogin43）との違い

提供する機能は同じですが、EC-CUBE と Symfony のバージョン差から次の点が異なります。

| 項目 | 4.0/4.1 版 (EcAuthLogin40) | 4.2/4.3 版 (EcAuthLogin43) |
|---|---|---|
| パスキー認証オプション取得のレート制限 | **なし** | あり（IP 単位 10 回 / 60 分） |
| パスワード認証を拒否する位置 | `kernel.request`（ファイアウォールの前） | `CheckPassportEvent`（CSRF 検証の後） |
| HTTP クライアント | プラグイン内の抽象 + Guzzle 6 | PSR-18 + Guzzle 7 |
| 依存パッケージ | `ec-cube/plugin-installer` のみ | PSR インタフェース群も require |

レート制限は EC-CUBE 4.2 で追加された本体機能（`eccube.rate_limiter`）を使っており、
4.0/4.1 には対応する仕組みがありません。

## 開発環境

4.0/4.1 系には公式の Docker イメージが存在しない（`ghcr.io/ec-cube/ec-cube-php` は
4.2 系が最古）ため、`Dockerfile` が EC-CUBE 本体ごとビルドします。

```bash
# EC-CUBE 4.0.6-p5 で起動（既定）
docker compose up -d --build

# EC-CUBE 4.1.2-p5 で起動
ECCUBE_VERSION=4.1.2-p5 docker compose up -d --build

# 管理画面: https://localhost:8081/admin
# デフォルトID: admin / password
```

プラグインのソースを直したあとは `docker compose restart ec-cube` で反映されます
（entrypoint が `/plugin` から `app/Plugin/EcAuthLogin40` へ同期します）。
ファイルの削除、`composer.json`、Entity の変更を反映するときは
`docker compose down -v` で作り直してください。

## ライセンス

LGPL-2.1-or-later
