# 1Password テンプレートファイル（検証キー経路の追加分）
#
# オーナーズストアにリリース申請すると検証キー（X-ECCUBE-KEY）が発行される。
# そのキーを使うと `bin/console eccube:composer:require` が実際の package-api と通信して
# 「申請中のパッケージ」を取得できるため、公開前のパッケージを本番と同じ配布経路で
# E2E 検証できる。
#
# 使用方法（.env.tpl と併用する）:
#   op run --env-file=.env.tpl --env-file=.env.verify.tpl -- docker compose up -d --build
#
# バージョンを固定したい場合は非秘密なのでインラインで渡す（値は検証したい
# バージョンに読み替える。省略すると最新が入る）:
#   ECAUTH_PLUGIN_VERSION=1.0.2 op run --env-file=.env.tpl --env-file=.env.verify.tpl -- \
#     docker compose up -d --build
#
# このファイルを読み込まなければ従来どおり /plugin のローカルソースからインストールされる。

ECCUBE_AUTHENTICATION_KEY=op://EcAuth/eccube4-ecauth-plugin/eccube_authentication_key
