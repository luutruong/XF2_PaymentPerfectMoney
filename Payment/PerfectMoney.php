<?php

namespace Truonglv\PaymentPerfectMoney\Payment;

use XF\Mvc\Controller;
use XF\Purchasable\Purchase;
use XF\Entity\PaymentProfile;
use XF\Payment\CallbackState;
use XF\Entity\PurchaseRequest;
use XF\Payment\AbstractProvider;

class PerfectMoney extends AbstractProvider
{
    /**
     * List of perfect money server IPs.
     *
     * @var string[]
     */
    private $whitelistIps = [
        '77.109.141.170',
        '91.205.41.208',
        '94.242.216.60',
        '78.41.203.75'
    ];

    /**
     * @return \XF\Phrase
     */
    public function getTitle()
    {
        return \XF::phraseDeferred('tpm_perfect_money_payment_title');
    }

    /**
     * @param array $options
     * @param mixed $errors
     * @return bool
     */
    public function verifyConfig(array &$options, &$errors = [])
    {
        $options = array_replace([
            'payee_account' => '',
            'payee_name' => '',
            'alternate_passphrase' => ''
        ], $options);

        if (strlen($options['payee_account']) === 0) {
            $errors[] = \XF::phraseDeferred('tpm_perfect_money_error_invalid_payee_account');

            return false;
        }

        if (strlen($options['payee_name']) === 0) {
            $errors[] = \XF::phraseDeferred('tpm_perfect_money_error_invalid_payee_name');

            return false;
        }

        if (strlen($options['alternate_passphrase']) === 0) {
            $errors[] = \XF::phraseDeferred('tpm_perfect_money_error_invalid_alternate_passphrase');

            return false;
        }

        return true;
    }

    /**
     * @param PurchaseRequest $purchaseRequest
     * @param Purchase $purchase
     * @return array
     */
    protected function getPaymentParams(PurchaseRequest $purchaseRequest, Purchase $purchase)
    {
        /** @var PaymentProfile $profile */
        $profile = $purchaseRequest->PaymentProfile;

        return [
            'PAYEE_ACCOUNT' => $profile->options['payee_account'],
            'PAYEE_NAME' => $profile->options['payee_name'],
            'PAYMENT_AMOUNT' => $purchase->cost,
            'PAYMENT_UNITS' => $purchase->currency,
            'PAYMENT_ID' => $purchaseRequest->request_key,
            'STATUS_URL' => $this->getCallbackUrl(),
            'PAYMENT_URL' => $purchase->returnUrl,
            'PAYMENT_URL_METHOD' => 'POST',
            'NOPAYMENT_URL' => $purchase->cancelUrl,
            'NOPAYMENT_URL_METHOD' => 'GET'
        ];
    }

    /**
     * @param Controller $controller
     * @param PurchaseRequest $purchaseRequest
     * @param Purchase $purchase
     * @return \XF\Mvc\Reply\View
     */
    public function initiatePayment(Controller $controller, PurchaseRequest $purchaseRequest, Purchase $purchase)
    {
        $params = $this->getPaymentParams($purchaseRequest, $purchase);
        $apiUrl = $this->getApiEndpoint() . '/step1.asp';

        return $controller->view('', 'payment_initiate_perfect_money', [
            'formAction' => $apiUrl,
            'formParams' => $params,
            'purchaseRequest' => $purchaseRequest,
            'purchase' => $purchase,
        ]);
    }

    /**
     * @param \XF\Http\Request $request
     * @return CallbackState
     */
    public function setupCallback(\XF\Http\Request $request)
    {
        $state = new CallbackState();
        $state->_POST = $_POST;

        $inputFiltered = $request->filter([
            'PAYEE_ACCOUNT' => 'str',
            'PAYMENT_ID' => 'str',
            'PAYMENT_AMOUNT' => 'unum',
            'PAYMENT_UNITS' => 'str',
            'PAYMENT_BATCH_NUM' => 'str',
            'PAYER_ACCOUNT' => 'str',
            'TIMESTAMPGMT' => 'uint',
            'V2_HASH' => 'str'
        ]);

        $state->transactionId = $inputFiltered['PAYMENT_BATCH_NUM'];
        $state->requestKey = $inputFiltered['PAYMENT_ID'];

        $state->filtered = $inputFiltered;
        $state->ip = $request->getIp();

        return $state;
    }

