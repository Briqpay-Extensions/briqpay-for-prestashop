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

use Briqpay\Payment\Api\Client;
use Briqpay\Payment\Session\SessionManager;
use Briqpay\Payment\Tests\Support\TestCase;

/**
 * Pins the shape of the decision request.
 *
 * Briqpay refuses a reject that omits rejectionType, and reports it as
 * "data.rejectionType is required" -- which reads as though the field belongs
 * under a data object. It does not: putting it there is silently ignored and
 * the reject fails with a 400 every time, so the shop believes it stopped a
 * purchase that Briqpay never heard about.
 *
 * @covers \Briqpay\Payment\Session\SessionManager
 */
class DecisionPayloadTest extends TestCase
{
    /** @var array<int, array> */
    private $sent = [];

    private function manager(): SessionManager
    {
        $this->sent = [];
        $recorded = [];

        $client = new Client(
            'https://api.test',
            'merchant',
            'secret',
            'test',
            $this->makeTransport(
                [['status' => 204, 'body' => '', 'error' => null]],
                $recorded
            )
        );

        $this->sent = &$recorded;

        return new SessionManager($client);
    }

    /**
     * @return array
     */
    private function lastBody(): array
    {
        self::assertNotEmpty($this->sent, 'no request was sent');

        return json_decode($this->sent[count($this->sent) - 1]['body'], true);
    }

    public function testAllowSendsOnlyTheDecision(): void
    {
        $this->manager()->decide('sess-1', true);

        self::assertSame(['decision' => 'allow'], $this->lastBody());
    }

    public function testRejectCarriesRejectionTypeAtTheTopLevel(): void
    {
        $this->manager()->decide('sess-1', false, 'Please accept the terms.');

        $body = $this->lastBody();

        self::assertSame('reject', $body['decision']);
        self::assertSame(
            SessionManager::REJECT_NOTIFY_USER,
            $body['rejectionType'],
            'rejectionType must sit at the top level of the body'
        );
    }

    /**
     * Nesting it under data is exactly the mistake the API error message leads
     * you into, and it fails silently at runtime.
     */
    public function testRejectionTypeIsNotNestedUnderData(): void
    {
        $this->manager()->decide('sess-1', false, 'nope');

        $body = $this->lastBody();

        self::assertArrayNotHasKey('data', $body);
    }

    public function testRejectionReasonIsSentAsAHardError(): void
    {
        $this->manager()->decide('sess-1', false, 'Cart totals changed.');

        self::assertSame(
            ['message' => 'Cart totals changed.'],
            $this->lastBody()['hardError']
        );
    }

    /**
     * notify_user lets the shopper fix the problem and try again;
     * reject_session_with_error ends the session for good.
     */
    public function testRejectDoesNotKillTheSession(): void
    {
        $this->manager()->decide('sess-1', false, 'nope');

        self::assertNotSame(
            SessionManager::REJECT_SESSION_WITH_ERROR,
            $this->lastBody()['rejectionType']
        );
    }

    public function testRejectWithoutAReasonOmitsTheErrorBlock(): void
    {
        $this->manager()->decide('sess-1', false, '');

        $body = $this->lastBody();

        self::assertSame('reject', $body['decision']);
        self::assertArrayNotHasKey('hardError', $body);
    }
}
