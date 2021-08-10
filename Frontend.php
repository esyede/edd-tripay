<?php

namespace Tripay\EDD_Tripay;

defined('ABSPATH') or exit('No direct access.');

class Frontend
{
    const URL_API_SANDBOX = 'https://tripay.co.id/api-sandbox/';
    const URL_API_PRODUCTION = 'https://tripay.co.id/api/';

    private $time;

    public function __construct()
    {
        $timeZone = date_default_timezone_get();
        $this->time = time();

        if ($timeZone !== 'Asia/Jakarta') {
            date_default_timezone_set('Asia/Jakarta');
            $this->time = time();
            date_default_timezone_set($timeZone);
        }

        $this->hooks();
    }

    public function hooks()
    {
        add_filter('edd_payment_gateways', [$this, 'registerTripay']);
        add_action('edd_tripay_cc_form', '__return_false');
        add_action('edd_gateway_tripay', [$this, 'processPayment']);
        add_action('edd_pre_process_purchase', [$this, 'isTripayConfigured'], 1);
        add_action('init', [$this, 'processRedirect']);
        add_action('edd_tripay_ipn_verify', [$this, 'processIPN']);
        add_filter('edd_currencies', [$this, 'addIdrCurrency']);
        add_filter('edd_currency_symbol', [$this, 'addIdrCurrencySymbol']);

        add_action('edd_purchase_form_user_info_fields', [$this, 'addCustomCheckoutFields']);
        add_action('edd_checkout_error_checks', [$this, 'validateCustomCheckoutFields'], 10, 2);
        add_filter('edd_payment_meta', [$this, 'storeCustomCheckoutFields']);
        add_action('edd_payment_personal_details_list', [$this, 'addCustomCheckoutFieldsToOrderDetailView'], 10, 2);
        add_action('edd_add_email_tags', [$this, 'addCustomCheckoutFieldsToEmailTags']);
    }

    public function registerTripay($gateways)
    {
        $label = edd_get_option('edd_tripay_checkout_label', 'TriPay Payment');

        $gateways['tripay'] = [
            'admin_label' => __('Tripay', 'edd-tripay'),
            'checkout_label' => __($label, 'edd-tripay'),
        ];

        return $gateways;
    }

    public function isTripayConfigured()
    {
        $isEnabled = edd_is_gateway_active('tripay');
        $chosenGateway = edd_get_chosen_gateway();

        if ('tripay' === $chosenGateway && (! $isEnabled || false === Helper::allSettingsAreProvided())) {
            edd_set_error('tripay_gateway_not_configured', __('Tripay payment gateway is not setup.', 'edd-tripay'));
        }

        if ('tripay' === $chosenGateway && strtoupper(edd_get_currency()) !== 'IDR') {
            edd_set_error(
                'tripay_gateway_invalid_currency',
                __('Currency not supported by Tripay. Set the store currency to Indonesian Rupiah (IDR)', 'edd-tripay')
            );
        }
    }

    public function getPaymentLink($data)
    {
        $tripayApiUrl = (edd_get_option('edd_tripay_sandbox_mode')) ? self::URL_API_SANDBOX : self::URL_API_PRODUCTION;
        $tripayApiUrl .= 'transaction/create';

        $callbackUrl = add_query_arg('edd-listener', 'tripay', home_url('index.php'));
        $merchantCode = edd_get_option('edd_tripay_merchant_code');
        $apiKey = edd_get_option('edd_tripay_api_key');
        $privateKey = edd_get_option('edd_tripay_private_key');
        $expiresAfter = (int) edd_get_option('edd_tripay_expires_after', 1);
        $returnUrl = edd_get_success_page_uri('?payment-confirmation=tripay');

        $orderItems = [];
        $cartDetails = $data['cart_details'];

        for ($i = 0; $i < count($cartDetails); $i++) {
            $orderItems[$i] = [
                'sku' => 'SKU-'.$cartDetails[$i]['id'].'-'.$data['post_data']['edd-process-checkout-nonce'].'-EDD',
                'name' => $cartDetails[$i]['name'],
                'price' => $cartDetails[$i]['item_price'],
                'quantity' => $cartDetails[$i]['quantity'],
            ];
        }

        $body = [
            'method' => $data['post_data']['edd_channel'],
            'merchant_ref' => $data['merchant_ref'],
            'amount' => $data['subtotal'],
            'customer_name' => $data['user_info']['first_name'].' '.$data['user_info']['last_name'],
            'customer_email' => $data['user_info']['email'],
            'customer_phone' => $data['post_data']['edd_phone'],
            'order_items' => $orderItems,
            'callback_url' => $callbackUrl,
            'return_url' => $returnUrl,
            'expired_time' => ($this->time + (24 * 60 * 60 * (int) $expiresAfter)),
            'signature' => hash_hmac('sha256', $merchantCode.$data['merchant_ref'].$data['subtotal'], $privateKey),
        ];

        $payloads = [
            'body' => $body,
            'headers' => ['Authorization' => 'Bearer '.$apiKey],
            'timeout' => 120,
        ];

        $request = wp_remote_post($tripayApiUrl, $payloads);
        $response = json_decode(wp_remote_retrieve_body($request));

        if (JSON_ERROR_NONE !== json_last_error()) {
            throw new \Exception('Invalid JSON format detected.');
        }

        return $response;
    }

