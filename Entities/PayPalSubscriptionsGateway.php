<?php

namespace Modules\PayPalSubscriptionsGateway\Entities;

use App\Models\Gateways\PaymentGatewayInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use App\Models\Gateways\Gateway;
use Illuminate\Http\Request;
use App\Models\PackagePrice;
use App\Models\Package;
use App\Models\Payment;
use App\Models\Settings;
use App\Models\User;

/**
 * Class ExampleGateway
 *
 * ExampleGateway implements the PaymentGatewayInterface, defining the contract for payment gateways within the system.
 * It provides methods to handle payments, receive responses from the payment gateway, process refunds, configure the gateway,
 * fetch configuration, and check subscriptions.
 *
 * @package Modules\ExampleGateway\Entities
 */
class PayPalSubscriptionsGateway implements PaymentGatewayInterface
{

    /**
     * The method is responsible for preparing and processing the payment get the gateway and payment objects
     * use dd($gateway $payment) for debugging
     *
     * @param Gateway $gateway
     * @param Payment $payment
     */
    public static function processGateway(Gateway $gateway, Payment $payment)
    {                    self::emailSubscriptionsDetails($payment->user);

        return self::createSubscription($payment);
    }

    public static function createSubscription(Payment $payment)
    {
        self::createWebhookUrl();

        $planId = self::createPlan($payment->price);

        if(!$planId)
        {
            throw new \Exception('Failed to create plan');
        }

        // Step 3: Create Subscription
        $subscription = self::createPaypalSubscription($payment, $planId);

        if (!$subscription || !isset($subscription['links'])) {
            return redirect()->back()->withErrors('Unable to create subscription.');
        }

        foreach ($subscription['links'] as $link) {
            if ($link['rel'] === 'approve') {
                // Redirect user to PayPal for approval
                return redirect($link['href']);
            }
        }

        return redirect()->back()->withErrors('No approval link found.');
    }

    protected static function createWebhookUrl()
    {
        $gateway = self::getGateway();
        $accessToken = self::getAccessToken();

        $response = Http::withToken($accessToken)
            ->post(self::apiEndpoint('/notifications/webhooks'), [
                "url" => route('payment.return', ['gateway' => self::endpoint()]),
                "event_types" => [
                    ['name' => 'BILLING.SUBSCRIPTION.ACTIVATED'],
                    ['name' => 'BILLING.SUBSCRIPTION.CANCELLED'],
                    ['name' => 'BILLING.SUBSCRIPTION.EXPIRED'],
                    ['name' => 'BILLING.SUBSCRIPTION.RE-ACTIVATED'],
                    ['name' => 'BILLING.SUBSCRIPTION.SUSPENDED'],
                    ['name' => 'PAYMENT.SALE.COMPLETED'],
                ],
            ]);

        if ($response->failed()) {
            return null;
        }

        $webhook = $response->json();

        if(self::isSandboxMode())
        {
            Settings::put('PayPalSubscriptionsGateway:sandbox_webhook_id', $webhook['id']);
            return;
        }

        Settings::put('PayPalSubscriptionsGateway:webhook_id', $webhook['id']);
    }

    protected static function getWebhookId()
    {
        $webhookId = self::isSandboxMode() ? settings('PayPalSubscriptionsGateway:sandbox_webhook_id') : settings('PayPalSubscriptionsGateway:webhook_id');

        if(!$webhookId)
        {
            self::createWebhookUrl();
            return self::getWebhookId();
        }

        return $webhookId;
    }

    protected static function createPaypalSubscription(Payment $payment, $planId)
    {
        $accessToken = self::getAccessToken();
        $response = Http::withToken($accessToken)
            ->post(self::apiEndpoint('/billing/subscriptions'), [
                "plan_id" => $planId,
                'custom_id' => $payment->id,
                "application_context" => [
                    "return_url" => route('payment.success', $payment->id),
                    "cancel_url" => route('payment.cancel', $payment->id),
                ]
            ]);

        if ($response->failed()) {
            throw new \Exception('Failed to create subscription');
        }

        return $response->json();
    }

