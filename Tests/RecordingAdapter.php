<?php

namespace Omnidns\Cloudflare\Tests;

use Cloudflare\API\Adapter\Adapter;
use Cloudflare\API\Adapter\ResponseException;
use Cloudflare\API\Auth\Auth;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseInterface;

/** cloudflare/sdk's adapter, answering recorded answers by "METHOD uri?query" and keeping each call. */
final class RecordingAdapter implements Adapter
{
    /** @var array<string, mixed> */
    public array $routes = [];

    /** @var list<array{call: string, data: array<mixed>}> */
    public array $calls = [];

    public function __construct(Auth $auth, string $baseURI)
    {
    }

    /**
     * @param array<mixed> $data
     * @param array<mixed> $headers
     */
    public function get(string $uri, array $data = [], array $headers = []): ResponseInterface
    {
        return $this->answer('GET', $uri.([] === $data ? '' : '?'.http_build_query($data)), []);
    }

    /**
     * @param array<mixed> $data
     * @param array<mixed> $headers
     */
    public function post(string $uri, array $data = [], array $headers = []): ResponseInterface
    {
        return $this->answer('POST', $uri, $data);
    }

    /**
     * @param array<mixed> $data
     * @param array<mixed> $headers
     */
    public function put(string $uri, array $data = [], array $headers = []): ResponseInterface
    {
        return $this->answer('PUT', $uri, $data);
    }

    /**
     * @param array<mixed> $data
     * @param array<mixed> $headers
     */
    public function patch(string $uri, array $data = [], array $headers = []): ResponseInterface
    {
        return $this->answer('PATCH', $uri, $data);
    }

    /**
     * @param array<mixed> $data
     * @param array<mixed> $headers
     */
    public function delete(string $uri, array $data = [], array $headers = []): ResponseInterface
    {
        return $this->answer('DELETE', $uri, $data);
    }

    /** @param array<mixed> $data */
    private function answer(string $method, string $uri, array $data): ResponseInterface
    {
        $call = "$method $uri";
        $this->calls[] = ['call' => $call, 'data' => $data];
        $answer = $this->routes[$call] ?? [404, ['success' => false, 'errors' => [['code' => 7003, 'message' => "not recorded: $call"]], 'messages' => [], 'result' => null]];
        $status = 200;
        if (\is_array($answer) && [0, 1] === array_keys($answer) && \is_int($answer[0])) {
            [$status, $answer] = $answer;
        }
        $response = new Response($status, ['Content-Type' => 'application/json'], (string) json_encode($answer));
        if ($status >= 400) {
            // As the SDK's Guzzle adapter does: Guzzle's exception, turned into the SDK's.
            throw ResponseException::fromRequestException(new ClientException('Client error', new Request($method, $uri), $response));
        }

        return $response;
    }
}
