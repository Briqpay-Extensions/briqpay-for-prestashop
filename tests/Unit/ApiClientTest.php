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

use Briqpay\Payment\Api\ApiException;
use Briqpay\Payment\Api\Client;
use Briqpay\Payment\Tests\Support\TestCase;

/**
 * @covers \Briqpay\Payment\Api\Client
 * @covers \Briqpay\Payment\Api\ApiException
 */
class ApiClientTest extends TestCase
{
    public function testSuccessfulResponseIsDecoded(): void
    {
        $recorded = [];
        $client = $this->makeClient([
            ['status' => 200, 'body' => '{"sessionId":"abc-123"}', 'error' => null],
        ], $recorded);

        self::assertSame(['sessionId' => 'abc-123'], $client->get('/v3/session/abc-123'));
    }

    public function testRequestCarriesBasicAuthAndJsonHeaders(): void
    {
        $recorded = [];
        $client = $this->makeClient([['status' => 200, 'body' => '{}', 'error' => null]], $recorded);

        $client->get('/v3/session/x');

        $headers = $recorded[0]['headers'];

        self::assertContains('Authorization: Basic ' . base64_encode('merchant:secret'), $headers);
        self::assertContains('Content-Type: application/json', $headers);
        self::assertContains('Accept: application/json', $headers);
    }

    public function testPostSendsTheEncodedPayload(): void
    {
        $recorded = [];
        $client = $this->makeClient([['status' => 201, 'body' => '{}', 'error' => null]], $recorded);

        $client->post('/v3/session', ['product' => ['type' => 'payment']]);

        self::assertSame('POST', $recorded[0]['method']);
        self::assertSame('https://api.test/v3/session', $recorded[0]['url']);
        self::assertSame('{"product":{"type":"payment"}}', $recorded[0]['body']);
    }

    public function testGetSendsNoBody(): void
    {
        $recorded = [];
        $client = $this->makeClient([['status' => 200, 'body' => '{}', 'error' => null]], $recorded);

        $client->get('/v3/session/x');

        self::assertNull($recorded[0]['body']);
    }

    public function testEmptyBodyDecodesToAnEmptyArray(): void
    {
        $recorded = [];
        $client = $this->makeClient([['status' => 204, 'body' => '', 'error' => null]], $recorded);

        self::assertSame([], $client->post('/v3/session/x/order/cancel'));
    }

    public function testErrorStatusRaisesAnException(): void
    {
        $recorded = [];
        $client = $this->makeClient([
            ['status' => 422, 'body' => '{"error":{"message":"Amount mismatch"}}', 'error' => null],
        ], $recorded);

        try {
            $client->post('/v3/session', []);
            self::fail('Expected an ApiException.');
        } catch (ApiException $e) {
            self::assertSame(422, $e->getStatusCode());
            self::assertSame('Amount mismatch', $e->getMerchantMessage());
        }
    }

    public function testTransportFailureRaisesAnException(): void
    {
        $recorded = [];
        $client = $this->makeClient([
            ['status' => 0, 'body' => '', 'error' => 'Could not resolve host'],
        ], $recorded);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Could not resolve host');

        $client->post('/v3/session', []);
    }

    /**
     * POST is not replayed: capturing twice because a response was slow would
     * take the shopper's money twice.
     */
    public function testPostIsNotRetried(): void
    {
        $recorded = [];
        $client = $this->makeClient([
            ['status' => 500, 'body' => '', 'error' => null],
            ['status' => 200, 'body' => '{}', 'error' => null],
        ], $recorded);

        try {
            $client->post('/v3/session', []);
        } catch (ApiException $e) {
            // expected
        }

        self::assertCount(1, $recorded, 'POST must be attempted exactly once');
    }

    public function testGetIsRetriedOnServerErrors(): void
    {
        $recorded = [];
        $client = $this->makeClient([
            ['status' => 503, 'body' => '', 'error' => null],
            ['status' => 200, 'body' => '{"ok":true}', 'error' => null],
        ], $recorded);

        self::assertSame(['ok' => true], $client->get('/v3/session/x'));
        self::assertCount(2, $recorded);
    }

    public function testGetStopsRetryingAfterTheLimit(): void
    {
        $recorded = [];
        $client = $this->makeClient([
            ['status' => 500, 'body' => '', 'error' => null],
            ['status' => 500, 'body' => '', 'error' => null],
            ['status' => 500, 'body' => '', 'error' => null],
            ['status' => 200, 'body' => '{}', 'error' => null],
        ], $recorded);

        $this->expectException(ApiException::class);

        try {
            $client->get('/v3/session/x');
        } finally {
            self::assertCount(Client::MAX_RETRIES + 1, $recorded);
        }
    }

    public function testClientErrorsAreNotRetried(): void
    {
        $recorded = [];
        $client = $this->makeClient([
            ['status' => 404, 'body' => '{}', 'error' => null],
            ['status' => 200, 'body' => '{}', 'error' => null],
        ], $recorded);

        try {
            $client->get('/v3/session/missing');
        } catch (ApiException $e) {
            // expected
        }

        self::assertCount(1, $recorded, 'a 404 is a definitive answer');
    }

    /**
     * @dataProvider merchantMessageProvider
     */
    public function testMerchantMessageExtraction(array $body, string $expected): void
    {
        $exception = new ApiException('fallback', 400, $body);

        self::assertSame($expected, $exception->getMerchantMessage());
    }

    /**
     * @return array<string, array{0: array, 1: string}>
     */
    public function merchantMessageProvider(): array
    {
        return [
            'nested error message' => [['error' => ['message' => 'Nested']], 'Nested'],
            'flat message' => [['message' => 'Flat'], 'Flat'],
            'string error' => [['error' => 'Stringy'], 'Stringy'],
            'nothing usable' => [[], 'fallback'],
            'unexpected shape' => [['error' => ['code' => 7]], 'fallback'],
        ];
    }

    /**
     * @param array<int, array> $responses
     */
    private function makeClient(array $responses, array &$recorded): Client
    {
        return new Client(
            'https://api.test',
            'merchant',
            'secret',
            'Briqpay-PrestaShop/2.0.0',
            $this->makeTransport($responses, $recorded)
        );
    }
}
