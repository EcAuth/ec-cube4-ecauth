<?php

namespace Plugin\EcAuthLogin40\Security;

use Plugin\EcAuthLogin40\Service\AdminPasswordLoginPolicy;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security\FirewallMap;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Core\Security;

/**
 * 管理画面のパスワード認証が無効化されているとき、パスワードによる認証を拒否する。
 *
 * ログインフォームの見た目（Resource/template/admin/login_passkey.twig が入力欄を隠す）
 * は案内でしかなく、実際に塞いでいるのはここ。curl 等でフォームを経由せずに
 * POST されても同じように弾く必要がある。
 *
 * ## 4.2/4.3 版との実装の違い
 *
 * 4.2/4.3 版は `CheckPassportEvent`（Symfony の認証パイプライン）に割り込んでいたが、
 * あれは Symfony 5.1 で入った新しい認証システムの仕組みで、EC-CUBE 4.0 / 4.1 が使う
 * Symfony 3.4 / 4.4 には存在しない。この系統の管理画面ログインは security.yaml の
 * `form_login`（UsernamePasswordFormAuthenticationListener）で処理される。
 *
 * そこで 40 版は `kernel.request` に入り、ファイアウォールが動く前にログイン POST を
 * 弾く。狙い（フォームを経由しない POST も塞ぐ・ユーザー列挙を許さない）は同じ。
 *
 * ## 優先度 10 の理由
 *
 *   32 RouterListener   ここで `_route` が決まる。判定に使うので後に置く必要がある
 * → 10 本リスナー
 *    8 Firewall         認証処理。ここに入る前に止めたい
 *
 * `%eccube_admin_route%` はサイトごとに変更できるため、パスではなくルート名で判定する
 * （パス判定はカスタマイズ済みサイトで素通りする）。EC-CUBE 本体の security.yaml が
 * admin ファイアウォールの `check_path` にルート名 `admin_login` を指定しており、
 * Symfony 側も HttpUtils::checkRequestPath() で同じルート名との一致を見ている。
 * つまり「ログイン試行かどうか」の判定条件は本体と同じものを使っている。
 *
 * ## ユーザー列挙を許さない
 *
 * ファイアウォールに入る前に一律で拒否するため、`login_id` が存在するかどうかで
 * 応答が変わらない。ユーザーの解決もパスワードのハッシュ計算も走らない。
 *
 * ## 4.2/4.3 版との挙動差（意図的）
 *
 * - CSRF トークンの検証より前に拒否する。4.2/4.3 版は CSRF 検証の後だった。
 *   無効化されている状況ではどのみち全て拒否するため、順序の違いに実害はない。
 * - Symfony の login_throttling（試行回数制限）は 5.2 以降の機能で 4.0 / 4.1 には無い。
 *   このため「試行制限に先に当たる」という 4.2/4.3 版の挙動はそもそも起こらない。
 */
class AdminPasswordLoginListener implements EventSubscriberInterface
{
    /**
     * EC-CUBE 本体の app/config/eccube/packages/security.yaml が定義する
     * 管理画面向けファイアウォールの名前。4.0 〜 4.4 で変わっていない。
     * プラグインは既に Service/PasskeyAuthService でも同じ前提（_security_admin）に
     * 依存している。
     */
    public const ADMIN_FIREWALL = 'admin';

    /**
     * admin ファイアウォールの form_login が check_path に指定しているルート名。
     * 4.0 / 4.1 の security.yaml で `check_path: admin_login` として固定されている。
     */
    public const LOGIN_CHECK_ROUTE = 'admin_login';

    /**
     * security.yaml の `username_parameter`。ログに残す試行 ID の取得に使う。
     */
    public const USERNAME_PARAMETER = 'login_id';

    /**
     * ルーティング解決(32)の後、ファイアウォール(8)の前。詳細はクラスの docblock を参照。
     */
    public const PRIORITY = 10;

    /**
     * @var AdminPasswordLoginPolicy
     */
    private $policy;

    /**
     * @var FirewallMap
     */
    private $firewallMap;

    /**
     * @var UrlGeneratorInterface
     */
    private $urlGenerator;

    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(
        AdminPasswordLoginPolicy $policy,
        FirewallMap $firewallMap,
        UrlGeneratorInterface $urlGenerator,
        LoggerInterface $logger
    ) {
        $this->policy = $policy;
        $this->firewallMap = $firewallMap;
        $this->urlGenerator = $urlGenerator;
        $this->logger = $logger;
    }

