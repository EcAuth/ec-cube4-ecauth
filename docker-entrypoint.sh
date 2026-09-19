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

# オーナーズストアの検証キー (X-ECCUBE-KEY) を dtb_base_info に書き込む。
#
# package-api と通信する経路でのみ必要。EC-CUBE は認証キーを DB
# (dtb_base_info.authentication_key) から読むため、環境変数を渡すだけでは効かない。
# 管理画面から手で入れる代わりに、ここで一度だけ設定する。
#
# 値はログに出さない (検証キーは秘密情報)。
set_authentication_key() {
    php <<'PHP'
<?php
// DATABASE_URL 例: postgresql://eccube:password@postgres:5432/eccube_db
$url = parse_url((string) (isset($_SERVER['DATABASE_URL']) ? $_SERVER['DATABASE_URL'] : ''));
$key = (string) (isset($_SERVER['ECCUBE_AUTHENTICATION_KEY']) ? $_SERVER['ECCUBE_AUTHENTICATION_KEY'] : '');

if (!is_array($url) || !isset($url['host'], $url['path']) || $key === '') {
    fwrite(STDERR, "authentication_key を設定できません (DATABASE_URL または ECCUBE_AUTHENTICATION_KEY が不正です)\n");
    exit(1);
}

// 43 版は str_starts_with() を使っているが、このコンテナの PHP は 7.4 なので使えない。
$scheme = isset($url['scheme']) ? (string) $url['scheme'] : 'postgresql';
$driver = strpos($scheme, 'mysql') === 0 ? 'mysql' : 'pgsql';
$dsn = sprintf(
    '%s:host=%s;port=%d;dbname=%s',
    $driver,
    $url['host'],
    isset($url['port']) ? $url['port'] : ($driver === 'mysql' ? 3306 : 5432),
    ltrim($url['path'], '/')
);

$pdo = new PDO(
    $dsn,
    rawurldecode((string) (isset($url['user']) ? $url['user'] : '')),
    rawurldecode((string) (isset($url['pass']) ? $url['pass'] : '')),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// dtb_base_info は単一行運用。値はプレースホルダで渡し SQL 文字列に埋め込まない。
$pdo->prepare('UPDATE dtb_base_info SET authentication_key = ?')->execute([$key]);

fwrite(STDOUT, "authentication_key を設定しました (値は出力しません)\n");
PHP
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
    if [ -n "${ECCUBE_AUTHENTICATION_KEY:-}" ]; then
        # package-api から入れた配布物を検証している最中なので、ワーキングツリーで
        # 上書きしてはいけない。上書きすると「配布物を検証した」と言えなくなる。
        echo "${PLUGIN_CODE} plugin already installed (package-api); ソースの同期はしない"
    else
        echo "${PLUGIN_CODE} plugin already installed; syncing source from ${PLUGIN_SRC}"
        # 開発中にソースを直したら docker compose restart ec-cube で反映できるようにする。
        # 4.0/4.1 はプラグインをアーカイブから app/Plugin へ展開する方式なので、
        # 4.2/4.3 版のように /plugin をそのまま参照してはくれない。
        #
        # 上書きのみでファイルの削除は反映されない。消したファイルを反映したいときや
        # composer.json / Entity を変更したときは docker compose down -v で作り直すこと。
        make_plugin_archive
        tar xzf "${PLUGIN_ARCHIVE}" -C "${PLUGIN_DIR}"
    fi
elif [ -n "${ECCUBE_AUTHENTICATION_KEY:-}" ]; then
    # オーナーズストア (package-api) 経由。公開前・公開後のパッケージを実際の
    # 配布経路どおりに検証したいときに使う。
    #
    # この経路は PluginService::installWithCode() を通り、composer.json の
    # extra.id (= source) が非 0 なので getPluginRequired() →
    # ComposerService::foreachRequires() に入る。つまり composer のリポジトリ
    # メタデータを引く。**4.1 系専用**と考えること。4.0 系は Composer v1 で
    # packagist のメタデータ提供が終了しており、この経路は成立しない
    # (詳細は .env.verify.tpl と CLAUDE.md)。
    echo "Installing ${PLUGIN_CODE} from package-api (version: ${ECAUTH_PLUGIN_VERSION:-latest})..."
    set_authentication_key
    if [ -n "${ECAUTH_PLUGIN_VERSION:-}" ]; then
        bin/console eccube:composer:require ec-cube/ecauthlogin40 "${ECAUTH_PLUGIN_VERSION}"
    else
        bin/console eccube:composer:require ec-cube/ecauthlogin40
    fi
    bin/console eccube:plugin:enable --code="${PLUGIN_CODE}" || {
        echo "プラグインの有効化に失敗しました。" >&2
        echo "package-api からの取得自体は成功しているので、app/Plugin/${PLUGIN_CODE} の" >&2
        echo "中身と dtb_plugin の状態を確認してください。" >&2
        exit 1
    }
    echo "${PLUGIN_CODE} plugin installed from package-api and enabled."
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
    echo "${PLUGIN_CODE} plugin installed and enabled."
fi

echo "--- installed plugin ---"
bin/console doctrine:query:sql \
    "select code, version, enabled from dtb_plugin where code = '${PLUGIN_CODE}'" || true

# Apache を起動する前に、CLI 側でコンテナを作り切り、結果を検証してから www-data へ渡す。
#
# EccubeExtension::prepend() は dtb_plugin を読んで「無効なプラグイン」の一覧を
# eccube.plugins.disabled に入れ、PluginPass がその名前空間のサービスから
# doctrine.repository_service 以外の全タグを剥がす。ここで問題になるのは
# prepend() が **DB に接続できなかったときに app/Plugin のディレクトリ一覧を
# そのまま無効扱いにして早期 return する** こと。その状態でコンパイルされた
# コンテナが残ると
#
#   - dtb_plugin.enabled は t
#   - なのに kernel.event_subscriber が剥がれていて TemplateEvent が発火しない
#   - 表に出る症状は「ログイン画面にパスキーのボタンが出ない」だけ
#
# という極めて分かりにくい状態になる。しかも **偶発的にしか起きない**
# (同じ手順で起動し直すと再現しないことがある)。4.1 系の CI で先に踏んだが、
# 条件はバージョンに依存しないので 4 系のどれでも起こりうる。
#
# そのため「作って終わり」にせず、コンパイル結果を確認してリトライする。
# clear だけで済ませないこと。clear しただけだと最初のコンパイルが Apache
# (www-data) 側で走り、CLI とは環境変数もパーミッションも違う状態になる。
warm_container() {
    bin/console cache:clear --no-warmup
    bin/console cache:warmup --no-optional-warmers
}

# コンパイル済みコンテナがプラグインを有効と見なしているか。
container_sees_plugin_enabled() {
    ! bin/console debug:container --parameter=eccube.plugins.disabled 2>/dev/null \
        | grep -q "${PLUGIN_CODE}"
}

for attempt in 1 2 3; do
    warm_container
    if container_sees_plugin_enabled; then
        break
    fi
    echo "warning: ${PLUGIN_CODE} は dtb_plugin では有効だが、コンパイル済みコンテナでは" >&2
    echo "         無効扱いになっている (attempt ${attempt}/3)。キャッシュを作り直す。" >&2
done

if ! container_sees_plugin_enabled; then
    echo "error: ${PLUGIN_CODE} が eccube.plugins.disabled に残ったままです。" >&2
    echo "       この状態では PluginPass にイベント購読を剥がされ、設定画面もパスキーも動きません。" >&2
    echo "       静かに壊れたまま起動させないため、ここで停止します。" >&2
    exit 1
fi

chown -R www-data: "${PLUGIN_DIR}" "${APACHE_DOCUMENT_ROOT}/var"

# Apache 起動
exec "$@"
