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
        $help = '<br>Untuk mode <b>Sandbox</b> lihat <a href="https://tripay.co.id/simulator/merchant" '.
            'target="_blank">disini</a>'.
            '<br>Untuk mode <b>Production</b> lihat <a href="https://tripay.co.id/member/merchant" '.
            'target="_blank">disini</a>';

        $forms = [
            [
                'id' => 'edd_tripay_settings',
                'name' => '<strong>Pengaturan TriPay</strong>',
                'desc' => 'Kelola pengaturan TriPay Payment',
                'type' => 'header',
            ],
            [
                'id' => 'edd_tripay_sandbox_mode',
                'name' => 'Gunakan API Sandbox',
                'desc' => $help,
                'type' => 'checkbox',
                'std' => 0,
            ],
            [
                'id' => 'edd_tripay_merchant_code',
                'name' => 'Kode Merchant',
                'desc' => $help,
                'type' => 'text',
                'size' => 'regular',
            ],
            [
                'id' => 'edd_tripay_callback_url',
                'type' => 'descriptive_text',
                'name' => 'URL Callback',
                'desc' => '<p>Gunakan URL ini untuk isian URL Callback di dashboard TriPay:</p>'.
                    '<p><strong><pre>'.home_url('index.php?edd-listener=tripay').'</pre></strong></p>',
            ],
            [
                'id' => 'edd_tripay_api_key',
                'name' => 'API Key',
                'desc' => $help,
                'type' => 'text',
                'size' => 'regular',
            ],
            [
                'id' => 'edd_tripay_private_key',
                'name' => 'Private Key',
                'desc' => $help,
                'type' => 'text',
                'size' => 'regular',
            ],
            [
                'id' => 'edd_tripay_payment_chamnnels',
                'name' => 'Channel Pembayaran',
                'desc' => 'Centang channel pembayaran sesuai yang telah anda aktifkan di dashboard TriPay',
                'type' => 'multicheck',
                'size' => 'regular',
                'options' => [
                    'ALFAMART' => 'Alfamart',
                    'ALFAMIDI' => 'Alfamidi',
                    'BCAVA' => 'Bank BCA VA',
                    'BNIVA' => 'Bank BNI VA',
                    'BRIVA' => 'Bank BRI VA',
                    'CIMBVA' => 'Bank CIMB VA',
                    'MANDIRIVA' => 'Bank Mandiri VA',
                    'MYBVA' => 'Bank MayBank VA',
                    'MUAMALATVA' => 'Bank Muamalat VA',
                    'PERMATAVA' => 'Bank Permata VA',
                    'SMSVA' => 'Bank Sinarmas VA',
                    'QRIS' => 'QRIS',
                    'QRISC' => 'QRIS (Customizable)',
                ],
            ],
            [
                'id' => 'edd_tripay_expires_after',
                'name' => 'Kedaluwarsa Setelah',
                'desc' => '<br>Jumlah hari sebelum invoice dianggap kedaluwarsa.',
                'type' => 'select',
                'options' => [
                    '1' => '1 Hari',
                    '2' => '2 Hari',
                    '3' => '3 Hari',
                    '4' => '4 Hari',
                    '5' => '5 Hari',
                    '6' => '6 Hari',
                    '7' => '7 Hari',
                ],
                'size' => 'regular',
            ],
            [
                'id' => 'edd_tripay_checkout_label',
                'name' => 'Label Checkout',
                'desc' => '<br>Tampilan nama pembayaran pada halaman checkout (opsional)',
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
            Helper::log('Mode sandox masih aktif!');

            echo '<div class="notice notice-error">
                <p>'.__(
                'Mode sandbox TriPay EDD masih dalam keadaan aktif, '.
                    'klik <a href="'.admin_url('edit.php?post_type=download&page=edd-settings&tab=gateways&section=tripay-settings')
                    .'">disini</a> untuk menonaktifkannya saat anda sudah siap menjalankan plugin ini di lingkungan produksi.',
                'edd-tripay'
            ).'
                </p>
             </div>';
        }
    }

    public function showTripayNotConfiguredNotice()
    {
        if (! Helper::allSettingsAreProvided()) {
            Helper::log('Konfigurasi plugin TriPay belum lengkap!');

            echo '<div class="notice notice-error">
                <p>'.__(
                'Plugin TriPay EDD belum dikonfigurasi dengan lengkap, '.
                    'please <a href="'.admin_url('edit.php?post_type=download&page=edd-settings&tab=gateways&section=tripay-settings').'">segera lengkapi</a> konfigurasi anda.',
                'edd-tripay'
            ).'
                </p>
             </div>';
        }
    }
}

new namespace\Admin();
