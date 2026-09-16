<?php
/**
 * Copyright since 2020 Briqpay AB
 *
 * @author    Briqpay AB <hello@briqpay.com>
 * @copyright Since 2020 Briqpay AB
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */

namespace Briqpay\Payment;

use Briqpay\Payment\Api\Client;
use Briqpay\Payment\Order\OrderCreator;
use Briqpay\Payment\Order\OrderManager;
use Briqpay\Payment\Repository\OrderRepository;
use Briqpay\Payment\Repository\SessionRepository;
use Briqpay\Payment\Session\SessionManager;
use Briqpay\Payment\Validation\SessionValidator;
use Briqpay\Payment\Webhook\WebhookHandler;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Lazily wires the module's services together.
 *
 * Deliberately hand-rolled rather than using PrestaShop's Symfony container:
 * the front controllers and legacy hooks run outside it, and the module has to
 * work identically on 1.7 and 9.
 */
class ServiceContainer
{
    /** @var \PaymentModule */
    private $module;

    /** @var array<string, object> */
    private $services = [];

    public function __construct(\PaymentModule $module)
    {
        $this->module = $module;
    }

    /**
     * @return Client
     */
    public function getClient()
    {
        return $this->resolve('client', function () {
            return Client::fromConfiguration($this->module->version);
        });
    }

    /**
     * @return SessionManager
     */
    public function getSessionManager()
    {
        return $this->resolve('sessionManager', function () {
            return new SessionManager($this->getClient());
        });
    }

    /**
     * @return OrderCreator
     */
    public function getOrderCreator()
    {
        return $this->resolve('orderCreator', function () {
            return new OrderCreator($this->module, $this->getSessionManager(), $this->getOrderRepository());
        });
    }

    /**
     * @return OrderManager
     */
    public function getOrderManager()
    {
        return $this->resolve('orderManager', function () {
            return new OrderManager($this->getClient(), $this->getOrderRepository());
        });
    }

    /**
     * @return \Briqpay\Payment\Order\PaymentSync
     */
    public function getPaymentSync()
    {
        return $this->resolve('paymentSync', function () {
            return new \Briqpay\Payment\Order\PaymentSync(
                $this->getSessionManager(),
                $this->getOrderRepository()
            );
        });
    }

    /**
     * @return \Briqpay\Payment\Hpp\HostedPageManager
     */
    public function getHostedPageManager()
    {
        return $this->resolve('hostedPageManager', function () {
            return new \Briqpay\Payment\Hpp\HostedPageManager($this->getClient(), $this->getOrderRepository());
        });
    }

    /**
     * @return WebhookHandler
     */
    public function getWebhookHandler()
    {
        return $this->resolve('webhookHandler', function () {
            return new WebhookHandler(
                $this->getSessionManager(),
                $this->getOrderCreator(),
                $this->getOrderRepository(),
                $this->getSessionRepository()
            );
        });
    }

    /**
     * @return SessionValidator
     */
    public function getSessionValidator()
    {
        return $this->resolve('sessionValidator', function () {
            return new SessionValidator();
        });
    }

    /**
     * @return OrderRepository
     */
    public function getOrderRepository()
    {
        return $this->resolve('orderRepository', function () {
            return new OrderRepository();
        });
    }

    /**
     * @return SessionRepository
     */
    public function getSessionRepository()
    {
        return $this->resolve('sessionRepository', function () {
            return new SessionRepository();
        });
    }

    /**
     * @param string   $id
     * @param callable $factory
     *
     * @return object
     */
    private function resolve($id, $factory)
    {
        if (!isset($this->services[$id])) {
            $this->services[$id] = $factory();
        }

        return $this->services[$id];
    }
}
