#!/bin/bash
set -eo pipefail

cd "${APACHE_DOCUMENT_ROOT:-/var/www/html}"

# ベースイメージのエントリポイント（composer install, DB初期化）を実行
# ただし Apache 起動は行わない（後でプラグインインストール後に起動）
if [ ! -d /var/www/html/vendor/bin ]; then
    composer install \
        --no-scripts \
        --no-autoloader \
        --no-plugins \
        -d ${APACHE_DOCUMENT_ROOT}
    composer dumpautoload -o --apcu
    chown -R www-data: vendor
fi

bin/console doctrine:query:sql 'select * from dtb_base_info' > /dev/null 2>&1 || (
    if [ -z "${DATABASE_URL}" ]; then
        cp .env.dist .env
    fi
    # installer-scripts 相当の処理（--if-not-exists で DB 既存時のエラーを回避）
    bin/console doctrine:database:create --if-not-exists
    bin/console doctrine:schema:create
    bin/console eccube:fixtures:load
    # auto-scripts
    composer run-script auto-scripts
    find ${APACHE_DOCUMENT_ROOT} \( -path ${APACHE_DOCUMENT_ROOT}/vendor -prune \) -or -print0 \
        | xargs -0 chown www-data:www-data
    find ${APACHE_DOCUMENT_ROOT} \( -path ${APACHE_DOCUMENT_ROOT}/vendor -prune \) -or \( -type d -print0 \) \
      | xargs -0 chmod g+s
)

echo "PassEnv APP_ENV APP_DEBUG TRUSTED_PROXIES TRUSTED_HOSTS" > /etc/apache2/conf-enabled/eccube_env.conf

# 検証キー（オーナーズストアのリリース申請で発行される X-ECCUBE-KEY）を
# dtb_base_info.authentication_key に設定する。
#
# ComposerApiService は package-api リポジトリに `X-ECCUBE-KEY: {authentication_key}` を
# 付けてリクエストするため、composer require の前に DB へ入れておく必要がある。
# 管理画面「オーナーズストア > 認証キー設定」で人が入力するのと同じ場所であり、
# EC-CUBE コアへのパッチではない。
#
# キーをコマンド引数に載せると `ps` や docker のコマンドラインに現れてしまうため、
# スクリプトは標準入力から渡し、キー自体は PHP 側で環境変数から読む。
# （プラグイン本体のコードでは env 直参照を禁止しているが、ここは DI コンテナの無い
#   開発／CI 用エントリポイントであり、env 経由にすること自体が漏洩対策になっている）
#
# 環境変数の参照は getenv() ではなく $_SERVER を使う。getenv() はスレッドセーフでなく
# Symfony でも非推奨。$_ENV は variables_order に E が無いと空になるが、$_SERVER は
# EGPCS / GPCS のどちらでも CLI SAPI が populate する。
set_authentication_key() {
    php <<'PHP'
<?php
// DATABASE_URL 例: postgresql://eccube:password@postgres:5432/eccube_db
$url = parse_url((string) ($_SERVER['DATABASE_URL'] ?? ''));
$key = (string) ($_SERVER['ECCUBE_AUTHENTICATION_KEY'] ?? '');

if (!is_array($url) || !isset($url['host'], $url['path']) || $key === '') {
    fwrite(STDERR, "authentication_key を設定できません（DATABASE_URL または ECCUBE_AUTHENTICATION_KEY が不正です）\n");
    exit(1);
}

$driver = str_starts_with((string) ($url['scheme'] ?? 'postgresql'), 'mysql') ? 'mysql' : 'pgsql';
$dsn = sprintf(
    '%s:host=%s;port=%d;dbname=%s',
    $driver,
    $url['host'],
    $url['port'] ?? ($driver === 'mysql' ? 3306 : 5432),
    ltrim($url['path'], '/')
);

$pdo = new PDO(
    $dsn,
    rawurldecode((string) ($url['user'] ?? '')),
    rawurldecode((string) ($url['pass'] ?? '')),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// dtb_base_info は単一行運用。値はプレースホルダで渡し SQL 文字列に埋め込まない。
$pdo->prepare('UPDATE dtb_base_info SET authentication_key = ?')->execute([$key]);

fwrite(STDOUT, "authentication_key を設定しました（値は出力しません）\n");
PHP
}

# プラグインがまだインストールされていなければインストールする。
#
# インストール元は ECCUBE_AUTHENTICATION_KEY の有無で切り替える。
#   未設定: /plugin（このリポジトリのワーキングツリー）から入れる。PR CI の既定動作。
#   設定済: package-api から入れる。オーナーズストアにリリース申請すると検証キーが発行され、
#           そのキーで「申請中のパッケージ」を取得できる。公開前のパッケージを実際の配布
#           経路どおりに検証したいときに使う。
#
# eccube:composer:require は --from を付けると path リポジトリを追加し、そのパッケージを
# package-api リポジトリから exclude する（ComposerApiService::init）。両立しないため
# 明示的に分岐させる。
# 導入済み判定には composer を使う。EC-CUBE 4.3 に eccube:plugin:list は存在せず
# （bin/console が "Command not found" を返す）、それで判定すると常に未導入扱いになる。
# コンテナを restart したときに再インストールへ走り、eccube:plugin:enable が
# 「既に有効」で落ちて set -e により Apache が起動しない。
if composer show ec-cube/ecauthlogin43 >/dev/null 2>&1; then
    echo "EcAuthLogin43 plugin already installed."
else
    if [ -n "${ECCUBE_AUTHENTICATION_KEY:-}" ]; then
        echo "Installing EcAuthLogin43 from package-api (version: ${ECAUTH_PLUGIN_VERSION:-latest})..."
        set_authentication_key
        if [ -n "${ECAUTH_PLUGIN_VERSION:-}" ]; then
            bin/console eccube:composer:require ec-cube/ecauthlogin43 "${ECAUTH_PLUGIN_VERSION}"
        else
            bin/console eccube:composer:require ec-cube/ecauthlogin43
        fi
    else
        echo "Installing EcAuthLogin43 from /plugin (local source)..."
        bin/console eccube:composer:require ec-cube/ecauthlogin43 --from=/plugin
    fi
    bin/console eccube:plugin:enable --code=EcAuthLogin43
    bin/console cache:clear --no-warmup
    bin/console cache:warmup --no-optional-warmers
    echo "EcAuthLogin43 plugin installed and enabled."
fi

# どのバージョンで入ったかを起動ログに残す。
# 検証キー経由（package-api）で回すときは「実際に配布された版が入ったか」がまさに
# 確認したいことなので、成功時にも必ず残す。
echo "--- installed plugin version ---"
composer show ec-cube/ecauthlogin43 || true

# Apache 起動
exec "$@"
