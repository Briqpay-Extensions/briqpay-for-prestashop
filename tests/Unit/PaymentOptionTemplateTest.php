<?php
/**
 * Copyright since 2020 Briqpay AB
 *
 * @author    Briqpay AB <hello@briqpay.com>
 * @copyright Since 2020 Briqpay AB
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */

declare(strict_types=1);

namespace Briqpay\Payment\Tests\Unit;

use Briqpay\Payment\Tests\Support\TestCase;

/**
 * Guards the shape PrestaShop demands of a payment option's form.
 *
 * Everything handed to PaymentOption::setForm() is passed through
 * PaymentOptionFormDecorator::addHiddenSubmitButton(), which does:
 *
 *     $forms = $doc->getElementsByTagName('form');
 *     if ($forms->length !== 1) {
 *         return false;
 *     }
 *
 * A payload with no form element -- or with two -- is therefore replaced by
 * `false`, the theme's `{if $option.form}` is falsy, and the payment option
 * renders with an empty body. There is no error, no log line and no exception:
 * the iframe simply never appears.
 *
 * That shipped once. These tests fail loudly if the wrapper is ever removed.
 */
class PaymentOptionTemplateTest extends TestCase
{
    /** @var string Template markup, with Smarty comments removed. */
    private $template;

    protected function setUp(): void
    {
        parent::setUp();

        $path = __DIR__ . '/../../briqpay_payment_module/views/templates/front/payment_iframe.tpl';

        self::assertFileExists($path, 'the payment option template is missing');

        // Strip {* ... *} blocks: the template's own comments discuss <form>
        // elements, and only the emitted markup is what PrestaShop parses.
        $this->template = (string) preg_replace(
            '/\{\*.*?\*\}/s',
            '',
            (string) file_get_contents($path)
        );
    }

    public function testTemplateContainsExactlyOneFormElement(): void
    {
        self::assertSame(
            1,
            preg_match_all('/<form\b/i', $this->template),
            'PrestaShop discards the payment form unless it holds exactly one <form> element'
        );

        self::assertSame(
            1,
            preg_match_all('/<\/form>/i', $this->template),
            'the single form element must be closed exactly once'
        );
    }

    public function testTheFormWrapsTheBriqpayContainer(): void
    {
        $formStart = strpos($this->template, '<form');
        $container = strpos($this->template, 'id="briqpay-checkout"');
        $formEnd = strpos($this->template, '</form>');

        self::assertIsInt($formStart);
        self::assertIsInt($container);
        self::assertIsInt($formEnd);

        self::assertTrue(
            $formStart < $container && $container < $formEnd,
            'the Briqpay container must sit inside the form, or the decorator drops it'
        );
    }

    public function testTemplateExposesTheHooksTheScriptDependsOn(): void
    {
        foreach ([
            'id="briqpay-checkout-form"',
            'id="briqpay-checkout"',
            'id="briqpay-checkout-notice"',
            'id="briqpay-checkout-frame"',
            'data-briqpay-session',
        ] as $needle) {
            self::assertStringContainsString(
                $needle,
                $this->template,
                'briqpay_checkout.js looks this up by id or attribute'
            );
        }
    }

    /**
     * The snippet is injected unescaped, which is correct -- it is Briqpay's
     * own markup -- but every value the module supplies around it must be
     * escaped.
     */
    public function testOnlyTheBriqpaySnippetIsUnescaped(): void
    {
        preg_match_all('/\{\$(\w+)(?:\|[^}]*)?\s*(nofilter)?\s*\}/', $this->template, $matches, PREG_SET_ORDER);

        self::assertNotEmpty($matches, 'expected smarty variables in the template');

        foreach ($matches as $match) {
            $variable = $match[1];
            $isRaw = isset($match[2]) && $match[2] === 'nofilter';

            if ($variable === 'briqpaySnippet') {
                self::assertTrue($isRaw, 'the Briqpay snippet must be output unescaped');
                continue;
            }

            self::assertFalse(
                $isRaw,
                sprintf('$%s is output unescaped; only briqpaySnippet may be', $variable)
            );
        }
    }
}
