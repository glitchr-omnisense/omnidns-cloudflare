<?php

namespace Omnidns\Cloudflare\Tests;

use Cloudflare\API\Adapter\Adapter;
use Cloudflare\API\Adapter\ResponseException;
use Cloudflare\API\Auth\Auth;
use Cloudflare\API\Auth\None;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Omnidns\Cloudflare\CloudflareFactory;
use Omnidns\Cloudflare\CloudflareProvider;
use Omnidns\Exception\OmnidnsException;
use Omnidns\Exception\ProviderException;
use Omnidns\Exception\UsageLimitReachedException;
use Omnidns\Registry;
use Omnisense\Contracts\Policy\InvalidPolicyException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * CloudflareProvider's usage policy (policy.json) through omnidns's
 * registry: as declared (1,200 requests every 5 minutes), a site's limit
 * counted with the call over it never made, a 429 and its Retry-After read
 * from the SDK's exception and holding every next call, a looser limit
 * refused. No network: an adapter answering in place of the SDK's Guzzle.
 */
final class PolicyTest extends TestCase
{
    /** @var \ArrayObject<int, string> the calls the last adapter made */
    private \ArrayObject $calls;

    public function testThePolicyIsTheOneDeclared(): void
    {
        $policy = $this->registry($this->adapter(200))->policy('cf');

        self::assertSame('cloudflare', $policy->provider);
        self::assertSame('2026-11-10', $policy->provenance->verifiedAt);
        self::assertFileExists(CloudflareProvider::POLICY);
        self::assertSame(['1200/5minute/application/*', '200/second/application/*'], array_map(static fn ($l) => $l->id(), $policy->rateLimits ?? []));
        self::assertSame('1200 every 5 minutes', ($policy->rateLimits ?? [])[0]->describe());
        self::assertTrue($policy->requirements->key);
    }

    public function testASitesLimitIsCountedAndTheCallOverItIsNeverMade(): void
    {
        $adapter = $this->adapter(200);
        $registry = $this->registry($adapter, ['rateLimits' => [['max' => 1, 'window' => 'minute', 'operations' => ['records']]]]);

        self::assertSame([], $registry->dns('cf')->records('example.org'));
        $made = \count($this->calls);
        try {
            $registry->dns('cf')->records('example.org');
            self::fail('The call over the site\'s limit was made.');
        } catch (UsageLimitReachedException $e) {
            self::assertInstanceOf(OmnidnsException::class, $e, 'the family\'s catch holds it');
            self::assertSame('1/minute/application/records', $e->limit?->id());
        }
        self::assertCount($made, $this->calls, 'the refused call reached nothing');
    }

    public function testA429AndItsRetryAfterHoldEveryCallUntilTheWaitIsOver(): void
    {
        $adapter = $this->adapter(429);
        $registry = $this->registry($adapter);

        try {
            $registry->dns('cf')->records('example.org');
            self::fail('A 429 was read as an answer.');
        } catch (UsageLimitReachedException $e) {
            self::assertSame(120, $e->retryAfter);
            self::assertSame('cloudflare answered 429 Too Many Requests: try again in 120 s.', $e->getMessage());
        }
        try {
            $registry->dns('cf')->records('example.net');
            self::fail('A call went out while the provider asked to wait.');
        } catch (UsageLimitReachedException $e) {
            self::assertGreaterThanOrEqual(119, $e->retryAfter);
        }
        self::assertCount(1, $this->calls, 'the wait held the next request back');
    }

    public function testWithoutABackoffA429IsAnErrorAnsweredAsBefore(): void
    {
        $provider = new CloudflareProvider($this->adapter(429));

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('Cloudflare answered 429');
        $provider->records('example.org');
    }

    public function testASiteCannotLoosenThePolicy(): void
    {
        $registry = $this->registry($this->adapter(200), ['rateLimits' => [['max' => 5000, 'window' => 'minute', 'every' => 5]]]);

        $this->expectException(InvalidPolicyException::class);
        $registry->dns('cf');
    }

    /** @param array<string, mixed> $policy */
    private function registry(Adapter $adapter, array $policy = []): Registry
    {
        return new Registry([new CloudflareFactory($adapter)], ['cf' => ['factory' => 'cloudflare', 'options' => ['token' => 'not-a-real-cloudflare-token']] + ([] === $policy ? [] : ['policy' => $policy])]);
    }

    /** An adapter answering a zone and its empty records, or a 429 with Retry-After: 120 to everything. */
    private function adapter(int $status): Adapter
    {
        $adapter = new class(new None(), '') implements Adapter {
            /** @var \ArrayObject<int, string> */
            public \ArrayObject $calls;

            public int $status = 200;

            public function __construct(Auth $auth, string $baseURI)
            {
            }

            /**
             * @param array<mixed> $data
             * @param array<mixed> $headers
             */
            public function get(string $uri, array $data = [], array $headers = []): ResponseInterface
            {
                return $this->answer('GET', $uri);
            }

            /**
             * @param array<mixed> $data
             * @param array<mixed> $headers
             */
            public function post(string $uri, array $data = [], array $headers = []): ResponseInterface
            {
                return $this->answer('POST', $uri);
            }

            /**
             * @param array<mixed> $data
             * @param array<mixed> $headers
             */
            public function put(string $uri, array $data = [], array $headers = []): ResponseInterface
            {
                return $this->answer('PUT', $uri);
            }

            /**
             * @param array<mixed> $data
             * @param array<mixed> $headers
             */
            public function patch(string $uri, array $data = [], array $headers = []): ResponseInterface
            {
                return $this->answer('PATCH', $uri);
            }

            /**
             * @param array<mixed> $data
             * @param array<mixed> $headers
             */
            public function delete(string $uri, array $data = [], array $headers = []): ResponseInterface
            {
                return $this->answer('DELETE', $uri);
            }

            private function answer(string $method, string $uri): ResponseInterface
            {
                $this->calls[] = "$method $uri";
                if (429 === $this->status) {
                    throw ResponseException::fromRequestException(new ClientException('Client error: 429 Too Many Requests', new Request($method, $uri), new Response(429, ['Content-Type' => 'application/json', 'Retry-After' => '120'], '{"success": false, "errors": [{"code": 10000, "message": "Rate limited"}]}')));
                }
                $result = 'zones' === $uri ? [['id' => 'zone-1', 'name' => 'example.org']] : [];

                return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode(['success' => true, 'errors' => [], 'messages' => [], 'result' => $result, 'result_info' => ['page' => 1, 'per_page' => 100, 'total_pages' => 1, 'count' => \count($result), 'total_count' => \count($result)]]));
            }
        };
        $adapter->status = $status;
        $adapter->calls = $this->calls = new \ArrayObject();

        return $adapter;
    }
}
