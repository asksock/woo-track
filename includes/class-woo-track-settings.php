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
        $sanitized['gads_id'] = isset($input['gads_id']) ? sanitize_text_field(strtoupper(trim($input['gads_id']))) : '';

        // Google Ads Conversion Labels
        $sanitized['purchase_label']       = isset($input['purchase_label']) ? sanitize_text_field(trim($input['purchase_label'])) : '';
        $sanitized['begin_checkout_label'] = isset($input['begin_checkout_label']) ? sanitize_text_field(trim($input['begin_checkout_label'])) : '';
        $sanitized['add_to_cart_label']    = isset($input['add_to_cart_label']) ? sanitize_text_field(trim($input['add_to_cart_label'])) : '';

        // Định danh sản phẩm
        $sanitized['product_identifier']   = isset($input['product_identifier']) && in_array($input['product_identifier'], array('id', 'sku')) ? $input['product_identifier'] : 'id';

        // Checkboxes
        $checkboxes = array(
            'enable_view_item_list',
            'enable_view_item',
            'enable_add_to_cart',
            'enable_begin_checkout',
            'enable_purchase',
            'enable_enhanced_conversions',
            'debug_mode'
        );

        foreach ($checkboxes as $cb) {
            $sanitized[$cb] = !empty($input[$cb]) ? 'yes' : 'no';
        }

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
        $product_identifier   = isset($options['product_identifier']) ? $options['product_identifier'] : 'id';

        $enable_view_item_list       = isset($options['enable_view_item_list']) ? $options['enable_view_item_list'] : 'yes';
        $enable_view_item            = isset($options['enable_view_item']) ? $options['enable_view_item'] : 'yes';
        $enable_add_to_cart          = isset($options['enable_add_to_cart']) ? $options['enable_add_to_cart'] : 'yes';
        $enable_begin_checkout       = isset($options['enable_begin_checkout']) ? $options['enable_begin_checkout'] : 'yes';
        $enable_purchase             = isset($options['enable_purchase']) ? $options['enable_purchase'] : 'yes';
        $enable_enhanced_conversions = isset($options['enable_enhanced_conversions']) ? $options['enable_enhanced_conversions'] : 'no';
        $debug_mode                  = isset($options['debug_mode']) ? $options['debug_mode'] : 'no';
        ?>
        <div class="wrap" style="max-width: 950px;">
            <h1><span class="dashicons dashicons-chart-bar" style="font-size:30px; margin-right:8px; vertical-align:middle;"></span><?php esc_html_e('WooTrack - Bộ Công Cụ Theo Dõi Độc Lập Google', 'woo-track'); ?></h1>
            <p class="description" style="margin-bottom: 20px; font-size:14px;">
                <?php esc_html_e('Tự động chèn mã và đồng bộ sự kiện E-commerce độc lập cho Google Tag Manager (GTM), Google Analytics 4 (GA4) và Google Ads mà không cần phụ thuộc vào bất kỳ plugin bên thứ 3 nào.', 'woo-track'); ?>
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
                </table>

                <!-- 4. SẢN PHẨM & SỰ KIỆN -->
                <h2 style="border-bottom: 2px solid #5f6368; padding-bottom: 8px; margin-top:25px; color:#3c4043;">
                    <span class="dashicons dashicons-cart" style="margin-right:6px;"></span>4. Cấu hình E-commerce & Danh sách Sự kiện
                </h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="product_identifier"><?php esc_html_e('Định danh sản phẩm (item_id)', 'woo-track'); ?></label></th>
                        <td>
                            <select name="woo_track_settings[product_identifier]" id="product_identifier">
                                <option value="id" <?php selected($product_identifier, 'id'); ?>><?php esc_html_e('ID bài viết WordPress (Post ID - Ví dụ: 1042)', 'woo-track'); ?></option>
                                <option value="sku" <?php selected($product_identifier, 'sku'); ?>><?php esc_html_e('Mã SKU sản phẩm (Nếu không có SKU sẽ dùng Post ID)', 'woo-track'); ?></option>
                            </select>
                            <p class="description"><?php esc_html_e('Chọn giá trị trùng khớp với nguồn cấp dữ liệu sản phẩm trên Google Merchant Center (GMC).', 'woo-track'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Bật/Tắt sự kiện', 'woo-track'); ?></th>
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

                <!-- 5. DEBUG MODE -->
                <h2 style="border-bottom: 2px solid #5f6368; padding-bottom: 8px; margin-top:25px; color:#3c4043;">
                    <span class="dashicons dashicons-admin-tools" style="margin-right:6px;"></span>5. Chế độ Kiểm thử (Debug Mode)
                </h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('Console Log', 'woo-track'); ?></th>
                        <td>
                            <label>
                                <input name="woo_track_settings[debug_mode]" type="checkbox" value="yes" <?php checked($debug_mode, 'yes'); ?> />
                                <?php esc_html_e('Bật Debug Console (In toàn bộ sự kiện và dữ liệu ra F12 Developer Tools)', 'woo-track'); ?>
                            </label>
                        </td>
                    </tr>
                </table>

                <?php submit_button(__('Lưu Cấu Hình WooTrack', 'woo-track'), 'primary', 'submit', true, array('style' => 'margin-top:20px; font-size:15px; padding:6px 25px;')); ?>
            </form>
        </div>
        <?php
    }
}
