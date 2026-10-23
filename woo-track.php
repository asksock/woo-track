<?php
/**
 * Plugin Name: WooTrack - All-in-One Tracker for WooCommerce (Google & Meta)
 * Plugin URI:  https://github.com/asksock/woo-track
 * Description: Bộ công cụ độc lập chuyên dụng tích hợp Google Tag Manager (GTM), GA4, Google Ads và Meta Pixel & Conversions API (CAPI) cho WooCommerce.
 * Version:     1.2.1
 * Author:      AskSock
 * Author URI:  https://github.com/asksock
 * Text Domain: woo-track
 * Domain Path: /languages
 * Requires at least: 5.6
 * Requires PHP: 8.0
 * WC requires at least: 5.0
 * WC tested up to: 8.5
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Định nghĩa các hằng số của plugin
define('WOO_TRACK_VERSION', '1.2.0');
define('WOO_TRACK_FILE', __FILE__);
define('WOO_TRACK_PATH', plugin_dir_path(__FILE__));
define('WOO_TRACK_URL', plugin_dir_url(__FILE__));

/**
 * Khởi tạo Plugin
 */
final class WooTrack {

    /**
     * Singleton instance
     */
    private static $instance = null;

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Khai báo tương thích với các tính năng nâng cao của WooCommerce (HPOS - High Performance Order Storage và Blocks)
        add_action('before_woocommerce_init', array($this, 'declare_woocommerce_compatibility'));
        add_action('plugins_loaded', array($this, 'init'));
    }

    /**
     * Khai báo tính tương thích với WooCommerce HPOS (custom_order_tables) và Checkout Blocks
     */
    public function declare_woocommerce_compatibility() {
        if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', WOO_TRACK_FILE, true);
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', WOO_TRACK_FILE, true);
        }
    }

    /**
     * Khởi động các module nếu WooCommerce đang active
     */
    public function init() {
        // Kiểm tra WooCommerce đã kích hoạt chưa
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', array($this, 'notice_woocommerce_required'));
            return;
        }

        // Tải các file chức năng
        $this->includes();

        // Khởi tạo các lớp chức năng
        if (is_admin()) {
            new WooTrack_Settings();
        }
        new WooTrack_Tracker();
        WooTrack_Meta_CAPI::init();
    }

    /**
     * Load các class thành phần
     */
    private function includes() {
        require_once WOO_TRACK_PATH . 'includes/class-woo-track-helper.php';
        require_once WOO_TRACK_PATH . 'includes/class-woo-track-settings.php';
        require_once WOO_TRACK_PATH . 'includes/class-woo-track-meta-capi.php';
        require_once WOO_TRACK_PATH . 'includes/class-woo-track-tracker.php';
    }

    /**
     * Thông báo yêu cầu cài WooCommerce
     */
    public function notice_woocommerce_required() {
        ?>
        <div class="notice notice-error">
            <p><?php esc_html_e('WooTrack yêu cầu plugin WooCommerce phải được cài đặt và kích hoạt để hoạt động!', 'woo-track'); ?></p>
        </div>
        <?php
    }
}

// Khởi chạy plugin
WooTrack::instance();
