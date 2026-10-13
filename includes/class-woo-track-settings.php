<?php
/**
 * WooTrack Admin Settings
 *
 * Quản lý trang cấu hình WooTrack trong trang quản trị WooCommerce
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
            __('WooTrack (Google)', 'woo-track'),
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

        $sanitized['gads_id'] = isset($input['gads_id']) ? sanitize_text_field(trim($input['gads_id'])) : '';
        $sanitized['purchase_label'] = isset($input['purchase_label']) ? sanitize_text_field(trim($input['purchase_label'])) : '';
        $sanitized['add_to_cart_label'] = isset($input['add_to_cart_label']) ? sanitize_text_field(trim($input['add_to_cart_label'])) : '';
        $sanitized['begin_checkout_label'] = isset($input['begin_checkout_label']) ? sanitize_text_field(trim($input['begin_checkout_label'])) : '';
        
        $sanitized['product_identifier'] = isset($input['product_identifier']) && in_array($input['product_identifier'], array('id', 'sku')) ? $input['product_identifier'] : 'id';
        
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

        $gads_id              = isset($options['gads_id']) ? $options['gads_id'] : '';
        $purchase_label       = isset($options['purchase_label']) ? $options['purchase_label'] : '';
        $add_to_cart_label    = isset($options['add_to_cart_label']) ? $options['add_to_cart_label'] : '';
        $begin_checkout_label = isset($options['begin_checkout_label']) ? $options['begin_checkout_label'] : '';
        $product_identifier   = isset($options['product_identifier']) ? $options['product_identifier'] : 'id';
        
        $enable_view_item_list      = isset($options['enable_view_item_list']) ? $options['enable_view_item_list'] : 'yes';
        $enable_view_item           = isset($options['enable_view_item']) ? $options['enable_view_item'] : 'yes';
        $enable_add_to_cart         = isset($options['enable_add_to_cart']) ? $options['enable_add_to_cart'] : 'yes';
        $enable_begin_checkout      = isset($options['enable_begin_checkout']) ? $options['enable_begin_checkout'] : 'yes';
        $enable_purchase            = isset($options['enable_purchase']) ? $options['enable_purchase'] : 'yes';
        $enable_enhanced_conversions= isset($options['enable_enhanced_conversions']) ? $options['enable_enhanced_conversions'] : 'no';
        $debug_mode                 = isset($options['debug_mode']) ? $options['debug_mode'] : 'no';
        ?>
        <div class="wrap" style="max-width: 900px;">
            <h1><span class="dashicons dashicons-chart-bar" style="font-size:30px; margin-right:8px;"></span><?php esc_html_e('WooTrack - Theo dõi Google Analytics (GA4) & Google Ads', 'woo-track'); ?></h1>
            <p class="description" style="margin-bottom: 20px;">
                <?php esc_html_e('Tự động đồng bộ các sự kiện E-commerce với Google Analytics 4 và chuyển đổi Google Ads. Tương thích trực tiếp với Google Site Kit đã cài sẵn trên website.', 'woo-track'); ?>
            </p>

            <form method="post" action="options.php" style="background:#fff; padding:25px 30px; border:1px solid #ccd0d4; box-shadow:0 1px 3px rgba(0,0,0,0.05); border-radius:6px;">
                <?php settings_fields('woo_track_settings_group'); ?>

                <h2 style="border-bottom: 2px solid #2271b1; padding-bottom: 8px; margin-top:0;">1. Cấu hình Google Ads (Chuyển đổi & Remarketing)</h2>
                <p class="description"><?php esc_html_e('GA4 sẽ tự động nhận sự kiện qua mã Site Kit. Phần dưới đây dành riêng cho việc ghi nhận Chuyển đổi (Conversion) trên tài khoản Google Ads.', 'woo-track'); ?></p>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="gads_id"><?php esc_html_e('Google Ads / Google Tag ID', 'woo-track'); ?></label></th>
                        <td>
                            <input name="woo_track_settings[gads_id]" type="text" id="gads_id" value="<?php echo esc_attr($gads_id); ?>" class="regular-text" placeholder="AW-123456789 hoặc GT-XXXXXX" />
                            <p class="description"><?php esc_html_e('Mã thẻ Google của tài khoản Google Ads (bắt đầu bằng AW- hoặc GT-). Bắt buộc để kết nối trực tiếp với Google Ads.', 'woo-track'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="purchase_label"><?php esc_html_e('Purchase Conversion Label', 'woo-track'); ?></label></th>
                        <td>
                            <input name="woo_track_settings[purchase_label]" type="text" id="purchase_label" value="<?php echo esc_attr($purchase_label); ?>" class="regular-text" placeholder="Để trống nếu Google Ads dùng mẫu Event: Purchase" />
                            <p class="description">
                                <strong><?php esc_html_e('Mẹo:', 'woo-track'); ?></strong> 
                                <?php esc_html_e('Nếu trong Google Ads chuyển đổi của bạn hiển thị dạng "Event: Purchase: yourdomain.com" (mẫu mới dựa trên sự kiện), bạn CÓ THỂ ĐỂ TRỐNG ô này! Chỉ điền nếu bạn tạo hành động chuyển đổi dạng thủ công truyền thống.', 'woo-track'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="add_to_cart_label"><?php esc_html_e('Add to Cart Conversion Label', 'woo-track'); ?></label></th>
                        <td>
                            <input name="woo_track_settings[add_to_cart_label]" type="text" id="add_to_cart_label" value="<?php echo esc_attr($add_to_cart_label); ?>" class="regular-text" placeholder="Tuỳ chọn (Nếu muốn đo chuyển đổi phụ)" />
                            <p class="description"><?php esc_html_e('Nhãn chuyển đổi khi thêm vào giỏ hàng (Không bắt buộc, chỉ điền nếu bạn tạo hành động chuyển đổi riêng).', 'woo-track'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="begin_checkout_label"><?php esc_html_e('Begin Checkout Conversion Label', 'woo-track'); ?></label></th>
                        <td>
                            <input name="woo_track_settings[begin_checkout_label]" type="text" id="begin_checkout_label" value="<?php echo esc_attr($begin_checkout_label); ?>" class="regular-text" placeholder="Tuỳ chọn" />
                            <p class="description"><?php esc_html_e('Nhãn chuyển đổi khi bắt đầu thanh toán (Không bắt buộc).', 'woo-track'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Enhanced Conversions', 'woo-track'); ?></th>
                        <td>
                            <label>
                                <input name="woo_track_settings[enable_enhanced_conversions]" type="checkbox" value="yes" <?php checked($enable_enhanced_conversions, 'yes'); ?> />
                                <?php esc_html_e('Bật Dữ liệu người dùng nâng cao (Enhanced Conversions)', 'woo-track'); ?>
                            </label>
                            <p class="description"><?php esc_html_e('Tự động mã hoá SHA-256 Email, Số điện thoại và Địa chỉ khi đặt hàng để Google Ads so khớp chính xác chuyển đổi ngay cả khi chặn cookie bên thứ 3.', 'woo-track'); ?></p>
                        </td>
                    </tr>
                </table>

                <h2 style="border-bottom: 2px solid #2271b1; padding-bottom: 8px; margin-top:20px;">2. Cấu hình Dữ liệu Sản phẩm (Merchant Center & Dynamic Remarketing)</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="product_identifier"><?php esc_html_e('Định danh sản phẩm (item_id)', 'woo-track'); ?></label></th>
                        <td>
                            <select name="woo_track_settings[product_identifier]" id="product_identifier">
                                <option value="id" <?php selected($product_identifier, 'id'); ?>><?php esc_html_e('ID bài viết WordPress (Post ID - Ví dụ: 1042)', 'woo-track'); ?></option>
                                <option value="sku" <?php selected($product_identifier, 'sku'); ?>><?php esc_html_e('Mã SKU sản phẩm (Nếu không có SKU sẽ dùng Post ID)', 'woo-track'); ?></option>
                            </select>
                            <p class="description"><?php esc_html_e('Chọn giá trị trùng khớp với nguồn cấp dữ liệu sản phẩm bạn tải lên Google Merchant Center để Tiếp thị lại động (Dynamic Remarketing) hoạt động chính xác.', 'woo-track'); ?></p>
                        </td>
                    </tr>
                </table>

                <h2 style="border-bottom: 2px solid #2271b1; padding-bottom: 8px; margin-top:20px;">3. Bật / Tắt Sự kiện Theo dõi (Events)</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('Danh sách sự kiện', 'woo-track'); ?></th>
                        <td>
                            <fieldset>
                                <label style="display:block; margin-bottom:8px;">
                                    <input name="woo_track_settings[enable_view_item_list]" type="checkbox" value="yes" <?php checked($enable_view_item_list, 'yes'); ?> />
                                    <strong>view_item_list:</strong> <?php esc_html_e('Xem danh sách / Trang cửa hàng & danh mục', 'woo-track'); ?>
                                </label>
                                <label style="display:block; margin-bottom:8px;">
                                    <input name="woo_track_settings[enable_view_item]" type="checkbox" value="yes" <?php checked($enable_view_item, 'yes'); ?> />
                                    <strong>view_item:</strong> <?php esc_html_e('Xem trang chi tiết sản phẩm (Cần thiết cho Remarketing)', 'woo-track'); ?>
                                </label>
                                <label style="display:block; margin-bottom:8px;">
                                    <input name="woo_track_settings[enable_add_to_cart]" type="checkbox" value="yes" <?php checked($enable_add_to_cart, 'yes'); ?> />
                                    <strong>add_to_cart:</strong> <?php esc_html_e('Thêm vào giỏ hàng (Hỗ trợ cả nút bấm chuẩn và AJAX)', 'woo-track'); ?>
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

                <h2 style="border-bottom: 2px solid #2271b1; padding-bottom: 8px; margin-top:20px;">4. Chế độ Kiểm thử (Debug Mode)</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('Ghi log kiểm tra', 'woo-track'); ?></th>
                        <td>
                            <label>
                                <input name="woo_track_settings[debug_mode]" type="checkbox" value="yes" <?php checked($debug_mode, 'yes'); ?> />
                                <?php esc_html_e('Bật Debug Console (In toàn bộ sự kiện ra F12 Developer Console)', 'woo-track'); ?>
                            </label>
                            <p class="description"><?php esc_html_e('Mở Console (F12) trên trình duyệt để kiểm tra chi tiết các dữ liệu và sự kiện được bắn.', 'woo-track'); ?></p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(__('Lưu Cấu Hình WooTrack', 'woo-track'), 'primary', 'submit', true, array('style' => 'margin-top:15px; font-size:15px; padding:6px 20px;')); ?>
            </form>
        </div>
        <?php
    }
}
