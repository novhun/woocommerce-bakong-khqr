<?php
/*
 * Plugin Name: WooCommerce Bakong KHQR Payment Gateway
 * Plugin URI: https://github.com/novhun/woocommerce-bakong-khqr
 * Description: Accept payments via Bakong KHQR in WooCommerce
 * Author: Nov Hun
 * Author URI: https://github.com/novhun/woocommerce-bakong-khqr
 * Version: 1.1.0
 * Requires at least: 5.0
 * Tested up to: 6.4
 * WC requires at least: 3.0
 * WC tested up to: 8.0
 * Text Domain: woocommerce-bakong-khqr
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Check if WooCommerce is active
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    return;
}

// Include Composer autoload
if (file_exists(dirname(__FILE__) . '/vendor/autoload.php')) {
    require_once dirname(__FILE__) . '/vendor/autoload.php';
}

use KHQR\BakongKHQR;
use KHQR\Helpers\KHQRData;
use KHQR\Models\IndividualInfo;

// Declare HPOS compatibility
add_action('before_woocommerce_init', function () {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

// Add the gateway to WooCommerce
add_filter('woocommerce_payment_gateways', 'add_bakong_khqr_gateway');
function add_bakong_khqr_gateway($gateways) {
    $gateways[] = 'WC_Gateway_Bakong_KHQR';
    return $gateways;
}

// Initialize the plugin
add_action('plugins_loaded', 'init_bakong_khqr_gateway');
function init_bakong_khqr_gateway() {
    class WC_Gateway_Bakong_KHQR extends WC_Payment_Gateway {
        public function __construct() {
            $this->id = 'bakong_khqr';
            $this->icon = apply_filters('woocommerce_bakong_khqr_icon', plugins_url('assets/icon.png', __FILE__));
            $this->has_fields = false;
            $this->method_title = __('Bakong KHQR', 'woocommerce-bakong-khqr');
            $this->method_description = __('Pay with Bakong KHQR mobile banking', 'woocommerce-bakong-khqr');
            
            $this->init_form_fields();
            $this->init_settings();
            
            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->bakong_account_id = $this->get_option('bakong_account_id');
            $this->merchant_name = $this->get_option('merchant_name');
            $this->merchant_city = $this->get_option('merchant_city');
            $this->api_token = $this->get_option('api_token');
            
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            add_action('woocommerce_thankyou_' . $this->id, array($this, 'thankyou_page'));
            add_action('admin_post_bakong_khqr_test_payment', array($this, 'handle_test_payment'));
        }

        public function init_form_fields() {
            $this->form_fields = array(
                'enabled' => array(
                    'title' => __('Enable/Disable', 'woocommerce-bakong-khqr'),
                    'type' => 'checkbox',
                    'label' => __('Enable Bakong KHQR Payment', 'woocommerce-bakong-khqr'),
                    'default' => 'yes'
                ),
                'title' => array(
                    'title' => __('Title', 'woocommerce-bakong-khqr'),
                    'type' => 'text',
                    'description' => __('This controls the title which the user sees during checkout.', 'woocommerce-bakong-khqr'),
                    'default' => __('Bakong KHQR Payment', 'woocommerce-bakong-khqr'),
                    'desc_tip' => true,
                ),
                'description' => array(
                    'title' => __('Description', 'woocommerce-bakong-khqr'),
                    'type' => 'textarea',
                    'description' => __('Payment method description that the customer will see on your checkout.', 'woocommerce-bakong-khqr'),
                    'default' => __('Pay securely using Bakong KHQR mobile banking.', 'woocommerce-bakong-khqr'),
                    'desc_tip' => true,
                ),
                'bakong_account_id' => array(
                    'title' => __('Bakong Account ID', 'woocommerce-bakong-khqr'),
                    'type' => 'text',
                    'description' => __('Your Bakong Account ID (e.g., yourname@bank)', 'woocommerce-bakong-khqr'),
                    'default' => '',
                    'desc_tip' => true,
                ),
                'merchant_name' => array(
                    'title' => __('Merchant Name', 'woocommerce-bakong-khqr'),
                    'type' => 'text',
                    'description' => __('Your merchant name as registered with Bakong', 'woocommerce-bakong-khqr'),
                    'default' => '',
                    'desc_tip' => true,
                ),
                'merchant_city' => array(
                    'title' => __('Merchant City', 'woocommerce-bakong-khqr'),
                    'type' => 'text',
                    'description' => __('Your merchant city (e.g., Phnom Penh)', 'woocommerce-bakong-khqr'),
                    'default' => 'Phnom Penh',
                    'desc_tip' => true,
                ),
                'api_token' => array(
                    'title' => __('API Token', 'woocommerce-bakong-khqr'),
                    'type' => 'text',
                    'description' => __('Your Bakong API token from https://api-bakong.nbc.gov.kh/register', 'woocommerce-bakong-khqr'),
                    'default' => '',
                    'desc_tip' => true,
                ),
            );
        }

        public function admin_options() {
            $test_result = get_transient('bakong_khqr_test_result'); // Get test result if available
            delete_transient('bakong_khqr_test_result'); // Clear after display
            ?>
            <div class="bakong-khqr-settings">
                <div class="card shadow-sm border-0">
                    <div class="card-header bg-primary text-white">
                        <h3 class="mb-0"><i class="fas fa-qrcode me-2"></i>Bakong KHQR Payment Settings</h3>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-info d-flex align-items-center" role="alert">
                            <i class="fas fa-info-circle me-2"></i>
                            <div>
                                Configure your Bakong KHQR payment gateway settings below. Get your API token from 
                                <a href="https://api-bakong.nbc.gov.kh/register" target="_blank" class="alert-link">Bakong Portal</a>.
                            </div>
                        </div>

                        <form method="post" id="wc_bakong_khqr_form">
                            <?php wp_nonce_field('woocommerce-settings'); ?>
                            <div class="row g-4">
                                <div class="col-12">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" 
                                               id="woocommerce_bakong_khqr_enabled" 
                                               name="woocommerce_bakong_khqr_enabled" 
                                               <?php echo $this->get_option('enabled') === 'yes' ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="woocommerce_bakong_khqr_enabled">
                                            <?php _e('Enable Bakong KHQR Payment', 'woocommerce-bakong-khqr'); ?>
                                        </label>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="form-floating">
                                        <input type="text" class="form-control" 
                                               id="woocommerce_bakong_khqr_title" 
                                               name="woocommerce_bakong_khqr_title" 
                                               value="<?php echo esc_attr($this->get_option('title')); ?>"
                                               placeholder="Payment Title">
                                        <label for="woocommerce_bakong_khqr_title"><?php _e('Title', 'woocommerce-bakong-khqr'); ?></label>
                                    </div>
                                    <small class="text-muted"><?php _e('Title shown during checkout', 'woocommerce-bakong-khqr'); ?></small>
                                </div>

                                <div class="col-md-6">
                                    <div class="form-floating">
                                        <textarea class="form-control" 
                                                  id="woocommerce_bakong_khqr_description" 
                                                  name="woocommerce_bakong_khqr_description" 
                                                  placeholder="Payment Description"><?php echo esc_textarea($this->get_option('description')); ?></textarea>
                                        <label for="woocommerce_bakong_khqr_description"><?php _e('Description', 'woocommerce-bakong-khqr'); ?></label>
                                    </div>
                                    <small class="text-muted"><?php _e('Description shown during checkout', 'woocommerce-bakong-khqr'); ?></small>
                                </div>

                                <div class="col-md-6">
                                    <div class="form-floating">
                                        <input type="text" class="form-control" 
                                               id="woocommerce_bakong_khqr_bakong_account_id" 
                                               name="woocommerce_bakong_khqr_bakong_account_id" 
                                               value="<?php echo esc_attr($this->get_option('bakong_account_id')); ?>"
                                               placeholder="Bakong Account ID">
                                        <label for="woocommerce_bakong_khqr_bakong_account_id"><?php _e('Bakong Account ID', 'woocommerce-bakong-khqr'); ?></label>
                                    </div>
                                    <small class="text-muted"><?php _e('Format: yourname@bank', 'woocommerce-bakong-khqr'); ?></small>
                                </div>

                                <div class="col-md-6">
                                    <div class="form-floating">
                                        <input type="text" class="form-control" 
                                               id="woocommerce_bakong_khqr_merchant_name" 
                                               name="woocommerce_bakong_khqr_merchant_name" 
                                               value="<?php echo esc_attr($this->get_option('merchant_name')); ?>"
                                               placeholder="Merchant Name">
                                        <label for="woocommerce_bakong_khqr_merchant_name"><?php _e('Merchant Name', 'woocommerce-bakong-khqr'); ?></label>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="form-floating">
                                        <input type="text" class="form-control" 
                                               id="woocommerce_bakong_khqr_merchant_city" 
                                               name="woocommerce_bakong_khqr_merchant_city" 
                                               value="<?php echo esc_attr($this->get_option('merchant_city')); ?>"
                                               placeholder="Merchant City">
                                        <label for="woocommerce_bakong_khqr_merchant_city"><?php _e('Merchant City', 'woocommerce-bakong-khqr'); ?></label>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="form-floating">
                                        <input type="text" class="form-control" 
                                               id="woocommerce_bakong_khqr_api_token" 
                                               name="woocommerce_bakong_khqr_api_token" 
                                               value="<?php echo esc_attr($this->get_option('api_token')); ?>"
                                               placeholder="API Token">
                                        <label for="woocommerce_bakong_khqr_api_token"><?php _e('API Token', 'woocommerce-bakong-khqr'); ?></label>
                                    </div>
                                    <small class="text-muted"><?php _e('Get from Bakong Portal', 'woocommerce-bakong-khqr'); ?></small>
                                </div>
                            </div>

                            <div class="mt-4">
                                <button type="submit" class="btn btn-primary btn-lg">
                                    <i class="fas fa-save me-2"></i><?php _e('Save Changes', 'woocommerce-bakong-khqr'); ?>
                                </button>
                            </div>
                        </form>

                        <!-- Test Payment Section -->
                        <hr class="my-4">
                        <h4 class="mt-4"><i class="fas fa-flask me-2"></i><?php _e('Test Payment', 'woocommerce-bakong-khqr'); ?></h4>
                        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" class="mt-3">
                            <input type="hidden" name="action" value="bakong_khqr_test_payment">
                            <?php wp_nonce_field('bakong_khqr_test_payment_nonce', 'test_payment_nonce'); ?>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <div class="form-floating">
                                        <input type="number" class="form-control" 
                                               id="test_amount" 
                                               name="test_amount" 
                                               value="1000" 
                                               min="1" 
                                               required 
                                               placeholder="Test Amount">
                                        <label for="test_amount"><?php _e('Test Amount (KHR)', 'woocommerce-bakong-khqr'); ?></label>
                                    </div>
                                </div>
                                <div class="col-md-6 d-flex align-items-end">
                                    <button type="submit" class="btn btn-success w-100">
                                        <i class="fas fa-play me-2"></i><?php _e('Generate Test QR', 'woocommerce-bakong-khqr'); ?>
                                    </button>
                                </div>
                            </div>
                        </form>

                        <?php if ($test_result): ?>
                            <div class="mt-4">
                                <?php if ($test_result['success']): ?>
                                    <div class="alert alert-success" role="alert">
                                        <h5><i class="fas fa-check-circle me-2"></i><?php _e('Test Successful', 'woocommerce-bakong-khqr'); ?></h5>
                                        <p><?php _e('QR Code Generated:', 'woocommerce-bakong-khqr'); ?></p>
                                        <img src="https://chart.googleapis.com/chart?chs=200x200&cht=qr&chl=<?php echo urlencode($test_result['qr']); ?>" alt="Test QR Code" class="img-fluid mb-3">
                                        <p><strong>MD5:</strong> <?php echo esc_html($test_result['md5']); ?></p>
                                        <?php if ($test_result['status']): ?>
                                            <p><strong>Transaction Status:</strong> <?php echo esc_html($test_result['status']); ?></p>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="alert alert-danger" role="alert">
                                        <h5><i class="fas fa-exclamation-triangle me-2"></i><?php _e('Test Failed', 'woocommerce-bakong-khqr'); ?></h5>
                                        <p><?php echo esc_html($test_result['message']); ?></p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php
        }

        public function process_payment($order_id) {
            $order = wc_get_order($order_id);
            
            try {
                $individualInfo = new IndividualInfo(
                    bakongAccountID: $this->bakong_account_id,
                    merchantName: $this->merchant_name,
                    merchantCity: $this->merchant_city,
                    currency: KHQRData::CURRENCY_KHR,
                    amount: $order->get_total() * 100 // Convert to smallest unit
                );
                
                $response = BakongKHQR::generateIndividual($individualInfo);
                
                if ($response->status['code'] === 0) {
                    $order->update_meta_data('_bakong_khqr_code', $response->data['qr']);
                    $order->update_meta_data('_bakong_khqr_md5', $response->data['md5']);
                    $order->update_status('on-hold', __('Awaiting Bakong KHQR payment', 'woocommerce-bakong-khqr'));
                    $order->save();
                    
                    wc_reduce_stock_levels($order_id);
                    WC()->cart->empty_cart();
                    
                    return array(
                        'result' => 'success',
                        'redirect' => $this->get_return_url($order)
                    );
                }
                
                throw new Exception(__('KHQR generation failed', 'woocommerce-bakong-khqr'));
                
            } catch (Exception $e) {
                wc_add_notice(__('Payment error: ', 'woocommerce-bakong-khqr') . $e->getMessage(), 'error');
                return array('result' => 'failure');
            }
        }

        public function thankyou_page($order_id) {
            $order = wc_get_order($order_id);
            $qr_code = $order->get_meta('_bakong_khqr_code');
            
            if ($qr_code) {
                echo '<div class="text-center">';
                echo '<h3>' . __('Scan to Pay with Bakong', 'woocommerce-bakong-khqr') . '</h3>';
                echo '<p>' . __('Please scan this QR code using your Bakong mobile app to complete the payment.', 'woocommerce-bakong-khqr') . '</p>';
                echo '<img src="https://chart.googleapis.com/chart?chs=300x300&cht=qr&chl=' . urlencode($qr_code) . '" alt="KHQR Code" class="img-fluid mb-3">';
                echo '<p>' . __('After payment, your order will be processed once we receive confirmation.', 'woocommerce-bakong-khqr') . '</p>';
                echo '</div>';
            }
        }

        public function handle_test_payment() {
            if (!current_user_can('manage_options') || !isset($_POST['test_payment_nonce']) || !wp_verify_nonce($_POST['test_payment_nonce'], 'bakong_khqr_test_payment_nonce')) {
                wp_die(__('Permission denied', 'woocommerce-bakong-khqr'));
            }

            $test_amount = isset($_POST['test_amount']) ? floatval($_POST['test_amount']) * 100 : 1000; // Default to 10 KHR if not set

            try {
                $individualInfo = new IndividualInfo(
                    bakongAccountID: $this->bakong_account_id,
                    merchantName: $this->merchant_name,
                    merchantCity: $this->merchant_city,
                    currency: KHQRData::CURRENCY_KHR,
                    amount: $test_amount
                );

                $response = BakongKHQR::generateIndividual($individualInfo);

                if ($response->status['code'] !== 0) {
                    throw new Exception(__('KHQR generation failed', 'woocommerce-bakong-khqr'));
                }

                $result = array(
                    'success' => true,
                    'qr' => $response->data['qr'],
                    'md5' => $response->data['md5'],
                );

                // Optionally check transaction status if API token is available
                if ($this->api_token) {
                    $bakong = new BakongKHQR($this->api_token);
                    $status_response = $bakong->checkTransactionByMD5($response->data['md5']);
                    if ($status_response->status['code'] === 0 && isset($status_response->data['transactionStatus'])) {
                        $result['status'] = $status_response->data['transactionStatus'];
                    }
                }

            } catch (Exception $e) {
                $result = array(
                    'success' => false,
                    'message' => $e->getMessage(),
                );
            }

            set_transient('bakong_khqr_test_result', $result, 60); // Store result for 60 seconds
            wp_redirect(admin_url('admin.php?page=wc-settings&tab=checkout&section=bakong_khqr'));
            exit;
        }
    }
}

add_action('admin_enqueue_scripts', 'bakong_khqr_admin_styles');
function bakong_khqr_admin_styles($hook) {
    if ($hook !== 'woocommerce_page_wc-settings' || !isset($_GET['section']) || $_GET['section'] !== 'bakong_khqr') {
        return;
    }

    wp_enqueue_style('bootstrap', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css');
    wp_enqueue_style('font-awesome', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css');
    
    $custom_css = '
        .bakong-khqr-settings {
            max-width: 900px;
            margin: 20px auto;
        }
        .card-header {
            background: linear-gradient(45deg, #007bff, #00b4ff);
        }
        .form-floating textarea {
            height: 100px;
        }
        .form-check-input:checked {
            background-color: #007bff;
            border-color: #007bff;
        }
        .form-check-input:focus {
            box-shadow: 0 0 0 0.25rem rgba(0,123,255,.25);
        }
        .btn-primary, .btn-success {
            background: linear-gradient(45deg, #007bff, #00b4ff);
            border: none;
            padding: 10px 30px;
            transition: all 0.3s ease;
        }
        .btn-success {
            background: linear-gradient(45deg, #28a745, #34d058);
        }
        .btn-primary:hover, .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0,123,255,0.4);
        }
        .btn-success:hover {
            box-shadow: 0 4px 15px rgba(40,167,69,0.4);
        }
        small.text-muted {
            display: block;
            margin-top: 5px;
        }
    ';
    wp_add_inline_style('bootstrap', $custom_css);
}

add_action('wp', 'bakong_khqr_check_transaction_status');
function bakong_khqr_check_transaction_status() {
    if (!is_admin() || !wp_doing_cron()) {
        return;
    }
    
    $args = array(
        'status' => 'on-hold',
        'meta_key' => '_bakong_khqr_md5',
        'limit' => 50,
    );
    
    $orders = wc_get_orders($args);
    $gateway = WC()->payment_gateways()->payment_gateways()['bakong_khqr'];
    
    if (!$gateway->api_token) {
        return;
    }
    
    $bakong = new BakongKHQR($gateway->api_token);
    
    foreach ($orders as $order) {
        $md5 = $order->get_meta('_bakong_khqr_md5');
        
        try {
            $response = $bakong->checkTransactionByMD5($md5);
            
            if ($response->status['code'] === 0 && isset($response->data['transactionStatus']) && $response->data['transactionStatus'] === 'SUCCESS') {
                $order->payment_complete();
                $order->add_order_note(__('Payment confirmed via Bakong KHQR', 'woocommerce-bakong-khqr'));
                $order->save();
            }
        } catch (Exception $e) {
            $order->add_order_note(__('Transaction check failed: ', 'woocommerce-bakong-khqr') . $e->getMessage());
            $order->save();
        }
    }
}