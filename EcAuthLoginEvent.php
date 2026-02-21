<?php

namespace Plugin\EcAuthLogin43;

use Eccube\Event\TemplateEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class EcAuthLoginEvent implements EventSubscriberInterface
{
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
        // login_frame.twig は plugin_snippets を描画しないため、
        // addSnippet() ではなく setSource() でテンプレートソースに直接変更する。
        // login.twig は {% block javascript %} を定義していないので、追加する。
        $source = $event->getSource();
        $source .= '{% block javascript %}{% include "@EcAuthLogin43/admin/login_passkey.twig" %}{% endblock %}';
        $event->setSource($source);
    }
}
