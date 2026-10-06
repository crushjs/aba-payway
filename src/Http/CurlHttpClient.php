<?php

namespace Crushjs\AbaPayway\Http;

use Crushjs\AbaPayway\Exceptions\PayWayException;

final class CurlHttpClient implements HttpClient
{
    public function __construct(
        private int $timeout = 30,
    ) {
    }

    public function post(string $url, array $data, bool $json = false): HttpResponse
    {
        $handle = curl_init($url);

        $headers = ['Accept: application/json'];

        if ($json) {
            $headers[] = 'Content-Type: application/json';
            $body = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } else {
            // An array body makes cURL send multipart/form-data, which PayWay expects.
            $body = array_map(static fn ($value) => $value instanceof \CURLFile ? $value : (string) $value, $data);
        }

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        $responseBody = curl_exec($handle);

        if ($responseBody === false) {
            $error = curl_error($handle);
            curl_close($handle);

            throw new PayWayException("PayWay request failed: {$error}");
        }

        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $redirectUrl = curl_getinfo($handle, CURLINFO_REDIRECT_URL) ?: null;
        curl_close($handle);

        return new HttpResponse($status, (string) $responseBody, $redirectUrl);
    }
}
