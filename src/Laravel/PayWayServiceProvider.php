<?php

namespace Crushjs\AbaPayway\Laravel;

use Crushjs\AbaPayway\Http\CurlHttpClient;
use Crushjs\AbaPayway\PayWay;
use Illuminate\Support\ServiceProvider;

class PayWayServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/payway.php', 'payway');

        $this->app->singleton(PayWay::class, function ($app) {
            $config = $app['config']['payway'];

            return new PayWay(
                merchantId: (string) $config['merchant_id'],
                apiKey: (string) $config['api_key'],
                sandbox: filter_var($config['sandbox'], FILTER_VALIDATE_BOOLEAN),
                baseUrl: $config['base_url'] ?: null,
                http: new CurlHttpClient((int) $config['timeout']),
                rsaPublicKey: $config['rsa_public_key'] ?: null,
            );
        });

        $this->app->alias(PayWay::class, 'payway');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../../config/payway.php' => config_path('payway.php'),
            ], 'payway-config');
        }
    }
}
