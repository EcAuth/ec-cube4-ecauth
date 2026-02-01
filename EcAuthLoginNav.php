<?php

namespace Plugin\EcAuthLogin43;

use Eccube\Common\EccubeNav;

class EcAuthLoginNav implements EccubeNav
{
    /**
     * @return array
     */
    public static function getNav()
    {
        return [
            'setting' => [
                'children' => [
                    'ecauth_login43' => [
                        'name' => 'ecauth_login43.admin.nav.config',
                        'children' => [
                            'ecauth_login43_config' => [
                                'name' => 'ecauth_login43.admin.nav.config.setting',
                                'url' => 'ecauth_login43_admin_config',
                            ],
                            'ecauth_login43_passkey' => [
                                'name' => 'ecauth_login43.admin.nav.config.passkey',
                                'url' => 'ecauth_login43_admin_passkey',
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
