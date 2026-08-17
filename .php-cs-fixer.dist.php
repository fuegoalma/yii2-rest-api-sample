<?php

declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())
    ->in([
        __DIR__ . '/commands',
        __DIR__ . '/components',
        __DIR__ . '/config',
        __DIR__ . '/controllers',
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
        // the config itself: the pre-commit hook passes staged files explicitly
        // and would check it anyway, so `make cs-check` must see it too
        __FILE__,
    ]);

return (new PhpCsFixer\Config())
    // `declare_strict_types` is classified risky because adding the declaration
    // changes runtime behaviour: scalar arguments are no longer coerced at the
    // call sites inside the file. That is exactly what we want it to do, and the
    // full suite is what proves nothing depended on the coercion. No other risky
    // rule is enabled — @PSR12 contains none.
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,
        'declare_strict_types' => true,
    ])
    ->setFinder($finder);
