<?php
/**
 * Copyright since 2020 Briqpay AB
 *
 * @author    Briqpay AB <hello@briqpay.com>
 * @copyright Since 2020 Briqpay AB
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 *
 * Coding standard, aligned with PrestaShop's own module conventions.
 */

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__ . '/briqpay_payment_module')
    ->in(__DIR__ . '/tests')
    ->append([__FILE__])
    ->name('*.php')
    ->exclude(['vendor', 'node_modules']);

return (new PhpCsFixer\Config())
    // The module targets PHP 7.2+, but the toolchain runs on whatever the
    // developer or CI has installed.
    ->setUnsupportedPhpVersionAllowed(true)
    ->setRiskyAllowed(false)
    ->setRules([
        '@PSR12' => true,
        'array_syntax' => ['syntax' => 'short'],
        'binary_operator_spaces' => ['default' => 'single_space'],
        'blank_line_after_opening_tag' => false,
        'blank_line_before_statement' => [
            'statements' => ['return', 'throw', 'try'],
        ],
        'cast_spaces' => ['space' => 'single'],
        'concat_space' => ['spacing' => 'one'],
        'no_extra_blank_lines' => true,
        'no_trailing_whitespace' => true,
        'no_unused_imports' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'phpdoc_align' => ['align' => 'vertical'],
        'phpdoc_indent' => true,
        'phpdoc_separation' => true,
        'phpdoc_trim' => true,
        'single_quote' => true,
        'trailing_comma_in_multiline' => ['elements' => ['arrays']],
        'whitespace_after_comma_in_array' => true,
    ])
    ->setFinder($finder);
