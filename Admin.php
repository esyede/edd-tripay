<?php

namespace Tripay\EDD_Tripay;

defined('ABSPATH') or exit('No direct access.');

class Admin
{
    public function __construct()
    {
        $this->registerHooks();
    }

    public function registerHooks()
    {
        add_filter('edd_settings_sections_gateways', [$this, 'settingSection']);
        add_filter('edd_settings_gateways', [$this, 'showSettings'], 1);
        add_filter('plugin_action_links_'.plugin_basename(EDD_TRIPAY_PLUGIN_FILE), [$this, 'pluginActionLinks']);
        add_action('admin_notices', [$this, 'showSandboxModeNotice']);
        add_action('admin_notices', [$this, 'showTripayNotConfiguredNotice']);
    }

    public function settingSection($sections)
    {
        $sections['tripay-settings'] = __('Tripay', 'edd-tripay');

        return $sections;
    }

    public function showSettings($settings)
    {
        $help = '<br>For <b>Sandbox</b> mode see <a href="https://tripay.co.id/simulator/merchant" '.
            'target="_blank">here</a>'.
            '<br>For <b>Production</b> mode see <a href="https://tripay.co.id/member/merchant" '.
            'target="_blank">here</a>';

        $forms = [
            [
                'id' => 'edd_tripay_settings',
                'name' => '<strong>'.__('Tripay Settings', 'edd-tripay').'</strong>',
                'desc' => __('Configure the gateway settings', 'edd-tripay'),
                'type' => 'header',
            ],
            [
                'id' => 'edd_tripay_sandbox_mode',
                'name' => __('Use Sandbox API', 'edd-tripay'),
                'desc' => $help,
                'type' => 'checkbox',
                'std' => 0,
            ],
            [
                'id' => 'edd_tripay_merchant_code',
                'name' => __('Merchant Code', 'edd-tripay'),
                'desc' => $help,
                'type' => 'text',
                'size' => 'regular',
            ],
            [
                'id' => 'edd_tripay_callback_url',
                'type' => 'descriptive_text',
                'name' => __('Callback URL', 'edd-tripay'),
                'desc' => '<p>Use this for Callback URL on your Tripay dashboard:</p>'.
                    '<p><strong><pre>'.home_url('index.php?edd-listener=tripay').'</pre></strong></p>',
            ],
            [
                'id' => 'edd_tripay_api_key',
                'name' => __('API Key', 'edd-tripay'),
                'desc' => $help,
                'type' => 'text',
                'size' => 'regular',
            ],
            [
                'id' => 'edd_tripay_private_key',
                'name' => __('Private Key', 'edd-tripay'),
                'desc' => $help,
                'type' => 'text',
                'size' => 'regular',
            ],
            [
                'id' => 'edd_tripay_payment_chamnnels',
                'name' => __('Payment Channels', 'edd-tripay'),
                'desc' => 'Choose on your desired payment channels',
                'type' => 'multicheck',
                'size' => 'regular',
                'options' => [
                    'ALFAMART' => 'Alfamart',
                    'ALFAMIDI' => 'Alfamidi',
                    'BCAVA' => 'Bank BCA',
                    'BNIVA' => 'Bank BNI',
                    'BRIVA' => 'Bank BRI',
                    'CIMBVA' => 'Bank CIMB',
                    'MANDIRIVA' => 'Bank Mandiri',
                    'MYBVA' => 'Bank MayBank',
                    'MUAMALATVA' => 'Bank Muamalat',
                    'PERMATAVA' => 'Bank Permata',
                    'SMSVA' => 'Bank Sinarmas',
                    'QRIS' => 'Scan QR',
                    'QRISC' => 'Scan QR (Customizable)',
                ],
            ],
            [
                'id' => 'edd_tripay_expires_after',
                'name' => __('Expires After', 'edd-tripay'),
                'desc' => '<br>Jumlah hari sebelum invoice dianggap kedaluwarsa.',
                'type' => 'select',
                'options' => [
                    '1' => '1 Day',
                    '2' => '2 Days',
                    '3' => '3 Days',
                    '4' => '4 Days',
                    '5' => '5 Days',
                    '6' => '6 Days',
                    '7' => '7 Days',
                ],
                'size' => 'regular',
            ],
            [
                'id' => 'edd_tripay_checkout_label',
                'name' => __('Checkout Label', 'edd-tripay'),
                'desc' => '<br>Payment gateway display name on checkout page',
                'type' => 'text',
                'size' => 'regular',
            ],
        ];

        $forms = version_compare(EDD_VERSION, 2.5, '>=') ? ['tripay-settings' => $forms] : $forms;

        return array_merge($settings, $forms);
    }

    public function pluginActionLinks($links)
    {
        $link = admin_url('edit.php?post_type=download&page=edd-settings&tab=gateways&section=tripay-settings');
        $anchor = '<a href="'.esc_url($link).'">'.__('Settings', 'edd-tripay').'</a>';

        array_unshift($links, $anchor);

        return $links;
    }

    public function showSandboxModeNotice()
    {
        if (edd_get_option('edd_tripay_sandbox_mode')) {
            echo '<div class="notice notice-error">
                <p>'.__(
                    'TriPay Sandbox mode is still enabled for Easy Digital Downloads, '.
                    'click <a href="'.admin_url('edit.php?post_type=download&page=edd-settings&tab=gateways&section=tripay-settings')
                    .'">here</a> to disable it when you want to start accepting live payment on your site.', 'edd-tripay'
                ).'
                </p>
             </div>';
        }
    }

    public function showTripayNotConfiguredNotice()
    {
        if (! Helper::allSettingsAreProvided()) {
            echo '<div class="notice notice-error">
                <p>'.__(
                    'The TriPay Payment Gateway for Easy Digital Downloads has not been fully configured, '.
                    'please <a href="'.admin_url('edit.php?post_type=download&page=edd-settings&tab=gateways&section=tripay-settings').'">complete the setup</a> by adding your settings.', 'edd-tripay'
                ).'
                </p>
             </div>';
        }
    }
}

new namespace\Admin();
