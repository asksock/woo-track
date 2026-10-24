<?php
/**
 * WooTrack TikTok Events API Handler
 *
 * Xử lý gửi các sự kiện Server-to-Server trực tiếp đến TikTok Business Events API (v1.3 / v2)
 * Đảm bảo khử trùng lặp (Deduplication) với TikTok Pixel phía trình duyệt qua event_id
 */

if (!defined('ABSPATH')) {
    exit;
}

class WooTrack_TikTok_Events {

    /**
     * Endpoint TikTok Business API v1.3 cho Server Events
     */
    const API_ENDPOINT = 'https://business-api.tiktok.com/open_api/v1.3/event/track/';

    /**
     * Hàng đợi các sự kiện TikTok Events API sẽ được gửi ngầm ở cuối vòng đời request (shutdown)
     * giúp quá trình Thanh toán và tải Trang Cảm ơn đạt tốc độ tức thì (0ms delay)
     */
    private static $shutdown_queue = array();

    /**
     * Cờ đánh dấu đã đăng ký hook shutdown hay chưa
     */
    private static $shutdown_registered = false;

    /**
     * Khởi tạo các hook cần thiết cho TikTok Events API
     */
    public static function init() {
        // Tự động lưu ttclid vào cookie ngay khi khách truy cập từ quảng cáo TikTok
        add_action('init', array(__CLASS__, 'capture_ttclid'), 1);

        // Lưu cookie ttp, ttclid, IP, User Agent của khách vào Order Meta khi tiến hành checkout
        add_action('woocommerce_checkout_update_order_meta', array(__CLASS__, 'save_checkout_meta_data'), 10, 2);
        add_action('woocommerce_store_api_checkout_update_order_meta', array(__CLASS__, 'save_checkout_meta_data'), 10, 1);
        add_action('woocommerce_checkout_order_created', array(__CLASS__, 'save_checkout_meta_data'), 10, 1);

        // Hỗ trợ bắn Events API dự phòng khi trạng thái thanh toán hoàn tất (nếu chưa từng được gửi trước đó)
        add_action('woocommerce_payment_complete', array(__CLASS__, 'maybe_send_purchase_on_payment_complete'), 10, 1);
        add_action('woocommerce_order_status_completed', array(__CLASS__, 'maybe_send_purchase_on_status_completed'), 10, 1);
    }

    /**
     * Bắt mã ttclid từ URL và lưu vào cookie _ttclid & ttclid
     */
    public static function capture_ttclid() {
        if (!empty($_GET['ttclid'])) {
            $ttclid = sanitize_text_field(wp_unslash($_GET['ttclid']));
            if (!empty($ttclid) && !headers_sent()) {
                $expire = time() + (90 * DAY_IN_SECONDS);
                $cookie_path = defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/';
                $cookie_domain = defined('COOKIE_DOMAIN') && COOKIE_DOMAIN ? COOKIE_DOMAIN : '';
                setcookie('_ttclid', $ttclid, $expire, $cookie_path, $cookie_domain, is_ssl(), false);
                setcookie('ttclid', $ttclid, $expire, $cookie_path, $cookie_domain, is_ssl(), false);
            }
            $_COOKIE['_ttclid'] = $ttclid;
            $_COOKIE['ttclid']  = $ttclid;
        }
    }

    /**
     * Lưu trữ ttp, ttclid, IP, User Agent vào order meta để phục vụ TikTok Events API
     */
    public static function save_checkout_meta_data($order_or_id, $data = array()) {
        $order = ($order_or_id instanceof WC_Order) ? $order_or_id : wc_get_order($order_or_id);
        if (!$order) {
            return;
        }

        $ttp = WooTrack_Helper::get_ttp();
        if (empty($ttp) && !empty($_POST['woo_track_ttp'])) {
            $ttp = sanitize_text_field(wp_unslash($_POST['woo_track_ttp']));
        }

        $ttclid = WooTrack_Helper::get_ttclid();
        if (empty($ttclid) && !empty($_POST['woo_track_ttclid'])) {
            $ttclid = sanitize_text_field(wp_unslash($_POST['woo_track_ttclid']));
        }

        $ip = WooTrack_Helper::get_client_ip();
        $ua = WooTrack_Helper::get_client_user_agent();

        $updated = false;

        if (!empty($ttp) && empty($order->get_meta('_woo_track_tiktok_ttp'))) {
            $order->update_meta_data('_woo_track_tiktok_ttp', $ttp);
            $updated = true;
        }
        if (!empty($ttclid) && empty($order->get_meta('_woo_track_tiktok_ttclid'))) {
            $order->update_meta_data('_woo_track_tiktok_ttclid', $ttclid);
            $updated = true;
        }
        if (!empty($ip) && empty($order->get_meta('_woo_track_tiktok_ip'))) {
            $order->update_meta_data('_woo_track_tiktok_ip', $ip);
            $updated = true;
        }
        if (!empty($ua) && empty($order->get_meta('_woo_track_tiktok_ua'))) {
            $order->update_meta_data('_woo_track_tiktok_ua', $ua);
            $updated = true;
        }

        if ($updated) {
            $order->save();
        }
    }

