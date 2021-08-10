<?php

namespace Tripay\EDD_Tripay;

defined('ABSPATH') or exit('No direct access.');

class Helper
{
    public static function allSettingsAreProvided($value = '')
    {
        $merchantCode = trim(edd_get_option('edd_tripay_merchant_code'));
        $apiKey = trim(edd_get_option('edd_tripay_api_key'));
        $privateKey = trim(edd_get_option('edd_tripay_private_key'));
        $expiresAfter = (int) trim(edd_get_option('edd_tripay_expires_after'));
        $paymentChannels = edd_get_option('edd_tripay_payment_chamnnels');

        return (
            ! empty($merchantCode)
            && ! empty($apiKey)
            && ! empty($privateKey)
            && ($expiresAfter >= 1 && $expiresAfter <= 7)
            && (! empty($paymentChannels) && is_array($paymentChannels) && count($paymentChannels) >= 1));
    }
}
