<?php

namespace Plugin\EcAuthLogin43\Service;

/**
 * 管理画面のパスワード認証を無効化するかどうかを保持する。
 *
 * 不正に作成された管理者アカウントによるログインを防ぐための機構。管理画面ユーザーを
 * 勝手に作られてしまう脆弱性を踏んでも、パスワード認証さえ塞いでおけば「作られた
 * アカウントでログインされる」ところまでは進めない（パスキーは EcAuth 側に登録済みの
 * クレデンシャルが無ければ通らないため）。
 *
 * 切り替えを DB（プラグイン設定）ではなく環境変数に置いているのは意図的。設定画面から
 * 変えられると、管理画面を乗っ取られた時点でパスワード認証を再び有効化されてしまい、
 * この機構が守りになっていない。環境変数はアプリケーションの外側にあり、管理画面から
 * 触れないため、乗っ取り後の復帰手段にならない。
 *
 * 同じ理由で「プラグイン未設定なら自動的にパスワード認証を許す」といったフォールバックも
 * 持たない。DB を書き換えられる攻撃者が設定を消すだけでパスワード認証を復活できてしまい、
 * 環境変数に置いた意味が無くなる。パスキーを紛失したときの復旧手段は
 * 「環境変数を無効に戻す」の一本に統一する。
 *
 * EC-CUBE / Symfony に依存しない判定のみを置く。EC-CUBE のカーネルを起動しない
 * phpunit.xml.dist の対象に入れ、Tests/Unit/AdminPasswordLoginPolicyTest.php で
 * 判定表を固定するため（判定を誤ると、フロント会員のログインまで巻き添えで塞ぐか、
 * 逆に管理画面のパスワード認証が塞げていないのに塞げたつもりになる）。
 */
class AdminPasswordLoginPolicy
{
    /**
     * 無効化を指示する環境変数名。実際の解決は
     * Resource/config/services.yaml の bind (%env(...)%) が行う。
     * ここに置いているのは画面・ドキュメントへの表示用。変更時は両方を直すこと。
     */
    public const ENV_NAME = 'ECAUTH_DISABLE_ADMIN_PASSWORD_LOGIN';

    /**
     * @var bool
     */
    private $disabled;

    public function __construct(bool $disabled)
    {
        $this->disabled = $disabled;
    }

    /**
     * 管理画面のパスワード認証が無効化されているか。
     */
    public function isDisabled(): bool
    {
        return $this->disabled;
    }

    /**
     * 認証を拒否すべきかを判定する。
     *
     * 拒否するのは「admin ファイアウォールでの、パスワードによる認証」だけに限る。
     *
     * - パスワード以外の資格情報（パスキーのコールバックで確立するセッション等）は
     *   そもそもこの判定を通らないが、通った場合でも塞がない。無効化したいのは
     *   パスワード認証であって、ログインそのものではない。
     * - admin 以外のファイアウォール（EC-CUBE のフロント会員 = customer）は対象外。
     *   Symfony は CheckPassportEvent のグローバルリスナーを全ファイアウォールの
     *   ディスパッチャに複製する（RegisterGlobalSecurityEventListenersPass）ため、
     *   会員ログインでも本判定が呼ばれる。ここで絞らないと EC サイトの会員が
     *   ログインできなくなる。
     */
    public function shouldReject(bool $hasPasswordCredentials, bool $isAdminFirewall): bool
    {
        return $this->disabled && $hasPasswordCredentials && $isAdminFirewall;
    }
}
