<?php

namespace Crushjs\AbaPayway;

use CURLFile;
use Crushjs\AbaPayway\Exceptions\PayWayException;
use Crushjs\AbaPayway\Http\CurlHttpClient;
use Crushjs\AbaPayway\Http\HttpClient;
use Crushjs\AbaPayway\Http\HttpResponse;
use Crushjs\AbaPayway\Support\RsaEncryptor;
use DateTimeInterface;

/**
 * ABA PayWay API client.
 *
 * @see https://developer.payway.com.kh
 */
class PayWay
{
    public const SANDBOX_URL = 'https://checkout-sandbox.payway.com.kh';

    public const PRODUCTION_URL = 'https://checkout.payway.com.kh';

    /**
     * Endpoint paths, relative to the base URL.
     */
    public const ENDPOINTS = [
        'purchase' => '/api/payment-gateway/v1/payments/purchase',
        'check_transaction' => '/api/payment-gateway/v1/payments/check-transaction-2',
        'transaction_detail' => '/api/payment-gateway/v1/payments/transaction-detail',
        'close_transaction' => '/api/payment-gateway/v1/payments/close-transaction',
        'transaction_list' => '/api/payment-gateway/v1/payments/transaction-list-2',
        'transactions_by_ref' => '/api/payment-gateway/v1/payments/get-transactions-by-mc-ref',
        'exchange_rate' => '/api/payment-gateway/v1/exchange-rate',
        'generate_qr' => '/api/payment-gateway/v1/payments/generate-qr',
        'refund' => '/api/merchant-portal/merchant-access/online-transaction/refund',
        'pre_auth_completion' => '/api/merchant-portal/merchant-access/online-transaction/pre-auth-completion',
        'pre_auth_cancellation' => '/api/merchant-portal/merchant-access/online-transaction/pre-auth-cancellation',
        'payment_link_create' => '/api/merchant-portal/merchant-access/payment-link/create',
        'payment_link_detail' => '/api/merchant-portal/merchant-access/payment-link/detail',
        'payout' => '/api/payment-gateway/v2/direct-payment/merchant/payout',
        'whitelist_add' => '/api/merchant-portal/merchant-access/whitelist-account/add-whitelist-payout',
        'whitelist_update' => '/api/merchant-portal/merchant-access/whitelist-account/update-whitelist-status',
        'link_account' => '/api/payment-credential/v3/aof/link-account',
        'link_card' => '/api/payment-credential/v3/cof/link-card',
        'token_payment' => '/api/payment-gateway/v3/purchase/payment-credential',
        'token_details' => '/api/payment-credential/v3/token-management/get-token-details',
        'token_renew' => '/api/payment-credential/v3/token-management/renew-expired-account-token',
        'token_remove' => '/api/payment-credential/v3/token-management/remove-token',
    ];

    /**
     * Purchase (and subscription) fields in PayWay's hash order. A subscription
     * appends token_flag and frequency; empty fields contribute nothing.
     */
    private const PURCHASE_HASH = [
        'req_time', 'merchant_id', 'tran_id', 'amount', 'items', 'shipping',
        'firstname', 'lastname', 'email', 'phone', 'type', 'payment_option',
        'return_url', 'cancel_url', 'continue_success_url', 'return_deeplink',
        'currency', 'custom_fields', 'return_params', 'payout', 'lifetime',
        'additional_params', 'google_pay_token', 'skip_success_page',
        'token_flag', 'frequency',
    ];

    private const QR_HASH = [
        'req_time', 'merchant_id', 'tran_id', 'amount', 'items',
        'first_name', 'last_name', 'email', 'phone', 'purchase_type',
        'payment_option', 'callback_url', 'return_deeplink', 'currency',
        'custom_fields', 'return_params', 'payout', 'lifetime', 'qr_image_template',
    ];

    private const TRANSACTION_LIST_HASH = [
        'req_time', 'merchant_id', 'from_date', 'to_date', 'from_amount',
        'to_amount', 'status', 'page', 'pagination',
    ];

    private const LINK_ACCOUNT_HASH = [
        'merchant_id', 'request_time', 'ctid', 'return_deeplink', 'callback_url',
        'request_id', 'token_flag', 'currency',
    ];

    private const LINK_CARD_HASH = [
        'merchant_id', 'request_time', 'ctid', 'callback_url', 'request_id',
        'token_flag', 'frequency', 'amount', 'currency', 'continue_success_url',
    ];