    protected static function createPlan(PackagePrice $price)
    {
        $livePlanKey = 'paypal_plan_id';
        $sandboxPlanKey = 'paypal_sandbox_plan_id';
        $planKey = self::isSandboxMode() ? $sandboxPlanKey : $livePlanKey;

        if($price->data && isset($price->data[$planKey]))
        {
            return $price->data[$planKey];
        }

        $package = $price->package;
        $product = self::createProduct($package);
        $accessToken = self::getAccessToken();

        $paymentPreference = [
            "auto_bill_outstanding" => true,
            "setup_fee_failure_action" => "CONTINUE",
            "payment_failure_threshold" => 3
        ];

        if($price->setup_fee)
        {
            $paymentPreference['setup_fee'] = [
                "value" => $price->setup_fee,
                "currency_code" => settings('currency', 'USD'),
            ];
        }

        $response = Http::withToken($accessToken)
            ->post(self::apiEndpoint('/billing/plans'), [
                "product_id" => $product['id'],
                "name" => $package->name,
                "billing_cycles" => [
                    [
                        "frequency" => [
                            "interval_unit" => "DAY",
                            "interval_count" => $price->period
                        ],
                        "tenure_type" => "REGULAR",
                        "sequence" => 1,
                        "total_cycles" => 0, // 0 means it keeps renewing until canceled
                        "pricing_scheme" => [
                            "fixed_price" => [
                                "value" => $price->renewal_price,
                                "currency_code" => settings('currency', 'USD'),
                            ]
                        ]
                    ]
                ],
                "payment_preferences" => $paymentPreference,
            ]);

        if ($response->failed()) {
            return null;
        }

        $price->data = array_merge($price->data ?? [], [$planKey => $response->json()['id']]);
        $price->save();

        $plan = $response->json();
        return $plan['id'] ?? null;
    }

    protected static function createProduct(Package $package)
    {
        $accessToken = self::getAccessToken();
        $response = Http::withToken($accessToken)
        ->post(self::apiEndpoint('/catalogs/products'), [
            "name" => $package->name,
            "type" => "DIGITAL",
            "category" => "SOFTWARE",
        ]);

        // dd($response, $response->json(), self::apiEndpoint('/catalogs/products'));

        if ($response->failed()) {
            return null;
        }

        $product = $response->json();
        return $product;
    }

    /**
     * Handles the response from the payment gateway. It uses a Request object to receive and handle the response appropriately.
     * endpoint: payment/return/{endpoint_gateway}
     * @param Request $request
     */
    public static function returnGateway(Request $request)
    {
        // if request doesnt contain event type, then return an json response
        if (!$request->has('event_type')) {
            return response()->json(['status' => 'Invalid Response'], 400);
        }

        // Retrieve all event data
        $event = $request->all();
        $eventType = $event['event_type'] ?? null;

        // Step 1: Verify the webhook to ensure authenticity
        if (!self::verifyPaypalWebhook($request)) {
            // If verification fails, respond accordingly and do not process the event
            return response()->json(['status' => 'verification_failed'], 400);
        }

        // Step 2: Process the event only if verification passed
        if (in_array($eventType, [
            'BILLING.SUBSCRIPTION.ACTIVATED', 
            'BILLING.SUBSCRIPTION.CANCELLED',
            'BILLING.SUBSCRIPTION.EXPIRED',
            // ... other subscription events you care about
        ])) {
            $subscriptionData = $event['resource'];
            $customId = $subscriptionData['custom_id'] ?? null;
            $subscriptionId = $subscriptionData['id'] ?? null;

            if ($customId) {
                if($eventType === 'BILLING.SUBSCRIPTION.ACTIVATED') {
                    $payment = Payment::find($customId);
                    if ($payment) {
                        $payment->completed($subscriptionId);
                    }

                    self::emailSubscriptionsDetails($payment->user);

                    ErrorLog('paypal-subscriptions-gateway', 'Subscription activated for payment ID: ' . $customId);
                } else if ($eventType === 'BILLING.SUBSCRIPTION.CANCELLED') {
                    // Handle cancellation logic here
                }
            }
        }

        return response()->json(['status' => 'ok']);
    }

    protected static function emailSubscriptionsDetails(User $user)
    {
        $manageUrl = 'https://www.paypal.com/myaccount/autopay/';

        if(self::isSandboxMode())
        {
            $manageUrl = 'https://www.sandbox.paypal.com/myaccount/autopay/';
        }

        $user->email([
            'subject' => 'Your PayPal subscription has been activated',
            'content' => 'Your subscription has been activated successfully. If you wish to cancel, please visit your account settings on PayPal.com or use the button below.',
            'button' => [
                'name' => 'Manage Subscription',
                'url' => $manageUrl,
            ]
        ]);
    }

