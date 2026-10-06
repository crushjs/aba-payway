# ABA PayWay for Laravel

Pay as You Wish in Cambodia. A Laravel package for the [ABA PayWay](https://www.payway.com.kh) payment gateway. It covers every API in the [PayWay developer docs](https://developer.payway.com.kh):

- **Checkout:** cards, ABA Pay, KHQR, WeChat, Alipay and Google Pay
- **Transactions:** status, details, lists, closing a transaction, exchange rates
- **Money back:** refunds, plus completing and cancelling pre-authorisations
- **ABA QR API:** KHQR codes for your own UI
- **Payment links**
- **Payouts:** and the beneficiary whitelist
- **Credentials on file:** saving ABA accounts and cards, charging a saved token, and subscriptions

> This is a community package. It isn't made by or affiliated with ABA Bank.

## Installation

```bash
composer require crushjs/aba-payway
```

Laravel finds the service provider and the `PayWay` facade on its own. To publish the config file:

```bash
php artisan vendor:publish --tag=payway-config
```

Add the credentials ABA sent you to `.env`. Sandbox and production use different keys:

```dotenv
PAYWAY_MERCHANT_ID=ec000002
PAYWAY_API_KEY=your-public-key
PAYWAY_SANDBOX=true

# Only needed for refunds, pre-auth, payment links and payouts.
# Accepts the PEM text (escaped \n is fine), its base64 body, or a path to a .pem file.
PAYWAY_RSA_PUBLIC_KEY="-----BEGIN PUBLIC KEY-----\nMIGfMA0...\n-----END PUBLIC KEY-----"
```

PayWay only accepts API calls from domains and IPs it has whitelisted. Ask the PayWay integration team to add yours, or every call fails with `6: wrong domain`.

## Responses

Every API call returns a `Crushjs\AbaPayway\Response`:

```php
$response = PayWay::checkTransaction('INV-1001');

$response->successful();               // PayWay status code "00" or "0"
$response->message();                  // PayWay's status message
$response->paymentStatus();            // APPROVED | PENDING | DECLINED | REFUNDED | CANCELLED ...
$response->isPaid();                   // successful() && APPROVED
$response->get('data.total_amount');   // read a value with dot notation
$response->toArray();
```

The package sets `req_time`/`request_time` and `merchant_id`, computes the `hash`, and does the base64 and RSA encoding each endpoint requires. You pass plain values: arrays for `items`, `custom_fields`, `payout` and `return_deeplink`, and plain URLs for `return_url` and `callback_url`. A failed HTTP request throws `Crushjs\AbaPayway\Exceptions\PayWayException`.

## Checkout

### 1. Build the signed form (controller)

```php
use Crushjs\AbaPayway\Laravel\Facades\PayWay;

public function checkout(Order $order)
{
    $form = PayWay::checkoutForm([
        'tran_id'        => $order->reference,      // unique, max 20 chars
        'amount'         => $order->total,          // 12.5 becomes "12.50" (KHR: no decimals)
        'currency'       => 'USD',                  // USD | KHR
        'firstname'      => $order->first_name,
        'lastname'       => $order->last_name,
        'email'          => $order->email,
        'phone'          => $order->phone,
        'payment_option' => 'abapay_khqr',          // cards | abapay_khqr | abapay_khqr_deeplink | wechat | alipay | google_pay
        'items'          => [['name' => 'Coffee', 'quantity' => 2, 'price' => 6.25]],
        'return_url'     => route('payway.callback'),
        'cancel_url'     => route('orders.show', $order),
        'continue_success_url' => route('orders.thanks', $order),
        // Optional: type ('pre-auth'), shipping, lifetime (minutes), custom_fields, return_params,
        // payout, return_deeplink, skip_success_page, view_type, payment_gate, additional_params
    ]);

    return view('checkout', compact('form'));
}
```

`PayWay::purchaseFields([...])` returns the signed fields as an array if you'd rather build the form yourself. `PayWay::checkoutUrl()` returns the URL the form posts to.

### 2. Open the PayWay sheet (Blade)

```blade
{!! $form !!}

<button id="checkout_button">Pay with ABA PayWay</button>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://checkout.payway.com.kh/plugins/checkout2-0.js"></script>
<script>
    $('#checkout_button').on('click', () => AbaPayway.checkout());
</script>
```

To show the QR or deeplink in your own UI instead, call PayWay from the server. This works with `abapay_khqr_deeplink`:

```php
$response = PayWay::purchase([...same params..., 'payment_option' => 'abapay_khqr_deeplink']);
$response->get('qr_string');
$response->get('abapay_deeplink');
$response->get('checkout_qr_url');
```

### 3. Confirm the payment (callback)

PayWay POSTs to your `return_url` once the payment finishes. The posted body can't be verified, so ask PayWay for the real status:

```php
Route::post('/payway/callback', function (Request $request) {
    $order = Order::where('reference', $request->input('tran_id'))->firstOrFail();

    if (PayWay::isPaid($order->reference)) {
        $order->markAsPaid();
    }

    return response()->json(['ok' => true]);
})->name('payway.callback');
```

PayWay doesn't send a CSRF token, so exclude this route from CSRF checks in `bootstrap/app.php`:

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->validateCsrfTokens(except: ['payway/callback']);
})
```

## Transactions

```php
PayWay::checkTransaction('INV-1001');      // payment status
PayWay::isPaid('INV-1001');                // bool
PayWay::transactionDetail('INV-1001');     // full details and operation history
PayWay::closeTransaction('INV-1001');      // stop an unpaid transaction from being paid
PayWay::exchangeRate();

