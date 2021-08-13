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
                'Mata uang yang anda pilih tidak didukung oleh Tripay. '.
                'Silahkan ubah konfigurasi mata uang di EDD menjadi Rupiah (IDR) terlebih dahulu'
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

        Helper::log('Payload: '.json_encode($payloads));

        $request = wp_remote_post($tripayApiUrl, $payloads);
        $response = json_decode(wp_remote_retrieve_body($request));

        Helper::log('Respon: '.json_encode($response));

        if (JSON_ERROR_NONE !== json_last_error()) {
            Helper::log('Respon json yang didapat mengandung karakter yang tidak valid');
            exit;
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

        Helper::log('Data EDD: '.json_encode($payments));

        $paymentId = edd_insert_payment($payments);

        Helper::log('ID Payment EDD: '.$paymentId);

        if (false === $paymentId) {
            $message = 'Error! Tidak dapat meng-insert data pembayaran ke database EDD. Data pembayaran: '.json_encode($payments);

            Helper::log($message);
            edd_record_gateway_error('Payment Error', $message, $paymentId);

            Helper::log('Mengembalikan user ke halaman checkout..');
            edd_send_back_to_checkout('?payment-mode=tripay');
        } else {
            $transactionId = 'EDD-'.$paymentId.'-'.uniqid(); // Ex: EDD-12-6a87vfft55
            $data = ['merchant_ref' => $transactionId]; // Ex: T429518138RGQI9, DEV-T429518138RGQI9 (sandbox)
            $data = array_merge($data, $purchase);

            Helper::log('Merchant Ref untuk TriPay: '.$transactionId);

            edd_set_payment_transaction_id($paymentId, $transactionId);

            $result = $this->getPaymentLink($data);

            if (isset($result->success) && $result->success) {
                Helper::log('Mengalihkan user ke halaman pembayaran TriPay: '.$result->data->checkout_url);

                wp_redirect($result->data->checkout_url); // redirect to tripay

                Helper::log('Selesai. dialihkan ke halaman pembayaran TriPay!');
                exit;
            }

            Helper::log('Error! Gagal mendapatkan link ke halaman pembayaran TriPay.');

            $message = isset($result->message) ? $result->message : 'Sambungan ke payment gateway gagal, coba lagi.';

            Helper::log($message);
            edd_set_error('tripay_error', $message);

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
            Helper::log('Error! Call back atau signature tidak valid.');
            exit;
        }

        $json = file_get_contents('php://input');

        Helper::log('Data JSPN TriPay: '.$json);

        $tripaySign = isset($_SERVER['HTTP_X_CALLBACK_SIGNATURE']) ? $_SERVER['HTTP_X_CALLBACK_SIGNATURE'] : '';
        $localSign = hash_hmac('sha256', $json, edd_get_option('edd_tripay_private_key'));

        if (! hash_equals($tripaySign, $localSign)) {
            $message = 'Error! Signature tidak cocok. Lokal: '.$localSign.' --- TriPay: '.$tripaySign;

            Helper::log($message);
            edd_record_gateway_error('Error', $message);
            exit;
        }

        $tripay = json_decode($json);

        if (JSON_ERROR_NONE !== json_last_error()) {
            $message = 'Error! Format JSON yang didapat tidak valid.';

            Helper::log($message);
            echo json_encode(['success' => false, 'message' => $message]);
            exit;
        }

        $event = $_SERVER['HTTP_X_CALLBACK_EVENT'];

        http_response_code(200);

        if ($event == 'payment_status') {
            $savedId = edd_get_purchase_id_by_transaction_id($tripay->merchant_ref);
            $status = edd_get_payment_status($savedId);

            switch ($tripay->status) {
                case 'UNPAID':
                    // Status invoice sudah unpaid sebelumnya.
                    if (in_array($status, ['unpaid'], true)) {
                        $message = 'Gagal ubah status pembayaran ke UNPAID: Status invoice sudah UNPAID.';

                        Helper::log('Error! '.$message);
                        echo json_encode(['success' => false, 'message' => $message]);
                        exit;
                    }

                    // Sudah expired, stop!
                    if (in_array($status, ['abandoned'], true)) {
                        $message = 'Gagal ubah status pembayaran ke UNPAID: Invoice sudah EXPIRED.';

                        Helper::log('Error! '.$message);
                        echo json_encode(['success' => false, 'message' => $message]);
                        exit;
                    }

                    // Sudah dibayar sebelumnya, tidak boleh di set ke unpaid.
                    if (in_array($status, ['publish', 'complete'], true)) {
                        $message = 'Gagal ubah status pembayaran ke PAID: Status invoice sudah PAID.';

                        Helper::log('Error! '.$message);
                        echo json_encode(['success' => false, 'message' => $message]);
                        exit;
                    }

                    $ref = explode('-', $tripay->merchant_ref);

                    if (! is_array($ref) || empty($ref)) {
                        $message = 'Gagal ubah status pembayaran ke UNPAID: Reference ID tidak valid.';

                        Helper::log('Error! '.$message);
                        echo json_encode(['success' => false, 'message' => $message]);
                        exit;
                    }

                    $paymentId = $ref[1];
                    $savedRef = edd_get_payment_transaction_id($paymentId);

                    if ($tripay->merchant_ref !== $savedRef) {
                        $message = 'Gagal ubah status pembayaran ke UNPAID: Merchant ref tidak cocok. '.
                            'MRef TriPay: '.$tripay->merchant_ref.', MRef EDD: '.$savedRef;

                        Helper::log('Error! '.$message);
                        echo json_encode(['success' => false, 'message' => $message]);
                        exit;
                    }


                    $payment = new \EDD_Payment($paymentId);

                    $note = 'Status pembayaran sukses diubah ke UNPAID/PENDING. TriPay Ref: '.$tripay->reference;

                    $payment->status = 'pending';
                    $payment->add_note($note);
                    $payment->transaction_id = $tripay->merchant_ref;
                    $payment->save();

                    Helper::log('Success! '.$note);
                    echo json_encode(['success' => true, 'message' => $note]);
                    exit;
                    break;

                case 'PAID':
                    // Sudah dibayar sebelumnya, stop!
                    if (in_array($status, ['publish', 'complete'], true)) {
                        $message = 'Gagal ubah status pembayaran ke PAID: Status invoice sudah PAID.';

                        Helper::log('Error! '.$message);
                        echo json_encode(['success' => false, 'message' => $message]);
                        exit;
                    }

                    // Sudah expired, stop!
                    if (in_array($status, ['abandoned'], true)) {
                        $message = 'Gagal ubah status pembayaran ke PAID: Status invoice sudah EXPIRED.';

                        Helper::log('Error! '.$message);
                        echo json_encode(['success' => false, 'message' => $message]);
                        exit;
                    }

                    $ref = explode('-', $tripay->merchant_ref);

                    if (! is_array($ref) || empty($ref)) {
                        $message = 'Gagal ubah status pembayaran ke PAID: Reference ID tidak valid.';

                        Helper::log('Error! '.$message);
                        echo json_encode(['success' => false, 'message' => $message]);
                        exit;
                    }

                    $paymentId = $ref[1];
                    $savedRef = edd_get_payment_transaction_id($paymentId);

                    if ($tripay->merchant_ref !== $savedRef) {
                        $message = 'Gagal ubah status pembayaran ke PAID: Merchant Ref tidak cocok. '.
                            'MRef TriPay: '.$tripay->merchant_ref.', MRef EDD: '.$savedRef;

                        Helper::log('Error! '.$message);
                        echo json_encode(['success' => false, 'message' => $message]);
                        exit;
                    }


                    $payment = new \EDD_Payment($paymentId);

                    if (! $payment) {
                        $message = 'Gagal ubah status pembayaran ke PAID: Invoice tidak ditemukan.';

                        Helper::log('Error! '.$message);
                        echo json_encode(['success' => false, 'message' => $message]);
                        exit;
                    }

                    $charged = edd_get_payment_amount($paymentId);
                    $rupiah = edd_currency_symbol($payment->currency);
                    $paid = $tripay->total_amount;

                    // Gagal, harga barang lebih kecil dari jumlah bayar customer.
                    if ($paid < $charged) {
                        $note = sprintf(
                            'Pesanan dibatalkan, jumlah pembayaran tidak cocok. Dibayar: %1$s Tagihan: %2$s. TriPay Ref: %3$s',
                            $rupiah.$paid,
                            $rupiah.$charged,
                            $tripay->reference
                        );

                        $payment->status = 'revoked';
                        $payment->add_note($note);
                        $payment->transaction_id = $tripay->merchant_ref;
                        $payment->save();

                        Helper::log('Error! '.$note);
                        echo json_encode(['success' => false, 'message' => $note]);
                        exit;

                    // OK. ubah status menjadi paid.
                    } else {
                        $note = sprintf('Pembayaran berhasil. TriPay Ref: %s', $tripay->reference);
                        $payment->status = 'publish';
                        $payment->add_note($note);
                        $payment->transaction_id = $tripay->merchant_ref;
                        $payment->save();

                        Helper::log('Success! '.$note);
                        echo json_encode(['success' => true, 'message' => $note]);
                        exit;
                    }
                    break;

                case 'EXPIRED':
                    // Sudah dibayar sebelumnya, tidak boleh diubah lagi ke expired.
                    if (in_array($status, ['publish', 'complete'], true)) {
                        $message = 'Gagal ubah status pembayaran keo EXPIRED: Status invoice sudah PAID.';

                        Helper::log('Error! '.$message);
                        echo json_encode(['success' => false, 'message' => $message]);
                        exit;
                    }

                    // Sudah di set expired sebelumnya, stop!
                    if (in_array($status, ['abandoned'], true)) {
                        $message = 'Gagal ubah status pembayaran ke EXPIRED: Status invoice sudah EXPIRED.';

                        Helper::log('Error! '.$message);
                        echo json_encode(['success' => false, 'message' => $message]);
                        exit;
                    }

                    $ref = explode('-', $tripay->merchant_ref);

                    if (! is_array($ref) || empty($ref)) {
                        $message = 'Gagal ubah status pembayaran ke EXPIRED: Reference ID tidak valid.';

                        Helper::log('Error! '.$message);
                        echo json_encode(['success' => false, 'message' => $message]);
                        exit;
                    }

                    $paymentId = $ref[1];
                    $savedRef = edd_get_payment_transaction_id($paymentId);

                    if ($tripay->merchant_ref !== $savedRef) {
                        $message = 'Gagal ubah status pembayaran ke EXPIRED: Merchant Ref tidak cocok. '.
                            'MRef TriPay: '.$tripay->merchant_ref.', MRef EDD: '.$savedRef;

                        Helper::log('Error! '.$message);
                        echo json_encode(['success' => false, 'message' => $message]);
                        exit;
                    }


                    $payment = new \EDD_Payment($paymentId);

                    if (! $payment) {
                        $message = 'Gagal ubah status pembayaran ke EXPIRED: Invoice tidak ditemukan.';

                        Helper::log('Error! '.$message);
                        echo json_encode(['success' => false, 'message' => $message]);
                        exit;
                    }

                    $note = sprintf(
                        'Status pembayaran sukses diubah ke EXPIRED/ABANDONED. TriPay Ref: %s',
                        $tripay->reference
                    );

                    // List order status yang tersedia di EDD hanya:
                    // 'publish', 'complete', 'pending', 'refunded', 'revoked',
                    // 'failed', 'abandoned', 'preapproval', 'cancelled'
                    $payment->status = 'abandoned';
                    $payment->add_note($note);
                    $payment->transaction_id = $tripay->merchant_ref;
                    $payment->save();

                    Helper::log('Success! '.$note);
                    echo json_encode(['success' => true, 'message' => $note]);
                    exit;
                    break;

                case 'FAILED':
                    // Sudah dibayar sebelumnya, tidak boleh diubah lagi ke failed.
                    if (in_array($status, ['publish', 'complete'], true)) {
                        $message = 'Gagal ubah status pembayaran ke FAILED: Status invoice sudah PAID.';

                        Helper::log('Error! '.$message);
                        echo json_encode(['success' => false, 'message' => $message]);
                        exit;
                    }

                    $ref = explode('-', $tripay->merchant_ref);

                    if (! is_array($ref) || empty($ref)) {
                        $message = 'Gagal ubah status pembayaran ke FAILED: Reference ID tidak valid.';

                        Helper::log('Error! '.$message);
                        echo json_encode(['success' => false, 'message' => $message]);
                        exit;
                    }

                    $paymentId = $ref[1];
                    $savedRef = edd_get_payment_transaction_id($paymentId);

                    if ($tripay->merchant_ref !== $savedRef) {
                        $message = 'Gagal ubah status pembayaran ke FAILED: Merchant Ref tidak cocok. '.
                            'MRef TriPay: '.$tripay->merchant_ref.', Mref EDD: '.$savedRef;

                        Helper::log('Error! '.$message);
                        echo json_encode(['success' => false, 'message' => $message]);
                        exit;
                    }


                    $payment = new \EDD_Payment($paymentId);

                    if (! $payment) {
                        $message = 'Gagal ubah status pembayaran ke FAILED: Invoice tidak ditemukan.';

                        Helper::log('Error! '.$message);
                        echo json_encode(['success' => false, 'message' => $message]);
                        exit;
                    }

                    $note = sprintf('Status pembayaran sukses diubah ke FAILED. TriPay Ref: %s', $tripay->reference);

                    $payment->status = 'failed';
                    $payment->add_note($note);
                    $payment->transaction_id = $tripay->merchant_ref;
                    $payment->save();

                    Helper::log('Success! '.$note);
                    echo json_encode(['success' => true, 'message' => $note]);
                    exit;
                    break;

                case 'REFUND':
                    // Status invoice belum dibayar, tidak boleh diubah ke refunded.
                    if (! in_array($status, ['publish', 'complete'], true)) {
                        $message = 'Gagal ubah status pembayaran ke REFUND: Status invoice masih UNPAID.';

                        Helper::log('Error! '.$message);
                        echo json_encode(['success' => false, 'message' => $message]);
                        exit;
                    }

                    // Sudah expired, stop!
                    if (in_array($status, ['abandoned'], true)) {
                        $message = 'Gagal ubah status pembayaran ke REFUND: Status invoice sudah EXPIRED.';

                        Helper::log('Error! '.$message);
                        echo json_encode(['success' => false, 'message' => $message]);
                        exit;
                    }

                    // Sudah direfund sebelumnya, stop!
                    if (in_array($status, ['refunded'], true)) {
                        $message = 'Gagal ubah status pembayaran ke REFUND: Status invoice sudah REFUND.';

                        Helper::log('Error! '.$message);
                        echo json_encode(['success' => false, 'message' => $message]);
                        exit;
                    }

                    $ref = explode('-', $tripay->merchant_ref);

                    if (! is_array($ref) || empty($ref)) {
                        $message = 'Gagal ubah status pembayaran ke REFUND: Reference ID tidak valid.';

                        Helper::log('Error! '.$message);
                        echo json_encode(['success' => false, 'message' => $message]);
                        exit;
                    }

                    $paymentId = $ref[1];
                    $savedRef = edd_get_payment_transaction_id($paymentId);

                    if ($tripay->merchant_ref !== $savedRef) {
                        $message = 'Gagal ubah status pembayaran ke REFUND: Merchant Ref tidak cocok. '.
                            'MRef TriPay: '.$tripay->merchant_ref.', MRef EDD: '.$savedRef;

                        Helper::log('Error! '.$message);
                        echo json_encode(['success' => false, 'message' => $message]);
                        exit;
                    }


                    $payment = new \EDD_Payment($paymentId);

                    if (! $payment) {
                        $message = 'Gagal ubah status pembayaran ke REFUND: invoice tidak ditemukan.';

                        Helper::log('Error! '.$message);
                        echo json_encode(['success' => false, 'message' => $message]);
                        exit;
                    }

                    $note = sprintf('Status pembayaran sukses diubah ke REFUNDED. TriPay Ref: %s', $tripay->reference);

                    $payment->status = 'refunded';
                    $payment->transaction_id = $tripay->merchant_ref;
                    $payment->add_note($note);
                    $payment->save();

                    Helper::log('Success! '.$note);
                    echo json_encode(['success' => true, 'message' => $note]);
                    exit;
                    break;

                default:
                    $message = sprintf('Status pembayaran tidak dikenal: %s', $tripay->status);

                    Helper::log('Error! '.$message);
                    echo json_encode(['success' => false, 'message' => $message]);
                    exit;
                    break;
            }
        } else {
            $message = 'Ups! Tidak ada tindakan yang diambil.';

            Helper::log('Error! '.$message);
            echo json_encode(['success' => false, 'message' => $message]);
            exit;
        }
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
