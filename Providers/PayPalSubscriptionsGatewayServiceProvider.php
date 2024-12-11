<?php

namespace Modules\ExampleGateway\Providers;

use Illuminate\Support\ServiceProvider;

class PayPalSubscriptionsGatewayServiceProvider extends ServiceProvider
{
    /**
     * @var string $moduleName
     */
    protected $moduleName = 'PayPalSubscriptionsGateway';

    /**
     * @var string $moduleNameLower
     */
    protected $moduleNameLower = 'paypal_subscriptions_gateway';

    /**
     * Boot the application events.
     *
     * @return void
     */
    public function boot()
    {

    }

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {

    }
}
