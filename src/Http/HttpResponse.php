<?php

namespace Crushjs\AbaPayway\Http;

final class HttpResponse
{
    public function __construct(
        public int $status,
        public string $body,
        public ?string $redirectUrl = null,
    ) {
    }
}
