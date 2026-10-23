<?php
/**
 * WooTrack Meta Conversions API (CAPI) Handler
 *
 * Xử lý gửi các sự kiện Server-to-Server trực tiếp đến Meta Graph API
 * Đảm bảo khử trùng lặp (Deduplication) với Meta Pixel phía trình duyệt qua event_id
 */

if (!defined('ABSPATH')) {
    exit;
}

class WooTrack_Meta_CAPI {

    /**
     * Meta Graph API Version (Hỗ trợ dài hạn theo lộ trình Meta 2025-2027)
     */
    const API_VERSION = 'v22.0';

    /**
     * Hàng đợi các sự kiện CAPI sẽ được gửi ngầm ở cuối vòng đời request (shutdown)
     * giúp quá trình Thanh toán và tải Trang Cảm ơn đạt tốc độ tức thì (0ms delay)
     */
    private static $shutdown_queue = array();

    /**
     * Cờ đánh dấu đã đăng ký hook shutdown hay chưa
     */
    private static $shutdown_registered = false;

    /**
     * Khởi tạo các hook cần thiết cho CAPI
     */
    public static function init() {
        // Tự động lưu fbclid vào cookie _fbc ngay khi khách truy cập từ quảng cáo Facebook (phòng ngừa Ad Blocker chặn script)
        add_action('init', array(__CLASS__, 'capture_fbclid'), 1);

        // Lưu cookie fbp, fbc, IP, User Agent của khách vào Order Meta khi tiến hành checkout (Classic & Blocks)
        add_action('woocommerce_checkout_update_order_meta', array(__CLASS__, 'save_checkout_meta_data'), 10, 2);
        add_action('woocommerce_store_api_checkout_update_order_meta', array(__CLASS__, 'save_checkout_meta_data'), 10, 1);
        add_action('woocommerce_checkout_order_created', array(__CLASS__, 'save_checkout_meta_data'), 10, 1);

        // Hỗ trợ bắn CAPI dự phòng khi trạng thái thanh toán hoàn tất (nếu chưa từng được gửi trước đó)
        add_action('woocommerce_payment_complete', array(__CLASS__, 'maybe_send_purchase_on_payment_complete'), 10, 1);
        add_action('woocommerce_order_status_completed', array(__CLASS__, 'maybe_send_purchase_on_status_completed'), 10, 1);
    }

    /**
     * Bắt mã fbclid từ URL và lưu vào cookie _fbc chuẩn định dạng Meta
     */
    public static function capture_fbclid() {
        if (!empty($_GET['fbclid'])) {
            $fbclid = sanitize_text_field(wp_unslash($_GET['fbclid']));
            $current_fbc = isset($_COOKIE['_fbc']) ? sanitize_text_field(wp_unslash($_COOKIE['_fbc'])) : '';
            $suffix = '.' . $fbclid;

            // Lưu mới hoặc cập nhật nếu khách click vào quảng cáo mới có fbclid khác
            if (empty($current_fbc) || substr($current_fbc, -strlen($suffix)) !== $suffix) {
                $fbc_val = 'fb.1.' . (int) round(microtime(true) * 1000) . '.' . $fbclid;
                if (!headers_sent()) {
                    $expire = time() + (90 * DAY_IN_SECONDS);
                    $cookie_path = defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/';
                    $cookie_domain = defined('COOKIE_DOMAIN') && COOKIE_DOMAIN ? COOKIE_DOMAIN : '';
                    setcookie('_fbc', $fbc_val, $expire, $cookie_path, $cookie_domain, is_ssl(), false);
                }
                $_COOKIE['_fbc'] = $fbc_val;
            }
        }
    }

