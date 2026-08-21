<?php

namespace Plugin\EcAuthLogin43\Security;

use Plugin\EcAuthLogin43\Service\AdminPasswordLoginPolicy;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security\FirewallMap;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;

/**
 * 管理画面のパスワード認証が無効化されているとき、パスワードによる認証を拒否する。
 *
 * ログインフォームの見た目（Resource/template/admin/login_passkey.twig が入力欄を隠す）
 * は案内でしかなく、実際に塞いでいるのはここ。curl 等でフォームを経由せずに
 * POST されても同じように弾く必要がある。
 *
 * ## なぜ CheckPassportEvent なのか
 *
 * ルートやパスではなく、Symfony の認証パイプラインそのものに割り込む。
 * `%eccube_admin_route%` は変更できる（ログイン URL は site ごとに違う）ため、
 * パスで判定するとカスタマイズ済みサイトで素通りする。CheckPassportEvent なら
 * 「admin ファイアウォールでパスワードによる認証が行われようとしている」という
 * 事実そのものを捕まえられる。
 *
 * ## 優先度 300 の理由（Symfony 5.4 / 6.4 / 7.x で同じ並び）
 *
 *   2080 LoginThrottlingListener  … 試行回数の制限。先に通す（総当たりは従来どおり絞る）
 *   1024 UserProviderListener     … UserBadge に user loader を差すだけ
 *    512 CsrfProtectionListener   … CSRF 検証。先に通す
 *  → 300 本リスナー
 *    256 UserCheckerListener      … ここで初めて $passport->getUser() が実行される
 *      0 CheckCredentialsListener … パスワードのハッシュ検証
 *
 * UserCheckerListener より前に置くのが要点。ここでユーザーを解決させてしまうと、
 * 存在しない login_id は UserNotFoundException（表示は「Bad credentials」）、
 * 存在する login_id は「パスワード認証は無効です」となり、応答の differences から
 * login_id の存在を判別できてしまう（ユーザー列挙）。解決前に一律で拒否すれば、
 * どの login_id でも同じ応答になる。ハッシュ計算も走らない。
 *
 * ## リスナーは全ファイアウォールで呼ばれる
 *
 * Symfony はグローバルに登録された CheckPassportEvent リスナーを、全ファイアウォールの
 * ディスパッチャへ複製する（SecurityBundle の RegisterGlobalSecurityEventListenersPass）。
 * つまり EC サイトのフロント会員ログイン（customer ファイアウォール）でも本リスナーが
 * 動く。ファイアウォール名で絞らないと、会員が誰もログインできなくなる。
 */
class AdminPasswordLoginListener implements EventSubscriberInterface
{
    /**
     * EC-CUBE 本体の app/config/eccube/packages/security.yaml が定義する
     * 管理画面向けファイアウォールの名前。4.2 〜 4.4 で変わっていない。
     * プラグインは既に Service/PasskeyAuthService でも同じ前提（_security_admin）に
     * 依存している。
     */
    public const ADMIN_FIREWALL = 'admin';

    /**
     * CSRF 検証(512)の後、ユーザー解決(256)の前。詳細はクラスの docblock を参照。
     */
    public const PRIORITY = 300;

    /**
     * @var AdminPasswordLoginPolicy
     */
    private $policy;

    /**
     * @var FirewallMap
     */
    private $firewallMap;

    /**
     * @var RequestStack
     */
    private $requestStack;

    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(
        AdminPasswordLoginPolicy $policy,
        FirewallMap $firewallMap,
        RequestStack $requestStack,
        LoggerInterface $logger
    ) {
        $this->policy = $policy;
        $this->firewallMap = $firewallMap;
        $this->requestStack = $requestStack;
        $this->logger = $logger;
    }

    /**
     * @return array
     */
    public static function getSubscribedEvents()
    {
        return [
            CheckPassportEvent::class => ['onCheckPassport', self::PRIORITY],
        ];
    }

    public function onCheckPassport(CheckPassportEvent $event): void
    {
        $passport = $event->getPassport();

        if (!$this->policy->shouldReject($passport->hasBadge(PasswordCredentials::class), $this->isAdminFirewall())) {
            return;
        }

        // 何が起きたか分からないまま「パスワードが違う」と表示されるのを避けるため、
        // 試行された login_id とともに記録する。ここに来る時点でユーザーの解決は
        // まだ行っていないので、値は送信された文字列そのもの（秘密ではない）。
        $this->logger->warning('Rejected admin password login (password login is disabled)', [
            'user_identifier' => $this->extractUserIdentifier($event),
        ]);

        // login.twig は error.messageKey|trans(..., 'validators') で描画するため、
        // 文言は Resource/locale/validators.ja.yaml 側に置く。
        throw new CustomUserMessageAuthenticationException('ecauth_login43.admin.login.password_disabled');
    }

    /**
     * 認証中のリクエストが admin ファイアウォール配下かどうか。
     *
     * ファイアウォールの外（CLI 等）で認証が起きることは無いが、リクエストが取れない
     * 場合は admin と断定できないため false を返す。ここで true に倒すと、
     * 判定できない状況で会員ログインまで巻き添えで塞ぐ側に倒れる。
     */
    private function isAdminFirewall(): bool
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request === null) {
            return false;
        }

        $config = $this->firewallMap->getFirewallConfig($request);

        return $config !== null && $config->getName() === self::ADMIN_FIREWALL;
    }

    private function extractUserIdentifier(CheckPassportEvent $event): ?string
    {
        $passport = $event->getPassport();
        if (!$passport->hasBadge(UserBadge::class)) {
            return null;
        }

        /** @var UserBadge $badge */
        $badge = $passport->getBadge(UserBadge::class);

        return $badge->getUserIdentifier();
    }
}