    private const TOKEN_PAYMENT_HASH = [
        'request_time', 'merchant_id', 'tran_id', 'amount', 'currency', 'items',
        'ctid', 'pwt', 'first_name', 'last_name', 'email', 'phone', 'purchase_type',
        'callback_url', 'custom_fields', 'return_params', 'payout', 'token_flag', 'shipping_fee',
    ];

    /**
     * Fields PayWay expects as base64 encoded JSON when given as arrays.
     */
    private const BASE64_JSON_FIELDS = ['items', 'return_deeplink', 'custom_fields', 'payout', 'additional_params'];

    private string $baseUrl;

    private HttpClient $http;

    private ?RsaEncryptor $rsa = null;

    public function __construct(
        private string $merchantId,
        private string $apiKey,
        bool $sandbox = true,
        ?string $baseUrl = null,
        ?HttpClient $http = null,
        private ?string $rsaPublicKey = null,
    ) {
        if ($merchantId === '' || $apiKey === '') {
            throw new PayWayException('PayWay merchant ID and API key are required.');
        }

        $this->baseUrl = $this->normalizeBaseUrl($baseUrl ?? ($sandbox ? self::SANDBOX_URL : self::PRODUCTION_URL));
        $this->http = $http ?? new CurlHttpClient();
    }

    /* ---------------------------------------------------------------------
     | Ecommerce checkout
     * ------------------------------------------------------------------- */

    /**
     * URL the checkout form posts to.
     */
    public function checkoutUrl(): string
    {
        return $this->endpoint('purchase');
    }

    /**
     * Build the signed fields for a checkout (purchase) request.
     *
     * Post these from the browser to checkoutUrl(), usually through ABA's
     * checkout JS plugin. Arrays given for items, custom_fields,
     * return_deeplink, payout and additional_params are JSON + base64
     * encoded, and return_url is base64 encoded, as PayWay requires.
     *
     * For a subscription (scheduled payment), also pass ctid,
     * token_flag ("CITR_FIX") and frequency ("1W", "1M" or "2M").
     *
     * @param  array<string, mixed>  $params
     * @return array<string, string>
     */
    public function purchaseFields(array $params): array
    {
        $this->assertTransaction($params);

        $fields = array_merge([
            'req_time' => $this->requestTime(),
            'merchant_id' => $this->merchantId,
            'type' => 'purchase',
            'currency' => 'USD',
        ], $params);

        $fields = $this->encode($fields, base64Urls: ['return_url']);
        $fields['hash'] = $this->hash(...$this->pluck($fields, self::PURCHASE_HASH));

        return $fields;
    }

    /**
     * Render a hidden HTML form for ABA's checkout JS plugin.
     *
     * Include https://checkout.payway.com.kh/plugins/checkout2-0.js on the page
     * and call AbaPayway.checkout() to open the payment sheet.
     *
     * @param  array<string, mixed>  $params
     */
    public function checkoutForm(array $params, string $formId = 'aba_merchant_request'): string
    {
        return $this->form($this->checkoutUrl(), $this->purchaseFields($params), $formId, 'aba_webservice');
    }

    /**
     * Send the purchase request from the server.
     *
     * Use it for payment options that answer with JSON, such as
     * "abapay_khqr_deeplink" (returns qr_string, abapay_deeplink, checkout_qr_url).
     *
     * @param  array<string, mixed>  $params
     */
    public function purchase(array $params): Response
    {
        return $this->send('purchase', $this->purchaseFields($params), json: false);
    }

    /* ---------------------------------------------------------------------
     | Transactions
     * ------------------------------------------------------------------- */

    /**
     * Check a transaction's payment status.
     */
    public function checkTransaction(string $tranId): Response
    {
        return $this->transactionRequest('check_transaction', $tranId);
    }

    /**
     * Confirm a payment by asking PayWay directly.
     *
     * Use this in your return_url (pushback) handler instead of trusting the
     * posted payload.
     */
    public function isPaid(string $tranId): bool
    {
        return $this->checkTransaction($tranId)->isPaid();
    }

    /**
     * Get the full details and operation history of a transaction.
     */
    public function transactionDetail(string $tranId): Response
    {
        return $this->transactionRequest('transaction_detail', $tranId);
    }