    /**
     * Lưu trữ fbp, fbc, IP, User Agent vào order meta để phục vụ CAPI
     */
    public static function save_checkout_meta_data($order_or_id, $data = array()) {
        $order = ($order_or_id instanceof WC_Order) ? $order_or_id : wc_get_order($order_or_id);
        if (!$order) {
            return;
        }

        $fbp = WooTrack_Helper::get_fbp();
        if (empty($fbp) && !empty($_POST['woo_track_fbp'])) {
            $fbp = sanitize_text_field(wp_unslash($_POST['woo_track_fbp']));
        }

        $fbc = WooTrack_Helper::get_fbc();
        if (empty($fbc) && !empty($_POST['woo_track_fbc'])) {
            $fbc = sanitize_text_field(wp_unslash($_POST['woo_track_fbc']));
        }

        $ip  = WooTrack_Helper::get_client_ip();
        $ua  = WooTrack_Helper::get_client_user_agent();

        $updated = false;

        if (!empty($fbp) && empty($order->get_meta('_woo_track_meta_fbp'))) {
            $order->update_meta_data('_woo_track_meta_fbp', $fbp);
            $updated = true;
        }
        if (!empty($fbc) && empty($order->get_meta('_woo_track_meta_fbc'))) {
            $order->update_meta_data('_woo_track_meta_fbc', $fbc);
            $updated = true;
        }
        if (!empty($ip) && empty($order->get_meta('_woo_track_meta_ip'))) {
            $order->update_meta_data('_woo_track_meta_ip', $ip);
            $updated = true;
        }
        if (!empty($ua) && empty($order->get_meta('_woo_track_meta_ua'))) {
            $order->update_meta_data('_woo_track_meta_ua', $ua);
            $updated = true;
        }

        if ($updated) {
            $order->save();
        }
    }

    /**
     * Gửi sự kiện Purchase qua Conversions API (Mặc định đẩy vào hàng đợi ngầm shutdown để không làm chậm Checkout/ThankYou)
     *
     * @param WC_Order $order
     * @param string $event_id Event ID đồng bộ với trình duyệt để Meta tự động khử trùng lặp (Deduplicate)
     * @param bool $defer True = Gửi ngầm sau khi đã trả giao diện về trình duyệt (0ms delay); False = Gửi ngay lập tức
     * @param bool $save_order True = Gọi $order->save() ngay; False = Để hàm gọi bên ngoài gộp chung 1 lần save()
     * @return bool|WP_Error
     */
    public static function send_purchase($order, $event_id = '', $defer = true, $save_order = true) {
        if (
            !WooTrack_Helper::is_meta_capi_enabled() ||
            !WooTrack_Helper::is_meta_event_enabled('purchase') ||
            !WooTrack_Helper::is_order_valid_for_purchase_tracking($order)
        ) {
            return false;
        }

        $order_id = $order->get_id();
        $lock_key = 'woo_track_capi_lock_' . $order_id;

        // Kiểm tra khóa chống Race Condition (khi Webhook thanh toán và Trang Cảm ơn chạy cùng một tích tắc)
        if (get_transient($lock_key) || isset(self::$shutdown_queue[$order_id])) {
            return false;
        }

        // Kiểm tra xem đơn hàng đã từng được gửi CAPI chưa (Chống trùng lặp tuyệt đối ở server)
        $already_capi_tracked = $order->get_meta('_woo_track_meta_capi_tracked');
        if (!empty($already_capi_tracked)) {
            return false;
        }

        if (empty($event_id)) {
            $event_id = 'order_' . $order_id;
        }

        // Đặt khóa tức thì TRƯỚC KHI gọi HTTP sang Meta để chặn luồng song song thứ 2
        set_transient($lock_key, 'sending', 60);
        $order->update_meta_data('_woo_track_meta_capi_tracked', current_time('mysql'));
        $order->update_meta_data('_woo_track_meta_capi_event_id', $event_id);
        if ($save_order) {
            $order->save();
        }

        $custom_data = WooTrack_Helper::format_meta_order_data($order);
        $user_data   = WooTrack_Helper::format_meta_user_data_for_capi($order);
        $source_url  = $order->get_checkout_order_received_url();

        // Sử dụng thời điểm tạo đơn nếu trong vòng 7 ngày để khớp cửa sổ Deduplication 48h của Meta
        $event_time   = time();
        $date_created = $order->get_date_created();
        if ($date_created instanceof WC_DateTime) {
            $created_ts = $date_created->getTimestamp();
            if ($created_ts > 0 && $created_ts <= $event_time && ($event_time - $created_ts) <= (7 * DAY_IN_SECONDS)) {
                $event_time = $created_ts;
            }
        }

        // Đẩy vào hàng đợi chạy ngầm sau khi trình duyệt đã nhận xong trang (Không làm chậm trải nghiệm khách hàng)
        if ($defer) {
            self::$shutdown_queue[$order_id] = array(
                'order_id'    => $order_id,
                'lock_key'    => $lock_key,
                'event_name'  => 'Purchase',
                'event_id'    => $event_id,
                'custom_data' => $custom_data,
                'user_data'   => $user_data,
                'source_url'  => $source_url,
                'event_time'  => $event_time,
            );

            if (!self::$shutdown_registered) {
                add_action('shutdown', array(__CLASS__, 'process_shutdown_queue'), 999);
                self::$shutdown_registered = true;
            }

            return true;
        }

        $response = self::send_event('Purchase', $event_id, $custom_data, $user_data, $source_url, $event_time);

        if (is_wp_error($response)) {
            self::rollback_failed_capi_lock($order_id, $lock_key);
            return $response;
        }

        return true;
    }

