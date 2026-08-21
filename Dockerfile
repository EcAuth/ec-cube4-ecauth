# EC-CUBE 4.0/4.1 系の検証環境。
#
# 4.2/4.3 系と違い、この系統には公式イメージが存在しない。
# ghcr.io/ec-cube/ec-cube-php は 4.2 系が最古で、EC-CUBE 本体 4.0 ブランチの
# docker-compose.yml が参照する 7.4-apache-4.0 タグは publish されていない (404)。
# そのため本体 4.0 ブランチの Dockerfile を出発点に自前でビルドする。
# 本家からの変更点はすべて「本家からの変更」コメントを付けてある。
ARG PHP_TAG=7.4-apache-bullseye
FROM php:${PHP_TAG}

# 検証対象の EC-CUBE。downloads.ec-cube.net の配布パッケージ (vendor 同梱) を使う。
# GitHub の tarball は vendor を含まないため composer install が必要になり、
# 4.0 系では Composer v1 のメタデータ廃止をまともに踏む (下の COMPOSER_VERSION 参照)。
ARG ECCUBE_VERSION=4.0.6-p5

# 4.0 系は Composer v1、4.1 系は v2 系を本体が前提にしている
# (composer.json の require: composer/composer ^1.6 / ^2.0)。
# v1 は packagist.org のメタデータ提供が 2025-08-01 に終了しており、
# getcomposer.org の selfupdate --1 経路も廃止されうるため phar を直接取得する。
# https://doc4.ec-cube.net/plugin_eccube40
#
# 空のままにしておくと ECCUBE_VERSION から自動で決まる。ここを手で指定させると
# 「4.1 に切り替えたのに composer が v1 のまま」を踏むため、既定は自動判定にする。
ARG COMPOSER_VERSION=

ENV APACHE_DOCUMENT_ROOT=/var/www/html

# 本家からの変更: nodejs のインストールを削除した。
# 本家は deb.nodesource.com/setup_12.x を叩くが、この配布経路は既に廃止されており
# ビルドが失敗する。フロントのアセットは配布パッケージにビルド済みで含まれるため
# 検証環境に Node は要らない。
RUN apt-get update \
  && apt-get upgrade -y \
  && apt-get install --no-install-recommends -y \
    ca-certificates \
    curl \
    libfreetype6-dev \
    libicu-dev \
    libjpeg62-turbo-dev \
    libpng-dev \
    libpq-dev \
    libwebp-dev \
    libzip-dev \
    locales \
    ssl-cert \
    unzip \
    zlib1g-dev \
  && apt-get clean \
  && rm -r /var/lib/apt/lists \
  && echo "en_US.UTF-8 UTF-8" >/etc/locale.gen \
  && locale-gen \
  ;

RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
  && docker-php-ext-install -j"$(nproc)" zip gd mysqli pdo_mysql opcache intl pgsql pdo_pgsql \
  ;

# 本家からの変更: apcu のバージョンを固定した。
# 無指定だと pecl が最新版を取りに行き、PHP 8 以上を要求する版に当たると失敗する。
RUN pecl install apcu-5.1.21 && echo "extension=apcu.so" > "${PHP_INI_DIR}/conf.d/apc.ini"

RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
  && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
  && a2enmod rewrite headers ssl \
  && ln -s /etc/apache2/sites-available/default-ssl.conf /etc/apache2/sites-enabled/default-ssl.conf \
  ;
EXPOSE 443

RUN mv "${PHP_INI_DIR}/php.ini-production" "${PHP_INI_DIR}/php.ini"

# 本家の dockerbuild/php.ini と同じ内容。COPY 元のリポジトリを持たないので直接書く。
RUN { \
    echo '; Optimizations for Symfony, as documented on http://symfony.com/doc/current/performance.html'; \
    echo 'opcache.max_accelerated_files = 20000'; \
    echo 'opcache.memory_consumption=256'; \
    echo 'realpath_cache_size = 4096K'; \
    echo 'realpath_cache_ttl = 600'; \
    echo 'memory_limit = 786M'; \
  } > "${PHP_INI_DIR}/conf.d/eccube.ini"

# 本家からの変更: composer は selfupdate ではなく phar を直接取得する。
# あわせて packagist.jp へのミラー設定も落とした (サービス終了済み)。
RUN set -eu; \
    version="${COMPOSER_VERSION}"; \
    if [ -z "${version}" ]; then \
      case "${ECCUBE_VERSION}" in \
        4.0.*) version=1.10.27 ;; \
        *)     version=2.2.25 ;; \
      esac; \
    fi; \
    echo "installing composer ${version} for EC-CUBE ${ECCUBE_VERSION}"; \
    curl -fsSL "https://getcomposer.org/download/${version}/composer.phar" -o /usr/local/bin/composer; \
    chmod +x /usr/local/bin/composer; \
    composer --version

# 本家からの変更: COPY . ではなく配布パッケージを展開する。
WORKDIR ${APACHE_DOCUMENT_ROOT}
RUN curl -fsSL "https://downloads.ec-cube.net/src/eccube-${ECCUBE_VERSION}.tar.gz" \
    | tar xz --strip-components=1 -C "${APACHE_DOCUMENT_ROOT}" \
  && test -f "${APACHE_DOCUMENT_ROOT}/composer.json" \
  ;

# Composer v1 のメタデータ提供終了 (2025-08-01) への対応。4.0 系のみ必要。
# https://doc4.ec-cube.net/plugin_eccube40
#
#   - require-dev を削除する (開発時にしか使わないうえ、依存解決の巻き添えを生む)
#   - packagist.org を無効化し、ec-cube/plugin-installer だけ GitHub の vcs から引く
#
# この検証環境はプラグインを CLI (eccube:plugin:install --path=) で入れるため、
# 本来 composer は走らない。それでも設定しておくのは、管理画面のオーナーズストア経由
# (eccube:composer:require) を手で試したときに素の状態だと確実に詰まるため。
COPY docker/fix-composer-v1.php /usr/local/bin/fix-composer-v1.php
RUN case "${ECCUBE_VERSION}" in \
      4.0.*) php /usr/local/bin/fix-composer-v1.php "${APACHE_DOCUMENT_ROOT}/composer.json" ;; \
      *)     echo "skip: Composer v1 workaround is only for 4.0.x (got ${ECCUBE_VERSION})" ;; \
    esac

RUN find "${APACHE_DOCUMENT_ROOT}" \( -path "${APACHE_DOCUMENT_ROOT}/vendor" -prune \) -or -print0 \
    | xargs -0 chown www-data:www-data \
  && find "${APACHE_DOCUMENT_ROOT}" \( -path "${APACHE_DOCUMENT_ROOT}/vendor" -prune \) -or \( -type d -print0 \) \
    | xargs -0 chmod g+s \
  ;

COPY docker-entrypoint.sh /docker-entrypoint-plugin.sh
RUN chmod +x /docker-entrypoint-plugin.sh
ENTRYPOINT ["/docker-entrypoint-plugin.sh"]
CMD ["apache2-foreground"]

HEALTHCHECK --interval=10s --timeout=5s --retries=60 CMD pgrep apache2 > /dev/null || exit 1
