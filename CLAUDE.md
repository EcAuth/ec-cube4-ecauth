# CLAUDE.md

このファイルは Claude Code (claude.ai/code) がこのリポジトリで作業する際のガイダンスを提供します。

## プロジェクト概要

EC-CUBE 4.0/4.1系管理画面向け EcAuth B2Bパスキー認証プラグイン（EcAuthLogin40）。
EcAuth Identity Provider と連携し、管理画面にパスキー（WebAuthn/FIDO2）認証を追加する。

**このブランチ (`4.0`) は 4.0/4.1 系専用**。4.2/4.3 系は `main` ブランチの EcAuthLogin43
（`ec-cube/ecauthlogin43`）で、機能は同じだがコードベースは別物として扱う。
両者の差分は「EC-CUBE 4.0/4.1 固有の制約」を参照。

## 注意事項

プラグインインストール後に docker 側で何らかの修正をしたり、 EC-CUBEコア側にパッチをあてるのは本来の EC-CUBE プラグインの開発要件から大きく逸脱するので絶対にしないでください。
EC-CUBEプラグインは、EC-CUBE管理画面の Webインストーラーからインストール可能なことが絶対条件です。

開発時はワーキングツリーから tar.gz を作り、`eccube:plugin:install --path=` で導入している
（4.2/4.3 版のような composer のローカルリポジトリ経由は 4.0/4.1 では使えない。詳細は
「プラグインのインストール方法」）。これは管理画面からアップロードしたときと同じ
`PluginService::install()` を通るため、Web インストーラーで入る形のまま検証できている。

