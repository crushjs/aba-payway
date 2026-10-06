<?php

namespace Crushjs\AbaPayway\Support;

use Crushjs\AbaPayway\Exceptions\PayWayException;

/**
 * Encrypts payloads with the RSA public key ABA provides, as PayWay expects
 * for `merchant_auth` and payout `beneficiaries`: the plaintext is split into
 * chunks that fit the key (117 bytes for ABA's 1024-bit keys), each chunk is
 * encrypted with PKCS#1 padding, and the concatenated output is base64 encoded.
 */
final class RsaEncryptor
{
    /** @var \OpenSSLAsymmetricKey|resource */
    private $key;

    private int $chunkSize;

    public function __construct(string $publicKey)
    {
        $key = openssl_pkey_get_public(self::normalize($publicKey));

        if ($key === false) {
            throw new PayWayException('The PayWay RSA public key is invalid.');
        }

        $details = openssl_pkey_get_details($key);

        $this->key = $key;
        // PKCS#1 v1.5 padding takes 11 bytes of every block.
        $this->chunkSize = (int) ($details['bits'] / 8) - 11;
    }

    public function encrypt(string $plaintext): string
    {
        $output = '';

        foreach (str_split($plaintext, $this->chunkSize) as $chunk) {
            if (! openssl_public_encrypt($chunk, $encrypted, $this->key)) {
                throw new PayWayException('RSA encryption failed for a PayWay payload chunk.');
            }

            $output .= $encrypted;
        }

        return base64_encode($output);
    }

    /**
     * Accept the key as a PEM string, a PEM with escaped "\n" (as stored in
     * .env), a bare base64 body, or a path to a .pem file.
     */
    private static function normalize(string $key): string
    {
        $key = trim($key);

        if ($key !== '' && ! str_contains($key, '-----BEGIN') && is_file($key)) {
            $key = trim((string) file_get_contents($key));
        }

        $key = str_replace(['\\n', "\r\n"], "\n", $key);

        if (! str_contains($key, '-----BEGIN')) {
            $key = "-----BEGIN PUBLIC KEY-----\n"
                .chunk_split(preg_replace('/\s+/', '', $key), 64, "\n")
                .'-----END PUBLIC KEY-----';
        }

        return $key;
    }
}
