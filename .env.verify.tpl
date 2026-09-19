# 1Password テンプレートファイル（検証キー経路の追加分）
#
# オーナーズストアにリリース申請すると検証キー（X-ECCUBE-KEY）が発行される。
# そのキーを使うと `bin/console eccube:composer:require` が実際の package-api と通信して
# パッケージを取得できるため、公開前後のパッケージを本番と同じ配布経路で検証できる。
#
# ## この経路は 4.1 系専用
#
# 4.0 系（Composer v1）では成立しない。packagist.org は 2025-08-01 に Composer v1 向け
# メタデータの提供を終了しており、この経路が通る PluginService::installWithCode() は
# extra.id（= source）が非 0 のとき getPluginRequired() → ComposerService::foreachRequires()
# に入って composer のリポジトリメタデータを引くため。
# 4.0 系の検証は従来どおり tar.gz を eccube:plugin:install --path= で入れること。
#
# 使用方法（.env.tpl と併用し、ECCUBE_VERSION に 4.1 系を指定する）:
#   ECCUBE_VERSION=4.1.2-p5 op run --env-file=.env.tpl --env-file=.env.verify.tpl -- \
#     docker compose up -d --build
#
# バージョンを固定したい場合は非秘密なのでインラインで渡す（省略すると最新が入る）:
#   ECCUBE_VERSION=4.1.2-p5 ECAUTH_PLUGIN_VERSION=1.1.1 \
#     op run --env-file=.env.tpl --env-file=.env.verify.tpl -- docker compose up -d --build
#
# このファイルを読み込まなければ従来どおり /plugin のローカルソースからインストールされる。
#
# 1Password のフィールド名に注意: `eccube_authentication_key` は 43 版（EcAuthLogin43）の
# 検証キー。40 版は別パッケージとして申請したため別のキーが発行されており、`4.0` フィールドに
# 入っている。

ECCUBE_AUTHENTICATION_KEY=op://EcAuth/eccube4-ecauth-plugin/4.0