    public function processPayment($purchase)
    {
        $payments = [
            'price' => (int) $purchase['price'],
            'date' => $purchase['date'],
            'user_email' => $purchase['user_email'],
            'purchase_key' => $purchase['purchase_key'],
            'currency' => edd_get_currency(),
            'downloads' => $purchase['downloads'],
            'cart_details' => $purchase['cart_details'],
            'user_info' => $purchase['user_info'],
            'status' => 'pending',
            'gateway' => 'tripay',
        ];

        $paymentId = edd_insert_payment($payments);

        if (false === $paymentId) {
            edd_record_gateway_error(
                'Payment Error',
                sprintf(
                    'Payment creation failed before sending buyer to Tripay. Payment data: %s',
                    json_encode($payments)
                ),
                $paymentId
            );

            edd_send_back_to_checkout('?payment-mode=tripay');
        } else {
            $transactionId = 'EDD-'.$paymentId.'-'.uniqid(); // Ex: EDD-12-6a87vfft55
            $data = ['merchant_ref' => $transactionId]; // Ex: T429518138RGQI9, DEV-T429518138RGQI9 (sandbox)
            $data = array_merge($data, $purchase);

            edd_set_payment_transaction_id($paymentId, $transactionId);

            $result = $this->getPaymentLink($data);

            if (isset($result->success) && $result->success) {
                wp_redirect($result->data->checkout_url); // redirect to tripay
                exit;
            }

            $error = isset($result->message)
                ? __('payment gateway responded with: "'.$result->message.'"', 'edd-tripay')
                : __('Unable to connect to the payment gateway, try again.', 'edd-tripay');

            edd_set_error('tripay_error', $error);
            edd_send_back_to_checkout('?payment-mode=tripay');
        }
    }

    public function processRedirect()
    {
        if (! isset($_GET['edd-listener'])) {
            return;
        }

        if ('tripay' === sanitize_text_field($_GET['edd-listener'])) {
            do_action('edd_tripay_ipn_verify');
        }
    }

    public function processIPN()
    {
        if ((strtoupper($_SERVER['REQUEST_METHOD']) !== 'POST')
        || ! array_key_exists('HTTP_X_CALLBACK_SIGNATURE', $_SERVER)) {
            exit;
        }

        $json = file_get_contents('php://input');

        $tripaySignature = isset($_SERVER['HTTP_X_CALLBACK_SIGNATURE']) ? $_SERVER['HTTP_X_CALLBACK_SIGNATURE'] : '';
        $localSignature = hash_hmac('sha256', $json, edd_get_option('edd_tripay_private_key'));

        if (! hash_equals($tripaySignature, $localSignature)) {
            edd_record_gateway_error('Error', 'Local signature does not match against TriPay signature.');
            exit;
        }

        $tripay = json_decode($json);

        if (JSON_ERROR_NONE !== json_last_error()) {
            throw new \Exception('Invalid JSON format detected.');
        }

        $event = $_SERVER['HTTP_X_CALLBACK_EVENT'];

        if ($event == 'payment_status') {
            error_log("event == 'payment_status'");

            if ($tripay->status == 'PAID') {
                error_log("status == 'paid'");

                http_response_code(200);

                $transactionId = $tripay->reference;
                $savedPaymentId = edd_get_purchase_id_by_transaction_id($transactionId);
                $paymentStatus = edd_get_payment_status($savedPaymentId);

                if ($savedPaymentId && in_array($paymentStatus, ['publish', 'complete'], true)) {
                    error_log('payment already published');
                    exit;
                }

                error_log(json_encode($tripay));

                $refString = explode('-', $tripay->merchant_ref);

                if (! is_array($refString) || empty($refString)) {
                    error_log('Invalid merchant reference.');
                    exit;
                }

                $paymentId = $refString[1];
                $savedTransactionRef = edd_get_payment_transaction_id($paymentId);

                if ($tripay->merchant_ref !== $savedTransactionRef) {
                    error_log('trxref != savedref. TriRef = '.$tripay->reference.' - SavRef: '.$savedTransactionRef);
                    exit;
                }

                $payment = new \EDD_Payment($paymentId);

                if (! $payment) {
                    error_log('EDD_Payment null');
                    exit;
                }

                $orderTotal = edd_get_payment_amount($paymentId);
                $currencySymbol = edd_currency_symbol($payment->currency);
                $amountPaid = $tripay->total_amount;
                $tripayTransactionRef = $tripay->reference;

                if ($amountPaid < $orderTotal) {
                    error_log('amount paid mismatch. paid: '.$amountPaid.' -- order total: '.$orderTotal);

                    $formatted_amount_paid = $currencySymbol.$amountPaid;
                    $formatted_order_total = $currencySymbol.$orderTotal;

                    $note = sprintf(__(
                            'Look into this purchase. This order is currently revoked. '.
                            'Reason: Amount paid is less than the total order amount. '.
                            'Amount Paid was %1$s while the total order amount is %2$s. '.
                            'TriPay Transaction Reference: %3$s',
                        'edd-paystack'),
                        $formatted_amount_paid,
                        $formatted_order_total,
                        $tripayTransactionRef
                    );

                    $payment->status = 'revoked';
                    $payment->add_note($note);
                    $payment->transaction_id = $tripayTransactionRef;
                } else {
                    error_log('amount paid OK');

                    $note = sprintf(__(
                            'Payment transaction was successful. '.
                            'TriPay Transaction Reference: %s',
                        'edd-paystack'),
                        $tripayTransactionRef
                    );

                    $payment->status = 'publish';
                    $payment->add_note($note);
                    $payment->transaction_id = $tripayTransactionRef;
                }

                error_log('saving paymentpayloads');

                $payment->save();
                exit;
            }
        }

        error_log('OUTER BRACES');
        exit;
    }

