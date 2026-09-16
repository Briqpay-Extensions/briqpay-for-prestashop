<?php
/**
 * Copyright since 2020 Briqpay AB
 *
 * @author    Briqpay AB <hello@briqpay.com>
 * @copyright Since 2020 Briqpay AB
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */

namespace Briqpay\Payment\Validation;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Outcome of the decision-step validation.
 */
class ValidationResult
{
    /** @var bool */
    private $valid;

    /** @var string */
    private $message;

    /**
     * @param bool   $valid
     * @param string $message
     */
    private function __construct($valid, $message)
    {
        $this->valid = (bool) $valid;
        $this->message = (string) $message;
    }

    /**
     * @return self
     */
    public static function valid()
    {
        return new self(true, '');
    }

    /**
     * @param string $message
     *
     * @return self
     */
    public static function invalid($message)
    {
        return new self(false, $message);
    }

    /**
     * @return bool
     */
    public function isValid()
    {
        return $this->valid;
    }

    /**
     * @return string
     */
    public function getMessage()
    {
        return $this->message;
    }
}
