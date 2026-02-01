#!/bin/bash
set -e

# Composer ローカルリポジトリ経由でプラグインをシンボリックリンクインストール
composer config repositories.ecauth '{"type": "path", "url": "/plugin"}'
bin/console eccube:composer:require ecauth/ec-cube4-ecauth
bin/console eccube:plugin:enable --code=EcAuthLogin43

exec docker-php-entrypoint "$@"