    /**
     * Thực thi hàng đợi gửi CAPI ở hậu trường sau khi giao diện đã trả về hoàn tất cho khách hàng
     */
    public static function process_shutdown_queue() {
        if (empty(self::$shutdown_queue)) {
            return;
        }

        // Đóng kết nối HTTP với trình duyệt của khách ngay lập tức (nếu máy chủ hỗ trợ PHP-FPM hoặc LiteSpeed)
        // để trình duyệt kết thúc tải trang tức thì (0ms chờ đợi) trước khi server gọi ra quốc tế tới Facebook
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            @litespeed_finish_request();
        }

        $queue = self::$shutdown_queue;
        self::$shutdown_queue = array();

        foreach ($queue as $order_id => $job) {
            $response = self::send_event(
                $job['event_name'],
                $job['event_id'],
                $job['custom_data'],
                $job['user_data'],
                $job['source_url'],
                $job['event_time']
            );

            if (is_wp_error($response)) {
                self::rollback_failed_capi_lock($job['order_id'], $job['lock_key']);
            }
        }
    }

    /**
     * Gỡ khóa CAPI nếu kết nối mạng tới Meta gặp sự cố để cho phép gửi bù ở các hook sau
     */
    private static function rollback_failed_capi_lock($order_id, $lock_key) {
        $order = wc_get_order($order_id);
        if ($order instanceof WC_Order) {
            $order->delete_meta_data('_woo_track_meta_capi_tracked');
            $order->delete_meta_data('_woo_track_meta_capi_event_id');
            $order->save();
        }
        delete_transient($lock_key);
    }

    /**
     * Kiểm tra và gửi CAPI dự phòng khi đơn hàng hoàn tất thanh toán hoặc đổi sang Completed
     * Đảm bảo không bắn trùng nếu đã quá cửa sổ Deduplication 48h của Meta kể từ lúc Pixel trình duyệt đã bắn
     */
    private static function maybe_send_delayed_purchase($order_id) {
        $order = wc_get_order($order_id);
        if (!$order instanceof WC_Order) {
            return;
        }

        if (!empty($order->get_meta('_woo_track_meta_capi_tracked'))) {
            return;
        }

        // Nếu trình duyệt đã từng bắn Pixel (_woo_track_purchase_tracked đã có),
        // chỉ cho phép bắn bù CAPI nếu đơn hàng tạo chưa quá 48 giờ (cửa sổ Deduplication tối đa của Meta)
        $browser_tracked = $order->get_meta('_woo_track_purchase_tracked');
        if (!empty($browser_tracked)) {
            $date_created = $order->get_date_created();
            if ($date_created instanceof WC_DateTime) {
                $age_seconds = time() - $date_created->getTimestamp();
                if ($age_seconds > (48 * HOUR_IN_SECONDS)) {
                    return;
                }
            }
        }

        self::send_purchase($order, 'order_' . $order->get_id());
    }

    /**
     * Dự phòng: Gửi CAPI khi thanh toán hoàn tất (e.g. IPN/Webhook từ cổng thanh toán online)
     */
    public static function maybe_send_purchase_on_payment_complete($order_id) {
        self::maybe_send_delayed_purchase($order_id);
    }

    /**
     * Dự phòng: Gửi CAPI khi đơn đổi sang Completed (nếu chưa từng bắn)
     */
    public static function maybe_send_purchase_on_status_completed($order_id) {
        self::maybe_send_delayed_purchase($order_id);
    }

    /**
     * Gửi payload sự kiện đến Meta Graph API endpoint
     *
     * @param string $event_name Tên sự kiện chuẩn (Purchase, AddToCart, InitiateCheckout...)
     * @param string $event_id ID sự kiện để khử trùng lặp với Pixel
     * @param array $custom_data Dữ liệu e-commerce (value, currency, contents...)
     * @param array $user_data Dữ liệu người dùng đã chuẩn hóa và băm SHA-256
     * @param string $source_url URL nguồn phát sinh sự kiện
     * @param int $event_time Unix timestamp của sự kiện (0 = lấy time() hiện tại)
     * @return array|WP_Error
     */
    public static function send_event($event_name, $event_id, $custom_data = array(), $user_data = array(), $source_url = '', $event_time = 0) {
        $pixel_id    = trim((string) WooTrack_Helper::get_setting('meta_pixel_id', ''));
        $capi_token  = trim((string) WooTrack_Helper::get_setting('meta_capi_token', ''));
        $test_code   = trim((string) WooTrack_Helper::get_setting('meta_test_event_code', ''));
        $is_debug    = WooTrack_Helper::get_setting('debug_mode', 'no') === 'yes';

        if (empty($pixel_id) || empty($capi_token)) {
            return new WP_Error('missing_credentials', __('Meta Pixel ID hoặc CAPI Access Token chưa được cấu hình.', 'woo-track'));
        }

        // Đảm bảo access_token có mặt cả trên URL query param và Authorization Header
        $endpoint = sprintf(
            'https://graph.facebook.com/%s/%s/events?access_token=%s',
            self::API_VERSION,
            rawurlencode($pixel_id),
            rawurlencode($capi_token)
        );

        $event_item = array(
            'event_name'       => $event_name,
            'event_time'       => ($event_time > 0) ? (int) $event_time : time(),
            'event_id'         => $event_id,
            'action_source'    => 'website',
            'user_data'        => $user_data,
            'custom_data'      => $custom_data,
        );

        if (!empty($source_url)) {
            $event_item['event_source_url'] = esc_url_raw($source_url);
        }

        $payload = array(
            'data'         => array($event_item),
            'access_token' => $capi_token,
        );

        // Thêm test_event_code nếu đang trong chế độ kiểm thử
        if (!empty($test_code)) {
            $payload['test_event_code'] = $test_code;
        }

        $args = array(
            'body'        => wp_json_encode($payload),
            'headers'     => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $capi_token,
                'User-Agent'    => 'WooTrack/' . WOO_TRACK_VERSION . '; ' . home_url(),
            ),
            'timeout'     => 5,
            'blocking'    => true, // Chờ phản hồi để ghi nhận kết quả và debug
            'data_format' => 'body',
        );

        $response = wp_remote_post($endpoint, $args);

        if (is_wp_error($response)) {
            if ($is_debug) {
                error_log(sprintf('[WooTrack Meta CAPI Error] Event: %s, ID: %s, Message: %s', $event_name, $event_id, $response->get_error_message()));
            }
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        if ($is_debug) {
            error_log(sprintf('[WooTrack Meta CAPI Response] Event: %s, ID: %s, HTTP %d: %s', $event_name, $event_id, $response_code, $response_body));
        }

        if ($response_code < 200 || $response_code >= 300) {
            return new WP_Error('meta_api_error', sprintf('Meta API returned HTTP %d: %s', $response_code, $response_body));
        }

        return json_decode($response_body, true);
    }
}