    /**
     * Close (cancel) a pending transaction so it can no longer be paid.
     */
    public function closeTransaction(string $tranId): Response
    {
        return $this->transactionRequest('close_transaction', $tranId);
    }

    /**
     * List transactions.
     *
     * Filters: from_date, to_date (DateTimeInterface or "Y-m-d H:i:s"),
     * from_amount, to_amount, status (APPROVED, PRE-AUTH, REFUNDED, PENDING,
     * DECLINED, CANCELLED; comma separated or an array), page, pagination (max 1000).
     *
     * @param  array<string, mixed>  $filters
     */
    public function transactionList(array $filters = []): Response
    {
        foreach (['from_date', 'to_date'] as $key) {
            if (($filters[$key] ?? null) instanceof DateTimeInterface) {
                $filters[$key] = $filters[$key]->format('Y-m-d H:i:s');
            }
        }

        if (is_array($filters['status'] ?? null)) {
            $filters['status'] = implode(',', $filters['status']);
        }

        $fields = $this->withoutEmpty(array_merge($filters, [
            'req_time' => $this->requestTime(),
            'merchant_id' => $this->merchantId,
        ]));

        $fields['hash'] = $this->hash(...$this->pluck($fields, self::TRANSACTION_LIST_HASH));

        return $this->send('transaction_list', $fields);
    }

    /**
     * Get the transactions made through a payment link, by its merchant_ref_no.
     */
    public function transactionsByMerchantRef(string $merchantRef): Response
    {
        $reqTime = $this->requestTime();

        return $this->send('transactions_by_ref', [
            'req_time' => $reqTime,
            'merchant_id' => $this->merchantId,
            'merchant_ref' => $merchantRef,
            'hash' => $this->hash($reqTime, $this->merchantId, $merchantRef),
        ]);
    }

    /**
     * Get PayWay's current exchange rates.
     */
    public function exchangeRate(): Response
    {
        $reqTime = $this->requestTime();

        return $this->send('exchange_rate', [
            'req_time' => $reqTime,
            'merchant_id' => $this->merchantId,
            'hash' => $this->hash($reqTime, $this->merchantId),
        ]);
    }

    /* ---------------------------------------------------------------------
     | Refund and pre-auth (require the RSA public key)
     * ------------------------------------------------------------------- */

    /**
     * Refund all or part of an approved transaction.
     */
    public function refund(string $tranId, float|int|string $amount): Response
    {
        $requestTime = $this->requestTime();
        $auth = $this->merchantAuth(['mc_id' => $this->merchantId, 'tran_id' => $tranId, 'refund_amount' => $this->number($amount)]);

        return $this->send('refund', [
            'request_time' => $requestTime,
            'merchant_id' => $this->merchantId,
            'merchant_auth' => $auth,
            'hash' => $this->hash($requestTime, $this->merchantId, $auth),
        ]);
    }

    /**
     * Capture a pre-authorised transaction, optionally splitting it with a
     * payout ([['acc' => '000133879', 'amt' => 1.0], ...]).
     *
     * @param  array<int, array<string, mixed>>|null  $payout
     */
    public function completePreAuth(string $tranId, float|int|string $amount, ?array $payout = null): Response
    {
        $requestTime = $this->requestTime();

        $data = ['mc_id' => $this->merchantId, 'tran_id' => $tranId, 'complete_amount' => $this->number($amount)];

        if ($payout !== null) {
            $data['payout'] = $payout;
        }

        $auth = $this->merchantAuth($data);

        return $this->send('pre_auth_completion', [
            'request_time' => $requestTime,
            'merchant_id' => $this->merchantId,
            'merchant_auth' => $auth,
            'hash' => $this->hash($auth, $requestTime, $this->merchantId),
        ]);
    }

    /**
     * Cancel a pre-authorised transaction and release the held funds.
     */
    public function cancelPreAuth(string $tranId): Response
    {
        $requestTime = $this->requestTime();
        $auth = $this->merchantAuth(['mc_id' => $this->merchantId, 'tran_id' => $tranId]);

        return $this->send('pre_auth_cancellation', [
            'request_time' => $requestTime,
            'merchant_id' => $this->merchantId,
            'merchant_auth' => $auth,
            'hash' => $this->hash($this->merchantId, $auth, $requestTime),
        ]);
    }

    /* ---------------------------------------------------------------------
     | ABA QR API
     * ------------------------------------------------------------------- */

