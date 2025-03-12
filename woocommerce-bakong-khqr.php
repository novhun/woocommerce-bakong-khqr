<?php
/*
 * Plugin Name: WooCommerce Bakong KHQR Payment Gateway
 * Plugin URI: https://github.com/novhun/woocommerce-bakong-khqr
 * Description: Accept payments via Bakong KHQR in WooCommerce
 * Author: Nov Hun
 * Author URI: https://github.com/novhun/woocommerce-bakong-khqr
 * Version: 1.2.1
 * Requires at least: 5.0
 * Tested up to: 6.4
 * WC requires at least: 3.0
 * WC tested up to: 8.0
 * Text Domain: woocommerce-bakong-khqr
 * Domain Path: /languages
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define debug constants only if not already defined
if (!defined('WP_DEBUG')) {
    define('WP_DEBUG', true);
}
if (!defined('WP_DEBUG_LOG')) {
    define('WP_DEBUG_LOG', true);
}
if (!defined('WP_DEBUG_DISPLAY')) {
    define('WP_DEBUG_DISPLAY', false);
}

// Declare HPOS compatibility
add_action('before_woocommerce_init', function() {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

// Check if vendor/autoload.php exists
if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
    error_log('Autoload file not found in ' . __DIR__ . '/vendor/autoload.php. Ensure Composer dependencies are installed.');
    wp_die('Autoload file not found. Run "composer install" in the plugin directory.');
}

require_once __DIR__ . '/vendor/autoload.php';

use KHQR\BakongKHQR;
use KHQR\Helpers\KHQRData;
use KHQR\Models\MerchantInfo;
use Endroid\QrCode\QrCode;

// Ensure WooCommerce is active
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    add_action('admin_notices', function() {
        echo '<div class="error"><p>WooCommerce Bakong KHQR Payment Gateway requires WooCommerce to be installed and active.</p></div>';
    });
    return;
}

add_action('plugins_loaded', 'init_bakong_khqr_gateway');
function init_bakong_khqr_gateway() {
    class WC_Bakong_KHQR_Gateway extends WC_Payment_Gateway {
        public function __construct() {
            $this->id = 'bakong_khqr';
            $this->icon = plugin_dir_url(__FILE__) . 'assets/khqr-logo.png';
            $this->has_fields = true;
            $this->method_title = 'Bakong KHQR Payment';
            $this->method_description = 'Pay with Bakong KHQR (USD/KHR)';

            $this->init_form_fields();
            $this->init_settings();

            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->bakong_account_id = $this->get_option('bakong_account_id');
            $this->merchant_name = $this->get_option('merchant_name');
            $this->merchant_city = $this->get_option('merchant_city');
            $this->api_token = $this->get_option('api_token');

            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            add_action('wp_enqueue_scripts', array($this, 'payment_scripts'));
            add_action('admin_enqueue_scripts', array($this, 'admin_scripts'));
        }

        public function init_form_fields() {
            $this->form_fields = array(
                'enabled' => array(
                    'title' => 'Enable/Disable',
                    'type' => 'checkbox',
                    'label' => 'Enable Bakong KHQR Payment',
                    'default' => 'yes'
                ),
                'title' => array(
                    'title' => 'Title',
                    'type' => 'text',
                    'default' => 'Bakong KHQR Payment'
                ),
                'description' => array(
                    'title' => 'Description',
                    'type' => 'textarea',
                    'default' => 'Pay securely with Bakong KHQR (USD or KHR)'
                ),
                'bakong_account_id' => array(
                    'title' => 'Bakong Account ID',
                    'type' => 'text',
                    'description' => 'Your Bakong Account ID (e.g., youraccount@nbc.gov.kh)'
                ),
                'merchant_name' => array(
                    'title' => 'Merchant Name',
                    'type' => 'text',
                    'description' => 'Your business name'
                ),
                'merchant_city' => array(
                    'title' => 'Merchant City',
                    'type' => 'text',
                    'description' => 'Your city (e.g., Phnom Penh)'
                ),
                'api_token' => array(
                    'title' => 'API Token',
                    'type' => 'password',
                    'description' => 'Get this from Bakong API dashboard'
                )
            );
        }

        public function payment_scripts() {
            if (!is_checkout() && !is_wc_endpoint_url('order-received')) return;
            wp_enqueue_style('bootstrap-css', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css');
            wp_enqueue_script('bootstrap-js', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js', array('jquery'), null, true);
        }

        public function admin_scripts($hook) {
            if ($hook !== 'toplevel_page_bakong-khqr-settings' && $hook !== 'bakong-khqr_page_bakong-khqr-info') return;
            wp_enqueue_style('bootstrap-css', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css');
            wp_enqueue_script('bootstrap-js', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js', array('jquery'), null, true);
            wp_enqueue_script('html5-qrcode', 'https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js', array(), null, true);
        }

        public function payment_fields() {
            echo '<div class="khqr-payment-fields">' . wpautop(wp_kses_post($this->description)) . 
                 '<select name="khqr_currency" class="form-control w-25">
                    <option value="KHR">KHR</option>
                    <option value="USD">USD</option>
                  </select></div>';
        }

        public function process_payment($order_id) {
            $order = wc_get_order($order_id);
            $amount = $order->get_total();
            $currency = isset($_POST['khqr_currency']) ? sanitize_text_field($_POST['khqr_currency']) : 'KHR';

            try {
                $merchantInfo = new MerchantInfo(
                    bakongAccountID: $this->bakong_account_id,
                    merchantName: $this->merchant_name,
                    merchantCity: $this->merchant_city,
                    merchantID: 'WC_' . $order_id,
                    acquiringBank: 'Bakong',
                    mobileNumber: ''
                );

                $khqr = new BakongKHQR($this->api_token);
                $currency_code = $currency === 'USD' ? KHQRData::CURRENCY_USD : KHQRData::CURRENCY_KHR;
                $response = BakongKHQR::generateMerchant($merchantInfo, $amount, $currency_code);

                if ($response->status->code === 0) {
                    $qr_code = $response->data->qr;
                    $md5 = $response->data->md5;

                    update_post_meta($order_id, '_khqr_qr', $qr_code);
                    update_post_meta($order_id, '_khqr_md5', $md5);
                    update_post_meta($order_id, '_khqr_currency', $currency);

                    $order->update_status('pending', 'Awaiting KHQR payment');
                    wc_reduce_stock_levels($order_id);

                    return array(
                        'result' => 'success',
                        'redirect' => add_query_arg(
                            array('order_id' => $order_id, 'khqr' => urlencode($qr_code)),
                            $this->get_return_url($order))
                    );
                } else {
                    $errorMessage = $response->status->message ?? 'Unknown error from Bakong API';
                    wc_add_notice('Payment error: ' . $errorMessage, 'error');
                    return array('result' => 'failure');
                }
            } catch (Exception $e) {
                error_log('Process Payment Error: ' . $e->getMessage());
                wc_add_notice('Payment error: ' . $e->getMessage(), 'error');
                return array('result' => 'failure');
            }
        }
    }

    add_filter('woocommerce_payment_gateways', 'add_bakong_khqr_gateway');
    function add_bakong_khqr_gateway($gateways) {
        $gateways[] = 'WC_Bakong_KHQR_Gateway';
        return $gateways;
    }
}

add_action('admin_menu', 'bakong_khqr_admin_menu');
function bakong_khqr_admin_menu() {
    add_menu_page(
        'Bakong KHQR Settings',
        'Bakong KHQR',
        'manage_options',
        'bakong-khqr-settings',
        'bakong_khqr_admin_page',
        'dashicons-money-alt'
    );
    
    add_submenu_page(
        'bakong-khqr-settings',
        'Bakong KHQR Info',
        'KHQR Info',
        'manage_options',
        'bakong-khqr-info',
        'bakong_khqr_info_page'
    );
}

function bakong_khqr_admin_page() {
    $settings = get_option('woocommerce_bakong_khqr_settings');
    ?>
    <div class="wrap">
        <h1>Bakong KHQR Settings</h1>
        <nav class="nav-tab-wrapper mb-3">
            <a href="<?php echo esc_url(admin_url('admin.php?page=bakong-khqr-settings')); ?>" class="nav-tab nav-tab-active">Test QR</a>
            <a href="<?php echo esc_url(admin_url('admin.php?page=bakong-khqr-info')); ?>" class="nav-tab">KHQR Info</a>
        </nav>
        
        <div class="card p-3 mb-3">
            <h3>Test QR Code Generation and Scanning</h3>
            <form id="testQrForm">
                <?php wp_nonce_field('khqr_test_settings'); ?>
                <div class="mb-3">
                    <label class="form-label">Amount</label>
                    <input type="number" class="form-control" name="test_amount" value="1000" step="0.01" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Currency</label>
                    <select name="test_currency" class="form-control">
                        <option value="KHR">KHR</option>
                        <option value="USD">USD</option>
                    </select>
                </div>
                <button type="button" class="btn btn-primary" id="generateTestQr">Generate QR</button>
                <button type="button" class="btn btn-info" id="scanTestQr">Scan QR</button>
            </form>
            <div id="qrGenerationStatus" class="mt-2 text-danger"></div>
        </div>

        <!-- QR Generation Modal -->
        <div class="modal fade" id="qrModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Generated QR Code</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div id="qrCodeContainer"></div>
                        <div id="qrError" class="text-danger mt-2"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- QR Scanning Modal -->
        <div class="modal fade" id="scanModal" tabindex="-1">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Scan QR Code</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div id="qr-reader" style="width: 100%"></div>
                        <div id="scanResult" class="mt-3"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
    jQuery(document).ready(function($) {
        // Generate QR Code
        $('#generateTestQr').click(function() {
            var amount = $('input[name="test_amount"]').val();
            var currency = $('select[name="test_currency"]').val();
            
            $.ajax({
                url: ajaxurl,
                method: 'POST',
                data: {
                    action: 'generate_test_qr',
                    amount: amount,
                    currency: currency,
                    nonce: '<?php echo esc_js(wp_create_nonce('generate_test_qr')); ?>'
                },
                success: function(response) {
                    $('#qrGenerationStatus').html('');
                    if (response.success) {
                        $('#qrCodeContainer').html('<img src="' + response.data.qr + '" class="img-fluid" alt="QR Code">');
                        $('#qrError').html('');
                        $('#qrModal').modal('show');
                    } else {
                        $('#qrCodeContainer').html('');
                        $('#qrError').html('Error: ' + response.data.message);
                        $('#qrGenerationStatus').html('Error generating QR: ' + response.data.message);
                    }
                },
                error: function(xhr, status, error) {
                    $('#qrCodeContainer').html('');
                    var errorMessage = 'AJAX Error: ' . status + ' - ' + error + ' (Response: ' + (xhr.responseText || 'No response') + ')';
                    $('#qrError').html(errorMessage);
                    $('#qrGenerationStatus').html(errorMessage);
                    console.log('AJAX Error Details:', xhr, status, error, xhr.responseText);
                }
            });
        });

        let html5QrcodeScanner;
        $('#scanTestQr').click(function() {
            $('#scanModal').modal('show');
            
            html5QrcodeScanner = new Html5QrcodeScanner(
                "qr-reader", 
                { fps: 10, qrbox: { width: 250, height: 250 } }
            );
            
            html5QrcodeScanner.render(
                function(decodedText) {
                    $.ajax({
                        url: ajaxurl,
                        method: 'POST',
                        data: {
                            action: 'decode_test_qr',
                            qr_code: decodedText,
                            nonce: '<?php echo esc_js(wp_create_nonce('decode_test_qr')); ?>'
                        },
                        success: function(response) {
                            if (response.success) {
                                $('#scanResult').html(
                                    '<div class="alert alert-success">' +
                                    '<p>Merchant: ' + response.data.merchantName + '</p>' +
                                    '<p>Amount: ' + response.data.transactionAmount + ' ' + 
                                    (response.data.transactionCurrency === '840' ? 'USD' : 'KHR') + '</p>' +
                                    '<p>City: ' + response.data.merchantCity + '</p>' +
                                    '</div>'
                                );
                            } else {
                                $('#scanResult').html('<div class="alert alert-danger">Error: ' + response.data.message + '</div>');
                            }
                        }
                    });
                    html5QrcodeScanner.clear();
                },
                function(error) {
                    console.warn('QR Scan Error:', error);
                }
            );
        });

        $('#scanModal').on('hidden.bs.modal', function() {
            if (html5QrcodeScanner) {
                html5QrcodeScanner.clear();
            }
        });
    });
    </script>
    <?php
}

function bakong_khqr_info_page() {
    $settings = get_option('woocommerce_bakong_khqr_settings');
    ?>
    <div class="wrap">
        <h1>Bakong KHQR Information</h1>
        <nav class="nav-tab-wrapper mb-3">
            <a href="<?php echo esc_url(admin_url('admin.php?page=bakong-khqr-settings')); ?>" class="nav-tab">Test QR</a>
            <a href="<?php echo esc_url(admin_url('admin.php?page=bakong-khqr-info')); ?>" class="nav-tab nav-tab-active">KHQR Info</a>
        </nav>
        
        <div class="card p-3">
            <h3>Current Configuration</h3>
            <table class="table">
                <tr>
                    <th>Bakong Account ID</th>
                    <td><?php echo esc_html($settings['bakong_account_id'] ?? 'Not set'); ?></td>
                </tr>
                <tr>
                    <th>Merchant Name</th>
                    <td><?php echo esc_html($settings['merchant_name'] ?? 'Not set'); ?></td>
                </tr>
                <tr>
                    <th>Merchant City</th>
                    <td><?php echo esc_html($settings['merchant_city'] ?? 'Not set'); ?></td>
                </tr>
                <tr>
                    <th>API Token</th>
                    <td><?php echo $settings['api_token'] ? '******** (Set)' : 'Not set'; ?></td>
                </tr>
            </table>
            <p>Configure these settings in <a href="<?php echo esc_url(admin_url('admin.php?page=wc-settings&tab=checkout&section=bakong_khqr')); ?>">WooCommerce Payment Settings</a></p>
        </div>
    </div>
    <?php
}

add_action('wp_ajax_generate_test_qr', 'generate_test_qr_callback');
function generate_test_qr_callback() {
    check_ajax_referer('generate_test_qr', 'nonce');
    
    $amount = floatval($_POST['amount']);
    $currency = sanitize_text_field($_POST['currency']);
    $settings = get_option('woocommerce_bakong_khqr_settings');

    // Check prerequisites
    if (empty($settings['api_token'])) {
        error_log('Generate QR Error: API Token is not configured.');
        wp_send_json_error(array('message' => 'API Token is not configured. Please set it in WooCommerce settings.'));
        return;
    }

    if (empty($settings['bakong_account_id'])) {
        error_log('Generate QR Error: Bakong Account ID is not configured.');
        wp_send_json_error(array('message' => 'Bakong Account ID is not configured. Please set it in WooCommerce settings.'));
        return;
    }

    if (empty($settings['merchant_name'])) {
        error_log('Generate QR Error: Merchant Name is not configured.');
        wp_send_json_error(array('message' => 'Merchant Name is not configured. Please set it in WooCommerce settings.'));
        return;
    }

    if (empty($settings['merchant_city'])) {
        error_log('Generate QR Error: Merchant City is not configured.');
        wp_send_json_error(array('message' => 'Merchant City is not configured. Please set it in WooCommerce settings.'));
        return;
    }

    if (!class_exists('Endroid\QrCode\QrCode')) {
        error_log('Generate QR Error: Endroid\QrCode\QrCode class not found. Ensure composer require endroid/qrcode was run.');
        wp_send_json_error(array('message' => 'Endroid\QrCode\QrCode class not found. Run "composer require endroid/qrcode" in the plugin directory.'));
        return;
    }

    if (!class_exists('KHQR\BakongKHQR')) {
        error_log('Generate QR Error: KHQR\BakongKHQR class not found. Ensure composer require fidele007/bakong-khqr-php was run.');
        wp_send_json_error(array('message' => 'KHQR\BakongKHQR class not found. Run "composer require fidele007/bakong-khqr-php" in the plugin directory.'));
        return;
    }

    if (!extension_loaded('gd')) {
        error_log('Generate QR Error: PHP GD extension is not enabled.');
        wp_send_json_error(array('message' => 'PHP GD extension is not enabled. Please enable it in php.ini.'));
        return;
    }

    if (!extension_loaded('curl')) {
        error_log('Generate QR Error: PHP cURL extension is not enabled.');
        wp_send_json_error(array('message' => 'PHP cURL extension is not enabled. Please enable it in php.ini.'));
        return;
    }

    // Increase memory limit temporarily
    ini_set('memory_limit', '256M');

    try {
        $merchantInfo = new MerchantInfo(
            bakongAccountID: $settings['bakong_account_id'],
            merchantName: $settings['merchant_name'],
            merchantCity: $settings['merchant_city'],
            merchantID: 'TEST_' . time(),
            acquiringBank: 'Bakong',
            mobileNumber: ''
        );

        $khqr = new BakongKHQR($settings['api_token']);
        $currency_code = $currency === 'USD' ? KHQRData::CURRENCY_USD : KHQRData::CURRENCY_KHR;
        $response = BakongKHQR::generateMerchant($merchantInfo, $amount, $currency_code);

        // Log the full response for debugging
        error_log('Bakong API Response: ' . print_r($response, true));

        if (is_object($response) && property_exists($response, 'status') && $response->status->code === 0) {
            if (!isset($response->data->qr) || !isset($response->data->md5)) {
                error_log('Generate QR Error: Invalid response structure from Bakong API. Response: ' . json_encode($response));
                wp_send_json_error(array('message' => 'Invalid response from Bakong API. Missing qr or md5 data.'));
                return;
            }

            $qrContent = $response->data->qr;
            $qrCode = new QrCode($qrContent);
            $qrCode->setSize(300);
            $qrImage = 'data:image/png;base64,' . base64_encode($qrCode->writeString());

            wp_send_json_success(array(
                'qr' => $qrImage,
                'md5' => $response->data->md5
            ));
        } else {
            $errorMessage = is_object($response) && property_exists($response, 'status') && isset($response->status->message) ? $response->status->message : 'Unknown error from Bakong API';
            error_log('Generate QR Error: Bakong API error: ' . $errorMessage . ' | Full Response: ' . json_encode($response));
            wp_send_json_error(array('message' => 'Bakong API error: ' . $errorMessage));
        }
    } catch (Exception $e) {
        $errorMessage = 'Exception: ' . $e->getMessage() . ' (File: ' . $e->getFile() . ', Line: ' . $e->getLine() . ')';
        error_log('Generate QR Error: ' . $errorMessage);
        wp_send_json_error(array('message' => 'Exception: ' . $e->getMessage()));
    }
    wp_die();
}

add_action('wp_ajax_decode_test_qr', 'decode_test_qr_callback');
function decode_test_qr_callback() {
    check_ajax_referer('decode_test_qr', 'nonce');
    
    $qr_code = sanitize_text_field($_POST['qr_code']);
    $settings = get_option('woocommerce_bakong_khqr_settings');

    if (empty($settings['api_token'])) {
        error_log('Decode QR Error: API Token is not configured.');
        wp_send_json_error(array('message' => 'API Token is not configured. Please set it in WooCommerce settings.'));
        return;
    }

    try {
        $khqr = new BakongKHQR($settings['api_token']);
        $response = BakongKHQR::decode($qr_code);

        // Log the full response for debugging
        error_log('Bakong API Decode Response: ' . print_r($response, true));

        if (is_object($response) && property_exists($response, 'status') && $response->status->code === 0) {
            wp_send_json_success($response->data);
        } else {
            $errorMessage = is_object($response) && property_exists($response, 'status') && isset($response->status->message) ? $response->status->message : 'Unknown error from Bakong API';
            error_log('Decode QR Error: ' . $errorMessage);
            wp_send_json_error(array('message' => 'Decode error: ' . $errorMessage));
        }
    } catch (Exception $e) {
        $errorMessage = 'Exception: ' . $e->getMessage() . ' (File: ' . $e->getFile() . ', Line: ' . $e->getLine() . ')';
        error_log('Decode QR Error: ' . $errorMessage);
        wp_send_json_error(array('message' => 'Decode Exception: ' . $e->getMessage()));
    }
    wp_die();
}

add_action('woocommerce_thankyou', 'show_khqr_on_thankyou', 10, 1);
function show_khqr_on_thankyou($order_id) {
    if (get_post_meta($order_id, '_payment_method', true) !== 'bakong_khqr') return;

    $qr_code = get_post_meta($order_id, '_khqr_qr', true);
    $currency = get_post_meta($order_id, '_khqr_currency', true);
    
    if ($qr_code) {
        try {
            $qrCode = new QrCode($qr_code);
            $qrCode->setSize(300);
            $qrImage = 'data:image/png;base64,' . base64_encode($qrCode->writeString());

            echo '<div class="card p-3 mt-3">';
            echo '<h3>Scan to Pay with Bakong KHQR (' . esc_html($currency) . ')</h3>';
            echo '<img src="' . esc_attr($qrImage) . '" class="img-fluid" alt="Payment QR Code">';
            echo '</div>';
        } catch (Exception $e) {
            error_log('Thank You Page QR Error: ' . $e->getMessage());
            echo '<div class="card p-3 mt-3 text-danger">';
            echo '<p>Error generating QR code for payment: ' . esc_html($e->getMessage()) . '</p>';
            echo '</div>';
        }
    }
}