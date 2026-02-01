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
        $event->addSnippet('@EcAuthLogin43/admin/login_passkey.twig');
    }
}