`docker/fix-composer-v1.php` だけは EC-CUBE 本体の `composer.json` を書き換えるが、これは
**検証環境のイメージをビルドするときにだけ実行される**もので、プラグインの配布物にも
実行時の動作にも含まれない。内容も EC-CUBE 公式が 4.0 系の利用者に案内している手順
（[Composer v1 利用時の注意点](https://doc4.ec-cube.net/plugin_eccube40)）そのもの。

## 開発コマンド

### Docker 環境

4.0/4.1 系には公式イメージが無い（`ghcr.io/ec-cube/ec-cube-php` は 4.2 系が最古で、
EC-CUBE 本体 4.0 ブランチの docker-compose.yml が参照する `7.4-apache-4.0` タグは
publish されていない = 404）。そのため `Dockerfile` が EC-CUBE 本体ごとビルドする。
本体のソースは downloads.ec-cube.net の配布パッケージ（vendor 同梱）を展開している。

```bash
# EC-CUBE 4.0.6-p5 で起動（既定）
docker compose up -d --build

# EC-CUBE 4.1.2-p5 で起動
ECCUBE_VERSION=4.1.2-p5 docker compose up -d --build

# ログ確認
docker compose logs ec-cube

# 停止（DB volume ごと消す）
docker compose down -v
```

composer のメジャーバージョン（4.0 は v1 / 4.1 は v2）は `ECCUBE_VERSION` から
Dockerfile が決める。手で指定させると「4.1 に切り替えたのに composer が v1 のまま」を踏む。

### プラグインのインストール方法

4.0/4.1 には `eccube:composer:require` の `--from` オプションが無い（4.2 で追加された）。
そのため 4.2/4.3 版のように composer の path リポジトリ経由では入れられない。
`docker-entrypoint.sh` はワーキングツリーから tar.gz を作り、
`eccube:plugin:install --path=` に渡す。これはオーナーズストアからアップロードしたときと
同じ `PluginService::install` を通るので、配布経路としてはむしろ実際に近い。

```bash
# entrypoint が中でやっていること
bin/console eccube:plugin:install --path=/tmp/EcAuthLogin40.tar.gz
bin/console eccube:plugin:enable --code=EcAuthLogin40
```

開発中にソースを直したら `docker compose restart ec-cube` で反映される
（entrypoint が `/plugin` から `app/Plugin/EcAuthLogin40` へ tar 経由で同期する）。
**上書きのみでファイルの削除は反映されない**。消したファイル、`composer.json`、Entity の
変更を反映するときは `docker compose down -v` で作り直すこと。

#### tar に `./` を含めてはいけない

`PluginService::unpackPluginArchive` は tar を `PharData` で展開する。tar に `./`
エントリ（カレントディレクトリ自身）が含まれていると

```
PharException: Extraction from phar "..." failed: Cannot extract ".", internal error
```

で必ず失敗する。`tar -C dir .` はこのエントリを作るため使えない。`docker-entrypoint.sh`
も `.github/workflows/deploy.yml` も glob（`*` / `./*`）でエントリを列挙している。
配布物のパッケージングを書き換えるときはここを壊さないこと。

#### オーナーズストア（package-api）経由の検証は自動化していない

4.2/4.3 版には `ECCUBE_AUTHENTICATION_KEY` を設定すると package-api から
「申請中のパッケージ」を入れる経路があるが、40 版では用意していない。4.0 系は
Composer v1 のメタデータ提供が終了しており、`eccube:composer:require` 経由の
インストールがそもそも安定しないため（EC-CUBE 公式もコマンドラインでの操作を推奨）。
検証が必要なときは管理画面から手で tar.gz をアップロードする。

### 静的解析

CI と同じコマンドで実行する。

```bash
# PHPStan（解析対象を PHP 7.1 として見る設定にしてある）
vendor/bin/phpstan analyse --no-progress

# Rector (dry-run)
vendor/bin/rector process --dry-run --config Tests/rector.php

# PHP CS Fixer (dry-run)
vendor/bin/php-cs-fixer fix --dry-run --diff --config Tests/.php-cs-fixer.dist.php

# PHPUnit（EC-CUBE のカーネルを起動しない純粋なユニットテストのみ）
vendor/bin/phpunit --configuration phpunit.xml.dist
```

`composer install` で生成される `vendor/` は Docker 環境に影響しない。
`docker-entrypoint.sh` が tar を作るときに除外しているためで、4.2/4.3 版にあった
「Docker で動かす前に vendor を消す」「composer.json が minify された 1 行 JSON に
書き戻される」といった問題は 40 版では起きない（composer をまったく経由しないため）。

ユニットテストを PHP 7.4 で回したいときは、ビルド済みの検証用イメージを流用できる。

```bash
docker run --rm --entrypoint bash -v "$PWD:/app" -w /app eccube40-ec-cube:latest -c '
  curl -fsSL https://getcomposer.org/download/2.8.12/composer.phar -o /tmp/composer && chmod +x /tmp/composer
  /tmp/composer install --no-interaction --no-progress
  vendor/bin/phpunit --configuration phpunit.xml.dist
'
```

イメージに入っている composer は EC-CUBE 4.0 用の v1 なので、プラグインの依存解決には
使えない（packagist.org の v1 メタデータは提供終了済み）。上のように v2 を別途落とす。

### E2E テスト

```bash
pnpm install
pnpm exec playwright test
```

## ディレクトリ構成

```
ec-cube4-ecauth/                     # ブランチ 4.0 = EC-CUBE 4.0/4.1 系向け
├── composer.json                    # type: eccube-plugin, code: EcAuthLogin40
│                                    # require は ec-cube/plugin-installer のみに保つこと
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
│   ├── Config.php                   # plg_ecauth_login40_config
│   └── MemberTrait.php              # dtb_member に ecauth_subject 追加
├── Form/Type/Admin/
│   └── ConfigType.php
├── Http/                            # PSR-18/PSR-17 相当の自前抽象（本体に無いため）
│   ├── HttpClientInterface.php
│   ├── HttpClientExceptionInterface.php
│   ├── HttpClientException.php
│   ├── RequestFactoryInterface.php
│   ├── StreamFactoryInterface.php
│   └── GuzzleClient.php             # Guzzle を触ってよい唯一のクラス
├── Repository/
│   └── ConfigRepository.php
├── Security/
│   └── AdminPasswordLoginListener.php # kernel.request で管理画面のパスワード認証を拒否
├── Service/
│   ├── AdminPasswordLoginPolicy.php # パスワード認証を無効化するかの判定
│   ├── EcAuthApiClient.php          # EcAuth API HTTP クライアント
│   └── PasskeyAuthService.php       # パスキー認証ビジネスロジック
├── Resource/
│   ├── config/services.yaml
│   ├── locale/messages.ja.yaml
│   ├── template/admin/              # Bootstrap 4 で書くこと（4.0/4.1 の管理画面）
│   │   ├── config.twig
│   │   ├── passkey_list.twig
│   │   └── login_passkey.twig
│   └── assets/js/
│       └── ecauth-auth.umd.js       # @ecauth/auth-js ビルド成果物（gitignore）
├── Tests/specs/                     # Playwright E2E テスト
├── docker/
│   └── fix-composer-v1.php          # 検証環境の EC-CUBE 本体を Composer v1 向けに調整
├── Dockerfile                       # EC-CUBE 本体ごとビルドする（公式イメージが無いため）
├── docker-compose.yml
├── docker-compose.override.yml
└── docker-entrypoint.sh             # tar.gz を作って eccube:plugin:install --path= で導入
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

## EC-CUBE 4.0/4.1 固有の制約

4.2/4.3 版（`main` ブランチ / EcAuthLogin43）から移植する際に踏んだ非互換を、**実測した
事実だけ**まとめる。推測でここに足さないこと。

### 依存パッケージ（各バージョンの composer.lock 実測）

| パッケージ | 4.0.6-p5 | 4.1.2-p5 | 4.3 系 |
|---|---|---|---|
| php | ^7.1.3 | ^7.3 | ^8.1 |
| symfony/* | **3.4** | **4.4** | 6.4 |
| psr/http-client | **なし** | **なし** | あり |
| psr/http-factory | **なし** | **なし** | あり |
| guzzlehttp/guzzle | 6.4.1 | 6.5.5 | ^7 |
| guzzlehttp/psr7 | 1.6.1 | 1.8.3 | 2.x |
| doctrine/persistence | 1.2.0 | 1.3.8 | 3.x |
| symfony/translation-contracts | **なし** | 2.5.0 | あり |
| ec-cube/plugin-installer | 0.0.8 | 2.0.1 | ^2.0 |
| symfony/cache（`cache.app`） | 3.4 | 4.4 | あり |

### API の置き換え表

| 4.2/4.3 版 | 4.0/4.1 版 | 理由 |
|---|---|---|
| `Psr\Http\Client\ClientInterface` | `Plugin\EcAuthLogin40\Http\HttpClientInterface` | PSR-18 が本体に無い |
| `Psr\Http\Message\RequestFactoryInterface` | `Plugin\EcAuthLogin40\Http\RequestFactoryInterface` | PSR-17 が本体に無い |
| `UserPasswordHasherInterface` | `Security\Core\Encoder\UserPasswordEncoderInterface` | PasswordHasher は Symfony 5.3+ |
| `Symfony\Contracts\Translation\TranslatorInterface` | `Symfony\Component\Translation\TranslatorInterface` | 4.0 に translation-contracts が無い |
| `Doctrine\Persistence\ManagerRegistry` | `Doctrine\Common\Persistence\ManagerRegistry` | 4.0 の doctrine/persistence 1.2 に新名前空間が無い |
| `Psr\Container\ContainerInterface`（PluginManager） | `DependencyInjection\ContainerInterface` | 4.0/4.1 の `AbstractPluginManager` のシグネチャ |
| `new UsernamePasswordToken($user, $firewall, $roles)` | `new UsernamePasswordToken($user, null, $firewall, $roles)` | 3.4/4.4 は 4 引数（第 2 が credentials） |
| `CheckPassportEvent` | `kernel.request`（priority 10） | 新認証システムは Symfony 5.1+ |
| `%env(default:param:NAME)%` | `parameters: env(NAME)` + `%env(NAME)%` | `default:` プロセッサは Symfony 4.0+ |
| `eccube.rate_limiter` | （提供しない） | 本体のレート制限機能は 4.2 から |
| `eccube:composer:require --from=` | `eccube:plugin:install --path=` | `--from` は 4.2 で追加 |
| Bootstrap 5（`data-bs-*` / `ms-` / `badge bg-*`） | Bootstrap 4（`data-*` / `ml-` / `badge badge-*`） | 管理画面が読むのは Bootstrap 4.3.1 |

そのまま使えることを確認済みのもの: `TemplateEvent`（API 同一、`Eccube\Twig\Template` から
dispatch される）、`EccubeNav`、`AbstractPluginManager`、`AbstractRepository`、
`AbstractController::addSuccess|addWarning`、`EntityExtension`、プラグインの
`Resource/config/services.yaml` と `Resource/locale` の読み込み、歯車リンクのルート名規約
（`Container::underscore(code).'_admin_config'`）、`cache.app`(PSR-6)、`%timezone%`。
`@admin/login.twig` は 4.0 と 4.1 で完全に同一で、`login_frame.twig` に
`{% block javascript %}` があるため `setSource()` で差し込む手法もそのまま通る。

### Composer v1 のメタデータ提供終了（4.0 系）

packagist.org は 2025-08-01 に Composer v1 向けメタデータの提供を終了した。EC-CUBE 4.0 系は
composer/composer ^1.6 に依存しており、プラグインの install / enable / disable / uninstall /
update が composer を経由すると依存解決に失敗しうる。
詳細と対応は [EC-CUBE4.0系(Composer v1)利用時の注意点](https://doc4.ec-cube.net/plugin_eccube40)。

このプラグインでの向き合い方:

- **`composer.json` の require を `ec-cube/plugin-installer` だけに保つ**。依存を足すと、
  利用者が本体の composer.json に vcs リポジトリを書き足す必要が生じる（上記ドキュメント参照）。
  HTTP クライアントを自前抽象にしているのはこのため
- 検証環境は `eccube:plugin:install --path=` を使う。この経路は composer をまったく呼ばない
  （`PluginService::install()` は展開・アセットコピー・DB 登録だけを行う）
- 検証環境の Dockerfile は、本体側の対応（require-dev 削除 / packagist.org 無効化 /
  plugin-installer を vcs 参照）を `docker/fix-composer-v1.php` で適用している。
  CLI 経路では本来不要だが、管理画面からのインストールを手で試したときに素の状態だと詰まるため

### プラグインが「有効なのに動かない」状態（偶発的に起きる）

`EccubeExtension::prepend()` は `dtb_plugin` を読んで「無効なプラグイン」の一覧を
`eccube.plugins.disabled` に入れ、`PluginPass` がその名前空間のサービスから
`doctrine.repository_service` 以外の **全タグを剥がす**。

問題は `prepend()` が **DB に接続できなかったときに `app/Plugin` のディレクトリ一覧を
そのまま無効扱いにして早期 return する** こと。

```php
$pluginDirs = $this->getPluginDirectories($pluginDir);
$container->setParameter('eccube.plugins.disabled', $pluginDirs);  // ← 初期値
// ...
if (!$this->isConnected($conn)) {
    return;   // ← ここを通ると app/Plugin 配下が全部「無効」のまま確定する
}
```

この状態でコンパイルされたコンテナが残ると

- `dtb_plugin.enabled` は `t`
- なのに `kernel.event_subscriber` が剥がれていて `TemplateEvent` が発火しない
- 表に出る症状は「ログイン画面にパスキーのボタンが出ない」だけ

という、極めて切り分けにくい状態になる。

**偶発的にしか起きない。** 同じ手順で起動し直すと再現しないことがある（実際、CI の
4.1 系 E2E で踏んだあと、同じイメージで起動し直したら `ping: true` で正常にコンパイル
された）。したがって **一度通ったから大丈夫、とは言えない**。原因はバージョンに依存
しないので 4 系のどれでも起こりうる。「4.0 では起きない」と考えないこと。

4.1 系で先に顕在化したのは、`PluginPass` が

```php
$plugins = $container->getParameter('eccube.plugins.disabled');
if (empty($plugins)) { return; }
```

と早期 return する一方、4.1 の fixtures が `Recommend4` / `Coupon4` など 10 個を
無効状態で `dtb_plugin` に登録するため、一覧が空にならずタグ剥がしまで到達しやすいから。

#### 対処

`docker-entrypoint.sh` は Apache を起動する前に

1. `cache:clear --no-warmup` → `cache:warmup --no-optional-warmers`（CLI 側で作り切る。
   clear だけだと最初のコンパイルが Apache = www-data 側で走り、CLI とは環境変数も
   パーミッションも違う状態になる。4.2/4.3 版の entrypoint も両者をペアで呼んでいる）
2. **コンパイル結果を検証**し、プラグインが `eccube.plugins.disabled` に残っていたら
   作り直す（最大 3 回）
3. それでも直らなければ **起動を止める**

をやっている。偶発的な事象なので「作って終わり」にせず検証まで含めるのが要点。
静かに壊れたまま起動させると、E2E が個々の spec の失敗として散らばり原因に辿り着けない。

確認コマンド:

```bash
# DB 上は有効か
bin/console doctrine:query:sql "select code, enabled from dtb_plugin where code = 'EcAuthLogin40'"

# コンテナ側でも有効とみなされているか
# (ここに EcAuthLogin40 が出てきたら、上記の「有効なのに剥がれている」状態)
bin/console debug:container --parameter=eccube.plugins.disabled
```

CI の E2E ジョブにも同じ確認ステップを置いてある。

### `extra.id` を 0 にしてある理由

`composer.json` の `extra.id` はオーナーズストアのプラグイン ID。43 版は 3557 を持つが、
40 版は別パッケージとして申請するため未採番で、暫定的に 0 を入れている。
**オーナーズストアで採番されたら差し替えること。**

0 にしておくと実装上も都合がよい。`PluginService::readConfig()` は `extra.id` を `source` として
返し、`installWithCode()` は `source` が真のときだけ `getPluginRequired()` → composer による
依存解決に入る。0 なら composer をまったく経由しない。

## コーディング規約

- **Entity プロパティ名は snake_case** を使用する（EC-CUBE 本体の規約に準拠）。PSR-12 の camelCase 推奨よりも EC-CUBE 本体との一貫性を優先する
- EC-CUBE 本体のコーディングスタイルに従う
- **末尾カンマは配列リテラルだけに許される**。EC-CUBE 4.0 は PHP 7.1.3 以上をサポートするため、
  関数呼び出しの末尾カンマ（7.3+）も関数定義の末尾カンマ（8.0+）も Parse error になる
  - `Tests/.php-cs-fixer.dist.php` の `trailing_comma_in_multiline` は `arrays` のみ対象
  - `Tests/rector.php` の `phpVersion` は `PHP_71` に固定
  - `phpstan.neon.dist` の `phpVersion` は `70100`
  - CI の `php-syntax` ジョブが PHP 7.1 / 7.2 / 7.3 で `php -l` を回す。ユニットテストを
    7.1 で動かせないのは、phpunit ^9.6 の下限が 7.3、php-cs-fixer ^3 の下限が 7.4 で、
    それ未満では `composer install` 自体が通らないため

## HTTP クライアント

プラグイン内の HTTP 通信は **プラグイン自身が定義した抽象**（`Plugin\EcAuthLogin40\Http\*`）に
依存する。`GuzzleHttp\Client` を直接 `new` したり `use` したりしてよいのは
`Http/GuzzleClient.php` だけ。

### なぜ PSR-18 / PSR-17 を使わないのか

EC-CUBE 4.0/4.1 の依存には `psr/http-client`（PSR-18）も `psr/http-factory`（PSR-17）も
**含まれない**（composer.lock 実測）。本体が持つのは `psr/http-message`（PSR-7）1.0.1 と
`guzzlehttp/guzzle` 6.x で、Guzzle 6 は PSR-18 を実装していない（7.0 から）。
`GuzzleHttp\Psr7\HttpFactory` も psr7 2.x で入ったもので 1.x には無い。

プラグイン側で `psr/http-client` を require する手もあるが、4.0 系では避ける。
Composer v1 のメタデータ提供が終了しているため、`ec-cube/plugin-installer` 以外の依存を
持つプラグインは、**利用者が EC-CUBE 本体の composer.json に vcs リポジトリを書き足さないと
インストールできなくなる**（[EC-CUBE4.0系(Composer v1)利用時の注意点](https://doc4.ec-cube.net/plugin_eccube40)）。
依存を増やさないことがそのまま導入しやすさになるため、PSR-18/PSR-17 相当のインタフェースだけを
プラグイン内に置いている。PSR-7 は本体にあるのでそのまま使う。

- DI するインタフェース（`Http/`）:
  - `HttpClientInterface` — HTTP 送信（PSR-18 相当）
  - `RequestFactoryInterface` — PSR-7 Request 生成（PSR-17 相当）
  - `StreamFactoryInterface` — PSR-7 Body 生成（PSR-17 相当）
- 実装は `Http\GuzzleClient` 1 クラスが 3 つとも担う。バインドは
  `Resource/config/services.yaml` のエイリアス 3 本で一元管理する
- 例外は `Http\HttpClientExceptionInterface` を catch する（Guzzle 固有の例外型に依存しない）
- ストリームは `php://temp` から自前で組み立てている。psr7 1.7 で入った
  `GuzzleHttp\Psr7\Utils` は 4.0 の 1.6.1 に無く、逆に 1.x の関数 API
  `GuzzleHttp\Psr7\stream_for` は 2.x で削除されているため、どちらにも依存できない
- ユニットテストは `Tests/Unit/Support/TestPsr17Factory` が nyholm/psr7 を包んでこの抽象に
  合わせる。テストのために Guzzle を持ち出す必要は無い
- **`guzzlehttp/guzzle` は require-dev にも入れない**。Guzzle 6 系は全バージョンに
  セキュリティアドバイザリが出ており、composer の `policy.advisories.block`（既定で有効）が
  `composer install` 自体を失敗させる。静的解析で `GuzzleHttp\ClientInterface` の型を
  解決したくなるが、そのために 6 系を require-dev へ足すことはできない
  （実際に CI がこれで落ちた。`policy.advisories.ignore` を配布物の composer.json に
  書いて回避するのも、利用者側に脆弱性の無視を配ることになるので採らない）
- **`composer.json` の require は `ec-cube/plugin-installer` だけに保つこと**

## 環境変数の取り扱い

**コード内で `getenv()` / `$_ENV` を直接参照しない**。Symfony の env プロセッサ（`%env(...)%`）を
経由して DI で注入する。

- デフォルト値は **`env(NAME)` という名前のパラメータ**で与える

  ```yaml
  parameters:
      env(ECAUTH_CLIENT_RESOLVE_URL): 'https://api.ec-auth.io'

  services:
      _defaults:
          bind:
              $discoveryUrl: '%env(ECAUTH_CLIENT_RESOLVE_URL)%'
  ```

- 4.2/4.3 版が使う `%env(default:パラメータ名:NAME)%` は**使えない**。`default:` プロセッサは
  Symfony 4.0 で追加されたもので、EC-CUBE 4.0 が使う Symfony 3.4 には無い
  （3.4 の `EnvVarProcessor::getProvidedTypes()` が返すのは base64 / bool / const / file /
  float / int / json / resolve / string のみ）。書くと "Unsupported env var prefix" で
  コンテナのコンパイルに失敗する
- `env(NAME)` パラメータによる既定値は Symfony 3.3 以降で使え、4.4 でも同じ意味になるため
  4.0 / 4.1 のどちらでも動く

### bind は `_defaults` に置く

`bind` は**定義ブロックごとに閉じている**。`Plugin\EcAuthLogin40\` の resource 定義の中に
書くと、後から個別に上書きした定義（`CachedJwksProvider` など）には引き継がれず、

```
Cannot autowire service "...": argument "$signupUrl" of method "__construct()"
is type-hinted "string", you should configure its value explicitly.
```

で `eccube:plugin:enable` が失敗する。`_defaults` に置けばこのファイル内のどの定義にも効く。

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

プラグイン設定（`plg_ecauth_login40_config`）に持たせると、**管理画面を乗っ取られた時点で
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

環境変数を `1` のまま固定し、`disable_admin_password.spec.ts` の
「正しい ID とパスワードでもログインできない」を各状態で流して実測した結果
（**4.0.6-p5 / 4.1.2-p5 の両方で同じ結果**）:

| 操作 | 管理画面へのログイン |
|---|---|
| プラグイン有効 | 拒否される |
| `dtb_plugin.enabled = false` のみ | **拒否されたまま** |
| 上記 + `cache:clear` | ログインが成立してしまう |

**DB 書き換え単独では迂回できない**（有効・無効はコンテナのコンパイル時に解決されるため）。
迂回には DB 書き込みに加えてキャッシュ再構築＝ファイルシステム / CLI アクセスが要る。
本来の脅威（不正な管理者アカウント作成）への防御は成立しているが、断定表現は使わないこと。

プラグインは Web インストーラーからインストールできることが要件でコア改変も禁止のため、
**この経路はプラグイン内では塞げない。ドキュメント化が正しい対処**であり、「プラグイン外で
強制する」方向へ実装を広げないこと。

### 塞いでいる場所は `kernel.request`（`Security/AdminPasswordLoginListener`）

ログイン画面のテンプレート（`login_passkey.twig`）が入力欄を隠すのは案内でしかない。
実際に拒否しているのは `kernel.request` に入ったリスナーで、`curl` 等でフォームを経由せずに
POST されても同じように弾く。

#### 4.2/4.3 版と実装が違う

4.2/4.3 版は `CheckPassportEvent`（Symfony の認証パイプライン）に割り込んでいるが、あれは
**Symfony 5.1 で入った新しい認証システムの仕組み**で、EC-CUBE 4.0/4.1 が使う Symfony 3.4 /
4.4 には存在しない。この系統の管理画面ログインは security.yaml の `form_login`
（`UsernamePasswordFormAuthenticationListener`）で処理される。移植時にここだけは
そのまま持ってこられないので、`kernel.request` でファイアウォールの手前に立つ方式に変えた。

#### 優先度 10 の理由

```
32 RouterListener   ここで `_route` が決まる。判定に使うので後に置く必要がある
→10 本リスナー
 8 Firewall         認証処理。ここに入る前に止める
```

判定は **ルート名 `admin_login`** への POST かどうかで行う。`%eccube_admin_route%` は
サイトごとに変更できるため、パスで判定するとカスタマイズ済みサイトで素通りする。
EC-CUBE 本体の security.yaml が admin ファイアウォールの `check_path` にルート名
`admin_login` を指定しており、Symfony 側も `HttpUtils::checkRequestPath()` で同じルート名との
一致を見ている。つまり「ログイン試行かどうか」は本体と同じ条件で判定している。

ファイアウォールに入る前に一律で拒否するため、`login_id` の存在有無で応答が変わらない
（ユーザー列挙が起きない）。ユーザーの解決もパスワードのハッシュ計算も走らない。

#### 4.2/4.3 版との挙動差（意図的）

- **CSRF トークンの検証より前に拒否する**。4.2/4.3 版は CSRF 検証の後だった。無効化されて
  いる状況ではどのみち全て拒否するため、順序の違いに実害はない
- Symfony の `login_throttling`（試行回数制限）は 5.2 以降の機能で 4.0/4.1 には無い。
  4.2/4.3 版にあった「試行制限に先に当たる」挙動はそもそも起こらない

#### ファイアウォール名で絞るのは必須

`kernel.request` はあらゆるリクエストで呼ばれる。`FirewallMap::getFirewallConfig()` で
`admin` に絞り損ねると、EC サイトのフロント会員ログイン（`customer` ファイアウォール）まで
巻き添えで塞ぎかねない。リグレッションテストは
`Tests/specs/disable_admin_password.spec.ts` の「EC サイトのフロント会員ログインは影響を
受けない」。

判定表そのものは EC-CUBE 非依存の `Service/AdminPasswordLoginPolicy` に切り出してあり、
`Tests/Unit/AdminPasswordLoginPolicyTest.php` が固定している。4.2/4.3 版から
そのまま流用できた唯一の部分で、シグネチャ（`shouldReject(bool, bool)`）も変えていない。

#### 拒否のしかた

ログイン画面へリダイレクトし、セッションの `Security::AUTHENTICATION_ERROR` に
`CustomUserMessageAuthenticationException` を積む。EC-CUBE の
`Eccube\Controller\Admin\AdminController::login` が `AuthenticationUtils::getLastAuthenticationError()`
の戻り値を `error` としてテンプレートへ渡すため、通常の認証失敗とまったく同じ経路で
文言が表示される。

### 認証失敗の文言は `validators` ドメインに置く

`@admin/login.twig` は `{{ error.messageKey|trans(error.messageData, 'validators') }}` で
描画する。`messages.ja.yaml` に書いてもキーがそのまま画面に出るだけなので、
`Resource/locale/validators.ja.yaml` 側に置くこと。

### E2E は専用ジョブで動かす

他の E2E はパスワードで管理画面にログインするため、無効化状態と同じコンテナには同居
できない。env だけ差し替えてコンテナを作り直す手も使えない（DB は volume で残るのに
プラグインの導入状態はコンテナ側にしか無く、`docker-entrypoint.sh` の
`eccube:plugin:install` が `checkSamePlugin` で落ちる）。CI では
`.github/workflows/playwright.yml` の `e2e-password-login-disabled` ジョブが
クリーンな環境を立てて `disable_admin_password.spec.ts` だけを流す。

ローカルで再現する場合:

```bash
docker compose down -v
ECAUTH_DISABLE_ADMIN_PASSWORD_LOGIN=1 docker compose up -d --build
E2E_ADMIN_PASSWORD_LOGIN_DISABLED=1 pnpm exec playwright test Tests/specs/disable_admin_password.spec.ts
```

4.2/4.3 版の CLAUDE.md にある「`login_throttling` の 5 回 / 30 分に当たる」という注意は
4.0/4.1 には当てはまらない。Symfony の login_throttling は 5.2 以降の機能で、
4.0/4.1 の `security.yaml` は設定を持たない。

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
  リグレッションテスト: `Tests/specs/passkey_auth.spec.ts` の `#45:` で始まる test。
  なお `UsernamePasswordToken` の引数は Symfony 3.4 / 4.4 では
  `($user, $credentials, $providerKey, $roles)` の 4 引数で、5.4 以降の 3 引数版とは別物。
  資格情報はセッションに serialize されるため、第 2 引数には null を渡している
- **4.0/4.1 版はパスキー認証オプション取得のレート制限を持たない**。4.2/4.3 版が使う
  `eccube.rate_limiter` は EC-CUBE 4.2 で入った本体機能で、4.0/4.1 には対応する仕組みが
  無いため。総当たりへの耐性は EcAuth 側の制限に依存する。ここを塞ぐなら
  `cache.app` を使った自前のカウンタなどを別途設計すること
