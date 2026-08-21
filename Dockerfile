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
#
# ダウンロードした phar は SHA-256 で検証する。ハッシュは
# https://getcomposer.org/download/<version>/composer.phar.sha256sum で公開されている値を
# **ここに焼き込んでいる**。配布元から checksum も取ってきて突き合わせるのでは、配布元が
# 汚染された場合に両方差し替えられて検証にならないため。
# composer のバージョンを変えるときはハッシュも更新すること。
RUN set -eu; \
    version="${COMPOSER_VERSION}"; \
    if [ -z "${version}" ]; then \
      case "${ECCUBE_VERSION}" in \
        4.0.*) version=1.10.27 ;; \
        *)     version=2.2.25 ;; \
      esac; \
    fi; \
    case "${version}" in \
      1.10.27) sha256=230d28fb29f3c6c07ab2382390bef313e36de17868b2bd23b2e070554cae23d2 ;; \
      2.2.25)  sha256=8b3f41253363f0645402d1951d6e7f02adedeef29c16de6074763e463e25c23f ;; \
      *) echo "composer ${version} の SHA-256 が Dockerfile に登録されていません" >&2; exit 1 ;; \
    esac; \
    echo "installing composer ${version} for EC-CUBE ${ECCUBE_VERSION}"; \
    curl -fsSL "https://getcomposer.org/download/${version}/composer.phar" -o /usr/local/bin/composer; \
    echo "${sha256}  /usr/local/bin/composer" | sha256sum -c -; \
    chmod +x /usr/local/bin/composer; \
    composer --version

# 本家からの変更: COPY . ではなく配布パッケージを展開する。
#
# 取得元は downloads.ec-cube.net ではなく GitHub のリリースアセット。
# downloads.ec-cube.net にも同名の tar.gz があるが、GitHub 側とはバイト列が異なり
# (4.0.6-p5 で 35,891,624 / 35,922,621 バイト)、公開されている checksum で検証できない。
# GitHub のリリースには eccube-<version>.tar.gz.checksum.sha256 が併載されている。
# vendor 同梱・ディレクトリ構成は downloads 版と同じであることを確認済み。
#
# ハッシュは上記 checksum ファイルの値を **ここに焼き込んでいる**。配布元から
# checksum も取得して突き合わせる方式では、配布元が汚染された場合に両方差し替えられて
# 検証にならないため。バージョンを増やすときはハッシュも追記すること
# (未登録のままだとビルドを止める。検証を黙って飛ばさない)。
ARG ECCUBE_SHA256=
WORKDIR ${APACHE_DOCUMENT_ROOT}
RUN set -eu; \
    sha256="${ECCUBE_SHA256}"; \
    if [ -z "${sha256}" ]; then \
      case "${ECCUBE_VERSION}" in \
        4.0.6-p5) sha256=e92ea76b60057565f2b0f22cc62fe488e549bad9298d0e143ba99906cf8fa580 ;; \
        4.1.2-p5) sha256=11ec54ef84a5ac4254249285737e5b8899f5161a10530d1b290c0baec7312ce8 ;; \
        *) echo "EC-CUBE ${ECCUBE_VERSION} の SHA-256 が Dockerfile に登録されていません。" >&2; \
           echo "Dockerfile に追記するか、--build-arg ECCUBE_SHA256=... で渡してください。" >&2; \
           exit 1 ;; \
      esac; \
    fi; \
    curl -fsSL "https://github.com/EC-CUBE/ec-cube/releases/download/${ECCUBE_VERSION}/eccube-${ECCUBE_VERSION}.tar.gz" \
      -o /tmp/eccube.tar.gz; \
    echo "${sha256}  /tmp/eccube.tar.gz" | sha256sum -c -; \
    tar xz --strip-components=1 -C "${APACHE_DOCUMENT_ROOT}" -f /tmp/eccube.tar.gz; \
    rm -f /tmp/eccube.tar.gz; \
    test -f "${APACHE_DOCUMENT_ROOT}/composer.json"

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
