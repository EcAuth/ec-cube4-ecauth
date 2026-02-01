<?php

namespace Plugin\EcAuthLogin43;

use Eccube\Plugin\AbstractPluginManager;
use Plugin\EcAuthLogin43\Entity\Config;
use Psr\Container\ContainerInterface;

class PluginManager extends AbstractPluginManager
{
    public function enable(array $meta, ContainerInterface $container)
    {
        $this->createConfig($container);
    }

    private function createConfig(ContainerInterface $container)
    {
        $entityManager = $container->get('doctrine')->getManager();
        $Config = $entityManager->find(Config::class, 1);
        if ($Config) {
            return;
        }

        $Config = new Config();
        $entityManager->persist($Config);
        $entityManager->flush();
    }
}
