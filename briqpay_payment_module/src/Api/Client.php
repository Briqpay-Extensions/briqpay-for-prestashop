<?php
/**
 * Copyright since 2020 Briqpay AB
 *
 * @author    Briqpay AB <hello@briqpay.com>
 * @copyright Since 2020 Briqpay AB
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */

namespace Briqpay\Payment\Api;

use Briqpay\Payment\Config\Settings;
use Briqpay\Payment\Support\Logger;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * HTTP client for the Briqpay v3 API.
 *
 * The actual transport is injectable so the request/response mapping can be
 * unit tested without touching the network.
 */
class Client
{
    public const TIMEOUT = 30;
    public const CONNECT_TIMEOUT = 10;
    public const MAX_RETRIES = 2;

    /** @var string */
    private $baseUrl;

    /** @var string */
    private $username;

    /** @var string */
    private $secret;

    /** @var string */
    private $userAgent;

    /** @var callable|null */
    private $transport;

    /**
     * @param string        $baseUrl
     * @param string        $username
     * @param string        $secret
     * @param string        $userAgent
     * @param callable|null $transport function(string $method, string $url, ?string $body, array $headers): array{status:int, body:string, error:?string}
     */
    public function __construct($baseUrl, $username, $secret, $userAgent = '', $transport = null)
    {
        $this->baseUrl = rtrim((string) $baseUrl, '/');
        $this->username = (string) $username;
        $this->secret = (string) $secret;
        $this->userAgent = (string) $userAgent;
        $this->transport = $transport;
    }

    /**
     * Build a client from the module's stored configuration.
     *
     * @param string $moduleVersion
     *
     * @return self
     */
    public static function fromConfiguration($moduleVersion = '')
    {
        $userAgent = sprintf(
            'Briqpay-PrestaShop/%s (PrestaShop %s; PHP %s)',
            $moduleVersion !== '' ? $moduleVersion : 'unknown',
            defined('_PS_VERSION_') ? _PS_VERSION_ : 'unknown',
            PHP_VERSION
        );

        return new self(
            Settings::getApiBaseUrl(),
            Settings::getString(Settings::MERCHANT_ID),
            Settings::getString(Settings::SECRET),
            $userAgent
        );
    }

    /**
     * @param string $path
     *
     * @return array
     *
     * @throws ApiException
     */
    public function get($path)
    {
        return $this->request('GET', $path, null);
    }

    /**
     * @param string $path
     * @param array  $payload
     *
     * @return array
     *
     * @throws ApiException
     */
    public function post($path, array $payload = [])
    {
        return $this->request('POST', $path, $payload);
    }

    /**
     * @param string $path
     * @param array  $payload
     *
     * @return array
     *
     * @throws ApiException
     */
    public function patch($path, array $payload = [])
    {
        return $this->request('PATCH', $path, $payload);
    }

    /**
     * @param string     $method
     * @param string     $path
     * @param array|null $payload
     *
     * @return array Decoded response body (empty array for 204/empty bodies).
     *
     * @throws ApiException
     */
    public function request($method, $path, ?array $payload = null)
    {
        $url = $this->baseUrl . '/' . ltrim((string) $path, '/');
        $body = $payload === null ? null : json_encode($payload);

        if ($payload !== null && $body === false) {
            throw new ApiException('Unable to JSON-encode the Briqpay request payload.');
        }

        $headers = [
            'Authorization: Basic ' . base64_encode($this->username . ':' . $this->secret),
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: ' . $this->userAgent,
        ];

        Logger::debug('API request', ['method' => $method, 'url' => $url, 'payload' => $payload]);

        $result = $this->send($method, $url, $body, $headers);

        $decoded = [];
        if (isset($result['body']) && $result['body'] !== '') {
            $parsed = json_decode($result['body'], true);
            if (is_array($parsed)) {
                $decoded = $parsed;
            }
        }

        $status = isset($result['status']) ? (int) $result['status'] : 0;

        if (!empty($result['error'])) {
            Logger::error('API transport failure', ['method' => $method, 'url' => $url, 'error' => $result['error']]);

            throw new ApiException('Briqpay request failed: ' . $result['error'], $status, $decoded);
        }

        if ($status < 200 || $status >= 300) {
            Logger::error('API error response', ['method' => $method, 'url' => $url, 'status' => $status, 'body' => $decoded]);

            throw new ApiException(
                sprintf('Briqpay responded with HTTP %d for %s %s', $status, $method, $path),
                $status,
                $decoded
            );
        }

        Logger::debug('API response', ['method' => $method, 'url' => $url, 'status' => $status]);

        return $decoded;
    }

    /**
     * Perform the call, retrying idempotent requests on transport-level failures.
     *
     * @param string      $method
     * @param string      $url
     * @param string|null $body
     * @param array       $headers
     *
     * @return array{status:int, body:string, error:string|null}
     */
    private function send($method, $url, $body, array $headers)
    {
        $attempts = $this->isRetryable($method) ? self::MAX_RETRIES + 1 : 1;
        $result = ['status' => 0, 'body' => '', 'error' => 'No transport executed'];

        for ($attempt = 1; $attempt <= $attempts; ++$attempt) {
            $result = $this->transport !== null
                ? call_user_func($this->transport, $method, $url, $body, $headers)
                : $this->curl($method, $url, $body, $headers);

            $failed = !empty($result['error']) || (isset($result['status']) && (int) $result['status'] >= 500);
            if (!$failed || $attempt === $attempts) {
                break;
            }

            // Linear backoff: 200ms, then 400ms.
            usleep(200000 * $attempt);
        }

        return $result;
    }

    /**
     * GET is safe to replay; Briqpay treats session PATCH as idempotent too.
     *
     * @param string $method
     *
     * @return bool
     */
    private function isRetryable($method)
    {
        return in_array(strtoupper($method), ['GET', 'PATCH'], true);
    }

    /**
     * @param string      $method
     * @param string      $url
     * @param string|null $body
     * @param array       $headers
     *
     * @return array{status:int, body:string, error:string|null}
     */
    private function curl($method, $url, $body, array $headers)
    {
        $ch = curl_init();

        // curl_init() can return false -- no memory, curl misconfigured on the
        // host -- and every call below expects a real handle. Caught here
        // instead of left to fatal a few lines down on a call that expected
        // one, so a broken curl install becomes an ApiException on this
        // request rather than a fatal error that takes the whole page with it.
        if ($ch === false) {
            return ['status' => 0, 'body' => '', 'error' => 'Could not initialise a curl handle.'];
        }

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $responseBody = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = $responseBody === false ? curl_error($ch) : null;

        curl_close($ch);

        return [
            'status' => $status,
            'body' => $responseBody === false ? '' : (string) $responseBody,
            'error' => $error,
        ];
    }
}
