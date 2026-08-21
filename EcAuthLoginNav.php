<?php

namespace Plugin\EcAuthLogin40;

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
                    'ecauth_login40' => [
                        'name' => 'ecauth_login40.admin.nav.config',
                        'children' => [
                            'ecauth_login40_config' => [
                                'name' => 'ecauth_login40.admin.nav.config.setting',
                                'url' => 'ecauth_login40_admin_config',
                            ],
                            'ecauth_login40_passkey' => [
                                'name' => 'ecauth_login40.admin.nav.config.passkey',
                                'url' => 'ecauth_login40_admin_passkey',
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