    public function addIdrCurrency($currencies)
    {
        $currencies['IDR'] = 'Indonesian Rupiah (Rp.)';

        return $currencies;
    }

    public function addIdrCurrencySymbol()
    {
        return 'Rp.';
    }

    public function addCustomCheckoutFields()
    {
        echo '
        <p id="edd-phone-wrap">
            <label class="edd-label" for="edd-phone">'.__('Phone Number').
            ' <span class="edd-required-indicator">*</span></label>'.
            '<span class="edd-description">'.
            __('We will use this as well to personalize your account experience.').'
            </span>
            <input class="edd-input required" type="text" name="edd_phone" id="edd-phone" placeholder="'.__('Phone Number').'">
        </p>
        ';

        echo '
        <p id="edd-channel-wrap">
            <label class="edd-label" for="edd-channel">'.__('Payment Channel').
            ' <span class="edd-required-indicator">*</span></label>'.
            '<span class="edd-description">'.
            __('Choose your desired payment channel.').'
            </span>
            <select name="edd_channel" class="edd-input required" id="edd-channel" placeholder="'.__('Choose one').'..">
            <option value="">--- '.__('Choose one').'.. ---</option>';

        $channels = edd_get_option('edd_tripay_payment_chamnnels');
        $channels = is_array($channels) ? $channels : [];

        if (count($channels) >= 1) {
            foreach ($channels as $key => $value) {
                echo '<option value="'.$key.'">'.$value.'</option>';
            }
        }

        echo '
                </select>
            </p>
            ';
    }

    public function validateCustomCheckoutFields($valid_data, $data)
    {
        if (empty($data['edd_phone'])) {
            edd_set_error('invalid_phone', 'Please enter your phone number.');
        }

        if (empty($data['edd_channel'])) {
            edd_set_error('invalid_channel', 'Please choose payment channel.');
        }
    }

    public function storeCustomCheckoutFields($paymentMeta)
    {
        if (0 !== did_action('edd_pre_process_purchase')) {
            $paymentMeta['phone'] = isset($_POST['edd_phone']) ? sanitize_text_field($_POST['edd_phone']) : '';
            $paymentMeta['channel'] = isset($_POST['edd_channel']) ? sanitize_text_field($_POST['edd_channel']) : '';
        }

        return $paymentMeta;
    }

    public function addCustomCheckoutFieldsToOrderDetailView($paymentMeta, $userInfo)
    {
        $phone = isset($paymentMeta['phone']) ? $paymentMeta['phone'] : 'none';
        $channel = isset($paymentMeta['channel']) ? 'TRIPAY '.$paymentMeta['channel'] : 'none';

        echo '
        <div class="column-container">
            <div class="column">
                <strong>'.__('Phone').': </strong>'.$phone.'
            </div>
        </div>';

        echo '
        <div class="column-container">
            <div class="column">
                <strong>'.__('Payment Channel').': </strong>'.$channel.'
            </div>
        </div>';
    }

    public function addCustomCheckoutFieldsToEmailTags()
    {
        edd_add_email_tag('phone', __('Phone Number'), 'addPhoneToEmailTags');
        edd_add_email_tag('channel', __('Payment Channel'), 'addChannelToEmailTags');
    }

    public function addPhoneToEmailTags($paymentId)
    {
        $payments = edd_get_payment_meta($paymentId);

        return $payments['phone'];
    }

    public function addChannelToEmailTags($paymentId)
    {
        $payments = edd_get_payment_meta($paymentId);

        return $payments['channel'];
    }
}

new namespace\Frontend();