    /**
     * Generate a KHQR (or WeChat / Alipay) QR code for a transaction.
     *
     * @param  array<string, mixed>  $params
     */
    public function generateQr(array $params): Response
    {
        $this->assertTransaction($params);

        $fields = array_merge([
            'req_time' => $this->requestTime(),
            'merchant_id' => $this->merchantId,
            'purchase_type' => 'purchase',
            'payment_option' => 'abapay_khqr',
            'currency' => 'USD',
            'lifetime' => 6,
            'qr_image_template' => 'template3_color',
        ], $params);

        $fields = $this->encode($fields, base64Urls: ['callback_url']);
        $fields['hash'] = $this->hash(...$this->pluck($fields, self::QR_HASH));

        return $this->send('generate_qr', $fields);
    }

    /* ---------------------------------------------------------------------
     | Payment links (require the RSA public key)
     * ------------------------------------------------------------------- */

    /**
     * Create a shareable payment link.
     *
     * Params: title, amount, currency, return_url (required); description,
     * payment_limit, expired_date (timestamp or DateTimeInterface),
     * merchant_ref_no, payout ([['acc' => ..., 'amt' => ...]]).
     *
     * @param  array<string, mixed>  $params
     * @param  string|null  $imagePath  Optional image shown on the link page.
     */
    public function createPaymentLink(array $params, ?string $imagePath = null): Response
    {
        foreach (['title', 'amount', 'return_url'] as $key) {
            if (! isset($params[$key]) || $params[$key] === '') {
                throw new PayWayException("A payment link {$key} is required.");
            }
        }

        $data = array_merge(['mc_id' => $this->merchantId, 'currency' => 'USD', 'expired_date' => null], $params);

        $data['currency'] = strtoupper((string) $data['currency']);
        $data['amount'] = $this->number($data['amount']);
        $data['return_url'] = base64_encode((string) $data['return_url']);

        if ($data['expired_date'] instanceof DateTimeInterface) {
            $data['expired_date'] = $data['expired_date']->getTimestamp();
        }

        if (is_array($data['payout'] ?? null)) {
            $data['payout'] = $this->json($data['payout']);
        }

        $requestTime = $this->requestTime();
        $auth = $this->merchantAuth($data);

        $fields = [
            'request_time' => $requestTime,
            'merchant_id' => $this->merchantId,
            'merchant_auth' => $auth,
            'hash' => $this->hash($requestTime, $this->merchantId, $auth),
        ];

        if ($imagePath !== null) {
            if (! is_file($imagePath)) {
                throw new PayWayException("Payment link image [{$imagePath}] does not exist.");
            }

            $fields['image'] = new CURLFile($imagePath);
        }

        return $this->send('payment_link_create', $fields, json: false);
    }

    /**
     * Get a payment link's details by the id PayWay returned on creation.
     */
    public function paymentLinkDetail(string $id): Response
    {
        $requestTime = $this->requestTime();
        $auth = $this->merchantAuth(['mc_id' => $this->merchantId, 'id' => $id]);

        return $this->send('payment_link_detail', [
            'request_time' => $requestTime,
            'merchant_id' => $this->merchantId,
            'merchant_auth' => $auth,
            'hash' => $this->hash($requestTime, $this->merchantId, $auth),
        ]);
    }

    /* ---------------------------------------------------------------------
     | Payout (require the RSA public key)
     * ------------------------------------------------------------------- */

    /**
     * Send money from your merchant account to whitelisted beneficiaries.
     *
     * @param  array<int, array{account: string, amount: float|int|string}>  $beneficiaries
     * @param  array<string, mixed>|null  $customFields
     */
    public function payout(
        string $tranId,
        array $beneficiaries,
        float|int|string|null $amount = null,
        string $currency = 'USD',
        ?array $customFields = null,
    ): Response {
        $amount ??= array_sum(array_map(static fn (array $b) => (float) $b['amount'], $beneficiaries));
        $amount = (string) $this->number($amount);
        $currency = strtoupper($currency);
        $encrypted = $this->rsa()->encrypt($this->json($beneficiaries));
        $custom = $customFields === null ? '' : $this->json($customFields);

        $fields = $this->withoutEmpty([
            'merchant_id' => $this->merchantId,
            'tran_id' => $tranId,
            'beneficiaries' => $encrypted,
            'amount' => $amount,
            'currency' => $currency,
            'custom_fields' => $custom,
        ]);

        // The payout API takes a hex digest, unlike every other endpoint.
        $fields['hash'] = hash_hmac('sha512', $this->merchantId.$tranId.$encrypted.$amount.$custom.$currency, $this->apiKey);

        return $this->send('payout', $fields);
    }

