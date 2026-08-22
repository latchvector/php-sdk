<?php

declare(strict_types=1);

namespace LatchVector\Sso\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use LatchVector\Sso\Exception\AccessDeniedException;
use LatchVector\Sso\Exception\RateLimitException;
use LatchVector\Sso\SsoClient;
use PHPUnit\Framework\TestCase;

/**
 * `raiseCustomAlert` is a thin wrapper over the same `send()` helper every
 * other JSON endpoint uses, so this proves it wires the request correctly
 * and that a rejection maps through the SDK's existing typed exceptions —
 * not that the HTTP/error-mapping machinery itself works (that's covered
 * elsewhere).
 */
final class SsoClientTest extends TestCase
{
    private function clientWithHistory(array $responses, array &$history): SsoClient
    {
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));
        $http = new Client(['handler' => $stack]);

        return new SsoClient('https://sso.example.test', 'my-app', 2, $http);
    }

    public function testRaiseCustomAlertPostsWithBearerAuthAndResolvesOn202(): void
    {
        $history = [];
        $client = $this->clientWithHistory([new Response(202)], $history);

        $client->raiseCustomAlert(
            'machine.tok',
            'payment_failed',
            'Payment failed for order #4471',
            'WARNING',
            ['orderId' => '4471'],
        );

        self::assertCount(1, $history);
        $request = $history[0]['request'];
        self::assertSame('POST', $request->getMethod());
        self::assertSame('/api/alerts/custom', $request->getUri()->getPath());
        self::assertSame('Bearer machine.tok', $request->getHeaderLine('Authorization'));

        $body = json_decode((string) $request->getBody(), true);
        self::assertSame([
            'type' => 'payment_failed',
            'title' => 'Payment failed for order #4471',
            'severity' => 'WARNING',
            'metadata' => ['orderId' => '4471'],
        ], $body);
    }

    public function testRaiseCustomAlertOmitsNullSeverityAndMetadataFromTheBody(): void
    {
        $history = [];
        $client = $this->clientWithHistory([new Response(202)], $history);

        $client->raiseCustomAlert('machine.tok', 'payment_failed', 'Payment failed');

        $body = json_decode((string) $history[0]['request']->getBody(), true);
        self::assertSame(['type' => 'payment_failed', 'title' => 'Payment failed'], $body);
    }

    public function testRaiseCustomAlertWithoutAlertRaiseMapsToAccessDenied(): void
    {
        $history = [];
        $client = $this->clientWithHistory(
            [new Response(403, [], json_encode(['error' => 'access_denied']))],
            $history,
        );

        $this->expectException(AccessDeniedException::class);
        $client->raiseCustomAlert('machine.tok', 'payment_failed', 'Payment failed');
    }

    public function testRaiseCustomAlertPastTheRateLimitMapsToRateLimitException(): void
    {
        $history = [];
        $client = $this->clientWithHistory(
            array_fill(0, 3, new Response(429, [], json_encode(['error' => 'too_many_requests']))),
            $history,
        );

        $this->expectException(RateLimitException::class);
        $client->raiseCustomAlert('machine.tok', 'payment_failed', 'Payment failed');
    }
}