    /**
     * Verifies that the received webhook event is genuinely from PayPal.
     */
    private static function verifyPaypalWebhook(Request $request)
    {
        // Prepare data for verification
        $webhookId = self::getWebhookId();
        $verificationData = [
            'auth_algo'         => $request->header('PAYPAL-AUTH-ALGO'),
            'cert_url'          => $request->header('PAYPAL-CERT-URL'),
            'transmission_id'   => $request->header('PAYPAL-TRANSMISSION-ID'),
            'transmission_sig'  => $request->header('PAYPAL-TRANSMISSION-SIG'),
            'transmission_time' => $request->header('PAYPAL-TRANSMISSION-TIME'),
            'webhook_id'        => $webhookId,
            'webhook_event'     => $request->json()->all()
        ];

        // Send request to PayPal to verify signature
        $accessToken = self::getAccessToken();
        $response = Http::withToken($accessToken)
            ->post(self::apiEndpoint('/notifications/verify-webhook-signature'), $verificationData);

        ErrorLog('paypal-subscriptions-gateway', 'Webhook verification response: ' . json_encode($response->body()));

        if ($response->failed()) {
            return false;
        }

        // Check if the verification status is 'SUCCESS'
        return $response->json('verification_status') === 'SUCCESS';
    }


    public static function processRefund(Payment $payment, array $data)
    {
        // todo
    }

    /**
     * Defines the configuration for the payment gateway. It returns an array with data defining the gateway driver,
     * type, class, endpoint, refund support, etc.
     *
     * @return array
     */
    public static function drivers(): array
    {
        return [
            'PayPal_subscriptions_gateway' => [
                'driver' => 'PayPal_subscriptions_gateway',
                'type' => 'subscription', // subscription
                'class' => 'Modules\PayPalSubscriptionsGateway\Entities\PayPalSubscriptionsGateway',
                'endpoint' => self::endpoint(),
                'refund_support' => false,
            ]
        ];
    }

    /**
     * Defines the endpoint for the payment gateway. This is an ID used to automatically determine which gateway to use.
     *
     * @return string
     */
    public static function endpoint(): string
    {
        return 'paypal-subscriptions-gateway';
    }

    public static function getGateway()
    {
        return Gateway::where('driver', 'PayPal_subscriptions_gateway')->first();
    }

    public static function isSandboxMode(): bool
    {
        $gateway = self::getGateway();

        return $gateway->config['paypal_mode'] === 'sandbox';
    }

    public static function getAccessToken()
    {
        return Cache::remember('paypal_subscriptions_gateway:access_token', 60, function () {
            $gateway = self::getGateway();
            $clientId = $gateway->config['paypal_client_id'];
            $clientSecret = $gateway->config['paypal_client_secret'];

            $response = Http::withBasicAuth($clientId, $clientSecret)
                ->asForm()
                ->post(self::apiEndpoint('/oauth2/token'), [
                    'grant_type' => 'client_credentials',
                ]);

            if ($response->failed()) {
                return null;
            }

            return $response->json()['access_token'];
        });
    }

    public static function apiUrl()
    {
        return self::isSandboxMode() ? 'https://api-m.sandbox.paypal.com/v1' : 'https://api-m.paypal.com/v1';
    }

    public static function apiEndpoint($path = '')
    {
        return self::apiUrl() . $path;
    }

    /**
     * Returns an array with the configuration for the payment gateway.
     * These options are displayed for the administrator to configure.
     * You can access them: $gateway->config()
     * @return array
     */
    public static function getConfigMerge(): array
    {
        return [
            'paypal_client_id' => '',
            'paypal_client_secret' => '',
            'paypal_mode' => ['sandbox', 'live'],
            // more parameters ...
        ];
    }

    /**
     * Checks the status of a subscription in the payment gateway. If the subscription is active, it returns true; otherwise, it returns false.
     * Do not change this method if you are not using subscriptions
     * @param Gateway $gateway
     * @param $subscriptionId
     * @return bool
     */
    public static function checkSubscription(Gateway $gateway, $subscriptionId): bool
    {
        $accessToken = self::getAccessToken();

        $response = Http::withToken($accessToken)
            ->get(self::apiEndpoint('/billing/subscriptions/' . $subscriptionId));

        if ($response->failed()) {
            return false;
        }

        $subscription = $response->json();

        if(!isset($subscription['status']))
        {
            return false;
        }

        return $subscription['status'] === 'ACTIVE';
    }
}