    /**
     * @return array
     */
    public static function getSubscribedEvents()
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', self::PRIORITY],
        ];
    }

    /**
     * Symfony 4.4 が渡す RequestEvent は GetResponseEvent を継承しているため、
     * この型宣言のまま 3.4 / 4.4 の両方で受け取れる。
     */
    public function onKernelRequest(GetResponseEvent $event): void
    {
        if (!$event->isMasterRequest()) {
            return;
        }

        $request = $event->getRequest();

        if (!$this->policy->shouldReject($this->isPasswordLogin($request), $this->isAdminFirewall($request))) {
            return;
        }

        // 何が起きたか分からないまま「パスワードが違う」と表示されるのを避けるため、
        // 試行された login_id とともに記録する。ここに来る時点でユーザーの解決は
        // まだ行っていないので、値は送信された文字列そのもの（秘密ではない）。
        $this->logger->warning('Rejected admin password login (password login is disabled)', [
            'user_identifier' => $this->extractUserIdentifier($request),
        ]);

        $event->setResponse($this->rejectionResponse($request));
    }

    /**
     * 管理画面のログインフォームへの送信かどうか。
     *
     * ルート名で判定する理由はクラスの docblock を参照。
     */
    private function isPasswordLogin(Request $request): bool
    {
        return $request->isMethod('POST')
            && $request->attributes->get('_route') === self::LOGIN_CHECK_ROUTE;
    }

    /**
     * リクエストが admin ファイアウォール配下かどうか。
     *
     * ルート名だけでも実質的に確定するが、EC サイト側を巻き込んでいないことを
     * ファイアウォール名で明示的に確かめる。判定できない場合は false を返す。
     * ここで true に倒すと、分からない状況でフロント会員のログインまで塞ぐ側に倒れる。
     */
    private function isAdminFirewall(Request $request): bool
    {
        $config = $this->firewallMap->getFirewallConfig($request);

        return $config !== null && $config->getName() === self::ADMIN_FIREWALL;
    }

    /**
     * ログイン画面へ戻し、拒否した理由を表示させる。
     *
     * EC-CUBE の管理画面ログイン (Eccube\Controller\Admin\AdminController::login) は
     * Symfony の AuthenticationUtils::getLastAuthenticationError() が返す例外を
     * `error` としてテンプレートへ渡し、login.twig が
     * `error.messageKey|trans(error.messageData, 'validators')` で描画する。
     * 通常の認証失敗と同じ経路に載せるため、セッションへ認証エラーとして積む。
     * 文言は Resource/locale/validators.ja.yaml 側に置くこと。
     */
    private function rejectionResponse(Request $request): RedirectResponse
    {
        if ($request->hasSession()) {
            $request->getSession()->set(
                Security::AUTHENTICATION_ERROR,
                new CustomUserMessageAuthenticationException('ecauth_login40.admin.login.password_disabled')
            );
        }

        return new RedirectResponse($this->urlGenerator->generate(self::LOGIN_CHECK_ROUTE));
    }

    /**
     * ログに残す試行 ID を取り出す。
     *
     * **必ず長さを切り詰めること。** 本リスナーはファイアウォールの手前で拒否するため、
     * Symfony が `UsernamePasswordFormAuthenticationListener` で課している
     * `Security::MAX_USERNAME_LENGTH` の検査をまだ通っていない。切り詰めないと、
     * 未認証の POST に載ってきた文字列（`post_max_size` の上限まで）がそのまま
     * ログに書かれ、繰り返されるとログとディスクが膨らむ。CSRF 検証も
     * ログイン試行回数の制限（4.0/4.1 には login_throttling 自体が無い）も
     * この時点では効かないので、抑止するものが他に無い。
     *
     * 4.2/4.3 版は `UserBadge::getUserIdentifier()` から取っており、値を受け取る
     * 時点で Symfony 側が同じ上限で弾いているため、この手当ては要らなかった。
     * `kernel.request` で塞ぐ方式に変えたぶん、ここは自前で守る必要がある。
     */
    private function extractUserIdentifier(Request $request): ?string
    {
        $value = $request->request->get(self::USERNAME_PARAMETER);

        if (!is_string($value)) {
            return null;
        }

        return substr($value, 0, Security::MAX_USERNAME_LENGTH);
    }
}
