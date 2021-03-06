<?php
/*
    Plugin Name:       TriPay Payment for EDD
    Plugin URL:        https://tripay.co.id
    Description:       Terima pembayaran online dengan banyak pilihan channel seperti Virtual Account, Convenience Store, E-Wallet, E-Banking, dll
    Version:           1.0.0
    Author:            PT Trijaya Digital Group
    Author URI:        https://tripay.co.id
    License:           MIT
    License URI:       https://opensource.org/licenses/MIT
    Text Domain:       edd-tripay
    Domain Path:       /languages
*/

defined('ABSPATH') or exit('No direct access.');

defined('EDD_TRIPAY_VERSION') or define('EDD_TRIPAY_VERSION', '1.0.0');
defined('EDD_TRIPAY_PLUGIN_FILE') or define('EDD_TRIPAY_PLUGIN_FILE', __FILE__);
defined('EDD_TRIPAY_PLUGIN_DIR') or define('EDD_TRIPAY_PLUGIN_DIR', plugin_dir_path(__FILE__));
defined('EDD_TRIPAY_URL') or define('EDD_TRIPAY_URL', plugin_dir_url(__FILE__));

function register_tripay_loader()
{
    if (! class_exists('Easy_Digital_Downloads')) {
        return;
    }

    require_once EDD_TRIPAY_PLUGIN_DIR.'Helper.php';
    require_once EDD_TRIPAY_PLUGIN_DIR.'Frontend.php';

    if (is_admin()) {
        require_once EDD_TRIPAY_PLUGIN_DIR.'Admin.php';
    }
}

add_action('plugins_loaded', 'register_tripay_loader', 100);
