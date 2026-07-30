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
ECAUTH_BASE_URL=op://EcAuth/eccube4-ecauth-plugin/base_url
CLIENT_ID=op://EcAuth/eccube4-ecauth-plugin/client_id
CLIENT_SECRET=op://EcAuth/eccube4-ecauth-plugin/client_secret
RP_ID=localhost
