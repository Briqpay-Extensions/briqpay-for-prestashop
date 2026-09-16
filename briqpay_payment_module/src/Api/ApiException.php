<?php
/**
 * Copyright since 2020 Briqpay AB
 *
 * @author    Briqpay AB <hello@briqpay.com>
 * @copyright Since 2020 Briqpay AB
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */

namespace Briqpay\Payment\Api;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Raised when Briqpay answers with a non-2xx status or the transport fails.
 */
class ApiException extends \Exception
{
    /** @var int */
    private $statusCode;

    /** @var array */
    private $responseBody;

    /**
     * @param string $message
     * @param int    $statusCode
     * @param array  $responseBody
     */
    public function __construct($message, $statusCode = 0, array $responseBody = [], ?\Exception $previous = null)
    {
        parent::__construct($message, $statusCode, $previous);

        $this->statusCode = (int) $statusCode;
        $this->responseBody = $responseBody;
    }

    /**
     * @return int
     */
    public function getStatusCode()
    {
        return $this->statusCode;
    }

    /**
     * @return array
     */
    public function getResponseBody()
    {
        return $this->responseBody;
    }

    /**
     * Best-effort extraction of the human readable reason Briqpay rejected a call.
     *
     * @return string
     */
    public function getMerchantMessage()
    {
        $body = $this->responseBody;

        foreach ([['error', 'message'], ['message'], ['error']] as $path) {
            $cursor = $body;
            foreach ($path as $segment) {
                if (!is_array($cursor) || !isset($cursor[$segment])) {
                    $cursor = null;
                    break;
                }
                $cursor = $cursor[$segment];
            }

            if (is_string($cursor) && $cursor !== '') {
                return $cursor;
            }
        }

        return $this->getMessage();
    }
}