    /**
     * Gửi sự kiện Purchase / CompletePayment qua TikTok Events API
     * (Mặc định đẩy vào hàng đợi ngầm shutdown để không làm chậm Checkout/ThankYou)
     *
     * @param WC_Order $order
     * @param string $event_id Event ID đồng bộ với trình duyệt để TikTok tự động khử trùng lặp (Deduplicate)
     * @param bool $defer True = Gửi ngầm sau khi đã trả giao diện về trình duyệt (0ms delay); False = Gửi ngay lập tức
     * @param bool $save_order True = Gọi $order->save() ngay; False = Để hàm gọi bên ngoài gộp chung 1 lần save()
     * @return bool|WP_Error
     */
    public static function send_purchase($order, $event_id = '', $defer = true, $save_order = true) {
        if (
            !WooTrack_Helper::is_tiktok_events_api_enabled() ||
            !WooTrack_Helper::is_tiktok_event_enabled('purchase') ||
            !WooTrack_Helper::is_order_valid_for_purchase_tracking($order)
        ) {
            return false;
        }

        $order_id = $order->get_id();
        $lock_key = 'woo_track_tiktok_lock_' . $order_id;

        // Kiểm tra khóa chống Race Condition
        if (get_transient($lock_key) || isset(self::$shutdown_queue[$order_id])) {
            return false;
        }

        // Kiểm tra xem đơn hàng đã từng được gửi TikTok Events API chưa
        $already_tracked = $order->get_meta('_woo_track_tiktok_events_tracked');
        if (!empty($already_tracked)) {
            return false;
        }

        if (empty($event_id)) {
            $event_id = 'order_' . $order_id;
        }

        // Tên sự kiện mua hàng (Purchase hoặc CompletePayment)
        $event_name = WooTrack_Helper::get_tiktok_purchase_event_name();

        // Đặt khóa tức thì TRƯỚC KHI gọi HTTP sang TikTok để chặn luồng song song thứ 2
        set_transient($lock_key, 'sending', 60);
        $order->update_meta_data('_woo_track_tiktok_events_tracked', current_time('mysql'));
        $order->update_meta_data('_woo_track_tiktok_event_id', $event_id);
        if ($save_order) {
            $order->save();
        }

        $properties = WooTrack_Helper::format_tiktok_order_data($order);
        $user_data  = WooTrack_Helper::format_tiktok_user_data_for_api($order);
        $source_url = $order->get_checkout_order_received_url();

        // Sử dụng thời điểm tạo đơn nếu trong vòng 7 ngày để khớp cửa sổ Deduplication 48h của TikTok
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
                'order_id'   => $order_id,
                'lock_key'   => $lock_key,
                'event_name' => $event_name,
                'event_id'   => $event_id,
                'properties' => $properties,
                'user_data'  => $user_data,
                'source_url' => $source_url,
                'event_time' => $event_time,
            );

            if (!self::$shutdown_registered) {
                add_action('shutdown', array(__CLASS__, 'process_shutdown_queue'), 999);
                self::$shutdown_registered = true;
            }

