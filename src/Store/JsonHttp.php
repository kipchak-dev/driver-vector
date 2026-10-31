<?php

declare(strict_types=1);

namespace Kipchak\Driver\Vector\Store;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Kipchak\Driver\Vector\Exception\VectorConnectionException;
use Kipchak\Driver\Vector\Exception\VectorException;

/**
 * JSON requests to Qdrant or OpenSearch through the HTTP driver's client. Connection failures are retried
 * $retries times; the store's own errors are not.
 */
final class JsonHttp
{
    private const int JSON = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

    private readonly string $baseUrl;

    /**
     * @param array<string, string> $headers Sent with every request, such as an API key.
     * @param array<string, mixed> $options Guzzle options, such as TLS settings and basic auth.
     * @param Closure(Response): VectorException $error Builds the exception for an error response.
     */
    public function __construct(
        private readonly Factory $http,
        string $baseUrl,
        private readonly string $store,
        private readonly Closure $error,
        private readonly array $headers = [],
        private readonly array $options = [],
        private readonly float $timeout = 10.0,
        private readonly float $connectTimeout = 2.0,
        private readonly int $retries = 1,
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /**
     * @param array<string, mixed>|null $json Encoded with empty arrays as {} where $json is an object.
     * @param array<string, scalar> $query
     * @return array<string, mixed> The decoded body; empty for an empty body.
     */
    public function request(string $method, string $path, array|object|null $json = null, array $query = [], ?string $rawBody = null, string $contentType = 'application/json'): array
    {
        $response = $this->send($method, $path, $json, $query, $rawBody, $contentType);
        if ($response->failed()) {
            throw ($this->error)($response);
        }
        if ($response->body() === '') {
            return [];
        }

        $body = json_decode($response->body(), true, 512, JSON_BIGINT_AS_STRING);

        return is_array($body) ? $body : [];
    }

    /**
     * The response, whatever its status, for callers that read the status themselves (such as HEAD requests).
     */
    public function send(string $method, string $path, array|object|null $json = null, array $query = [], ?string $rawBody = null, string $contentType = 'application/json'): Response
    {
        $url = $this->baseUrl . $path . ($query === [] ? '' : '?' . http_build_query($query));
        $request = $this->pending();
        if ($json !== null) {
            $request = $request->withBody(json_encode($json, self::JSON), 'application/json');
        } elseif ($rawBody !== null) {
            $request = $request->withBody($rawBody, $contentType);
        }

        try {
            return $request->send($method, $url);
        } catch (ConnectionException $exception) {
            throw new VectorConnectionException(
                $this->store . ' is unreachable at ' . $this->baseUrl . ': ' . $exception->getMessage(),
                previous: $exception,
            );
        }
    }

    private function pending(): PendingRequest
    {
        $request = $this->http
            ->acceptJson()
            ->withHeaders($this->headers)
            ->timeout($this->timeout)
            ->connectTimeout($this->connectTimeout);

        if ($this->options !== []) {
            $request = $request->withOptions($this->options);
        }
        if ($this->retries > 0) {
            $request = $request->retry(
                $this->retries + 1,
                100,
                static fn (\Throwable $exception): bool => $exception instanceof ConnectionException,
                throw: false,
            );
        }

        return $request;
    }
}
