#!/bin/bash
set -eo pipefail

cd "${APACHE_DOCUMENT_ROOT:-/var/www/html}"

PLUGIN_CODE=EcAuthLogin40
PLUGIN_SRC=/plugin
PLUGIN_DIR="${APACHE_DOCUMENT_ROOT}/app/Plugin/${PLUGIN_CODE}"
PLUGIN_ARCHIVE=/tmp/${PLUGIN_CODE}.tar.gz

# 配布パッケージ (downloads.ec-cube.net) は vendor 同梱なので通常ここは通らない。
# GitHub の tarball など vendor 無しのソースに差し替えたときの保険として残す。
if [ ! -d "${APACHE_DOCUMENT_ROOT}/vendor/bin" ]; then
    composer install \
        --no-scripts \
        --no-autoloader \
        --no-plugins \
        -d "${APACHE_DOCUMENT_ROOT}"
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
    # auto-scripts 相当。4.0/4.1 の composer.json の auto-scripts は
    # cache:clear と assets:install なので、composer を経由せず直接叩く。
    # 4.0 系は Composer v1 のメタデータが廃止されており、composer run-script でも
    # 依存解決に入ると失敗しうるため、composer を使わない方が安定する。
    bin/console cache:clear --no-warmup
    bin/console assets:install
    find "${APACHE_DOCUMENT_ROOT}" \( -path "${APACHE_DOCUMENT_ROOT}/vendor" -prune \) -or -print0 \
        | xargs -0 chown www-data:www-data
    find "${APACHE_DOCUMENT_ROOT}" \( -path "${APACHE_DOCUMENT_ROOT}/vendor" -prune \) -or \( -type d -print0 \) \
      | xargs -0 chmod g+s
)

echo "PassEnv APP_ENV APP_DEBUG TRUSTED_PROXIES TRUSTED_HOSTS" > /etc/apache2/conf-enabled/eccube_env.conf

# ワーキングツリーから配布物と同じ形の tar.gz を作る。
#
# 4.0/4.1 には eccube:composer:require の --from オプションが無い (4.2 で追加された)。
# そのため 4.2/4.3 版のように composer の path リポジトリ経由では入れられず、
# アーカイブを作って eccube:plugin:install --path= に渡す。
# これはオーナーズストアからアップロードしたときと同じ PluginService::install を通るので、
# 配布経路としてはむしろ実際に近い。
#
# 除外リストは .github/workflows/deploy.yml のパッケージング処理と揃えること。
# ここに入れ忘れたファイルは「開発環境では動くのに配布物では動かない」を生む。
#
# アーカイブ対象を `.` ではなく `*` で渡しているのは PharData の制約による。
# EC-CUBE の PluginService::unpackPluginArchive は tar を PharData で展開するが、
# tar に "./" エントリ (カレントディレクトリ自身) が含まれていると
#   PharException: Cannot extract ".", internal error
# で失敗する。`tar -C dir .` はこのエントリを必ず作るため使えない。
# EC-CUBE 本体のリリース手順 (deploy.yml の `tar cvzf ... ./*`) が glob を
# 使っているのも同じ理由と思われる。
#
# glob なのでトップレベルのドットファイル (.git / .github / .gitignore /
# .env*.tpl) は最初から対象外になる。除外指定を並べていないのはそのため。
make_plugin_archive() {
    (
        cd "${PLUGIN_SRC}"
        tar czf "${PLUGIN_ARCHIVE}" \
            --exclude='node_modules' \
            --exclude='vendor' \
            --exclude='Tests' \
            --exclude='tests' \
            --exclude='docker' \
            --exclude='Dockerfile' \
            --exclude='docker-compose.yml' \
            --exclude='docker-compose.override.yml' \
            --exclude='docker-entrypoint.sh' \
            --exclude='package.json' \
            --exclude='pnpm-lock.yaml' \
            --exclude='playwright.config.ts' \
            --exclude='phpstan.neon.dist' \
            --exclude='phpunit.xml.dist' \
            --exclude='CLAUDE.md' \
            -- *
    )
}

# 導入済み判定はファイルの有無で行う。
#
# 4.2/4.3 版は composer show で見ていたが、CLI 経由 (eccube:plugin:install --path=) で
# 入れると composer.json には載らないため使えない。
#
# 注意: DB は volume に残るのに app/Plugin はコンテナ側にしか無いので、
# コンテナだけ作り直すと「DB にはプラグインが居るのにファイルが無い」状態になる。
# その場合 eccube:plugin:install は checkSamePlugin で落ちる。復旧は docker compose down -v。
if [ -f "${PLUGIN_DIR}/composer.json" ]; then
    echo "${PLUGIN_CODE} plugin already installed; syncing source from ${PLUGIN_SRC}"
    # 開発中にソースを直したら docker compose restart ec-cube で反映できるようにする。
    # 4.0/4.1 はプラグインをアーカイブから app/Plugin へ展開する方式なので、
    # 4.2/4.3 版のように /plugin をそのまま参照してはくれない。
    #
    # 上書きのみでファイルの削除は反映されない。消したファイルを反映したいときや
    # composer.json / Entity を変更したときは docker compose down -v で作り直すこと。
    make_plugin_archive
    tar xzf "${PLUGIN_ARCHIVE}" -C "${PLUGIN_DIR}"
    chown -R www-data: "${PLUGIN_DIR}"
    bin/console cache:clear --no-warmup
else
    echo "Installing ${PLUGIN_CODE} from ${PLUGIN_SRC} (local source)..."
    make_plugin_archive
    bin/console eccube:plugin:install --path="${PLUGIN_ARCHIVE}" || {
        echo "プラグインのインストールに失敗しました。" >&2
        echo "DB (dtb_plugin) とコンテナ内の app/Plugin の状態が食い違っている可能性があります" >&2
        echo "(DB は volume に残るが app/Plugin はコンテナ側にしか無いため)。" >&2
        echo "docker compose down -v で作り直してください。" >&2
        exit 1
    }
    bin/console eccube:plugin:enable --code="${PLUGIN_CODE}" || {
        echo "プラグインの有効化に失敗しました。services.yaml の記述ミスで" >&2
        echo "DI コンテナのコンパイルに失敗しているケースが多いので、上のエラーを確認してください。" >&2
        echo "直したあとは docker compose down -v で作り直すこと" >&2
        echo "(インストール済みの状態が残っていると、この処理自体が次回スキップされる)。" >&2
        exit 1
    }
    bin/console cache:clear --no-warmup
    chown -R www-data: "${PLUGIN_DIR}" "${APACHE_DOCUMENT_ROOT}/var"
    echo "${PLUGIN_CODE} plugin installed and enabled."
fi

echo "--- installed plugin ---"
bin/console doctrine:query:sql \
    "select code, version, enabled from dtb_plugin where code = '${PLUGIN_CODE}'" || true

# Apache 起動
exec "$@"