    /**
     * Whitelist a beneficiary (ABA account or MID) for payouts.
     */
    public function addBeneficiary(string $payee): Response
    {
        return $this->whitelistRequest('whitelist_add', ['mc_id' => $this->merchantId, 'payee' => $payee]);
    }

    /**
     * Enable or disable a whitelisted beneficiary.
     */
    public function updateBeneficiaryStatus(string $payee, bool $active): Response
    {
        return $this->whitelistRequest('whitelist_update', ['mc_id' => $this->merchantId, 'payee' => $payee, 'status' => $active ? 1 : 0]);
    }

    /* ---------------------------------------------------------------------
     | Credentials on file (saved ABA accounts and cards)
     * ------------------------------------------------------------------- */

    /**
     * Start linking a customer's ABA account. Returns a deeplink and QR
     * string; PayWay posts the token (pwt) to callback_url once linked.
     *
     * Params: ctid, request_id (5-24 alphanumerics), token_flag (CITI_FLEX |
     * CITO_FLEX), currency; optional callback_url, return_deeplink.
     *
     * @param  array<string, mixed>  $params
     */
    public function linkAccount(array $params): Response
    {
        $fields = $this->credentialFields($params);
        $fields['hash'] = $this->hash(...$this->pluck($fields, self::LINK_ACCOUNT_HASH));

        return $this->send('link_account', $fields);
    }

    /**
     * URL the link card form posts to.
     */
    public function linkCardUrl(): string
    {
        return $this->endpoint('link_card');
    }

    /**
     * Build the signed fields for linking a card. PayWay answers with a
     * hosted card form, so post these from the browser (e.g. into an iframe).
     *
     * Params: ctid, request_id, token_flag (CITI_FLEX | CITO_FLEX), currency;
     * optional callback_url, continue_success_url.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, string>
     */
    public function linkCardFields(array $params): array
    {
        $fields = $this->credentialFields($params, ['continue_success_url']);
        $fields['hash'] = $this->hash(...$this->pluck($fields, self::LINK_CARD_HASH));

        return $fields;
    }

    /**
     * Render a form that posts the link card request, by default into an
     * iframe named "aba_link_card".
     *
     * @param  array<string, mixed>  $params
     */
    public function linkCardForm(array $params, string $formId = 'aba_link_card_request', string $target = 'aba_link_card'): string
    {
        return $this->form($this->linkCardUrl(), $this->linkCardFields($params), $formId, $target);
    }

    /**
     * Request the link card page from the server and return the URL of
     * PayWay's hosted card form, to open in an iframe or redirect to.
     * If PayWay rejects the request, the hosted page shows the error.
     *
     * @param  array<string, mixed>  $params
     */
    public function linkCard(array $params): string
    {
        $response = $this->http->post($this->endpoint('link_card'), $this->linkCardFields($params), false);

        if ($response->redirectUrl === null) {
            throw new PayWayException(sprintf('PayWay did not return a link card page (HTTP %d).', $response->status));
        }

        return $response->redirectUrl;
    }

    /**
     * Charge a saved account or card using its token (pwt).
     *
     * Params: tran_id, ctid, pwt, amount, currency, token_flag (CITU_FLEX |
     * MITU_FLEX | MITR_FIX); optional first_name, last_name, email, phone,
     * purchase_type, items, callback_url, custom_fields, return_params,
     * payout, shipping_fee.
     *
     * @param  array<string, mixed>  $params
     */
    public function payWithToken(array $params): Response
    {
        $this->assertTransaction($params);

        $fields = array_merge([
            'request_time' => $this->requestTime(),
            'merchant_id' => $this->merchantId,
            'currency' => 'USD',
            'purchase_type' => 'purchase',
        ], $params);

        $fields = $this->encode($fields, base64Urls: ['callback_url']);
        $fields['hash'] = $this->hash(...$this->pluck($fields, self::TOKEN_PAYMENT_HASH));

        return $this->send('token_payment', $fields);
    }

