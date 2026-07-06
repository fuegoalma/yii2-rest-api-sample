<?php

$finder = (new PhpCsFixer\Finder())
    ->in([
        __DIR__ . '/commands',
        __DIR__ . '/components',
        __DIR__ . '/config',
        __DIR__ . '/controllers',
        __DIR__ . '/mail',
        __DIR__ . '/migrations',
        __DIR__ . '/models',
        __DIR__ . '/tests',
        __DIR__ . '/web',
    ])
    ->exclude([
        'assets',
        '_output',
        '_data',
        '_generated',
    ])
    ->append([
        __DIR__ . '/yii',
    ]);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(false)
    ->setRules([
        '@PSR12' => true,
    ])
    ->setFinder($finder);
