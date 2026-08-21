<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in([
        __DIR__.'/../Controller',
        __DIR__.'/../Entity',
        __DIR__.'/../Form',
        __DIR__.'/../Http',
        __DIR__.'/../Repository',
        __DIR__.'/../Security',
        __DIR__.'/../Service',
        __DIR__.'/Unit',
    ])
    ->append([
        __DIR__.'/../EcAuthLoginEvent.php',
        __DIR__.'/../EcAuthLoginNav.php',
        __DIR__.'/../PluginManager.php',
    ])
    ->name('*.php');

return (new PhpCsFixer\Config())
    ->setRules([
        '@PSR12' => true,
        'array_syntax' => ['syntax' => 'short'],
        'no_unused_imports' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'single_quote' => true,
        // 末尾カンマは arrays のみ許可する。
        // arguments (関数呼び出し) は PHP 7.3+、parameters (関数定義) は PHP 8.0+ の構文で、
        // このプラグインが対象にする PHP 7.1 では Parse error になる。
        'trailing_comma_in_multiline' => ['elements' => ['arrays']],
        'blank_line_before_statement' => [
            'statements' => ['return'],
        ],
    ])
    ->setFinder($finder);