            return true;
        }

        $response = self::send_event($event_name, $event_id, $properties, $user_data, $source_url, $event_time);

        if (is_wp_error($response)) {
            self::rollback_failed_lock($order_id, $lock_key);
            return $response;
        }

        return true;
    }

    /**
     * Thực thi hàng đợi gửi TikTok Events API ở hậu trường sau khi giao diện đã trả về hoàn tất cho khách hàng
     */
    public static function process_shutdown_queue() {
        if (empty(self::$shutdown_queue)) {
            return;
        }

        // Đóng kết nối HTTP với trình duyệt của khách ngay lập tức (nếu máy chủ hỗ trợ PHP-FPM hoặc LiteSpeed)
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
                $job['properties'],
                $job['user_data'],
                $job['source_url'],
                $job['event_time']
            );

            if (is_wp_error($response)) {
                self::rollback_failed_lock($job['order_id'], $job['lock_key']);
            }
        }
    }

    /**
     * Gỡ khóa nếu kết nối mạng tới TikTok gặp sự cố để cho phép gửi bù ở các hook sau
     */
    private static function rollback_failed_lock($order_id, $lock_key) {
        $order = wc_get_order($order_id);
        if ($order instanceof WC_Order) {
            $order->delete_meta_data('_woo_track_tiktok_events_tracked');
            $order->delete_meta_data('_woo_track_tiktok_event_id');
            $order->save();
        }
        delete_transient($lock_key);
    }

    /**
     * Kiểm tra và gửi TikTok Events API dự phòng khi đơn hàng hoàn tất thanh toán hoặc đổi sang Completed
     */
    private static function maybe_send_delayed_purchase($order_id) {
        $order = wc_get_order($order_id);
        if (!$order instanceof WC_Order) {
            return;
        }

        if (!empty($order->get_meta('_woo_track_tiktok_events_tracked'))) {
            return;
        }

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
     * Dự phòng: Gửi khi thanh toán hoàn tất (payment_complete)
     */
    public static function maybe_send_purchase_on_payment_complete($order_id) {
        self::maybe_send_delayed_purchase($order_id);
    }

    /**
     * Dự phòng: Gửi khi đơn đổi sang Completed (nếu chưa từng bắn)
     */
    public static function maybe_send_purchase_on_status_completed($order_id) {
        self::maybe_send_delayed_purchase($order_id);
    }

    /**
     * Gửi payload sự kiện đến TikTok Business Events API endpoint
     *
     * @param string $event_name Tên sự kiện (Purchase, CompletePayment, AddToCart...)
     * @param string $event_id ID sự kiện để khử trùng lặp với Pixel
     * @param array $properties Dữ liệu e-commerce (value, currency, contents...)
     * @param array $user_data Dữ liệu người dùng (email, phone, ip, user_agent, ttp, ttclid...)
     * @param string $source_url URL nguồn phát sinh sự kiện
     * @param int $event_time Unix timestamp của sự kiện
     * @return array|WP_Error
     */
    public static function send_event($event_name, $event_id, $properties = array(), $user_data = array(), $source_url = '', $event_time = 0) {
        $pixel_id     = trim((string) WooTrack_Helper::get_setting('tiktok_pixel_id', ''));
        $access_token = trim((string) WooTrack_Helper::get_setting('tiktok_access_token', ''));
        $test_code    = trim((string) WooTrack_Helper::get_setting('tiktok_test_event_code', ''));
        $is_debug     = WooTrack_Helper::get_setting('debug_mode', 'no') === 'yes';

        if (empty($pixel_id) || empty($access_token)) {
            return new WP_Error('missing_credentials', __('TikTok Pixel ID hoặc Access Token chưa được cấu hình.', 'woo-track'));
        }

        $event_item = array(
            'event'      => $event_name,
            'event_time' => ($event_time > 0) ? (int) $event_time : time(),
            'event_id'   => (string) $event_id,
            'user'       => $user_data,
            'properties' => $properties,
        );

        if (!empty($source_url)) {
            $event_item['page'] = array(
                'url' => esc_url_raw($source_url),
            );
        }

        $payload = array(
            'event_source'    => 'web',
            'event_source_id' => $pixel_id,
            'data'            => array($event_item),
        );

        // Thêm test_event_code nếu đang trong chế độ kiểm thử của TikTok
        if (!empty($test_code)) {
            $payload['test_event_code'] = $test_code;
        }

        $args = array(
            'body'        => wp_json_encode($payload),
            'headers'     => array(
                'Content-Type' => 'application/json',
                'Access-Token' => $access_token,
                'User-Agent'   => 'WooTrack/' . WOO_TRACK_VERSION . '; ' . home_url(),
            ),
            'timeout'     => 5,
            'blocking'    => true,
            'data_format' => 'body',
        );

        $response = wp_remote_post(self::API_ENDPOINT, $args);

        if (is_wp_error($response)) {
            if ($is_debug) {
                error_log(sprintf('[WooTrack TikTok API Error] Event: %s, ID: %s, Message: %s', $event_name, $event_id, $response->get_error_message()));
            }
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        if ($is_debug) {
            error_log(sprintf('[WooTrack TikTok API Response] Event: %s, ID: %s, HTTP %d: %s', $event_name, $event_id, $response_code, $response_body));
        }

        if ($response_code < 200 || $response_code >= 300) {
            return new WP_Error('tiktok_api_error', sprintf('TikTok API returned HTTP %d: %s', $response_code, $response_body));
        }

        $decoded = json_decode($response_body, true);
        if (isset($decoded['code']) && (int) $decoded['code'] !== 0) {
            if ($is_debug) {
                error_log(sprintf('[WooTrack TikTok API Code Error] Code: %s, Message: %s', $decoded['code'], isset($decoded['message']) ? $decoded['message'] : ''));
            }
            return new WP_Error('tiktok_business_error', isset($decoded['message']) ? $decoded['message'] : 'Unknown TikTok API Error');
        }

        return $decoded;
    }
}
