<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in([
        __DIR__.'/../Controller',
        __DIR__.'/../Entity',
        __DIR__.'/../Form',
        __DIR__.'/../Repository',
        __DIR__.'/../Service',
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
        'trailing_comma_in_multiline' => ['elements' => ['arrays', 'arguments', 'parameters']],
        'blank_line_before_statement' => [
            'statements' => ['return'],
        ],
    ])
    ->setFinder($finder);