PayWay::transactionList([
    'from_date'  => now()->subDays(2),     // DateTimeInterface or 'Y-m-d H:i:s'; max range 3 days
    'to_date'    => now(),
    'status'     => ['APPROVED', 'REFUNDED'],
    'from_amount' => 1, 'to_amount' => 100,
    'page' => 1, 'pagination' => 40,       // max 1000 per page
]);

PayWay::transactionsByMerchantRef('ref00001');   // not available on the sandbox
```

## Refunds and pre-auth

These calls need `PAYWAY_RSA_PUBLIC_KEY`.

```php
PayWay::refund('INV-1001', 5.00);                 // full or partial refund

// Start a pre-auth with checkoutForm([... 'type' => 'pre-auth']), then:
PayWay::completePreAuth('INV-1002', 20.00);
PayWay::completePreAuth('INV-1002', 20.00, payout: [['acc' => '000133879', 'amt' => 20]]);
PayWay::cancelPreAuth('INV-1002');
```

## ABA QR API

```php
$qr = PayWay::generateQr([
    'tran_id'           => 'INV-1003',
    'amount'            => 5,
    'currency'          => 'USD',
    'payment_option'    => 'abapay_khqr',       // abapay_khqr | wechat | alipay
    'callback_url'      => route('payway.callback'),
    'lifetime'          => 6,                   // minutes (3 minutes to 120 days)
    'qr_image_template' => 'template3_color',
]);

$qr->get('qrString');   // raw KHQR string
$qr->get('qrImage');    // data:image/png;base64,...
```

## Payment links

These calls need `PAYWAY_RSA_PUBLIC_KEY`.

```php
$link = PayWay::createPaymentLink([
    'title'           => 'Gym membership',
    'amount'          => 25,
    'currency'        => 'USD',
    'return_url'      => route('payway.callback'),
    'description'     => 'October',
    'payment_limit'   => 1,
    'expired_date'    => now()->addWeek(),      // or null for no expiry
    'merchant_ref_no' => 'GYM-OCT-42',
], imagePath: storage_path('app/gym.png'));     // image is optional

PayWay::paymentLinkDetail($link->get('data.id'));
```

## Payouts

These calls need `PAYWAY_RSA_PUBLIC_KEY`.

```php
PayWay::addBeneficiary('318111358120004');                 // ABA account or MID
PayWay::updateBeneficiaryStatus('318111358120004', false); // disable

PayWay::payout('PO-0001', [
    ['account' => '200030000', 'amount' => 1.72],
    ['account' => '012538302', 'amount' => 1.72],
], currency: 'USD', customFields: ['invoice' => 'INV-9']);   // amount defaults to the sum
```

## Credentials on file

PayWay has to enable the token flags you need on your merchant profile first. Until it does, these calls answer `104: Merchant not enabled token flag`.

```php
// Link an ABA account: returns a deeplink and a QR string.
PayWay::linkAccount([
    'ctid'         => 'customer00042',   // your customer id, 5-24 letters/numbers
    'request_id'   => 'link00001',       // 5-24 letters/numbers, unique
    'token_flag'   => 'CITI_FLEX',       // CITI_FLEX | CITO_FLEX
    'currency'     => 'USD',
    'callback_url' => route('payway.token'),
    'return_deeplink' => ['ios_scheme' => 'myapp://linked', 'android_scheme' => 'myapp://linked'],
]);

// Link a card: PayWay hosts the card form.
$url = PayWay::linkCard([...]);            // URL of the hosted form, for an iframe or redirect
$form = PayWay::linkCardForm([...]);       // or a form that posts into <iframe name="aba_link_card">

// Charge a saved token (pwt) that PayWay sent to your callback_url.
PayWay::payWithToken([
    'tran_id' => 'INV-2001', 'amount' => 3.5, 'currency' => 'USD',
    'ctid' => 'customer00042', 'pwt' => $token,
    'token_flag' => 'CITU_FLEX',         // CITU_FLEX | MITU_FLEX | MITR_FIX
]);

PayWay::tokenDetails('link00001');
PayWay::renewToken('customer00042', $token, 'renew00001');
PayWay::removeToken('customer00042', $token);
```

**Subscriptions** (scheduled payments) start with a normal checkout that carries the token fields:

```php
PayWay::checkoutForm([
    'tran_id' => 'SUB-0001', 'amount' => 20, 'payment_option' => 'cards',  // cards | abapay | abapay_deeplink
    'ctid' => 'customer00042', 'token_flag' => 'CITR_FIX', 'frequency' => '1M',  // 1W | 1M | 2M
]);
// After that, charge each billing cycle with payWithToken([... 'token_flag' => 'MITR_FIX']).
```

## Without Laravel

```php
$payway = new Crushjs\AbaPayway\PayWay(
    merchantId: 'ec000002',
    apiKey: 'your-public-key',
    sandbox: true,
    rsaPublicKey: file_get_contents('payway_rsa_public.pem'),
);
```

## Testing

```bash
composer test
```

## License

MIT
