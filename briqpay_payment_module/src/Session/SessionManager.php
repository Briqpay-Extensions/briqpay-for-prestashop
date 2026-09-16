<?php
/**
 * Copyright since 2020 Briqpay AB
 *
 * @author    Briqpay AB <hello@briqpay.com>
 * @copyright Since 2020 Briqpay AB
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */

namespace Briqpay\Payment\Session;

use Briqpay\Payment\Api\ApiException;
use Briqpay\Payment\Api\Client;
use Briqpay\Payment\Builder\SessionPayloadBuilder;
use Briqpay\Payment\Config\Settings;
use Briqpay\Payment\Repository\SessionRepository;
use Briqpay\Payment\Support\Logger;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Owns the lifecycle of a Briqpay session for a cart.
 */
class SessionManager
{
    /** Show the shopper the reason and let them correct it and retry. */
    public const REJECT_NOTIFY_USER = 'notify_user';

    /** End the session outright; the shopper cannot retry. */
    public const REJECT_SESSION_WITH_ERROR = 'reject_session_with_error';

    /** @var Client */
    private $client;

    /** @var SessionPayloadBuilder */
    private $payloadBuilder;

    /** @var SessionRepository */
    private $repository;

    public function __construct(
        Client $client,
        ?SessionPayloadBuilder $payloadBuilder = null,
        ?SessionRepository $repository = null
    ) {
        $this->client = $client;
        $this->payloadBuilder = $payloadBuilder !== null ? $payloadBuilder : new SessionPayloadBuilder();
        $this->repository = $repository !== null ? $repository : new SessionRepository();
    }

    /**
     * Return a session usable for the current cart, creating or refreshing it
     * as needed.
     *
     * @param \Context $context
     *
     * @return array Briqpay session resource.
     *
     * @throws ApiException
     */
    public function getOrCreateForCart(\Context $context)
    {
        $cart = $context->cart;
        $customerType = Settings::resolveCustomerType($cart);
        $stored = $this->repository->findByCartId((int) $cart->id);

        if ($stored !== null) {
            $session = $this->tryReuse($context, $stored, $customerType);
            if ($session !== null) {
                return $session;
            }
        }

        return $this->create($context, $customerType);
    }

    /**
     * Reuse a stored session when it is still valid for this cart.
     *
     * @param \Context $context
     * @param array    $stored
     * @param string   $customerType
     *
     * @return array|null Null when the caller should create a fresh session.
     */
    private function tryReuse(\Context $context, array $stored, $customerType)
    {
        $sessionId = (string) $stored['session_id'];

        try {
            $session = $this->read($sessionId);
        } catch (ApiException $e) {
            Logger::info('Stored session could not be read, creating a new one.', [
                'sessionId' => $sessionId,
                'status' => $e->getStatusCode(),
            ]);

            return null;
        }

        // A session that already carries transactions has been paid; it must
        // not be handed back to the buyer for a second purchase.
        if (!empty($session['data']['transactions'])) {
            Logger::info('Stored session is already completed.', ['sessionId' => $sessionId]);

            return null;
        }

        if (isset($session['customerType']) && $session['customerType'] !== $customerType) {
            Logger::info('Customer type changed, creating a new session.', [
                'sessionId' => $sessionId,
                'from' => $session['customerType'],
                'to' => $customerType,
            ]);

            return null;
        }

        $updated = $this->syncIfChanged($context, $sessionId, isset($stored['fingerprint']) ? (string) $stored['fingerprint'] : '');

        return $updated !== null ? $updated : $session;
    }

    /**
     * Create a brand new Briqpay session and persist the mapping.
     *
     * @param \Context $context
     * @param string   $customerType
     *
     * @return array
     *
     * @throws ApiException
     */
    public function create(\Context $context, $customerType)
    {
        $payload = $this->payloadBuilder->buildCreatePayload($context, $customerType);

        \Hook::exec('actionBriqpayCreateSessionBefore', [
            'sessionData' => &$payload,
            'context' => $context,
        ]);

        $session = $this->client->post('/v3/session', $payload);

        if (empty($session['sessionId'])) {
            throw new ApiException('Briqpay did not return a session id.', 0, $session);
        }

        $this->repository->save(
            (int) $context->cart->id,
            (string) $session['sessionId'],
            $customerType,
            $this->payloadBuilder->fingerprint($payload['data'])
        );

        Logger::info('Created Briqpay session.', [
            'sessionId' => $session['sessionId'],
            'cartId' => (int) $context->cart->id,
            'customerType' => $customerType,
        ]);

        return $session;
    }

