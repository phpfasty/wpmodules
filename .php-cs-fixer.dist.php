<?php

declare(strict_types=1);

// PHP-CS-Fixer, @Symfony ruleset (same as wp-fasty). `composer cs` checks, `composer cs-fix` applies.
// moduly/lib/core is vendored from phpfasty/moduly (tools/sync-core.sh) and formatted there.
$finder = (new PhpCsFixer\Finder())
    ->in([__DIR__.'/moduly', __DIR__.'/modules', __DIR__.'/tests', __DIR__.'/tools'])
    ->exclude(['lib'])
    ->notPath(['moduly.php']) // WordPress plugin header block, parsed by WordPress
    ->append([__FILE__]);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(false)
    ->setUnsupportedPhpVersionAllowed(true)
    ->setRules([
        '@Symfony' => true,
        'ordered_class_elements' => [
            'order' => ['use_trait', 'case', 'constant', 'property', 'construct', 'destruct', 'magic', 'method_public', 'method_protected', 'method_private'],
            'sort_algorithm' => 'none',
        ],
        // Migration_NNNN_<Name> (ADR-002) and WordPress method names on test doubles (get_col)
        'php_unit_method_casing' => false,
        'phpdoc_summary' => false,
        'phpdoc_to_comment' => ['ignored_tags' => ['var']],
        'phpdoc_no_package' => false,
    ])
    ->setCacheFile(__DIR__.'/.php-cs-fixer.cache')
    ->setFinder($finder);
