<?php

namespace Crushjs\AbaPayway\Tests;

use Crushjs\AbaPayway\Exceptions\PayWayException;
use Crushjs\AbaPayway\PayWay;
use PHPUnit\Framework\TestCase;

class PayWayTest extends TestCase
{
    private const MERCHANT = 'ec000002';

    private const KEY = 'test-api-key';

    private function payway(?FakeHttpClient $http = null, bool $sandbox = true): PayWay
    {
        return new PayWay(self::MERCHANT, self::KEY, $sandbox, http: $http ?? new FakeHttpClient());
    }

    private function expectedHash(string $data): string
    {
        return base64_encode(hash_hmac('sha512', $data, self::KEY, true));
    }

    public function test_it_uses_sandbox_or_production_urls(): void
    {
        $this->assertSame(
            'https://checkout-sandbox.payway.com.kh/api/payment-gateway/v1/payments/purchase',
            $this->payway()->checkoutUrl(),
        );

        $this->assertSame(
            'https://checkout.payway.com.kh/api/payment-gateway/v1/payments/purchase',
            $this->payway(sandbox: false)->checkoutUrl(),
        );
    }

    public function test_a_full_endpoint_base_url_is_reduced_to_its_host(): void
    {
        $payway = new PayWay(self::MERCHANT, self::KEY, baseUrl: 'https://checkout-sandbox.payway.com.kh/api/payment-gateway/v1/payments/purchase');

        $this->assertSame(PayWay::SANDBOX_URL, $payway->baseUrl());
        $this->assertSame(PayWay::SANDBOX_URL.'/api/payment-gateway/v1/payments/purchase', $payway->checkoutUrl());
    }

    public function test_purchase_fields_are_encoded_and_signed_in_payway_order(): void
    {
        $items = [['name' => 'Coffee', 'quantity' => 2, 'price' => 1.5]];

        $fields = $this->payway()->purchaseFields([
            'req_time' => '20261006120000',
            'tran_id' => 'INV-1001',
            'amount' => 3,
            'items' => $items,
            'firstname' => 'Sok',
            'email' => 'sok@example.com',
            'payment_option' => 'abapay_khqr',
            'return_url' => 'https://shop.test/payway/callback',
        ]);

        $this->assertSame('3.00', $fields['amount']);
        $this->assertSame('USD', $fields['currency']);
        $this->assertSame('purchase', $fields['type']);
        $this->assertSame(self::MERCHANT, $fields['merchant_id']);
        $this->assertSame($items, json_decode(base64_decode($fields['items']), true));
        $this->assertSame('https://shop.test/payway/callback', base64_decode($fields['return_url']));

        $expected = '20261006120000'.self::MERCHANT.'INV-1001'.'3.00'.$fields['items']
            .'Sok'.'sok@example.com'.'purchase'.'abapay_khqr'.$fields['return_url'].'USD';

        $this->assertSame($this->expectedHash($expected), $fields['hash']);
    }

    public function test_khr_amounts_have_no_decimals(): void
    {
        $fields = $this->payway()->purchaseFields(['tran_id' => 'T1', 'amount' => '4000', 'currency' => 'khr']);

        $this->assertSame('4000', $fields['amount']);
        $this->assertSame('KHR', $fields['currency']);
    }

    public function test_it_validates_the_transaction(): void
    {
        $this->expectException(PayWayException::class);

        $this->payway()->purchaseFields(['tran_id' => str_repeat('x', 21), 'amount' => 1]);
    }

    public function test_it_requires_an_amount(): void
    {
        $this->expectException(PayWayException::class);

        $this->payway()->purchaseFields(['tran_id' => 'T1']);
    }

    public function test_checkout_form_escapes_values(): void
    {
        $html = $this->payway()->checkoutForm(['tran_id' => 'T1', 'amount' => 1, 'firstname' => '"><script>']);

        $this->assertStringContainsString('id="aba_merchant_request"', $html);
        $this->assertStringContainsString('target="aba_webservice"', $html);
        $this->assertStringContainsString('&quot;&gt;&lt;script&gt;', $html);
        $this->assertStringNotContainsString('"><script>', $html);
    }

    public function test_check_transaction_sends_signed_json(): void
    {
        $http = new FakeHttpClient(json_encode([
            'data' => ['payment_status_code' => 0, 'payment_status' => 'APPROVED', 'total_amount' => 3],
            'status' => ['code' => '00', 'message' => 'Success!', 'tran_id' => 'INV-1001'],
        ]));

        $response = $this->payway($http)->checkTransaction('INV-1001');
        $request = $http->lastRequest();

        $this->assertSame('https://checkout-sandbox.payway.com.kh/api/payment-gateway/v1/payments/check-transaction-2', $request['url']);
        $this->assertTrue($request['json']);
        $this->assertSame(
            $this->expectedHash($request['data']['req_time'].self::MERCHANT.'INV-1001'),
            $request['data']['hash'],
        );

        $this->assertTrue($response->successful());
        $this->assertTrue($response->isPaid());
        $this->assertSame(3, $response->get('data.total_amount'));
    }

    public function test_is_paid_is_false_for_pending_transactions(): void
    {
        $http = new FakeHttpClient(json_encode([
            'data' => ['payment_status' => 'PENDING'],
            'status' => ['code' => '00', 'message' => 'Success!'],
        ]));

        $this->assertFalse($this->payway($http)->isPaid('INV-1001'));
    }

    public function test_generate_qr_signs_qr_fields(): void
    {
        $http = new FakeHttpClient(json_encode([
            'qrString' => '000201...',
            'status' => ['code' => '0', 'message' => 'Success.'],
        ]));

        $response = $this->payway($http)->generateQr([
            'req_time' => '20261006120000',
            'tran_id' => 'QR-1',
            'amount' => 1,
            'callback_url' => 'https://shop.test/hook',
        ]);

        $data = $http->lastRequest()['data'];

        $this->assertTrue($response->successful());
        $this->assertSame('https://shop.test/hook', base64_decode($data['callback_url']));

        $expected = '20261006120000'.self::MERCHANT.'QR-1'.'1.00'
            .'purchase'.'abapay_khqr'.$data['callback_url'].'USD'.'6'.'template3_color';

        $this->assertSame($this->expectedHash($expected), $data['hash']);
    }

    public function test_exchange_rate_signs_request_time_and_merchant(): void
    {
        $http = new FakeHttpClient();

        $this->payway($http)->exchangeRate();
        $data = $http->lastRequest()['data'];

        $this->assertSame($this->expectedHash($data['req_time'].self::MERCHANT), $data['hash']);
    }

    public function test_non_json_responses_throw(): void
    {
        $this->expectException(PayWayException::class);

        $this->payway(new FakeHttpClient('<html>checkout</html>'))->purchase(['tran_id' => 'T1', 'amount' => 1]);
    }

    public function test_legacy_numeric_status_is_understood(): void
    {
        $response = $this->payway(new FakeHttpClient('{"status":0,"description":"approved"}'))->closeTransaction('T1');

        $this->assertTrue($response->successful());
        $this->assertSame('approved', $response->message());
    }
}
