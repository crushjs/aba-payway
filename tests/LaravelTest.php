<?php

namespace Crushjs\AbaPayway\Tests;

use Crushjs\AbaPayway\Laravel\Facades\PayWay as PayWayFacade;
use Crushjs\AbaPayway\Laravel\PayWayServiceProvider;
use Crushjs\AbaPayway\PayWay;
use Orchestra\Testbench\TestCase;

class LaravelTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PayWayServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return ['PayWay' => PayWayFacade::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('payway.merchant_id', 'ec000002');
        $app['config']->set('payway.api_key', 'test-api-key');
    }

    public function test_it_registers_a_configured_singleton(): void
    {
        $payway = $this->app->make(PayWay::class);

        $this->assertSame($payway, $this->app->make('payway'));
        $this->assertSame('ec000002', $payway->merchantId());
        $this->assertSame(PayWay::SANDBOX_URL, $payway->baseUrl());
    }

    public function test_production_mode_uses_the_live_host(): void
    {
        $this->app['config']->set('payway.sandbox', 'false');
        $this->app->forgetInstance(PayWay::class);

        $this->assertSame(PayWay::PRODUCTION_URL, $this->app->make(PayWay::class)->baseUrl());
    }

    public function test_the_facade_resolves_the_client(): void
    {
        $fields = PayWayFacade::purchaseFields(['tran_id' => 'INV-1', 'amount' => 5]);

        $this->assertSame('5.00', $fields['amount']);
        $this->assertArrayHasKey('hash', $fields);
    }
}