    /**
     * Get a token's details by the request_id used to link it.
     */
    public function tokenDetails(string $requestId): Response
    {
        $requestTime = $this->requestTime();

        return $this->send('token_details', [
            'request_time' => $requestTime,
            'merchant_id' => $this->merchantId,
            'request_id' => $requestId,
            'hash' => $this->hash($this->merchantId, $requestTime, $requestId),
        ]);
    }

    /**
     * Renew an expired ABA account token.
     */
    public function renewToken(string $ctid, string $pwt, string $requestId): Response
    {
        $requestTime = $this->requestTime();

        return $this->send('token_renew', [
            'request_time' => $requestTime,
            'merchant_id' => $this->merchantId,
            'request_id' => $requestId,
            'ctid' => $ctid,
            'pwt' => $pwt,
            'hash' => $this->hash($ctid, $requestTime, $pwt, $this->merchantId, $requestId),
        ]);
    }

    /**
     * Remove (unlink) a saved account or card token.
     */
    public function removeToken(string $ctid, string $pwt): Response
    {
        $requestTime = $this->requestTime();

        return $this->send('token_remove', [
            'request_time' => $requestTime,
            'merchant_id' => $this->merchantId,
            'ctid' => $ctid,
            'pwt' => $pwt,
            'hash' => $this->hash($this->merchantId, $ctid, $requestTime, $pwt),
        ]);
    }

    /* ---------------------------------------------------------------------
     | Helpers
     * ------------------------------------------------------------------- */

    /**
     * Sign the given values: base64(HMAC-SHA512(concatenated values, api key)).
     */
    public function hash(string ...$values): string
    {
        return base64_encode(hash_hmac('sha512', implode('', $values), $this->apiKey, true));
    }

