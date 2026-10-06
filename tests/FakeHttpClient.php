<?php

namespace Crushjs\AbaPayway\Tests;

use Crushjs\AbaPayway\Http\HttpClient;
use Crushjs\AbaPayway\Http\HttpResponse;

final class FakeHttpClient implements HttpClient
{
    /** @var array<int, array{url: string, data: array<string, mixed>, json: bool}> */
    public array $requests = [];

    public function __construct(
        private string $body = '{"status":{"code":"00","message":"Success!"}}',
        private int $status = 200,
        private ?string $redirectUrl = null,
    ) {
    }

    public function post(string $url, array $data, bool $json = false): HttpResponse
    {
        $this->requests[] = compact('url', 'data', 'json');

        return new HttpResponse($this->status, $this->body, $this->redirectUrl);
    }

    /**
     * @return array{url: string, data: array<string, mixed>, json: bool}
     */
    public function lastRequest(): array
    {
        return $this->requests[array_key_last($this->requests)];
    }
}
