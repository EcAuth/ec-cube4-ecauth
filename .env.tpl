# 1Password テンプレートファイル
#
# 使用方法（平文 .env を作らず、サブプロセスの環境変数としてのみ注入する）:
#   op run --env-file=.env.tpl -- docker compose up -d --build
#
# 既定ではプラグインは /plugin（このリポジトリのワーキングツリー）からインストールされる。
# オーナーズストアに申請中のパッケージを package-api 経由で検証する場合は
# .env.verify.tpl を併用する。

# EcAuth クライアント設定
# CI (.github/workflows/playwright.yml) の注入名と揃えるため CLIENT_ID /
# CLIENT_SECRET (ECAUTH_ プレフィックスなし) を使用する。
# EcAuth Base URL として許可するホスト（カンマ区切り、非秘密）。
# プラグイン既定は .ec-auth.io のみ。ec-auth.io 配下に無い dev / staging の
# EcAuth に繋ぐ場合は、ここに「完全なホスト名」を追記する（EcAuthDocs #101）。
#   例: ECAUTH_ALLOWED_HOSTS=.ec-auth.io,ecauth-dev-xxxxx.example.net
# .azurewebsites.net のようなサフィックス指定にはしないこと。共有ホスティングの
# サフィックスを許可すると、そのサービスの全利用者を信頼することになる。
ECAUTH_ALLOWED_HOSTS=.ec-auth.io
ECAUTH_BASE_URL=op://EcAuth/eccube4-ecauth-plugin/base_url
CLIENT_ID=op://EcAuth/eccube4-ecauth-plugin/client_id
CLIENT_SECRET=op://EcAuth/eccube4-ecauth-plugin/client_secret
RP_ID=localhost
