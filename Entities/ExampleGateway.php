<?php

namespace Modules\ExampleGateway\Entities;

use App\Models\Gateways\Gateway;
use App\Models\Gateways\PaymentGatewayInterface;
use App\Models\Payment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Omnipay\Common\GatewayInterface;

/**
 * Class ExampleGateway
 *
 * ExampleGateway implements the PaymentGatewayInterface, defining the contract for payment gateways within the system.
 * It provides methods to handle payments, receive responses from the payment gateway, process refunds, configure the gateway,
 * fetch configuration, and check subscriptions.
 *
 * @package Modules\ExampleGateway\Entities
 */
class ExampleGateway implements PaymentGatewayInterface
{

    /**
     * The method is responsible for preparing and processing the payment get the gateway and payment objects
     * use dd($gateway $payment) for debugging
     *
     * @param Gateway $gateway
     * @param Payment $payment
     */
    public static function processGateway(Gateway $gateway, Payment $payment)
    {
        // You can choose not to use omnipay and use your custom code. To get gateway settings $gateway->config()
        /** @var GatewayInterface $omnipayGateway */
        $omnipayGateway = Gateway::getGateway($gateway->driver);



        // An example using Omnipay
        // These parameters can be different, it all depends on the gateway
        $response = $omnipayGateway->purchase([
            'payment_id' => $payment->id, // You need to pass the payment id to access it in the method below if it is used
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'description' => $payment->description,
            'returnUrl' => route('payment.return', ['gateway' => self::endpoint()]),
            'cancelUrl' => route('payment.cancel', ['payment' => $payment->id])
        ])->send();

        if ($response->isRedirect()) {
            $response->redirect();
        } else {
            throw new \Exception($response->getMessage());
        }
    }


    /**
     * Handles the response from the payment gateway. It uses a Request object to receive and handle the response appropriately.
     * endpoint: payment/return/{endpoint_gateway}
     * @param Request $request
     */
    public static function returnGateway(Request $request)
    {
        $gateway = Gateway::query()->where('driver', 'Example_Gateway')->firstOrFail();

        // An example using Omnipay
        /** @var GatewayInterface $omnipayGateway */
        $omnipayGateway = Gateway::getGateway($gateway->driver);

        $payment_id = $request->input('payment_id');

        $response = $omnipayGateway->completePurchase([
            'transactionReference' => $request->input('paymentId'),
            'payerId' => $request->input('PayerID'),
        ])->send();

        if ($response->isSuccessful()) {
            $transactionData = $response->getData();
            $transactionData['request'] = $request->all();

            if ($transactionData['state'] === 'approved' || $transactionData['state'] === 'completed') {
                $payment = Payment::find($payment_id); // We receive the user's payment object
                $payment->completed($transactionData['id'], $transactionData); // We confirm a successful payment
                return redirect()->route("payment.success", ['payment' => $payment->id]);
            } else {
                return redirect()->route("payment.cancel", ['payment' => $payment_id]);
            }
        } else {
            return redirect()->route("payment.cancel", ['payment' => $payment_id]);
        }

    }

    /**
     * Handles refunds. It takes a Payment object and additional data required for processing a refund.
     * An optional method to add user refund support
     *
     * @param Payment $payment
     * @param array $data
     */
    public static function processRefund(Payment $payment, array $data)
    {
        // You can choose not to use omnipay and use your custom code. To get gateway settings $gateway->config()
        /** @var GatewayInterface $omnipayGateway */
        $omnipayGateway = Gateway::getGateway($payment->gateway['driver']);
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
            'Example_Gateway' => [
                'driver' => 'Example_Gateway',
                'type' => 'once', // subscription
                'class' => 'Modules\ExampleGateway\Entities\ExampleGateway',
                'endpoint' => self::endpoint(),
                'refund_support' => false,
                'blade_edit_path' => 'examplegateway::gateways.edit.example_gateway_help', // optional
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
        return 'example-endpoint';
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
            'api_key' => '',
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
        return false;
    }
}