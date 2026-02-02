#!/bin/bash
set -e

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

# プラグインがまだインストールされていなければインストール
if ! bin/console eccube:plugin:list 2>/dev/null | grep -q EcAuthLogin43; then
    echo "Installing EcAuthLogin43 plugin..."
    bin/console eccube:composer:require ec-cube/ecauthlogin43 --from=/plugin
    bin/console eccube:plugin:enable --code=EcAuthLogin43
    bin/console cache:clear --no-warmup
    bin/console cache:warmup --no-optional-warmers
    echo "EcAuthLogin43 plugin installed and enabled."
else
    echo "EcAuthLogin43 plugin already installed."
fi

# Apache 起動
exec "$@"
