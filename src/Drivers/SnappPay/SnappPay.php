<?php


namespace Shetabit\Multipay\Drivers\SnappPay;

use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use Shetabit\Multipay\Abstracts\Driver;
use Shetabit\Multipay\Contracts\ReceiptInterface;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;
use Shetabit\Multipay\Invoice;
use Shetabit\Multipay\RedirectionForm;

class SnappPay extends Driver
{
    const VERSION = '1.8';
    const RELEASE_DATE = '2023-01-08';

    const OAUTH_URL = '/api/online/v1/oauth/token';
    const ELIGIBLE_URL = '/api/online/offer/v1/eligible';
    const TOKEN_URL = '/api/online/payment/v1/token';
    const VERIFY_URL = '/api/online/payment/v1/verify';
    const SETTLE_URL = '/api/online/payment/v1/settle';
    const REVERT_URL = '/api/online/payment/v1/revert';
    const STATUS_URL = '/api/online/payment/v1/status';
    const CANCEL_URL = '/api/online/payment/v1/cancel';
    const UPDATE_URL = '/api/online/payment/v1/update';

    /**
     * SnappPay Client.
     *
     * @var Client
     */
    protected $client;

    /**
     * Invoice
     *
     * @var Invoice
     */
    protected $invoice;

    /**
     * Driver settings
     *
     * @var object
     */
    protected $settings;
    /**
     * SnappPay Oauth Data
     *
     * @var string
     */
    protected $oauthToken;

    /**
     * SnappPay payment url
     *
     * @var string
     */
    protected $paymentUrl;

    /**
     * SnappPay constructor.
     * Construct the class with the relevant settings.
     */
    public function __construct(Invoice $invoice, $settings)
    {
        $this->invoice($invoice);
        $this->settings = (object) $settings;
        $this->client = new Client();
        $this->oauthToken = $this->oauth();
    }

    /**
     * @throws PurchaseFailedException
     */
    public function purchase(): string
    {
        $phone = $this->invoice->getDetail('phone')
            ?? $this->invoice->getDetail('cellphone')
            ?? $this->invoice->getDetail('mobile');

        // convert to format +98 901 XXX XXXX
        $phone = preg_replace('/^0/', '+98', $phone);

        $data = [
            'amount' => $this->normalizerAmount($this->invoice->getAmount()),
            'mobile' => $phone,
            'paymentMethodTypeDto' => 'INSTALLMENT',
            'transactionId' => $this->invoice->getUuid(),
            'returnURL' => $this->settings->callbackUrl,
        ];

        if (!is_null($discountAmount = $this->invoice->getDetail('discountAmount'))) {
            $data['discountAmount'] = $this->normalizerAmount($discountAmount);
        }

        if (!is_null($externalSourceAmount = $this->invoice->getDetail('externalSourceAmount'))) {
            $data['externalSourceAmount'] = $externalSourceAmount;
        }

        if (!is_null($cartList = $this->invoice->getDetail('cartList'))) {
            $data['cartList'] = $cartList;
        }

        $this->normalizerCartList($data);

        $response = $this
            ->client
            ->post(
                $this->settings->apiPaymentUrl.self::TOKEN_URL,
                [
                    RequestOptions::BODY => json_encode($data),
                    RequestOptions::HEADERS => [
                        'Content-Type' => 'application/json',
                        'Authorization' => 'Bearer '.$this->oauthToken,
                    ],
                    RequestOptions::HTTP_ERRORS => false,
                ]
            );

        $body = json_decode($response->getBody()->getContents(), true);

        if ($response->getStatusCode() != 200 || $body['successful'] === false) {
            // error has happened
            $message = $body['errorData']['message'] ?? 'خطا در هنگام درخواست برای پرداخت رخ داده است.';
            throw new PurchaseFailedException($message);
        }

        $this->invoice->transactionId($body['response']['paymentToken']);
        $this->setPaymentUrl($body['response']['paymentPageUrl']);

        // return the transaction's id
        return $this->invoice->getTransactionId();
    }

    public function pay(): RedirectionForm
    {
        return $this->redirectWithForm($this->getPaymentUrl(), [], 'GET');
    }

    /**
     * @throws InvalidPaymentException
     */
    public function verify(): ReceiptInterface
    {
        $paymentToken = $this->invoice->getTransactionId();

        $response = $this
            ->client
            ->post(
                $this->settings->apiPaymentUrl.self::TOKEN_URL,
                [
                    RequestOptions::BODY => [
                        'paymentToken' => $paymentToken,
                    ],
                    RequestOptions::HEADERS => [
                        'Authorization' => 'Bearer '.$this->oauthToken,
                    ],
                    RequestOptions::HTTP_ERRORS => false,
                ]
            );
    }

    /**
     * @throws PurchaseFailedException
     */
    protected function oauth()
    {
        $response = $this
            ->client
            ->post(
                $this->settings->apiPaymentUrl.self::OAUTH_URL,
                [
                    RequestOptions::HEADERS => [
                        'Authorization' => 'Basic '.base64_encode("{$this->settings->client_id}:{$this->settings->client_secret}"),
                    ],
                    RequestOptions::FORM_PARAMS => [
                        'grant_type' => 'password',
                        'scope' => 'online-merchant',
                        'username' => $this->settings->username,
                        'password' => $this->settings->password,
                    ],
                    RequestOptions::HTTP_ERRORS => false,
                ]
            );

        if ($response->getStatusCode() != 200) {
            throw new PurchaseFailedException('خطا در هنگام احراز هویت.');
        }

        $body = json_decode($response->getBody()->getContents(), true);

        return $body['access_token'];
    }

    /**
     * @throws PurchaseFailedException
     */
    public function eligible()
    {
        if (is_null($amount = $this->invoice->getAmount())) {
            throw new PurchaseFailedException('"amount" is required for this method.');
        }

        $response = $this->client->get($this->settings->apiPaymentUrl.self::ELIGIBLE_URL, [
            RequestOptions::HEADERS => [
                'Authorization' => 'Bearer '.$this->oauthToken,
            ],
            RequestOptions::QUERY => [
                'amount' => $this->normalizerAmount($amount),
            ],
        ]);

        $body = json_decode($response->getBody()->getContents(), true);

        if ($response->getStatusCode() != 200) {
            throw new InvalidPaymentException('', (int) $response->getStatusCode());
        }

        return $body;
    }

    private function normalizerAmount(int $amount): int
    {
        return $amount * ($this->settings->currency == 'T' ? 10 : 1);
    }

    private function normalizerCartList(array &$data): void
    {
        if (isset($data['cartList']['shippingAmount'])) {
            $data['cartList'] = [$data['cartList']];
        }

        foreach ($data['cartList'] as &$item) {
            if (isset($item['shippingAmount'])) {
                $item['shippingAmount'] = $this->normalizerAmount($item['shippingAmount']);
            }

            if (isset($item['taxAmount'])) {
                $item['taxAmount'] = $this->normalizerAmount($item['taxAmount']);
            }

            if (isset($item['totalAmount'])) {
                $item['totalAmount'] = $this->normalizerAmount($item['totalAmount']);
            }

            foreach ($item['cartItems'] as &$cartItem) {
                $cartItem['amount'] = $this->normalizerAmount($cartItem['amount']);
            }
        }
    }

    public function settle()
    {
    }

    public function revert()
    {
    }

    public function status()
    {
    }

    public function cancel()
    {
    }

    public function update()
    {
    }

    public function getPaymentUrl(): string
    {
        return $this->paymentUrl;
    }

    public function setPaymentUrl(string $paymentUrl): void
    {
        $this->paymentUrl = $paymentUrl;
    }
}