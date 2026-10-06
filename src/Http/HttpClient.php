<?php

namespace Crushjs\AbaPayway\Http;

interface HttpClient
{
    /**
     * Send a POST request.
     *
     * @param  array<string, mixed>  $data
     * @param  bool  $json  Send as a JSON body instead of multipart/form-data.
     */
    public function post(string $url, array $data, bool $json = false): HttpResponse;
}
