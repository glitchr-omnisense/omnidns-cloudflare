<?php

namespace Omnidns\Cloudflare\Tests;

use Cloudflare\API\Adapter\Adapter;
use Cloudflare\API\Adapter\ResponseException;
use Cloudflare\API\Auth\Auth;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\ResponseInterface;

/**
 * cloudflare/sdk's Guzzle adapter when Cloudflare is not reached: the token in the request's
 * headers, as the adapter sends it. Guzzle's ConnectException escapes the adapter as it is (it is
 * not a RequestException); with $wrapped, a RequestException without a response, wrapped by the
 * adapter in its ResponseException; with $refused, Cloudflare's 403 as the adapter wraps it.
 */
final class FailingAdapter implements Adapter
{
    public function __construct(Auth $auth, string $baseURI, private readonly string $token = '', private readonly bool $wrapped = false, private readonly bool $refused = false)
    {
    }

    /**
     * @param array<mixed> $data
     * @param array<mixed> $headers
     */
    public function get(string $uri, array $data = [], array $headers = []): ResponseInterface
    {
        return $this->send('GET', $uri);
    }

    /**
     * @param array<mixed> $data
     * @param array<mixed> $headers
     */
    public function post(string $uri, array $data = [], array $headers = []): ResponseInterface
    {
        return $this->send('POST', $uri);
    }

    /**
     * @param array<mixed> $data
     * @param array<mixed> $headers
     */
    public function put(string $uri, array $data = [], array $headers = []): ResponseInterface
    {
        return $this->send('PUT', $uri);
    }

    /**
     * @param array<mixed> $data
     * @param array<mixed> $headers
     */
    public function patch(string $uri, array $data = [], array $headers = []): ResponseInterface
    {
        return $this->send('PATCH', $uri);
    }

    /**
     * @param array<mixed> $data
     * @param array<mixed> $headers
     */
    public function delete(string $uri, array $data = [], array $headers = []): ResponseInterface
    {
        return $this->send('DELETE', $uri);
    }

    private function send(string $method, string $uri): never
    {
        $request = new Request($method, 'https://api.cloudflare.com/client/v4/'.$uri, ['Authorization' => 'Bearer '.$this->token]);
        if ($this->refused) {
            throw ResponseException::fromRequestException(new RequestException('Client error: 403 Forbidden', $request, new \GuzzleHttp\Psr7\Response(403, ['Content-Type' => 'application/json'], '{"success": false, "errors": [{"code": 10000, "message": "Authentication error"}]}')));
        }
        if ($this->wrapped) {
            throw ResponseException::fromRequestException(new RequestException('cURL error 28: Operation timed out for https://api.cloudflare.com/client/v4/'.$uri, $request));
        }

        throw new ConnectException('cURL error 6: Could not resolve host: api.cloudflare.com for https://api.cloudflare.com/client/v4/'.$uri, $request);
    }
}
