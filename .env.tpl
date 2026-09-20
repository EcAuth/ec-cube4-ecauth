# 1Password テンプレートファイル
#
# 平文 .env を作らず、op run でサブプロセスの環境変数としてのみ注入する。
#
# このテンプレートが持つのは E2E（Playwright）が staging の EcAuth に繋ぐための値で、
# CI (.github/workflows/playwright.yml) と同じ ecauth-staging-app アイテムから取得する。
# docker compose 自体が消費するのは ECCUBE_AUTHENTICATION_KEY / ECAUTH_PLUGIN_VERSION /
# ECAUTH_ALLOWED_HOSTS だけなので、staging に繋がないなら op run は不要
# （docker compose up -d --build だけで起動できる）。
#
# staging のホスト名は web_app_suffix から合成する。op run は変数展開より先に
# シークレット置換を行うため、テンプレート内では合成できない。合成は bash -c の
# サブシェルで行う（EcAuth リポジトリのマイグレーション実行例と同じパターン）。
#
#   # Docker 環境の起動（E2E で staging の EcAuth を設定に保存できるよう許可ホストを合成する）
#   op run --env-file=.env.tpl -- bash -c '
#     ECAUTH_ALLOWED_HOSTS=".ec-auth.io,ecauth-staging-${WEB_APP_SUFFIX}.azurewebsites.net" \
#     docker compose up -d --build'
#
#   # E2E の実行
#   op run --env-file=.env.tpl -- bash -c '
#     ECAUTH_BASE_URL="https://ecauth-staging-${WEB_APP_SUFFIX}.azurewebsites.net" \
#     BASE_URL=https://localhost:8081 \
#     pnpm exec playwright test'
#
# 既定ではプラグインは /plugin（このリポジトリのワーキングツリー）からインストールされる。
# オーナーズストアに申請中のパッケージを package-api 経由で検証する場合は
# .env.verify.tpl を併用する。
#
# ECAUTH_ALLOWED_HOSTS に .azurewebsites.net のようなサフィックス指定はしないこと。
# 共有ホスティングのサフィックスを許可すると、そのサービスの全利用者を信頼することになる
# （EcAuthDocs #101）。上の例のように「完全なホスト名」を足す。

# EcAuth クライアント設定
# CI の注入名と揃えるため CLIENT_ID / CLIENT_SECRET (ECAUTH_ プレフィックスなし) を使用する。
CLIENT_ID=op://EcAuth/ecauth-staging-app/client_id
CLIENT_SECRET=op://EcAuth/ecauth-staging-app/client_secret
# staging EcAuth のホスト名 ecauth-staging-${WEB_APP_SUFFIX}.azurewebsites.net の可変部分
WEB_APP_SUFFIX=op://EcAuth/ecauth-staging-app/web_app_suffix
RP_ID=localhost
