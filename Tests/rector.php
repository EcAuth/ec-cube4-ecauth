<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\SetList;

return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->paths([
        __DIR__.'/../Controller',
        __DIR__.'/../Entity',
        __DIR__.'/../Form',
        __DIR__.'/../Http',
        __DIR__.'/../Repository',
        __DIR__.'/../Security',
        __DIR__.'/../Service',
        __DIR__.'/../EcAuthLoginEvent.php',
        __DIR__.'/../EcAuthLoginNav.php',
        __DIR__.'/../PluginManager.php',
    ]);

    $rectorConfig->skip([
        __DIR__.'/../vendor',
    ]);

    // EC-CUBE 4.0 は PHP 7.1.3 以上をサポートするため、Rector のターゲットも PHP_71 に固定する。
    // これを上げると、7.1 で動かない構文へのリファクタが提案されてしまう。
    $rectorConfig->phpVersion(\Rector\ValueObject\PhpVersion::PHP_71);

    $rectorConfig->sets([
        SetList::CODE_QUALITY,
        SetList::DEAD_CODE,
    ]);
};