    /**
     * PATCH the session only when the cart data has actually diverged from what
     * was last sent.
     *
     * @param \Context $context
     * @param string   $sessionId
     * @param string   $knownFingerprint
     *
     * @return array|null The refreshed session, or null when nothing changed.
     */
    public function syncIfChanged(\Context $context, $sessionId, $knownFingerprint = null)
    {
        $payload = $this->payloadBuilder->buildUpdatePayload($context);
        $fingerprint = $this->payloadBuilder->fingerprint($payload['data']);

        if ($knownFingerprint === null) {
            $stored = $this->repository->findByCartId((int) $context->cart->id);
            $knownFingerprint = $stored !== null && isset($stored['fingerprint']) ? (string) $stored['fingerprint'] : '';
        }

        if ($fingerprint === $knownFingerprint && $knownFingerprint !== '') {
            Logger::debug('Session already in sync, skipping update.', ['sessionId' => $sessionId]);

            return null;
        }

        \Hook::exec('actionBriqpayUpdateSessionBefore', [
            'sessionData' => &$payload,
            'context' => $context,
        ]);

        $session = $this->client->patch('/v3/session/' . rawurlencode($sessionId), $payload);
        $this->repository->updateFingerprint((int) $context->cart->id, $fingerprint);

        Logger::info('Updated Briqpay session.', ['sessionId' => $sessionId]);

        return $session;
    }

    /**
     * @param string $sessionId
     *
     * @return array
     *
     * @throws ApiException
     */
    public function read($sessionId)
    {
        return $this->client->get('/v3/session/' . rawurlencode($sessionId));
    }

    /**
     * Answer Briqpay's decision step.
     *
     * @param string $sessionId
     * @param bool   $allow
     * @param string $rejectionMessage
     *
     * @return array
     *
     * @throws ApiException
     */
    public function decide($sessionId, $allow, $rejectionMessage = '')
    {
        if ($allow) {
            $payload = ['decision' => 'allow'];
        } else {
            $payload = [
                'decision' => 'reject',
                // Both fields sit at the top level of the body. Briqpay refuses
                // a reject without rejectionType, and reports it as
                // "data.rejectionType is required" -- which reads like it
                // belongs under data, where it is silently ignored.
                //
                // notify_user shows the reason and lets the shopper put it
                // right and try again; reject_session_with_error would end the
                // session outright, which is far too blunt for a cart that
                // drifted or an unticked checkbox.
                'rejectionType' => self::REJECT_NOTIFY_USER,
            ];

            if ($rejectionMessage !== '') {
                $payload['hardError'] = ['message' => $rejectionMessage];
            }
        }

        \Hook::exec('actionBriqpayDecisionBefore', ['decision' => &$payload]);

        Logger::info('Submitting decision.', ['sessionId' => $sessionId, 'decision' => $payload['decision']]);

        return $this->client->post('/v3/session/' . rawurlencode($sessionId) . '/decision', $payload);
    }

    /**
     * Write the PrestaShop order reference back onto the Briqpay session so the
     * two systems are reconcilable from either side.
     *
     * @param string $sessionId
     * @param string $orderReference
     * @param int    $orderId
     * @param int    $cartId
     *
     * @return void
     */
    public function updateReferences($sessionId, $orderReference, $orderId, $cartId)
    {
        try {
            $this->client->patch('/v3/session/' . rawurlencode($sessionId) . '/order/update/references', [
                'references' => [
                    'reference1' => (string) $orderReference,
                    'reference2' => (string) $orderId,
                    'cartId' => (string) $cartId,
                ],
            ]);
        } catch (ApiException $e) {
            // Never block order creation on a bookkeeping call.
            Logger::error('Failed to write order references back to Briqpay.', [
                'sessionId' => $sessionId,
                'error' => $e->getMerchantMessage(),
            ]);
        }
    }

    /**
     * Extract the merchant id from the session's client token so the admin
     * panel can deep-link into the right Briqpay dashboard account.
     *
     * @param array $session
     *
     * @return string
     */
    public static function extractMerchantId(array $session)
    {
        if (empty($session['clientToken'])) {
            return '';
        }

        $parts = explode('.', (string) $session['clientToken']);
        if (count($parts) < 2) {
            return '';
        }

        $payload = json_decode(self::base64UrlDecode($parts[1]), true);

        return is_array($payload) && isset($payload['merchantId']) ? (string) $payload['merchantId'] : '';
    }

    /**
     * @param string $value
     *
     * @return string
     */
    private static function base64UrlDecode($value)
    {
        $padded = strtr((string) $value, '-_', '+/');
        $remainder = strlen($padded) % 4;

        if ($remainder !== 0) {
            $padded .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode($padded, true);

        return $decoded === false ? '' : $decoded;
    }

    /**
     * @return SessionRepository
     */
    public function getRepository()
    {
        return $this->repository;
    }
}
