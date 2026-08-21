<?php

namespace Plugin\EcAuthLogin40;

use Eccube\Event\TemplateEvent;
use Plugin\EcAuthLogin40\Service\AdminPasswordLoginPolicy;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class EcAuthLoginEvent implements EventSubscriberInterface
{
    /**
     * @var string
     */
    private $authJsVersion;

    /**
     * @var AdminPasswordLoginPolicy
     */
    private $adminPasswordLoginPolicy;

    public function __construct(
        string $ecauth_auth_js_version,
        AdminPasswordLoginPolicy $adminPasswordLoginPolicy
    ) {
        $this->authJsVersion = $ecauth_auth_js_version;
        $this->adminPasswordLoginPolicy = $adminPasswordLoginPolicy;
    }

    /**
     * @return array
     */
    public static function getSubscribedEvents()
    {
        return [
            '@admin/login.twig' => 'onAdminLoginTwig',
        ];
    }

    public function onAdminLoginTwig(TemplateEvent $event)
    {
        $event->setParameter('ecauth_auth_js_version', $this->authJsVersion);
        // パスワード認証を無効化しているときは、ログインフォームの入力欄を隠して
        // パスキーへ誘導する。あくまで案内であって、実際に認証を拒否するのは
        // Security/AdminPasswordLoginListener（フォームを経由しない POST も塞ぐ）。
        $event->setParameter('ecauth_password_login_disabled', $this->adminPasswordLoginPolicy->isDisabled());
        // login_frame.twig は plugin_snippets を描画しないため、
        // addSnippet() ではなく setSource() でテンプレートソースに直接変更する。
        // login.twig は {% block javascript %} を定義していないので、追加する。
        $source = $event->getSource();
        $source .= '{% block javascript %}{% include "@EcAuthLogin40/admin/login_passkey.twig" %}{% endblock %}';
        $event->setSource($source);
    }
}
