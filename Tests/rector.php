<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\SetList;

return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->paths([
        __DIR__.'/../Controller',
        __DIR__.'/../Entity',
        __DIR__.'/../Form',
        __DIR__.'/../Repository',
        __DIR__.'/../Service',
        __DIR__.'/../EcAuthLoginEvent.php',
        __DIR__.'/../EcAuthLoginNav.php',
        __DIR__.'/../PluginManager.php',
    ]);

    $rectorConfig->skip([
        __DIR__.'/../vendor',
    ]);

    // PHP 7.4 互換を維持したいので Rector のターゲットも PHP_74 に固定する
    $rectorConfig->phpVersion(\Rector\ValueObject\PhpVersion::PHP_74);

    $rectorConfig->sets([
        SetList::CODE_QUALITY,
        SetList::DEAD_CODE,
    ]);
};
