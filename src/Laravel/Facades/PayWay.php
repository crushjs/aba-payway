<?php

namespace Crushjs\AbaPayway\Laravel\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static string checkoutUrl()
 * @method static array purchaseFields(array $params)
 * @method static string checkoutForm(array $params, string $formId = 'aba_merchant_request')
 * @method static \Crushjs\AbaPayway\Response purchase(array $params)
 * @method static \Crushjs\AbaPayway\Response checkTransaction(string $tranId)
 * @method static bool isPaid(string $tranId)
 * @method static \Crushjs\AbaPayway\Response transactionDetail(string $tranId)
 * @method static \Crushjs\AbaPayway\Response closeTransaction(string $tranId)
 * @method static \Crushjs\AbaPayway\Response transactionList(array $filters = [])
 * @method static \Crushjs\AbaPayway\Response transactionsByMerchantRef(string $merchantRef)
 * @method static \Crushjs\AbaPayway\Response exchangeRate()
 * @method static \Crushjs\AbaPayway\Response refund(string $tranId, float|int|string $amount)
 * @method static \Crushjs\AbaPayway\Response completePreAuth(string $tranId, float|int|string $amount, ?array $payout = null)
 * @method static \Crushjs\AbaPayway\Response cancelPreAuth(string $tranId)
 * @method static \Crushjs\AbaPayway\Response generateQr(array $params)
 * @method static \Crushjs\AbaPayway\Response createPaymentLink(array $params, ?string $imagePath = null)
 * @method static \Crushjs\AbaPayway\Response paymentLinkDetail(string $id)
 * @method static \Crushjs\AbaPayway\Response payout(string $tranId, array $beneficiaries, float|int|string|null $amount = null, string $currency = 'USD', ?array $customFields = null)
 * @method static \Crushjs\AbaPayway\Response addBeneficiary(string $payee)
 * @method static \Crushjs\AbaPayway\Response updateBeneficiaryStatus(string $payee, bool $active)
 * @method static \Crushjs\AbaPayway\Response linkAccount(array $params)
 * @method static string linkCardUrl()
 * @method static array linkCardFields(array $params)
 * @method static string linkCardForm(array $params, string $formId = 'aba_link_card_request', string $target = 'aba_link_card')
 * @method static string linkCard(array $params)
 * @method static \Crushjs\AbaPayway\Response payWithToken(array $params)
 * @method static \Crushjs\AbaPayway\Response tokenDetails(string $requestId)
 * @method static \Crushjs\AbaPayway\Response renewToken(string $ctid, string $pwt, string $requestId)
 * @method static \Crushjs\AbaPayway\Response removeToken(string $ctid, string $pwt)
 * @method static string hash(string ...$values)
 *
 * @see \Crushjs\AbaPayway\PayWay
 */
class PayWay extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Crushjs\AbaPayway\PayWay::class;
    }
}