    /**
     * @param CallbackState $state
     * @return bool
     */
    public function validateCallback(CallbackState $state)
    {
        /** @var PurchaseRequest|null $purchaseRequest */
        $purchaseRequest = $state->getPurchaseRequest();

        if (!$state->requestKey || $purchaseRequest === null) {
            $state->logType = 'error';
            $state->logMessage = 'Notification does not contain a recognised purchase request.';

            return false;
        }

        /** @var PaymentProfile|null $paymentProfile */
        $paymentProfile = $purchaseRequest->PaymentProfile;
        if ($paymentProfile === null) {
            $state->logType = 'error';
            $state->logMessage = 'Notification does not contain a valid payment profile';

            return false;
        }

        $passPhrase = $paymentProfile->options['alternate_passphrase'];
        $inputFiltered = $state->filtered;

        $computeHash = strtoupper(md5(implode(':', [
            $inputFiltered['PAYMENT_ID'],
            $inputFiltered['PAYEE_ACCOUNT'],
            $inputFiltered['PAYMENT_AMOUNT'],
            $inputFiltered['PAYMENT_UNITS'],
            $inputFiltered['PAYMENT_BATCH_NUM'],
            $inputFiltered['PAYER_ACCOUNT'],
            strtoupper(md5($passPhrase)),
            $inputFiltered['TIMESTAMPGMT']
        ])));

        if (strlen($inputFiltered['V2_HASH']) === 0
            || $inputFiltered['V2_HASH'] !== $computeHash
        ) {
            $state->logType = 'error';
            $state->logMessage = 'Could not verify PerferctMoney hash.';

            return false;
        }

        if (!in_array($state->ip, $this->whitelistIps, true)) {
            return false;
        }

        return true;
    }

    /**
     * @param CallbackState $state
     * @return bool
     */
    public function validateTransaction(CallbackState $state)
    {
        if (strlen($state->filtered['PAYMENT_BATCH_NUM']) === 0) {
            $state->logType = 'info';
            $state->logMessage = 'No transaction ID. No action to take.';

            return false;
        }

        return true;
    }

    /**
     * @param CallbackState $state
     * @return bool
     */
    public function validateCost(CallbackState $state)
    {
        /** @var PurchaseRequest $purchaseRequest */
        $purchaseRequest = $state->getPurchaseRequest();
        $filtered = $state->filtered;

        $costValidated = (
            $filtered['PAYMENT_AMOUNT'] === $purchaseRequest->cost_amount
            && $filtered['PAYMENT_UNITS'] === $purchaseRequest->cost_currency
        );
        if (!$costValidated) {
            $state->logType = 'error';
            $state->logMessage = 'Invalid cost amount';

            return false;
        }

        return true;
    }

    /**
     * @param CallbackState $state
     * @return void
     */
    public function getPaymentResult(CallbackState $state)
    {
        $state->paymentResult = CallbackState::PAYMENT_RECEIVED;
    }

    /**
     * @param CallbackState $state
     * @return void
     */
    public function prepareLogData(CallbackState $state)
    {
        $state->logDetails = [
            '_GET' => $_GET,
            '_POST' => $_POST
        ];
    }

    /**
     * @param PaymentProfile $paymentProfile
     * @param mixed $unit
     * @param mixed $amount
     * @param mixed $result
     * @return bool
     */
    public function supportsRecurring(PaymentProfile $paymentProfile, $unit, $amount, &$result = self::ERR_NO_RECURRING)
    {
        return false;
    }

    /**
     * @return string
     */
    public function getApiEndpoint()
    {
        return 'https://perfectmoney.is/api';
    }
}