    public function merchantId(): string
    {
        return $this->merchantId;
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    private function transactionRequest(string $endpoint, string $tranId): Response
    {
        $reqTime = $this->requestTime();

        return $this->send($endpoint, [
            'req_time' => $reqTime,
            'merchant_id' => $this->merchantId,
            'tran_id' => $tranId,
            'hash' => $this->hash($reqTime, $this->merchantId, $tranId),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function whitelistRequest(string $endpoint, array $data): Response
    {
        $requestTime = $this->requestTime();
        $auth = $this->merchantAuth($data);

        return $this->send($endpoint, [
            'request_time' => $requestTime,
            'merchant_id' => $this->merchantId,
            'merchant_auth' => $auth,
            'hash' => $this->hash($requestTime, $auth),
        ]);
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array<int, string>  $extraBase64Urls
     * @return array<string, string>
     */
    private function credentialFields(array $params, array $extraBase64Urls = []): array
    {
        foreach (['ctid', 'request_id', 'token_flag'] as $key) {
            if (! isset($params[$key]) || $params[$key] === '') {
                throw new PayWayException("A {$key} is required.");
            }
        }

        foreach (['ctid', 'request_id'] as $key) {
            if (! preg_match('/^[A-Za-z0-9]{5,24}$/', (string) $params[$key])) {
                throw new PayWayException("The {$key} must be 5 to 24 letters or numbers.");
            }
        }

        $fields = array_merge([
            'request_time' => $this->requestTime(),
            'merchant_id' => $this->merchantId,
            'currency' => 'USD',
        ], $params);

        return $this->encode($fields, base64Urls: array_merge(['callback_url'], $extraBase64Urls), formatAmount: false);
    }

    /**
     * Normalise request fields: upper-case currency, format the amount, and
     * base64 encode JSON and URL fields. Empty values are dropped.
     *
     * @param  array<string, mixed>  $fields
     * @param  array<int, string>  $base64Urls
     * @return array<string, string>
     */
    private function encode(array $fields, array $base64Urls, bool $formatAmount = true): array
    {
        if (isset($fields['currency'])) {
            $fields['currency'] = strtoupper((string) $fields['currency']);
        }

        if ($formatAmount && isset($fields['amount'])) {
            $fields['amount'] = $this->formatAmount($fields['amount'], $fields['currency'] ?? 'USD');
        }

        foreach (self::BASE64_JSON_FIELDS as $field) {
            if (is_array($fields[$field] ?? null)) {
                $fields[$field] = base64_encode($this->json($fields[$field]));
            }
        }

        foreach ($base64Urls as $field) {
            if (isset($fields[$field]) && $fields[$field] !== '') {
                $fields[$field] = base64_encode((string) $fields[$field]);
            }
        }

        $fields = array_map(static fn ($value) => is_bool($value) ? (string) (int) $value : $value, $fields);

        return array_map('strval', $this->withoutEmpty($fields));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function merchantAuth(array $data): string
    {
        return $this->rsa()->encrypt($this->json($data));
    }

    private function rsa(): RsaEncryptor
    {
        if ($this->rsaPublicKey === null || $this->rsaPublicKey === '') {
            throw new PayWayException(
                'This PayWay API needs the RSA public key ABA provides. Set PAYWAY_RSA_PUBLIC_KEY (or pass rsaPublicKey).'
            );
        }

        return $this->rsa ??= new RsaEncryptor($this->rsaPublicKey);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function send(string $endpoint, array $data, bool $json = true): Response
    {
        return $this->decode($this->http->post($this->endpoint($endpoint), $data, $json), $endpoint);
    }

    private function decode(HttpResponse $response, string $endpoint): Response
    {
        $decoded = json_decode($response->body, true);

        if (! is_array($decoded) && $response->status === 404) {
            throw new PayWayException(sprintf(
                'PayWay endpoint [%s] is not available on %s.',
                self::ENDPOINTS[$endpoint],
                $this->baseUrl,
            ));
        }

        if (! is_array($decoded)) {
            throw new PayWayException(sprintf(
                'PayWay returned a non-JSON response (HTTP %d) from [%s]. '
                .'Card and ABA Pay checkouts return an HTML page; use checkoutForm() for those.',
                $response->status,
                self::ENDPOINTS[$endpoint],
            ));
        }

        return new Response($decoded, $response->status);
    }

    private function endpoint(string $name): string
    {
        return $this->baseUrl.self::ENDPOINTS[$name];
    }

    /**
     * @param  array<string, string>  $fields
     */
    private function form(string $action, array $fields, string $formId, string $target): string
    {
        $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

        $inputs = '';

        foreach ($fields as $name => $value) {
            $inputs .= sprintf('<input type="hidden" name="%s" value="%s">', $escape($name), $escape($value));
        }

        return sprintf(
            '<form method="POST" target="%s" action="%s" id="%s">%s</form>',
            $escape($target),
            $escape($action),
            $escape($formId),
            $inputs,
        );
    }

    /**
     * Keep only scheme and host, so a full endpoint URL copied from
     * ABA's docs (e.g. .../api/payment-gateway/v1/payments/purchase) still works.
     */
    private function normalizeBaseUrl(string $url): string
    {
        $parts = parse_url($url);

        if (! isset($parts['scheme'], $parts['host'])) {
            throw new PayWayException("Invalid PayWay base URL [{$url}].");
        }

        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return $parts['scheme'].'://'.$parts['host'].$port;
    }

    /**
     * PayWay expects the request time in UTC as YYYYMMDDHHmmss.
     */
    private function requestTime(): string
    {
        return gmdate('YmdHis');
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function assertTransaction(array $params): void
    {
        $tranId = (string) ($params['tran_id'] ?? '');

        if ($tranId === '') {
            throw new PayWayException('A tran_id is required.');
        }

        if (strlen($tranId) > 20) {
            throw new PayWayException('The tran_id may not be longer than 20 characters.');
        }

        if (! isset($params['amount']) || ! is_numeric($params['amount'])) {
            throw new PayWayException('A numeric amount is required.');
        }
    }

    private function formatAmount(mixed $amount, string $currency): string
    {
        // KHR has no minor unit; USD is sent with two decimals.
        return strtoupper($currency) === 'KHR'
            ? number_format((float) $amount, 0, '.', '')
            : number_format((float) $amount, 2, '.', '');
    }

    private function number(float|int|string $amount): float|int
    {
        if (! is_numeric($amount)) {
            throw new PayWayException('A numeric amount is required.');
        }

        return $amount + 0;
    }

    private function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private function withoutEmpty(array $fields): array
    {
        return array_filter($fields, static fn ($value) => $value !== null && $value !== '');
    }

    /**
     * @param  array<string, mixed>  $fields
     * @param  array<int, string>  $keys
     * @return array<int, string>
     */
    private function pluck(array $fields, array $keys): array
    {
        return array_map(static fn (string $key): string => (string) ($fields[$key] ?? ''), $keys);
    }
}
