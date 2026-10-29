<?php
/**
 * WooTrack Admin Settings
 *
 * Quản lý trang cấu hình WooTrack trong trang quản trị WooCommerce
 * Hỗ trợ chèn độc lập: Google Tag Manager (GTM), Google Analytics 4 (GA4), và Google Ads
 */

if (!defined('ABSPATH')) {
    exit;
}

class WooTrack_Settings {

    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
    }

    /**
     * Thêm menu WooTrack vào menu WooCommerce
     */
    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            __('Cấu hình WooTrack', 'woo-track'),
            __('WooTrack (Google Suite)', 'woo-track'),
            'manage_woocommerce',
            'woo-track',
            array($this, 'render_settings_page')
        );
    }

    /**
     * Đăng ký cài đặt
     */
    public function register_settings() {
        register_setting('woo_track_settings_group', 'woo_track_settings', array($this, 'sanitize_settings'));
    }

    /**
     * Làm sạch dữ liệu đầu vào
     */
    public function sanitize_settings($input) {
        $sanitized = array();

        // 3 Mã công cụ độc lập của Google
        $sanitized['gtm_id']  = isset($input['gtm_id']) ? sanitize_text_field(strtoupper(trim($input['gtm_id']))) : '';
        $sanitized['ga4_id']  = isset($input['ga4_id']) ? sanitize_text_field(strtoupper(trim($input['ga4_id']))) : '';

        $gads_id = isset($input['gads_id']) ? sanitize_text_field(strtoupper(trim($input['gads_id']))) : '';
        if (!empty($gads_id) && is_numeric($gads_id)) {
            $gads_id = 'AW-' . $gads_id;
        }
        $sanitized['gads_id'] = $gads_id;

        // Google Ads Conversion Labels (tự làm sạch nếu dán kèm AW-XXXXX/)
        foreach (array('purchase_label', 'begin_checkout_label', 'add_to_cart_label') as $lbl_key) {
            if (isset($input[$lbl_key])) {
                $val = sanitize_text_field(trim($input[$lbl_key]));
                if (strpos($val, '/') !== false) {
                    $parts = explode('/', $val);
                    $val = end($parts);
                }
                $sanitized[$lbl_key] = $val;
            } else {
                $sanitized[$lbl_key] = '';
            }
        }

        // Google Server-Side API (Data Manager API v1 & GA4 Measurement Protocol)
        $sanitized['ga4_property_id']           = isset($input['ga4_property_id']) ? preg_replace('/[^0-9]/', '', (string) $input['ga4_property_id']) : '';
        $sanitized['ga4_api_secret']            = isset($input['ga4_api_secret']) ? sanitize_text_field(trim((string) $input['ga4_api_secret'])) : '';
        $sanitized['gads_customer_id']          = isset($input['gads_customer_id']) ? preg_replace('/[^0-9-]/', '', trim((string) $input['gads_customer_id'])) : '';
        $sanitized['gads_conversion_action_id'] = isset($input['gads_conversion_action_id']) ? preg_replace('/[^0-9]/', '', (string) $input['gads_conversion_action_id']) : '';

        if (!empty($input['google_service_account_json'])) {
            $raw_sa = trim((string) $input['google_service_account_json']);
            $decoded_sa = json_decode($raw_sa, true);
            if (!is_array($decoded_sa)) {
                $decoded_sa = json_decode(wp_unslash($raw_sa), true);
            }
            if (is_array($decoded_sa) && !empty($decoded_sa['client_email']) && !empty($decoded_sa['private_key'])) {
                $sanitized['google_service_account_json'] = wp_json_encode($decoded_sa, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            } else {
                $sanitized['google_service_account_json'] = sanitize_textarea_field($raw_sa);
            }
        } else {
            $sanitized['google_service_account_json'] = '';
        }

        // Meta (Facebook) Pixel & Conversions API (CAPI)
        $sanitized['meta_pixel_id']        = isset($input['meta_pixel_id']) ? sanitize_text_field(trim($input['meta_pixel_id'])) : '';
        $sanitized['meta_capi_token']      = isset($input['meta_capi_token']) ? sanitize_textarea_field(trim($input['meta_capi_token'])) : '';
        $sanitized['meta_test_event_code'] = isset($input['meta_test_event_code']) ? sanitize_text_field(trim($input['meta_test_event_code'])) : '';
        $sanitized['meta_content_type']    = isset($input['meta_content_type']) && in_array($input['meta_content_type'], array('product', 'product_group')) ? $input['meta_content_type'] : 'product';

        // TikTok Ads (Pixel & Events API)
        $sanitized['tiktok_pixel_id']            = isset($input['tiktok_pixel_id']) ? sanitize_text_field(trim($input['tiktok_pixel_id'])) : '';
        $sanitized['tiktok_access_token']        = isset($input['tiktok_access_token']) ? sanitize_textarea_field(trim($input['tiktok_access_token'])) : '';
        $sanitized['tiktok_test_event_code']     = isset($input['tiktok_test_event_code']) ? sanitize_text_field(trim($input['tiktok_test_event_code'])) : '';
        $sanitized['tiktok_purchase_event_name'] = isset($input['tiktok_purchase_event_name']) && in_array($input['tiktok_purchase_event_name'], array('Purchase', 'CompletePayment'), true) ? $input['tiktok_purchase_event_name'] : 'Purchase';

        // Định danh sản phẩm
        $sanitized['product_identifier']   = isset($input['product_identifier']) && in_array($input['product_identifier'], array('parent_id', 'id', 'sku'), true) ? $input['product_identifier'] : 'parent_id';

        // Checkboxes
        $checkboxes = array(
            'enable_view_item_list',
            'enable_view_item',
            'enable_add_to_cart',
            'enable_begin_checkout',
            'enable_purchase',
            'enable_enhanced_conversions',
            'enable_consent_mode_v2',
            'enable_google_server_api',
            'enable_meta_pixel',
            'enable_meta_capi',
            'enable_meta_advanced_matching',
            'meta_event_view_content',
            'meta_event_add_to_cart',
            'meta_event_initiate_checkout',
            'meta_event_purchase',
            'meta_event_search',
            'enable_tiktok_pixel',
            'enable_tiktok_events_api',
            'enable_tiktok_advanced_matching',
            'tiktok_event_view_content',
            'tiktok_event_add_to_cart',
            'tiktok_event_initiate_checkout',
            'tiktok_event_add_payment_info',
            'tiktok_event_place_an_order',
            'tiktok_event_purchase',
            'tiktok_event_search',
            'tiktok_event_add_to_wishlist',
            'enable_product_feed',
            'debug_mode'
        );

        foreach ($checkboxes as $cb) {
            $sanitized[$cb] = !empty($input[$cb]) ? 'yes' : 'no';
        }

        WooTrack_Helper::clear_settings_cache();

        return $sanitized;
    }

    /**
     * Render trang giao diện cài đặt
     */
    public function render_settings_page() {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        $options = get_option('woo_track_settings', array());

        $gtm_id               = isset($options['gtm_id']) ? $options['gtm_id'] : '';
        $ga4_id               = isset($options['ga4_id']) ? $options['ga4_id'] : '';
        $gads_id              = isset($options['gads_id']) ? $options['gads_id'] : '';
        $purchase_label       = isset($options['purchase_label']) ? $options['purchase_label'] : '';
        $begin_checkout_label = isset($options['begin_checkout_label']) ? $options['begin_checkout_label'] : '';
        $add_to_cart_label    = isset($options['add_to_cart_label']) ? $options['add_to_cart_label'] : '';
        $product_identifier   = isset($options['product_identifier']) ? $options['product_identifier'] : 'parent_id';

        // Google Server-Side API (Data Manager API v1 & Measurement Protocol)
        $enable_google_server_api    = isset($options['enable_google_server_api']) ? $options['enable_google_server_api'] : 'no';
        $google_service_account_json = isset($options['google_service_account_json']) ? $options['google_service_account_json'] : '';
        $ga4_property_id             = isset($options['ga4_property_id']) ? $options['ga4_property_id'] : '';
        $gads_customer_id            = isset($options['gads_customer_id']) ? $options['gads_customer_id'] : '';
        $gads_conversion_action_id   = isset($options['gads_conversion_action_id']) ? $options['gads_conversion_action_id'] : '';
        $ga4_api_secret              = isset($options['ga4_api_secret']) ? $options['ga4_api_secret'] : '';

        $parsed_sa_email = '';
        if (!empty($google_service_account_json)) {
            $sa_arr = json_decode($google_service_account_json, true);
            if (is_array($sa_arr) && !empty($sa_arr['client_email'])) {
                $parsed_sa_email = $sa_arr['client_email'];
            }
        }

        // Meta (Facebook) Settings
        $meta_pixel_id                 = isset($options['meta_pixel_id']) ? $options['meta_pixel_id'] : '';
        $meta_capi_token               = isset($options['meta_capi_token']) ? $options['meta_capi_token'] : '';
        $meta_test_event_code          = isset($options['meta_test_event_code']) ? $options['meta_test_event_code'] : '';
        $meta_content_type             = isset($options['meta_content_type']) ? $options['meta_content_type'] : 'product';
        $enable_meta_pixel             = isset($options['enable_meta_pixel']) ? $options['enable_meta_pixel'] : 'yes';
        $enable_meta_capi              = isset($options['enable_meta_capi']) ? $options['enable_meta_capi'] : 'yes';
        $enable_meta_advanced_matching = isset($options['enable_meta_advanced_matching']) ? $options['enable_meta_advanced_matching'] : 'yes';

        $meta_event_view_content       = isset($options['meta_event_view_content']) ? $options['meta_event_view_content'] : 'yes';
        $meta_event_add_to_cart        = isset($options['meta_event_add_to_cart']) ? $options['meta_event_add_to_cart'] : 'yes';
        $meta_event_initiate_checkout  = isset($options['meta_event_initiate_checkout']) ? $options['meta_event_initiate_checkout'] : 'yes';
        $meta_event_purchase           = isset($options['meta_event_purchase']) ? $options['meta_event_purchase'] : 'yes';
        $meta_event_search             = isset($options['meta_event_search']) ? $options['meta_event_search'] : 'yes';

        // TikTok Ads Settings
        $tiktok_pixel_id                 = isset($options['tiktok_pixel_id']) ? $options['tiktok_pixel_id'] : '';
        $tiktok_access_token             = isset($options['tiktok_access_token']) ? $options['tiktok_access_token'] : '';
        $tiktok_test_event_code          = isset($options['tiktok_test_event_code']) ? $options['tiktok_test_event_code'] : '';
        $tiktok_purchase_event_name      = isset($options['tiktok_purchase_event_name']) ? $options['tiktok_purchase_event_name'] : 'Purchase';
        $enable_tiktok_pixel             = isset($options['enable_tiktok_pixel']) ? $options['enable_tiktok_pixel'] : 'yes';
        $enable_tiktok_events_api        = isset($options['enable_tiktok_events_api']) ? $options['enable_tiktok_events_api'] : 'yes';
        $enable_tiktok_advanced_matching = isset($options['enable_tiktok_advanced_matching']) ? $options['enable_tiktok_advanced_matching'] : 'yes';

        $tiktok_event_view_content       = isset($options['tiktok_event_view_content']) ? $options['tiktok_event_view_content'] : 'yes';
        $tiktok_event_add_to_cart        = isset($options['tiktok_event_add_to_cart']) ? $options['tiktok_event_add_to_cart'] : 'yes';
        $tiktok_event_initiate_checkout  = isset($options['tiktok_event_initiate_checkout']) ? $options['tiktok_event_initiate_checkout'] : 'yes';
        $tiktok_event_add_payment_info   = isset($options['tiktok_event_add_payment_info']) ? $options['tiktok_event_add_payment_info'] : 'yes';
        $tiktok_event_place_an_order     = isset($options['tiktok_event_place_an_order']) ? $options['tiktok_event_place_an_order'] : 'yes';
        $tiktok_event_purchase           = isset($options['tiktok_event_purchase']) ? $options['tiktok_event_purchase'] : 'yes';
        $tiktok_event_search             = isset($options['tiktok_event_search']) ? $options['tiktok_event_search'] : 'yes';
        $tiktok_event_add_to_wishlist    = isset($options['tiktok_event_add_to_wishlist']) ? $options['tiktok_event_add_to_wishlist'] : 'yes';

        $enable_view_item_list       = isset($options['enable_view_item_list']) ? $options['enable_view_item_list'] : 'yes';
        $enable_view_item            = isset($options['enable_view_item']) ? $options['enable_view_item'] : 'yes';
        $enable_add_to_cart          = isset($options['enable_add_to_cart']) ? $options['enable_add_to_cart'] : 'yes';
        $enable_begin_checkout       = isset($options['enable_begin_checkout']) ? $options['enable_begin_checkout'] : 'yes';
        $enable_purchase             = isset($options['enable_purchase']) ? $options['enable_purchase'] : 'yes';
        $enable_enhanced_conversions = isset($options['enable_enhanced_conversions']) ? $options['enable_enhanced_conversions'] : 'no';
        $enable_consent_mode_v2      = isset($options['enable_consent_mode_v2']) ? $options['enable_consent_mode_v2'] : 'yes';
        $enable_product_feed         = isset($options['enable_product_feed']) ? $options['enable_product_feed'] : 'yes';
        $debug_mode                  = isset($options['debug_mode']) ? $options['debug_mode'] : 'no';
        ?>
        <div class="wrap" style="max-width: 950px;">
            <h1><span class="dashicons dashicons-chart-bar" style="font-size:30px; margin-right:8px; vertical-align:middle;"></span><?php esc_html_e('WooTrack - Bộ Công Cụ Theo Dõi Độc Lập Toàn Diện', 'woo-track'); ?></h1>
            <p class="description" style="margin-bottom: 20px; font-size:14px;">
                <?php esc_html_e('Tự động chèn mã và đồng bộ sự kiện E-commerce độc lập cho Google (GTM, GA4, Google Ads) và Meta (Meta Pixel & Conversions API CAPI) với cơ chế chống trùng lặp chuyển đổi tự động.', 'woo-track'); ?>
            </p>

            <form method="post" action="options.php" style="background:#fff; padding:25px 30px; border:1px solid #ccd0d4; box-shadow:0 1px 3px rgba(0,0,0,0.05); border-radius:6px;">
                <?php settings_fields('woo_track_settings_group'); ?>

                <!-- 1. GOOGLE TAG MANAGER -->
                <h2 style="border-bottom: 2px solid #1a73e8; padding-bottom: 8px; margin-top:0; color:#1a73e8;">
                    <span class="dashicons dashicons-tag" style="margin-right:6px;"></span>1. Google Tag Manager (GTM)
                </h2>
                <p class="description"><?php esc_html_e('Nếu sử dụng GTM, plugin sẽ tự động chèn mã vùng chứa vào <head> và sau thẻ <body> (wp_body_open), đồng thời đẩy toàn bộ sự kiện E-commerce vào dataLayer.', 'woo-track'); ?></p>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="gtm_id"><?php esc_html_e('GTM Container ID', 'woo-track'); ?></label></th>
                        <td>
                            <input name="woo_track_settings[gtm_id]" type="text" id="gtm_id" value="<?php echo esc_attr($gtm_id); ?>" class="regular-text" placeholder="GTM-XXXXXXX" />
                            <p class="description"><?php esc_html_e('Để trống nếu bạn không sử dụng Google Tag Manager.', 'woo-track'); ?></p>
                        </td>
                    </tr>
                </table>

                <!-- 2. GOOGLE ANALYTICS 4 -->
                <h2 style="border-bottom: 2px solid #e37400; padding-bottom: 8px; margin-top:25px; color:#e37400;">
                    <span class="dashicons dashicons-chart-area" style="margin-right:6px;"></span>2. Google Analytics 4 (GA4)
                </h2>
                <p class="description"><?php esc_html_e('Tự động nạp thư viện gtag.js và cấu hình GA4 độc lập, tự động bắn đầy đủ sự kiện E-commerce (view_item, add_to_cart, purchase...).', 'woo-track'); ?></p>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="ga4_id"><?php esc_html_e('GA4 Measurement ID', 'woo-track'); ?></label></th>
                        <td>
                            <input name="woo_track_settings[ga4_id]" type="text" id="ga4_id" value="<?php echo esc_attr($ga4_id); ?>" class="regular-text" placeholder="G-XXXXXXXXXX" />
                            <p class="description"><?php esc_html_e('Mã đo lường GA4 (bắt đầu bằng G-). Lấy tại: Quản trị GA4 > Luồng dữ liệu > Mã đo lường.', 'woo-track'); ?></p>
                        </td>
                    </tr>
                </table>

                <!-- 3. GOOGLE ADS -->
                <h2 style="border-bottom: 2px solid #188038; padding-bottom: 8px; margin-top:25px; color:#188038;">
                    <span class="dashicons dashicons-megaphone" style="margin-right:6px;"></span>3. Google Ads (Chuyển đổi & Dynamic Remarketing)
                </h2>
                <p class="description"><?php esc_html_e('Kết nối trực tiếp tài khoản Google Ads để ghi nhận chuyển đổi thời gian thực (Realtime), chống lệch số và hỗ trợ Tiếp thị lại động.', 'woo-track'); ?></p>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="gads_id"><?php esc_html_e('Google Ads / Google Tag ID', 'woo-track'); ?></label></th>
                        <td>
                            <input name="woo_track_settings[gads_id]" type="text" id="gads_id" value="<?php echo esc_attr($gads_id); ?>" class="regular-text" placeholder="AW-18508451909 hoặc GT-XXXXXX" />
                            <p class="description"><?php esc_html_e('Mã thẻ tài khoản Google Ads của bạn (bắt đầu bằng AW- hoặc GT-).', 'woo-track'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="purchase_label"><?php esc_html_e('Purchase Conversion Label', 'woo-track'); ?></label></th>
                        <td>
                            <input name="woo_track_settings[purchase_label]" type="text" id="purchase_label" value="<?php echo esc_attr($purchase_label); ?>" class="regular-text" placeholder="Để trống nếu dùng mẫu Event: Purchase" />
                            <p class="description"><?php esc_html_e('Nếu trong Google Ads chuyển đổi của bạn tạo theo mẫu sự kiện (Event: Purchase), bạn CÓ THỂ ĐỂ TRỐNG ô này.', 'woo-track'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="begin_checkout_label"><?php esc_html_e('Begin Checkout Conversion Label', 'woo-track'); ?></label></th>
                        <td>
                            <input name="woo_track_settings[begin_checkout_label]" type="text" id="begin_checkout_label" value="<?php echo esc_attr($begin_checkout_label); ?>" class="regular-text" placeholder="1_OBCJDolpsdEMWgwvlE" />
                            <p class="description"><?php esc_html_e('Nhãn chuyển đổi khi khách vào trang thanh toán (Ví dụ chuỗi ký tự sau dấu / của mã snippet).', 'woo-track'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="add_to_cart_label"><?php esc_html_e('Add to Cart Conversion Label', 'woo-track'); ?></label></th>
                        <td>
                            <input name="woo_track_settings[add_to_cart_label]" type="text" id="add_to_cart_label" value="<?php echo esc_attr($add_to_cart_label); ?>" class="regular-text" placeholder="Tuỳ chọn" />
                            <p class="description"><?php esc_html_e('Nhãn chuyển đổi khi thêm vào giỏ hàng (Để trống nếu không tạo hành động thủ công cho giỏ hàng).', 'woo-track'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Enhanced Conversions', 'woo-track'); ?></th>
                        <td>
                            <label>
                                <input name="woo_track_settings[enable_enhanced_conversions]" type="checkbox" value="yes" <?php checked($enable_enhanced_conversions, 'yes'); ?> />
                                <strong><?php esc_html_e('Bật Dữ liệu người dùng nâng cao (Enhanced Conversions)', 'woo-track'); ?></strong>
                            </label>
                            <p class="description"><?php esc_html_e('Tự động gửi thông tin Email, Số điện thoại (chuẩn E.164) và Địa chỉ khi đặt hàng để Google Ads tự động băm SHA-256 khớp chuyển đổi chính xác nhất.', 'woo-track'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Google Consent Mode v2', 'woo-track'); ?></th>
                        <td>
                            <label>
                                <input name="woo_track_settings[enable_consent_mode_v2]" type="checkbox" value="yes" <?php checked($enable_consent_mode_v2, 'yes'); ?> />
                                <strong><?php esc_html_e('Gửi tín hiệu Consent Mode v2 mặc định (ad_user_data & ad_personalization = granted)', 'woo-track'); ?></strong>
                            </label>
                            <p class="description"><?php esc_html_e('Đáp ứng yêu cầu mới nhất của Google Ads & GA4 để tính năng Enhanced Conversions và Tiếp thị lại động (Dynamic Remarketing) hoạt động tối đa công suất.', 'woo-track'); ?></p>
                        </td>
                    </tr>
                </table>

                <!-- 3B. GOOGLE SERVER-SIDE API (DATA MANAGER API v1 & MEASUREMENT PROTOCOL) -->
                <h2 style="border-bottom: 2px solid #0f9d58; padding-bottom: 8px; margin-top:25px; color:#0f9d58;">
                    <span class="dashicons dashicons-cloud-upload" style="margin-right:6px;"></span>3B. Google Server-Side API (Data Manager API v1 &amp; Measurement Protocol)
                </h2>
                <p class="description"><?php esc_html_e('Gửi sự kiện Purchase trực tiếp từ máy chủ (Server-to-Server) giống cơ chế Meta CAPI. Chống chặn quảng cáo (AdBlock/Safari ITP), bắt đơn chuyển khoản trễ và tự động khử trùng lặp (Deduplication) qua transaction_id.', 'woo-track'); ?></p>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('Kích hoạt Google Server API', 'woo-track'); ?></th>
                        <td>
                            <label>
                                <input name="woo_track_settings[enable_google_server_api]" type="checkbox" value="yes" <?php checked($enable_google_server_api, 'yes'); ?> />
                                <strong><?php esc_html_e('Bật gửi sự kiện Purchase phía Máy chủ (Google Data Manager API v1 / Measurement Protocol)', 'woo-track'); ?></strong>
                            </label>
                            <p class="description"><?php esc_html_e('Tự động bắt _ga (Client ID) và gclid / gbraid / wbraid đa tầng (URL, Cookie, Checkout Form) lưu vào đơn hàng và bắn ngầm 0ms độ trễ khi đặt hàng.', 'woo-track'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="google_service_account_json"><?php esc_html_e('Service Account JSON (Data Manager API v1 - Khuyên dùng)', 'woo-track'); ?></label></th>
                        <td>
                            <textarea name="woo_track_settings[google_service_account_json]" id="google_service_account_json" rows="4" class="large-text code" placeholder='{"type": "service_account", "project_id": "...", "private_key": "-----BEGIN PRIVATE KEY-----\n...", "client_email": "...@....iam.gserviceaccount.com"}'><?php echo esc_textarea($google_service_account_json); ?></textarea>
                            <?php if (!empty($parsed_sa_email)) : ?>
                                <p style="margin:6px 0; color:#0f9d58; font-weight:600;">
                                    &#9989; <?php esc_html_e('Đã nhận diện Service Account Email:', 'woo-track'); ?> <code><?php echo esc_html($parsed_sa_email); ?></code>
                                    <span style="font-weight:normal; color:#555;"><?php esc_html_e('(Hãy cấp quyền Editor cho email này trong GA4 Property Access Management và/hoặc Google Ads Access and Security).', 'woo-track'); ?></span>
                                </p>
                            <?php endif; ?>
                            <p class="description">
                                <?php esc_html_e('Dán toàn bộ nội dung file JSON của Google Cloud Service Account (đã bật Google Data Manager API - hoàn toàn miễn phí $0). Hỗ trợ gửi đồng thời User Data băm SHA-256 (Enhanced Conversions), gclid/gbraid/wbraid và Consent Mode v2.', 'woo-track'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ga4_property_id"><?php esc_html_e('GA4 Property ID (Cho Data Manager API)', 'woo-track'); ?></label></th>
                        <td>
                            <input name="woo_track_settings[ga4_property_id]" type="text" id="ga4_property_id" value="<?php echo esc_attr($ga4_property_id); ?>" class="regular-text" placeholder="123456789" />
                            <p class="description"><?php esc_html_e('Mã số Property ID của GA4 (chỉ gồm chữ số, lấy tại GA4 Admin > Property details). Kết hợp cùng GA4 Measurement ID (G-XXXXXXXX) ở mục 2.', 'woo-track'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="gads_customer_id"><?php esc_html_e('Google Ads Import ID (Tuỳ chọn nâng cao)', 'woo-track'); ?></label></th>
                        <td>
                            <div style="display:flex; gap:12px; flex-wrap:wrap; align-items:center;">
                                <div>
                                    <label for="gads_customer_id"><strong><?php esc_html_e('Customer ID (10 chữ số):', 'woo-track'); ?></strong></label><br>
                                    <input name="woo_track_settings[gads_customer_id]" type="text" id="gads_customer_id" value="<?php echo esc_attr($gads_customer_id); ?>" class="regular-text" placeholder="Để trống nếu dùng qua GA4" />
                                </div>
                                <div>
                                    <label for="gads_conversion_action_id"><strong><?php esc_html_e('Conversion Action ID (Loại Import):', 'woo-track'); ?></strong></label><br>
                                    <input name="woo_track_settings[gads_conversion_action_id]" type="text" id="gads_conversion_action_id" value="<?php echo esc_attr($gads_conversion_action_id); ?>" class="regular-text" placeholder="Để trống nếu dùng qua GA4" />
                                </div>
                            </div>
                            <p class="description"><?php esc_html_e('Khuyên dùng: ĐỂ TRỐNG 2 ô này nếu bạn đã điền GA4 Property ID ở trên và đặt sự kiện purchase_ga (GA4) làm Primary trong Google Ads. Chỉ điền 2 ô này nếu bạn tạo riêng một Conversion Action có nguồn là "Import" (Offline/API) trên Google Ads.', 'woo-track'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Kiểm tra Kết nối (Diagnostics Test)', 'woo-track'); ?></th>
                        <td>
                            <div>
                                <button type="button" id="woo-track-btn-test-google-api" class="button button-secondary" style="border-color:#0f9d58; color:#0f9d58; font-weight:600;">
                                    &#9889; <?php esc_html_e('Kiểm tra kết nối Google API ngay (Không cần đặt đơn ảo)', 'woo-track'); ?>
                                </button>
                                <span id="woo-track-google-test-spinner" class="spinner" style="float:none; margin-top:4px;"></span>
                            </div>
                            <p class="description"><?php esc_html_e('Bấm nút này để gửi 1 gói tin kiểm định (validateOnly = true) trực tiếp tới Google Data Manager API / Measurement Protocol mà không cần tạo đơn hàng nháp và không ảnh hưởng tới Meta hay TikTok.', 'woo-track'); ?></p>
                            <div id="woo-track-google-test-result" style="display:none; margin-top:12px; max-width:780px;"></div>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ga4_api_secret"><?php esc_html_e('GA4 Measurement Protocol API Secret (Dự phòng / Nhanh)', 'woo-track'); ?></label></th>
                        <td>
                            <input name="woo_track_settings[ga4_api_secret]" type="text" id="ga4_api_secret" value="<?php echo esc_attr($ga4_api_secret); ?>" class="regular-text" placeholder="Lấy tại GA4 > Luồng dữ liệu > Measurement Protocol API secrets" />
                            <p class="description"><?php esc_html_e('Nếu bạn chưa tạo Google Cloud Service Account JSON ở trên, bạn chỉ cần điền API Secret này (kèm GA4 Measurement ID ở mục 2) để plugin tự động gửi qua GA4 Measurement Protocol.', 'woo-track'); ?></p>
                        </td>
                    </tr>
                </table>

                <!-- 4. META (FACEBOOK) PIXEL & CONVERSIONS API (CAPI) -->
                <h2 style="border-bottom: 2px solid #0866ff; padding-bottom: 8px; margin-top:25px; color:#0866ff;">
                    <span class="dashicons dashicons-facebook" style="margin-right:6px;"></span>4. Meta (Facebook) Pixel & Conversions API (CAPI)
                </h2>
                <p class="description"><?php esc_html_e('Theo dõi chuyển đổi kép (Browser + Server-side) chuẩn Meta với cơ chế tự động khử trùng lặp (Deduplication) qua eventID.', 'woo-track'); ?></p>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="meta_pixel_id"><?php esc_html_e('Meta Pixel ID / Dataset ID', 'woo-track'); ?></label></th>
                        <td>
                            <input name="woo_track_settings[meta_pixel_id]" type="text" id="meta_pixel_id" value="<?php echo esc_attr($meta_pixel_id); ?>" class="regular-text" placeholder="123456789012345" />
                            <p class="description"><?php esc_html_e('ID điểm dữ liệu hoặc Pixel ID từ Meta Events Manager.', 'woo-track'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Kích hoạt Meta Pixel', 'woo-track'); ?></th>
                        <td>
                            <label>
                                <input name="woo_track_settings[enable_meta_pixel]" type="checkbox" value="yes" <?php checked($enable_meta_pixel, 'yes'); ?> />
                                <strong><?php esc_html_e('Bật theo dõi Meta Pixel phía Trình duyệt (Browser)', 'woo-track'); ?></strong>
                            </label>
                            <p class="description"><?php esc_html_e('Tự động chèn Base Code chuẩn và bắn các sự kiện chuẩn (PageView, ViewContent, AddToCart, InitiateCheckout, Purchase).', 'woo-track'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Conversions API (CAPI)', 'woo-track'); ?></th>
                        <td>
                            <label style="display:block; margin-bottom:10px;">
                                <input name="woo_track_settings[enable_meta_capi]" type="checkbox" value="yes" <?php checked($enable_meta_capi, 'yes'); ?> />
                                <strong><?php esc_html_e('Bật Meta Conversions API (Server-side)', 'woo-track'); ?></strong>
                            </label>
                            <label for="meta_capi_token"><strong><?php esc_html_e('CAPI Access Token:', 'woo-track'); ?></strong></label><br>
                            <textarea name="woo_track_settings[meta_capi_token]" id="meta_capi_token" rows="3" class="large-text" placeholder="EAA... (Lấy tại Events Manager > Cài đặt > Tạo Access Token)"><?php echo esc_textarea($meta_capi_token); ?></textarea>
                            <p class="description"><?php esc_html_e('Mã truy cập tạo từ Trình quản lý sự kiện của Meta để cho phép máy chủ gửi sự kiện chuyển đổi trực tiếp.', 'woo-track'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="meta_test_event_code"><?php esc_html_e('Test Event Code (Kiểm thử CAPI)', 'woo-track'); ?></label></th>
                        <td>
                            <input name="woo_track_settings[meta_test_event_code]" type="text" id="meta_test_event_code" value="<?php echo esc_attr($meta_test_event_code); ?>" class="regular-text" placeholder="TEST12345 (Tuỳ chọn)" />
                            <p class="description"><?php esc_html_e('Nhập mã kiểm thử sự kiện trong tab "Thử nghiệm sự kiện" của Meta Events Manager để test máy chủ realtime. Xóa đi khi chạy thực tế.', 'woo-track'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Advanced Matching', 'woo-track'); ?></th>
                        <td>
                            <label>
                                <input name="woo_track_settings[enable_meta_advanced_matching]" type="checkbox" value="yes" <?php checked($enable_meta_advanced_matching, 'yes'); ?> />
                                <strong><?php esc_html_e('Bật Khớp đối tượng nâng cao (Meta Advanced Matching)', 'woo-track'); ?></strong>
                            </label>
                            <p class="description"><?php esc_html_e('Tự động băm SHA-256 Email, Số điện thoại và Địa chỉ khách hàng gửi kèm sự kiện để tối đa hóa điểm chất lượng khớp sự kiện (Event Match Quality).', 'woo-track'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="meta_content_type"><?php esc_html_e('Loại nội dung (content_type)', 'woo-track'); ?></label></th>
                        <td>
                            <select name="woo_track_settings[meta_content_type]" id="meta_content_type">
                                <option value="product" <?php selected($meta_content_type, 'product'); ?>><?php esc_html_e('product (Mặc định - Dành cho danh mục sản phẩm chuẩn)', 'woo-track'); ?></option>
                                <option value="product_group" <?php selected($meta_content_type, 'product_group'); ?>><?php esc_html_e('product_group (Dành cho nhóm biến thể)', 'woo-track'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Các sự kiện Meta theo dõi', 'woo-track'); ?></th>
                        <td>
                            <fieldset>
                                <label style="display:block; margin-bottom:8px;">
                                    <input name="woo_track_settings[meta_event_view_content]" type="checkbox" value="yes" <?php checked($meta_event_view_content, 'yes'); ?> />
                                    <strong>ViewContent:</strong> <?php esc_html_e('Xem chi tiết sản phẩm', 'woo-track'); ?>
                                </label>
                                <label style="display:block; margin-bottom:8px;">
                                    <input name="woo_track_settings[meta_event_add_to_cart]" type="checkbox" value="yes" <?php checked($meta_event_add_to_cart, 'yes'); ?> />
                                    <strong>AddToCart:</strong> <?php esc_html_e('Thêm vào giỏ hàng (Form chi tiết và AJAX)', 'woo-track'); ?>
                                </label>
                                <label style="display:block; margin-bottom:8px;">
                                    <input name="woo_track_settings[meta_event_initiate_checkout]" type="checkbox" value="yes" <?php checked($meta_event_initiate_checkout, 'yes'); ?> />
                                    <strong>InitiateCheckout:</strong> <?php esc_html_e('Bắt đầu thanh toán', 'woo-track'); ?>
                                </label>
                                <label style="display:block; margin-bottom:8px;">
                                    <input name="woo_track_settings[meta_event_purchase]" type="checkbox" value="yes" <?php checked($meta_event_purchase, 'yes'); ?> />
                                    <strong>Purchase:</strong> <?php esc_html_e('Mua hàng thành công (Kèm CAPI & Deduplication)', 'woo-track'); ?>
                                </label>
                                <label style="display:block; margin-bottom:8px;">
                                    <input name="woo_track_settings[meta_event_search]" type="checkbox" value="yes" <?php checked($meta_event_search, 'yes'); ?> />
                                    <strong>Search:</strong> <?php esc_html_e('Tìm kiếm sản phẩm trên website', 'woo-track'); ?>
                                </label>
                            </fieldset>
                        </td>
                    </tr>
                </table>

                <!-- 5. TIKTOK ADS (PIXEL & EVENTS API) -->
                <h2 style="border-bottom: 2px solid #000; padding-bottom: 8px; margin-top:25px; color:#111;">
                    <span class="dashicons dashicons-video-alt3" style="margin-right:6px; color:#fe2c55;"></span>5. TikTok Ads (Pixel & Events API)
                </h2>
                <p class="description"><?php esc_html_e('Theo dõi chuyển đổi kép (Browser TikTok Pixel + Server-to-Server Events API v1.3/v2) chuẩn TikTok với cơ chế tự động khử trùng lặp (Deduplication) qua event_id.', 'woo-track'); ?></p>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="tiktok_pixel_id"><?php esc_html_e('TikTok Pixel ID', 'woo-track'); ?></label></th>
                        <td>
                            <input name="woo_track_settings[tiktok_pixel_id]" type="text" id="tiktok_pixel_id" value="<?php echo esc_attr($tiktok_pixel_id); ?>" class="regular-text" placeholder="C8XXXXXXXXXXXXXX" />
                            <p class="description"><?php esc_html_e('Mã TikTok Pixel ID (Lấy tại: TikTok Ads Manager > Tools > Events > Web Events).', 'woo-track'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Kích hoạt TikTok Pixel', 'woo-track'); ?></th>
                        <td>
                            <label>
                                <input name="woo_track_settings[enable_tiktok_pixel]" type="checkbox" value="yes" <?php checked($enable_tiktok_pixel, 'yes'); ?> />
                                <strong><?php esc_html_e('Bật theo dõi TikTok Pixel phía Trình duyệt (Browser)', 'woo-track'); ?></strong>
                            </label>
                            <p class="description"><?php esc_html_e('Tự động chèn Base Code chuẩn và bắn các sự kiện chuẩn (ViewContent, AddToCart, InitiateCheckout, PlaceAnOrder, Purchase...).', 'woo-track'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('TikTok Events API (Server-side)', 'woo-track'); ?></th>
                        <td>
                            <label style="display:block; margin-bottom:10px;">
                                <input name="woo_track_settings[enable_tiktok_events_api]" type="checkbox" value="yes" <?php checked($enable_tiktok_events_api, 'yes'); ?> />
                                <strong><?php esc_html_e('Bật TikTok Events API (Server-to-Server)', 'woo-track'); ?></strong>
                            </label>
                            <label for="tiktok_access_token"><strong><?php esc_html_e('TikTok Access Token:', 'woo-track'); ?></strong></label><br>
                            <textarea name="woo_track_settings[tiktok_access_token]" id="tiktok_access_token" rows="3" class="large-text" placeholder="Chuỗi Access Token (Lấy tại: Events Manager > Pixel Settings > Events API > Generate Access Token)"><?php echo esc_textarea($tiktok_access_token); ?></textarea>
                            <p class="description"><?php esc_html_e('Mã truy cập tạo từ Trình quản lý sự kiện của TikTok để cho phép máy chủ gửi sự kiện chuyển đổi trực tiếp.', 'woo-track'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="tiktok_test_event_code"><?php esc_html_e('Test Event Code (Kiểm thử Events API)', 'woo-track'); ?></label></th>
                        <td>
                            <input name="woo_track_settings[tiktok_test_event_code]" type="text" id="tiktok_test_event_code" value="<?php echo esc_attr($tiktok_test_event_code); ?>" class="regular-text" placeholder="TEST12345 (Tuỳ chọn)" />
                            <p class="description"><?php esc_html_e('Nhập mã kiểm thử sự kiện trong tab "Test Events" của TikTok Events Manager để xem dữ liệu gửi từ máy chủ realtime. Xóa đi khi chạy thực tế.', 'woo-track'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Advanced Matching', 'woo-track'); ?></th>
                        <td>
                            <label>
                                <input name="woo_track_settings[enable_tiktok_advanced_matching]" type="checkbox" value="yes" <?php checked($enable_tiktok_advanced_matching, 'yes'); ?> />
                                <strong><?php esc_html_e('Bật Khớp nâng cao TikTok (Advanced Matching)', 'woo-track'); ?></strong>
                            </label>
                            <p class="description"><?php esc_html_e('Tự động băm SHA-256 Email và Số điện thoại (chuẩn E.164) gửi kèm qua ttq.identify() và Events API để tối đa hóa tỷ lệ ghi nhận đơn hàng.', 'woo-track'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="tiktok_purchase_event_name"><?php esc_html_e('Tên sự kiện Mua hàng', 'woo-track'); ?></label></th>
                        <td>
                            <select name="woo_track_settings[tiktok_purchase_event_name]" id="tiktok_purchase_event_name">
                                <option value="Purchase" <?php selected($tiktok_purchase_event_name, 'Purchase'); ?>><?php esc_html_e('Purchase (Chuẩn mới nhất của TikTok - Khuyên dùng)', 'woo-track'); ?></option>
                                <option value="CompletePayment" <?php selected($tiktok_purchase_event_name, 'CompletePayment'); ?>><?php esc_html_e('CompletePayment (Chuẩn cũ của TikTok)', 'woo-track'); ?></option>
                            </select>
                            <p class="description"><?php esc_html_e('Chọn tên sự kiện bạn đã cấu hình trong Trình quản lý sự kiện TikTok để đảm bảo khớp 100% khi khử trùng lặp.', 'woo-track'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Các sự kiện TikTok theo dõi', 'woo-track'); ?></th>
                        <td>
                            <fieldset>
                                <label style="display:block; margin-bottom:8px;">
                                    <input name="woo_track_settings[tiktok_event_view_content]" type="checkbox" value="yes" <?php checked($tiktok_event_view_content, 'yes'); ?> />
                                    <strong>ViewContent:</strong> <?php esc_html_e('Xem chi tiết sản phẩm / extension', 'woo-track'); ?>
                                </label>
                                <label style="display:block; margin-bottom:8px;">
                                    <input name="woo_track_settings[tiktok_event_add_to_cart]" type="checkbox" value="yes" <?php checked($tiktok_event_add_to_cart, 'yes'); ?> />
                                    <strong>AddToCart:</strong> <?php esc_html_e('Thêm vào giỏ hàng (Form chi tiết và AJAX)', 'woo-track'); ?>
                                </label>
                                <label style="display:block; margin-bottom:8px;">
                                    <input name="woo_track_settings[tiktok_event_initiate_checkout]" type="checkbox" value="yes" <?php checked($tiktok_event_initiate_checkout, 'yes'); ?> />
                                    <strong>InitiateCheckout:</strong> <?php esc_html_e('Khách vào trang thanh toán', 'woo-track'); ?>
                                </label>
                                <label style="display:block; margin-bottom:8px;">
                                    <input name="woo_track_settings[tiktok_event_add_payment_info]" type="checkbox" value="yes" <?php checked($tiktok_event_add_payment_info, 'yes'); ?> />
                                    <strong>AddPaymentInfo:</strong> <?php esc_html_e('Khách chọn phương thức thanh toán', 'woo-track'); ?>
                                </label>
                                <label style="display:block; margin-bottom:8px;">
                                    <input name="woo_track_settings[tiktok_event_place_an_order]" type="checkbox" value="yes" <?php checked($tiktok_event_place_an_order, 'yes'); ?> />
                                    <strong>PlaceAnOrder:</strong> <?php esc_html_e('Khách bấm nút Đặt hàng trên Checkout', 'woo-track'); ?>
                                </label>
                                <label style="display:block; margin-bottom:8px;">
                                    <input name="woo_track_settings[tiktok_event_purchase]" type="checkbox" value="yes" <?php checked($tiktok_event_purchase, 'yes'); ?> />
                                    <strong>Purchase / CompletePayment:</strong> <?php esc_html_e('Mua hàng thành công (Kèm Events API & Deduplication)', 'woo-track'); ?>
                                </label>
                                <label style="display:block; margin-bottom:8px;">
                                    <input name="woo_track_settings[tiktok_event_search]" type="checkbox" value="yes" <?php checked($tiktok_event_search, 'yes'); ?> />
                                    <strong>Search:</strong> <?php esc_html_e('Tìm kiếm sản phẩm trên website', 'woo-track'); ?>
                                </label>
                                <label style="display:block; margin-bottom:8px;">
                                    <input name="woo_track_settings[tiktok_event_add_to_wishlist]" type="checkbox" value="yes" <?php checked($tiktok_event_add_to_wishlist, 'yes'); ?> />
                                    <strong>AddToWishlist:</strong> <?php esc_html_e('Thêm vào danh sách mong muốn', 'woo-track'); ?>
                                </label>
                            </fieldset>
                        </td>
                    </tr>
                </table>

                <!-- 6. SẢN PHẨM & SỰ KIỆN CHUNG -->
                <h2 style="border-bottom: 2px solid #5f6368; padding-bottom: 8px; margin-top:25px; color:#3c4043;">
                    <span class="dashicons dashicons-cart" style="margin-right:6px;"></span>6. Cấu hình Định danh Sản phẩm
                </h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="product_identifier"><?php esc_html_e('Định danh sản phẩm (item_id / content_id)', 'woo-track'); ?></label></th>
                        <td>
                            <select name="woo_track_settings[product_identifier]" id="product_identifier">
                                <option value="parent_id" <?php selected($product_identifier, 'parent_id'); ?>><?php esc_html_e('ID Sản phẩm cha (Parent ID - Khuyên dùng cho Extension / Phần mềm có nhiều gói biến thể)', 'woo-track'); ?></option>
                                <option value="id" <?php selected($product_identifier, 'id'); ?>><?php esc_html_e('ID riêng từng Biến thể / Bài viết (Variation / Post ID - Ví dụ: 1042)', 'woo-track'); ?></option>
                                <option value="sku" <?php selected($product_identifier, 'sku'); ?>><?php esc_html_e('Mã SKU sản phẩm (Nếu không có SKU sẽ dùng ID)', 'woo-track'); ?></option>
                            </select>
                            <p class="description"><?php esc_html_e('Chọn "ID Sản phẩm cha" giúp đồng nhất mã sản phẩm xuyên suốt từ bước Xem sản phẩm -> Thêm giỏ hàng -> Mua hàng, trong khi Giá tiền (value/price) và Tên gói (item_variant) vẫn tự động lấy chính xác theo gói biến thể khách chọn.', 'woo-track'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Sự kiện Google Analytics / GTM', 'woo-track'); ?></th>
                        <td>
                            <fieldset>
                                <label style="display:block; margin-bottom:8px;">
                                    <input name="woo_track_settings[enable_view_item_list]" type="checkbox" value="yes" <?php checked($enable_view_item_list, 'yes'); ?> />
                                    <strong>view_item_list:</strong> <?php esc_html_e('Xem danh sách / Trang cửa hàng & danh mục', 'woo-track'); ?>
                                </label>
                                <label style="display:block; margin-bottom:8px;">
                                    <input name="woo_track_settings[enable_view_item]" type="checkbox" value="yes" <?php checked($enable_view_item, 'yes'); ?> />
                                    <strong>view_item:</strong> <?php esc_html_e('Xem chi tiết sản phẩm (Dynamic Remarketing)', 'woo-track'); ?>
                                </label>
                                <label style="display:block; margin-bottom:8px;">
                                    <input name="woo_track_settings[enable_add_to_cart]" type="checkbox" value="yes" <?php checked($enable_add_to_cart, 'yes'); ?> />
                                    <strong>add_to_cart:</strong> <?php esc_html_e('Thêm vào giỏ hàng (Hỗ trợ nút chuẩn và AJAX)', 'woo-track'); ?>
                                </label>
                                <label style="display:block; margin-bottom:8px;">
                                    <input name="woo_track_settings[enable_begin_checkout]" type="checkbox" value="yes" <?php checked($enable_begin_checkout, 'yes'); ?> />
                                    <strong>begin_checkout:</strong> <?php esc_html_e('Khách bắt đầu vào trang thanh toán', 'woo-track'); ?>
                                </label>
                                <label style="display:block; margin-bottom:8px;">
                                    <input name="woo_track_settings[enable_purchase]" type="checkbox" value="yes" <?php checked($enable_purchase, 'yes'); ?> />
                                    <strong>purchase:</strong> <?php esc_html_e('Mua hàng thành công (Trang cảm ơn - Chống trùng lặp khi F5)', 'woo-track'); ?>
                                </label>
                            </fieldset>
                        </td>
                    </tr>
                </table>

                <!-- 7. NGUỒN CẤP DỮ LIỆU SẢN PHẨM (XML / CSV PRODUCT FEED) -->
                <?php
                $meta_xml_url   = WooTrack_Feed::get_feed_url('meta', 'xml');
                $meta_csv_url   = WooTrack_Feed::get_feed_url('meta', 'csv');
                $google_xml_url = WooTrack_Feed::get_feed_url('google', 'xml');
                $google_csv_url = WooTrack_Feed::get_feed_url('google', 'csv');
                $tiktok_xml_url = WooTrack_Feed::get_feed_url('tiktok', 'xml');
                $tiktok_csv_url = WooTrack_Feed::get_feed_url('tiktok', 'csv');
                ?>
                <h2 style="border-bottom: 2px solid #9334e6; padding-bottom: 8px; margin-top:25px; color:#9334e6;">
                    <span class="dashicons dashicons-rss" style="margin-right:6px;"></span>7. Nguồn Cấp Dữ Liệu Sản Phẩm (Meta, Google & TikTok Catalog Feed)
                </h2>
                <p class="description"><?php esc_html_e('Tự động tạo link XML / CSV chuẩn mới nhất cho Meta Commerce Manager, Google Merchant Center và TikTok Catalog Manager, đồng bộ 100% mã ID sản phẩm với Pixel, CAPI, TikTok Events API & Google Ads. Tích hợp bộ đệm thông minh tự cập nhật tức thì khi sửa sản phẩm (0% tải CPU).', 'woo-track'); ?></p>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('Kích hoạt Product Feed', 'woo-track'); ?></th>
                        <td>
                            <label>
                                <input name="woo_track_settings[enable_product_feed]" type="checkbox" value="yes" <?php checked($enable_product_feed, 'yes'); ?> />
                                <strong><?php esc_html_e('Bật đường dẫn XML / CSV Feed cho Meta, Google & TikTok', 'woo-track'); ?></strong>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Meta (Facebook) Catalog Feed', 'woo-track'); ?></th>
                        <td>
                            <div style="margin-bottom:8px;">
                                <strong>XML (Khuyên dùng):</strong><br>
                                <input type="text" readonly value="<?php echo esc_url($meta_xml_url); ?>" class="large-text code" onclick="this.select();" style="max-width:580px; background:#f6f7f7;" />
                                <a href="<?php echo esc_url($meta_xml_url); ?>" target="_blank" class="button button-secondary"><?php esc_html_e('Mở XML', 'woo-track'); ?></a>
                            </div>
                            <div>
                                <strong>CSV:</strong><br>
                                <input type="text" readonly value="<?php echo esc_url($meta_csv_url); ?>" class="large-text code" onclick="this.select();" style="max-width:580px; background:#f6f7f7;" />
                                <a href="<?php echo esc_url($meta_csv_url); ?>" target="_blank" class="button button-secondary"><?php esc_html_e('Tải CSV', 'woo-track'); ?></a>
                            </div>
                            <p class="description"><?php esc_html_e('Dán link này vào: Meta Commerce Manager > Danh mục (Catalog) > Nguồn dữ liệu (Data Sources) > Data Feed > Theo lịch trình (Scheduled Feed).', 'woo-track'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Google Merchant Center Feed', 'woo-track'); ?></th>
                        <td>
                            <div style="margin-bottom:8px;">
                                <strong>XML (Khuyên dùng):</strong><br>
                                <input type="text" readonly value="<?php echo esc_url($google_xml_url); ?>" class="large-text code" onclick="this.select();" style="max-width:580px; background:#f6f7f7;" />
                                <a href="<?php echo esc_url($google_xml_url); ?>" target="_blank" class="button button-secondary"><?php esc_html_e('Mở XML', 'woo-track'); ?></a>
                            </div>
                            <div>
                                <strong>CSV:</strong><br>
                                <input type="text" readonly value="<?php echo esc_url($google_csv_url); ?>" class="large-text code" onclick="this.select();" style="max-width:580px; background:#f6f7f7;" />
                                <a href="<?php echo esc_url($google_csv_url); ?>" target="_blank" class="button button-secondary"><?php esc_html_e('Tải CSV', 'woo-track'); ?></a>
                            </div>
                            <p class="description"><?php esc_html_e('Dán link này vào Google Merchant Center > Nguồn cấp dữ liệu (Feeds) > Nguồn cấp dữ liệu chính > Lịch biểu nạp nạp tự động.', 'woo-track'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('TikTok Catalog Data Feed', 'woo-track'); ?></th>
                        <td>
                            <div style="margin-bottom:8px;">
                                <strong>XML (Khuyên dùng):</strong><br>
                                <input type="text" readonly value="<?php echo esc_url($tiktok_xml_url); ?>" class="large-text code" onclick="this.select();" style="max-width:580px; background:#f6f7f7;" />
                                <a href="<?php echo esc_url($tiktok_xml_url); ?>" target="_blank" class="button button-secondary"><?php esc_html_e('Mở XML', 'woo-track'); ?></a>
                            </div>
                            <div>
                                <strong>CSV:</strong><br>
                                <input type="text" readonly value="<?php echo esc_url($tiktok_csv_url); ?>" class="large-text code" onclick="this.select();" style="max-width:580px; background:#f6f7f7;" />
                                <a href="<?php echo esc_url($tiktok_csv_url); ?>" target="_blank" class="button button-secondary"><?php esc_html_e('Tải CSV', 'woo-track'); ?></a>
                            </div>
                            <p class="description"><?php esc_html_e('Dán link này vào TikTok Ads Manager > Assets > Catalogs > Data Sources > Scheduled Feed (Tự động nạp theo lịch định kỳ).', 'woo-track'); ?></p>
                        </td>
                    </tr>
                </table>

                <!-- 8. DEBUG MODE -->
                <h2 style="border-bottom: 2px solid #5f6368; padding-bottom: 8px; margin-top:25px; color:#3c4043;">
                    <span class="dashicons dashicons-admin-tools" style="margin-right:6px;"></span>8. Chế độ Kiểm thử (Debug Mode)
                </h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('Console Log', 'woo-track'); ?></th>
                        <td>
                            <label>
                                <input name="woo_track_settings[debug_mode]" type="checkbox" value="yes" <?php checked($debug_mode, 'yes'); ?> />
                                <?php esc_html_e('Bật Debug Console (In toàn bộ sự kiện Google, Meta & TikTok ra F12 Developer Tools và ghi log Server Events)', 'woo-track'); ?>
                            </label>
                        </td>
                    </tr>
                </table>

                <?php submit_button(__('Lưu Cấu Hình WooTrack', 'woo-track'), 'primary', 'submit', true, array('style' => 'margin-top:20px; font-size:15px; padding:6px 25px;')); ?>
            </form>
        </div>

        <script type="text/javascript">
        jQuery(document).ready(function($) {
            $('#woo-track-btn-test-google-api').on('click', function(e) {
                e.preventDefault();
                var $btn     = $(this);
                var $spinner = $('#woo-track-google-test-spinner');
                var $result  = $('#woo-track-google-test-result');

                $btn.prop('disabled', true);
                $spinner.addClass('is-active');
                $result.hide().empty();

                $.post(ajaxurl, {
                    action: 'woo_track_test_google_api',
                    nonce: '<?php echo esc_js(wp_create_nonce('woo_track_test_google_api')); ?>',
                    sa_json: $('#google_service_account_json').val(),
                    ga4_id: $('#ga4_id').val(),
                    ga4_property_id: $('#ga4_property_id').val(),
                    gads_customer_id: $('#gads_customer_id').val(),
                    gads_conversion_action_id: $('#gads_conversion_action_id').val(),
                    ga4_api_secret: $('#ga4_api_secret').val()
                }).done(function(res) {
                    if (!res || !res.success || !res.data || !res.data.steps) {
                        var errMsg = (res && res.data && res.data.message) ? res.data.message : 'Không nhận được phản hồi hợp lệ từ máy chủ.';
                        $result.html('<div style="padding:12px; border-left:4px solid #d63638; background:#fcf0f1; color:#1d2327;"><strong>&#10060; Lỗi:</strong> ' + $('<div>').text(errMsg).html() + '</div>').fadeIn(150);
                        return;
                    }

                    var html = '';
                    $.each(res.data.steps, function(idx, step) {
                        var borderColor = '#0f9d58';
                        var bgColor     = '#edfaef';
                        var icon        = '&#9989;';
                        if (step.status === 'error') {
                            borderColor = '#d63638';
                            bgColor     = '#fcf0f1';
                            icon        = '&#10060;';
                        } else if (step.status === 'warning') {
                            borderColor = '#dba617';
                            bgColor     = '#fcf9e8';
                            icon        = '&#9888;&#65039;';
                        }
                        var safeTitle = $('<div>').text(step.title || '').html();
                        var safeMsg   = $('<div>').text(step.message || '').html().replace(/\n/g, '<br>');
                        html += '<div style="padding:10px 14px; margin-bottom:8px; border-left:4px solid ' + borderColor + '; background:' + bgColor + '; border-radius:2px;">';
                        html += '<div style="font-weight:600; margin-bottom:4px;">' + icon + ' ' + safeTitle + '</div>';
                        html += '<div style="font-size:13px; color:#2c3338; line-height:1.5; word-break:break-word;">' + safeMsg + '</div>';
                        html += '</div>';
                    });

                    $result.html(html).fadeIn(150);
                }).fail(function(xhr) {
                    $result.html('<div style="padding:12px; border-left:4px solid #d63638; background:#fcf0f1;"><strong>&#10060; Lỗi kết nối AJAX:</strong> HTTP ' + xhr.status + '</div>').fadeIn(150);
                }).always(function() {
                    $btn.prop('disabled', false);
                    $spinner.removeClass('is-active');
                });
            });
        });
        </script>
        <?php
    }
}
