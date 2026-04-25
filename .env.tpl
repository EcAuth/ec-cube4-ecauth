# 1Password テンプレートファイル
# 使用方法: op inject -i .env.tpl -o .env

# EcAuth クライアント設定
# CI (.github/workflows/playwright.yml) の注入名と揃えるため CLIENT_ID /
# CLIENT_SECRET (ECAUTH_ プレフィックスなし) を使用する。
ECAUTH_BASE_URL=op://EcAuth/eccube4-ecauth-plugin/base_url
CLIENT_ID=op://EcAuth/eccube4-ecauth-plugin/client_id
CLIENT_SECRET=op://EcAuth/eccube4-ecauth-plugin/client_secret
RP_ID=localhost
