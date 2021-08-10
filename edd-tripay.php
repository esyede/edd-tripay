<?php
/*
    Plugin Name:       Tripay Payment for EDD
    Plugin URL:        https://tripay.co.id
    Description:       Tripay payment gateway for Easy Digital Downloads
    Version:           2.0.0
    Author:            Suyadi
    Author URI:        https://tripay.co.id
    License:           GPL-2.0+
    License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
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
