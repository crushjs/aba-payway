<?php

namespace Crushjs\AbaPayway\Tests;

use Crushjs\AbaPayway\Exceptions\PayWayException;
use Crushjs\AbaPayway\PayWay;
use Crushjs\AbaPayway\Support\RsaEncryptor;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class EndpointsTest extends TestCase
{
    private const MERCHANT = 'ec000002';

    private const KEY = 'test-api-key';

    private static string $publicKey;

    private static \OpenSSLAsymmetricKey $privateKey;

    private FakeHttpClient $http;

    public static function setUpBeforeClass(): void
    {
        // ABA issues 1024-bit keys, which gives the 117-byte chunks in its samples.
        self::$privateKey = openssl_pkey_new(['private_key_bits' => 1024, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::$publicKey = openssl_pkey_get_details(self::$privateKey)['key'];
    }

    private function payway(?string $rsa = null): PayWay
    {
        $this->http = new FakeHttpClient();

        return new PayWay(self::MERCHANT, self::KEY, http: $this->http, rsaPublicKey: $rsa ?? self::$publicKey);
    }

    private function sign(string ...$values): string
    {
        return base64_encode(hash_hmac('sha512', implode('', $values), self::KEY, true));
    }

    private function decrypt(string $payload): array
    {
        $plain = '';

        foreach (str_split(base64_decode($payload), 128) as $block) {
            openssl_private_decrypt($block, $chunk, self::$privateKey);
            $plain .= $chunk;
        }

        return json_decode($plain, true);
    }

    /**
     * @return array{url: string, data: array<string, mixed>, json: bool}
     */
    private function sent(): array
    {
        return $this->http->lastRequest();
    }

    public function test_rsa_encryption_chunks_long_payloads(): void
    {
        $data = ['mc_id' => self::MERCHANT, 'note' => str_repeat('ក', 200)];

        $this->assertSame($data, $this->decrypt((new RsaEncryptor(self::$publicKey))->encrypt(json_encode($data))));
    }

    public function test_rsa_key_may_be_escaped_or_bare(): void
    {
        $escaped = str_replace("\n", '\n', self::$publicKey);
        $bare = preg_replace('/-----[^-]+-----|\s/', '', self::$publicKey);

        $this->assertNotEmpty((new RsaEncryptor($escaped))->encrypt('x'));
        $this->assertNotEmpty((new RsaEncryptor($bare))->encrypt('x'));
    }

    public function test_rsa_endpoints_explain_a_missing_key(): void
    {
        $this->expectException(PayWayException::class);
        $this->expectExceptionMessage('PAYWAY_RSA_PUBLIC_KEY');

        (new PayWay(self::MERCHANT, self::KEY, http: new FakeHttpClient()))->refund('T1', 1);
    }

    public function test_refund(): void
    {
        $this->payway()->refund('INV-1', '0.50');
        ['url' => $url, 'data' => $d] = $this->sent();

        $this->assertStringEndsWith('/online-transaction/refund', $url);
        $this->assertSame(['mc_id' => self::MERCHANT, 'tran_id' => 'INV-1', 'refund_amount' => 0.5], $this->decrypt($d['merchant_auth']));
        $this->assertSame($this->sign($d['request_time'], self::MERCHANT, $d['merchant_auth']), $d['hash']);
    }

    public function test_complete_pre_auth_with_payout(): void
    {
        $payout = [['acc' => '000133879', 'amt' => 1]];
        $this->payway()->completePreAuth('INV-2', 2, $payout);
        ['url' => $url, 'data' => $d] = $this->sent();

        $this->assertStringEndsWith('/pre-auth-completion', $url);
        $this->assertSame(['mc_id' => self::MERCHANT, 'tran_id' => 'INV-2', 'complete_amount' => 2, 'payout' => $payout], $this->decrypt($d['merchant_auth']));
        $this->assertSame($this->sign($d['merchant_auth'], $d['request_time'], self::MERCHANT), $d['hash']);
    }

    public function test_cancel_pre_auth(): void
    {
        $this->payway()->cancelPreAuth('INV-3');
        ['url' => $url, 'data' => $d] = $this->sent();

        $this->assertStringEndsWith('/pre-auth-cancellation', $url);
        $this->assertSame(['mc_id' => self::MERCHANT, 'tran_id' => 'INV-3'], $this->decrypt($d['merchant_auth']));
        $this->assertSame($this->sign(self::MERCHANT, $d['merchant_auth'], $d['request_time']), $d['hash']);
    }

    public function test_transaction_list_signs_only_given_filters_in_order(): void
    {
        $this->payway()->transactionList([
            'from_date' => new DateTimeImmutable('2026-10-01 00:00:00'),
            'to_date' => '2026-10-06 23:59:59',
            'status' => ['APPROVED', 'REFUNDED'],
            'page' => 1,
            'pagination' => 20,
        ]);
        ['url' => $url, 'data' => $d] = $this->sent();

        $this->assertStringEndsWith('/transaction-list-2', $url);
        $this->assertSame('2026-10-01 00:00:00', $d['from_date']);
        $this->assertSame('APPROVED,REFUNDED', $d['status']);
        $this->assertArrayNotHasKey('from_amount', $d);
        $this->assertSame(
            $this->sign($d['req_time'], self::MERCHANT, '2026-10-01 00:00:00', '2026-10-06 23:59:59', 'APPROVED,REFUNDED', '1', '20'),
            $d['hash'],
        );
    }

    public function test_transactions_by_merchant_ref(): void
    {
        $this->payway()->transactionsByMerchantRef('ref00001');
        ['url' => $url, 'data' => $d] = $this->sent();

        $this->assertStringEndsWith('/get-transactions-by-mc-ref', $url);
        $this->assertSame($this->sign($d['req_time'], self::MERCHANT, 'ref00001'), $d['hash']);
    }

    public function test_create_payment_link(): void
    {
        $this->payway()->createPaymentLink([
            'title' => 'Gym plan',
            'amount' => 25,
            'return_url' => 'https://shop.test/hook',
            'merchant_ref_no' => 'ref00001',
            'expired_date' => new DateTimeImmutable('@1893456000'),
            'payout' => [['acc' => '122092016015926', 'amt' => 25]],
        ]);
        ['url' => $url, 'data' => $d, 'json' => $json] = $this->sent();
        $auth = $this->decrypt($d['merchant_auth']);

        $this->assertStringEndsWith('/payment-link/create', $url);
        $this->assertFalse($json);
        $this->assertSame('https://shop.test/hook', base64_decode($auth['return_url']));
        $this->assertSame(1893456000, $auth['expired_date']);
        $this->assertSame('[{"acc":"122092016015926","amt":25}]', $auth['payout']);
        $this->assertSame('USD', $auth['currency']);
        $this->assertSame($this->sign($d['request_time'], self::MERCHANT, $d['merchant_auth']), $d['hash']);
    }

    public function test_payment_link_detail(): void
    {
        $this->payway()->paymentLinkDetail('hEbr4xQbpGQ==');
        ['data' => $d] = $this->sent();

        $this->assertSame(['mc_id' => self::MERCHANT, 'id' => 'hEbr4xQbpGQ=='], $this->decrypt($d['merchant_auth']));
        $this->assertSame($this->sign($d['request_time'], self::MERCHANT, $d['merchant_auth']), $d['hash']);
    }

    public function test_payout_uses_a_hex_hash(): void
    {
        $beneficiaries = [['account' => '200030000', 'amount' => 1], ['account' => '012538302', 'amount' => 2.5]];
        $this->payway()->payout('PO-1', $beneficiaries, customFields: ['invoice' => 'INV-9']);
        ['url' => $url, 'data' => $d] = $this->sent();

        $this->assertStringEndsWith('/direct-payment/merchant/payout', $url);
        $this->assertSame($beneficiaries, $this->decrypt($d['beneficiaries']));
        $this->assertSame('3.5', $d['amount']);
        $this->assertSame(
            hash_hmac('sha512', self::MERCHANT.'PO-1'.$d['beneficiaries'].'3.5'.'{"invoice":"INV-9"}'.'USD', self::KEY),
            $d['hash'],
        );
    }

    public function test_beneficiary_whitelist(): void
    {
        $payway = $this->payway();

        $payway->addBeneficiary('318111358120004');
        ['url' => $url, 'data' => $d] = $this->sent();
        $this->assertStringEndsWith('/add-whitelist-payout', $url);
        $this->assertSame(['mc_id' => self::MERCHANT, 'payee' => '318111358120004'], $this->decrypt($d['merchant_auth']));
        $this->assertSame($this->sign($d['request_time'], $d['merchant_auth']), $d['hash']);

        $payway->updateBeneficiaryStatus('318111358120004', false);
        ['url' => $url, 'data' => $d] = $this->sent();
        $this->assertStringEndsWith('/update-whitelist-status', $url);
        $this->assertSame(0, $this->decrypt($d['merchant_auth'])['status']);
        $this->assertSame($this->sign($d['request_time'], $d['merchant_auth']), $d['hash']);
    }

    public function test_subscription_purchase_signs_token_flag_and_frequency(): void
    {
        $f = $this->payway()->purchaseFields([
            'tran_id' => 'SUB-1', 'amount' => 20, 'ctid' => 'cust00001',
            'token_flag' => 'CITR_FIX', 'frequency' => '1M', 'payment_option' => 'cards',
        ]);

        $this->assertSame(
            $this->sign($f['req_time'], self::MERCHANT, 'SUB-1', '20.00', 'purchase', 'cards', 'USD', 'CITR_FIX', '1M'),
            $f['hash'],
        );
    }

    public function test_link_account(): void
    {
        $this->payway()->linkAccount([
            'ctid' => 'cust00001', 'request_id' => 'req00001', 'token_flag' => 'CITI_FLEX',
            'callback_url' => 'https://shop.test/token',
            'return_deeplink' => ['ios_scheme' => 'shop://done', 'android_scheme' => 'shop://done'],
        ]);
        ['url' => $url, 'data' => $d] = $this->sent();

        $this->assertStringEndsWith('/aof/link-account', $url);
        $this->assertSame('https://shop.test/token', base64_decode($d['callback_url']));
        $this->assertSame(
            $this->sign(self::MERCHANT, $d['request_time'], 'cust00001', $d['return_deeplink'], $d['callback_url'], 'req00001', 'CITI_FLEX', 'USD'),
            $d['hash'],
        );
    }

    public function test_link_card_fields(): void
    {
        $f = $this->payway()->linkCardFields([
            'ctid' => 'cust00001', 'request_id' => 'req00002', 'token_flag' => 'CITO_FLEX',
            'continue_success_url' => 'https://shop.test/cards',
        ]);

        $this->assertSame('https://shop.test/cards', base64_decode($f['continue_success_url']));
        $this->assertSame(
            $this->sign(self::MERCHANT, $f['request_time'], 'cust00001', 'req00002', 'CITO_FLEX', 'USD', $f['continue_success_url']),
            $f['hash'],
        );
        $this->assertStringContainsString('target="aba_link_card"', $this->payway()->linkCardForm([
            'ctid' => 'cust00001', 'request_id' => 'req00002', 'token_flag' => 'CITO_FLEX',
        ]));
    }

    public function test_link_card_returns_the_hosted_page_url(): void
    {
        $http = new FakeHttpClient('', 302, 'https://checkout-sandbox.payway.com.kh/add-card/abc');
        $payway = new PayWay(self::MERCHANT, self::KEY, http: $http);

        $url = $payway->linkCard(['ctid' => 'cust00001', 'request_id' => 'req00002', 'token_flag' => 'CITI_FLEX']);

        $this->assertSame('https://checkout-sandbox.payway.com.kh/add-card/abc', $url);
        $this->assertFalse($http->lastRequest()['json']);
    }

    public function test_missing_endpoints_report_the_environment(): void
    {
        $this->expectExceptionMessage('is not available on https://checkout-sandbox.payway.com.kh');

        (new PayWay(self::MERCHANT, self::KEY, http: new FakeHttpClient('<html>404</html>', 404)))->transactionsByMerchantRef('ref1');
    }

    public function test_credential_ids_are_validated(): void
    {
        $this->expectException(PayWayException::class);

        $this->payway()->linkAccount(['ctid' => 'bad id!', 'request_id' => 'req00001', 'token_flag' => 'CITI_FLEX']);
    }

    public function test_pay_with_token(): void
    {
        $this->payway()->payWithToken([
            'tran_id' => 'TK-1', 'amount' => 5, 'ctid' => 'cust00001', 'pwt' => 'PWT123',
            'token_flag' => 'MITU_FLEX', 'items' => [['name' => 'Ride', 'quantity' => 1, 'price' => 5]],
        ]);
        ['url' => $url, 'data' => $d] = $this->sent();

        $this->assertStringEndsWith('/v3/purchase/payment-credential', $url);
        $this->assertSame(
            $this->sign($d['request_time'], self::MERCHANT, 'TK-1', '5.00', 'USD', $d['items'], 'cust00001', 'PWT123', 'purchase', 'MITU_FLEX'),
            $d['hash'],
        );
    }

    public function test_token_management(): void
    {
        $payway = $this->payway();

        $payway->tokenDetails('req00001');
        $d = $this->sent()['data'];
        $this->assertSame($this->sign(self::MERCHANT, $d['request_time'], 'req00001'), $d['hash']);

        $payway->renewToken('cust00001', 'PWT123', 'req00003');
        $d = $this->sent()['data'];
        $this->assertSame($this->sign('cust00001', $d['request_time'], 'PWT123', self::MERCHANT, 'req00003'), $d['hash']);

        $payway->removeToken('cust00001', 'PWT123');
        ['url' => $url, 'data' => $d] = $this->sent();
        $this->assertStringEndsWith('/token-management/remove-token', $url);
        $this->assertSame($this->sign(self::MERCHANT, 'cust00001', $d['request_time'], 'PWT123'), $d['hash']);
    }
}
