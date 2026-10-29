<?php
/**
 * WooTrack Google Server-Side API Handler (Google Data Manager API v1 & GA4 Measurement Protocol)
 *
 * Xử lý gửi sự kiện chuyển đổi Server-to-Server trực tiếp tới Google Data Manager API (v1/events:ingest)
 * cho cả Google Analytics 4 (GOOGLE_ANALYTICS_PROPERTY) và Google Ads (GOOGLE_ADS),
 * kèm cơ chế dự phòng GA4 Measurement Protocol API và khử trùng lặp tự động qua transaction_id.
 */

if (!defined('ABSPATH')) {
    exit;
}

class WooTrack_Google_API {

    /**
     * Endpoint chính thức của Google Data Manager API v1
     */
    const DATA_MANAGER_ENDPOINT = 'https://datamanager.googleapis.com/v1/events:ingest';

    /**
     * Endpoint cấp OAuth 2.0 Token từ Service Account JWT
     */
    const OAUTH_TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';

    /**
     * OAuth Scope bắt buộc của Google Data Manager API
     */
    const OAUTH_SCOPE = 'https://www.googleapis.com/auth/datamanager';

    /**
     * Endpoint GA4 Measurement Protocol (Chế độ dự phòng / cấu hình nhanh bằng API Secret)
     */
    const MP_ENDPOINT = 'https://www.google-analytics.com/mp/collect';

    /**
     * Hàng đợi gửi ngầm ở cuối vòng đời request (shutdown) đảm bảo 0ms độ trễ cho khách hàng
     */
    private static $shutdown_queue = array();

    /**
     * Cờ đánh dấu đã đăng ký hook shutdown hay chưa
     */
    private static $shutdown_registered = false;

    /**
     * Khởi tạo các hook cần thiết cho Google Server-Side API
     */
    public static function init() {
        // Tự động bắt gclid, gbraid, wbraid từ URL lưu vào cookie 90 ngày
        add_action('init', array(__CLASS__, 'capture_google_click_ids'), 1);

        // Lưu _ga Client ID, gclid, gbraid, wbraid vào Order Meta khi khách đặt hàng
        add_action('woocommerce_checkout_update_order_meta', array(__CLASS__, 'save_checkout_meta_data'), 10, 2);
        add_action('woocommerce_store_api_checkout_update_order_meta', array(__CLASS__, 'save_checkout_meta_data'), 10, 1);
        add_action('woocommerce_checkout_order_created', array(__CLASS__, 'save_checkout_meta_data'), 10, 1);

        // Bắn bù Server API khi trạng thái thanh toán hoàn tất (Webhook ngân hàng / Admin duyệt đơn)
        add_action('woocommerce_payment_complete', array(__CLASS__, 'maybe_send_purchase_on_payment_complete'), 10, 1);
        add_action('woocommerce_order_status_completed', array(__CLASS__, 'maybe_send_purchase_on_status_completed'), 10, 1);

        // AJAX kiểm tra kết nối trực tiếp trong trang Admin mà không cần tạo đơn hàng ảo
        add_action('wp_ajax_woo_track_test_google_api', array(__CLASS__, 'ajax_test_google_api'));
    }

    /**
     * Bắt gclid, gbraid, wbraid từ URL khi khách nhấn vào quảng cáo Google Ads
     */
    public static function capture_google_click_ids() {
        $click_params = array(
            'gclid'  => '_woo_track_gclid',
            'gbraid' => '_woo_track_gbraid',
            'wbraid' => '_woo_track_wbraid',
        );

        $expire        = time() + (90 * DAY_IN_SECONDS);
        $cookie_path   = defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/';
        $cookie_domain = defined('COOKIE_DOMAIN') && COOKIE_DOMAIN ? COOKIE_DOMAIN : '';

        foreach ($click_params as $param => $cookie_name) {
            if (!empty($_GET[$param])) {
                $val = sanitize_text_field(wp_unslash($_GET[$param]));
                if (!empty($val)) {
                    if (!headers_sent()) {
                        setcookie($cookie_name, $val, $expire, $cookie_path, $cookie_domain, is_ssl(), false);
                    }
                    $_COOKIE[$cookie_name] = $val;
                }
            }
        }
    }

    /**
     * Lưu trữ GA Client ID (_ga) và Google Ads Click IDs (gclid, gbraid, wbraid) vào Order Meta
     */
    public static function save_checkout_meta_data($order_or_id, $data = array()) {
        $order = ($order_or_id instanceof WC_Order) ? $order_or_id : wc_get_order($order_or_id);
        if (!$order) {
            return;
        }

        $ga_cid = WooTrack_Helper::get_ga_client_id();
        if (empty($ga_cid) && !empty($_POST['woo_track_ga_cid'])) {
            $ga_cid = sanitize_text_field(wp_unslash($_POST['woo_track_ga_cid']));
        }

        $gclid = WooTrack_Helper::get_gclid();
        if (empty($gclid) && !empty($_POST['woo_track_gclid'])) {
            $gclid = sanitize_text_field(wp_unslash($_POST['woo_track_gclid']));
        }

        $gbraid = WooTrack_Helper::get_gbraid();
        if (empty($gbraid) && !empty($_POST['woo_track_gbraid'])) {
            $gbraid = sanitize_text_field(wp_unslash($_POST['woo_track_gbraid']));
        }

        $wbraid = WooTrack_Helper::get_wbraid();
        if (empty($wbraid) && !empty($_POST['woo_track_wbraid'])) {
            $wbraid = sanitize_text_field(wp_unslash($_POST['woo_track_wbraid']));
        }

        $updated = false;

        if (!empty($ga_cid) && empty($order->get_meta('_woo_track_google_cid'))) {
            $order->update_meta_data('_woo_track_google_cid', $ga_cid);
            $updated = true;
        }
        if (!empty($gclid) && empty($order->get_meta('_woo_track_google_gclid'))) {
            $order->update_meta_data('_woo_track_google_gclid', $gclid);
            $updated = true;
        }
        if (!empty($gbraid) && empty($order->get_meta('_woo_track_google_gbraid'))) {
            $order->update_meta_data('_woo_track_google_gbraid', $gbraid);
            $updated = true;
        }
        if (!empty($wbraid) && empty($order->get_meta('_woo_track_google_wbraid'))) {
            $order->update_meta_data('_woo_track_google_wbraid', $wbraid);
            $updated = true;
        }

        if ($updated) {
            $order->save();
        }
    }

    /**
     * Hook dự phòng khi đơn hàng chuyển sang trạng thái đã thanh toán
     */
    public static function maybe_send_purchase_on_payment_complete($order_id) {
        self::maybe_send_delayed_purchase($order_id);
    }

    /**
     * Hook dự phòng khi đơn hàng chuyển sang trạng thái hoàn thành
     */
    public static function maybe_send_purchase_on_status_completed($order_id) {
        self::maybe_send_delayed_purchase($order_id);
    }

    /**
     * Kiểm tra và gửi bù sự kiện Purchase qua Google Server API (trong cửa sổ 72 giờ của GA4 Data Manager API)
     */
    private static function maybe_send_delayed_purchase($order_id) {
        if (!WooTrack_Helper::is_google_server_api_enabled() || !WooTrack_Helper::is_event_enabled('purchase')) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order instanceof WC_Order) {
            return;
        }

        if ($order->get_meta('_woo_track_google_api_tracked')) {
            return;
        }

        // Nếu đơn đã được bắn qua trình duyệt nhưng quá 72 giờ (giới hạn eventTimestamp của GA4 trong Data Manager API), không bắn bù
        $browser_tracked = $order->get_meta('_woo_track_purchase_tracked');
        $date_created    = $order->get_date_created();
        if (!empty($browser_tracked) && $date_created && (time() - $date_created->getTimestamp()) > (72 * HOUR_IN_SECONDS)) {
            return;
        }

        self::send_purchase($order, true, true);
    }

    /**
     * Chuẩn bị và đưa sự kiện Purchase của Google vào hàng đợi ngầm (shutdown)
     *
     * @param WC_Order|int $order_or_id Đối tượng đơn hàng hoặc ID đơn hàng
     * @param bool         $queue_on_shutdown Đẩy vào hàng đợi ngầm cuối request để đạt 0ms độ trễ
     * @param bool         $save_order_now Có gọi $order->save() ngay hay để hàm điều phối gộp chung
     * @return bool
     */
    public static function send_purchase($order_or_id, $queue_on_shutdown = true, $save_order_now = true) {
        if (!WooTrack_Helper::is_google_server_api_enabled() || !WooTrack_Helper::is_event_enabled('purchase')) {
            return false;
        }

        $order = ($order_or_id instanceof WC_Order) ? $order_or_id : wc_get_order($order_or_id);
        if (!$order instanceof WC_Order) {
            return false;
        }

        if (!WooTrack_Helper::is_order_valid_for_purchase_tracking($order)) {
            return false;
        }

        $order_id = $order->get_id();

        // Chống gửi trùng lặp
        if ($order->get_meta('_woo_track_google_api_tracked')) {
            return false;
        }

        // Khóa nguyên tử Transient chống xung đột luồng song song (Webhook vs Thank You page)
        $lock_key = 'woo_track_google_lock_' . $order_id;
        if (get_transient($lock_key)) {
            return false;
        }
        set_transient($lock_key, 'sending', 60);

        $order->update_meta_data('_woo_track_google_api_tracked', current_time('mysql'));
        if ($save_order_now) {
            $order->save();
        }

        // Trích xuất toàn bộ dữ liệu đơn hàng & ngữ cảnh khách hàng
        $job_data = self::prepare_order_job_data($order);

        if ($queue_on_shutdown) {
            self::$shutdown_queue[$order_id] = $job_data;
            if (!self::$shutdown_registered) {
                add_action('shutdown', array(__CLASS__, 'process_shutdown_queue'), 999);
                self::$shutdown_registered = true;
            }
            return true;
        }

        return self::execute_job($job_data);
    }

    /**
     * Thực thi hàng đợi Google Server API ngầm sau khi đã trả giao diện về trình duyệt khách hàng
     */
    public static function process_shutdown_queue() {
        if (empty(self::$shutdown_queue)) {
            return;
        }

        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            @litespeed_finish_request();
        }

        $jobs = self::$shutdown_queue;
        self::$shutdown_queue = array();

        foreach ($jobs as $job) {
            self::execute_job($job);
        }
    }

    /**
     * Chuẩn bị gói dữ liệu chuẩn hóa từ đơn hàng để phục vụ gửi ngầm
     */
    private static function prepare_order_job_data($order) {
        $order_id   = $order->get_id();
        $order_data = WooTrack_Helper::format_order_data($order);

        // Thời gian sự kiện (trong vòng 72 giờ theo chuẩn GA4 Data Manager API)
        $now          = time();
        $date_created = $order->get_date_created();
        $order_ts     = $date_created ? (int) $date_created->getTimestamp() : $now;
        $event_time   = ($order_ts > 0 && $order_ts <= $now && ($now - $order_ts) < (70 * HOUR_IN_SECONDS)) ? $order_ts : $now;

        $is_customer_request = !is_admin() && !wp_doing_cron() && (!defined('REST_REQUEST') || !REST_REQUEST);

        // Client ID (_ga)
        $client_id = (string) $order->get_meta('_woo_track_google_cid');
        if (empty($client_id) && $is_customer_request) {
            $client_id = WooTrack_Helper::get_ga_client_id();
        }
        if (empty($client_id)) {
            // Tạo Client ID định danh ổn định theo đơn hàng nếu trình duyệt khách bị AdBlock chặn hoàn toàn _ga
            $client_id = $order_id . '.' . $event_time;
        }

        // Click IDs (gclid, gbraid, wbraid)
        $gclid  = (string) $order->get_meta('_woo_track_google_gclid');
        $gbraid = (string) $order->get_meta('_woo_track_google_gbraid');
        $wbraid = (string) $order->get_meta('_woo_track_google_wbraid');

        if ($is_customer_request) {
            if (empty($gclid))  $gclid  = WooTrack_Helper::get_gclid();
            if (empty($gbraid)) $gbraid = WooTrack_Helper::get_gbraid();
            if (empty($wbraid)) $wbraid = WooTrack_Helper::get_wbraid();
        }

        // IP của khách hàng
        $saved_ip = (string) $order->get_meta('_woo_track_meta_ip');
        if (empty($saved_ip)) {
            $saved_ip = (string) $order->get_customer_ip_address();
        }
        $ip = !empty($saved_ip) ? $saved_ip : ($is_customer_request ? WooTrack_Helper::get_client_ip() : '');

        // User-Agent của khách hàng
        $saved_ua = (string) $order->get_meta('_woo_track_meta_ua');
        if (empty($saved_ua)) {
            $saved_ua = (string) $order->get_customer_user_agent();
        }
        $ua = !empty($saved_ua) ? $saved_ua : ($is_customer_request ? WooTrack_Helper::get_client_user_agent() : '');

        return array(
            'order_id'         => $order_id,
            'lock_key'         => 'woo_track_google_lock_' . $order_id,
            'event_time'       => $event_time,
            'client_id'        => $client_id,
            'customer_id'      => (int) $order->get_customer_id(),
            'gclid'            => $gclid,
            'gbraid'           => $gbraid,
            'wbraid'           => $wbraid,
            'ip'               => $ip,
            'ua'               => $ua,
            'order_data'       => $order_data,
            'user_identifiers' => WooTrack_Helper::format_google_data_manager_user_identifiers($order),
        );
    }

    /**
     * Điều phối gửi sự kiện qua Google Data Manager API v1 (ưu tiên) và/hoặc GA4 Measurement Protocol
     */
    private static function execute_job($job) {
        $sa_json      = trim((string) WooTrack_Helper::get_setting('google_service_account_json', ''));
        $ga4_id       = trim((string) WooTrack_Helper::get_setting('ga4_id', ''));
        $ga4_prop_id  = preg_replace('/[^0-9]/', '', (string) WooTrack_Helper::get_setting('ga4_property_id', ''));
        $gads_cust_id = preg_replace('/[^0-9]/', '', (string) WooTrack_Helper::get_setting('gads_customer_id', ''));
        $gads_conv_id = preg_replace('/[^0-9]/', '', (string) WooTrack_Helper::get_setting('gads_conversion_action_id', ''));
        $mp_secret    = trim((string) WooTrack_Helper::get_setting('ga4_api_secret', ''));

        $success = false;

        // 1. Gửi qua Google Data Manager API v1 (nếu đã cấu hình Service Account JSON)
        if (!empty($sa_json)) {
            $access_token = self::get_access_token_from_service_account($sa_json);
            if (!is_wp_error($access_token) && !empty($access_token)) {
                // 1a. Đích đến: Google Analytics 4 (GOOGLE_ANALYTICS_PROPERTY)
                if (!empty($ga4_id) && !empty($ga4_prop_id)) {
                    $ga4_dest = array(
                        'operatingAccount' => array(
                            'accountType' => 'GOOGLE_ANALYTICS_PROPERTY',
                            'accountId'   => $ga4_prop_id,
                        ),
                        'loginAccount' => array(
                            'accountType' => 'GOOGLE_ANALYTICS_PROPERTY',
                            'accountId'   => $ga4_prop_id,
                        ),
                        'productDestinationId' => $ga4_id,
                    );
                    $res_ga4 = self::dispatch_data_manager_request($access_token, $ga4_dest, $job, 'GA4');
                    if (!is_wp_error($res_ga4)) {
                        $success = true;
                    }
                }

                // 1b. Đích đến: Google Ads (GOOGLE_ADS)
                if (!empty($gads_cust_id) && !empty($gads_conv_id)) {
                    $gads_dest = array(
                        'operatingAccount' => array(
                            'accountType' => 'GOOGLE_ADS',
                            'accountId'   => $gads_cust_id,
                        ),
                        'loginAccount' => array(
                            'accountType' => 'GOOGLE_ADS',
                            'accountId'   => $gads_cust_id,
                        ),
                        'productDestinationId' => $gads_conv_id,
                    );
                    $res_gads = self::dispatch_data_manager_request($access_token, $gads_dest, $job, 'GOOGLE_ADS');
                    if (!is_wp_error($res_gads)) {
                        $success = true;
                    }
                }
            }
        }

        // 2. Dự phòng: Nếu chưa cấu hình Data Manager API cho GA4 nhưng có điền GA4 API Secret (Measurement Protocol)
        $ga4_dm_configured = (!empty($sa_json) && !empty($ga4_id) && !empty($ga4_prop_id));
        if (!$ga4_dm_configured && !empty($ga4_id) && !empty($mp_secret)) {
            $res_mp = self::dispatch_measurement_protocol_request($ga4_id, $mp_secret, $job);
            if (!is_wp_error($res_mp)) {
                $success = true;
            }
        }

        // Nếu tất cả cuộc gọi đều thất bại do lỗi mạng, gỡ khóa để cho phép gửi bù sau
        if (!$success) {
            $failed_order = wc_get_order($job['order_id']);
            if ($failed_order instanceof WC_Order) {
                $failed_order->delete_meta_data('_woo_track_google_api_tracked');
                $failed_order->save();
            }
            delete_transient($job['lock_key']);
            return false;
        }

        delete_transient($job['lock_key']);
        return true;
    }

    /**
     * Xây dựng và gửi HTTP POST tới Google Data Manager API (v1/events:ingest)
     */
    private static function dispatch_data_manager_request($access_token, $destination, $job, $target_label = 'GA4', $force_validate_only = false) {
        $is_debug      = WooTrack_Helper::get_setting('debug_mode', 'no') === 'yes';
        $validate_only = (bool) $force_validate_only;
        $order_data    = $job['order_data'];

        // Xây dựng danh sách sản phẩm trong CartData chuẩn google.ads.datamanager.v1.Item
        $cart_items = array();
        if (!empty($order_data['items']) && is_array($order_data['items'])) {
            foreach ($order_data['items'] as $item) {
                $prod_id = (string) $item['item_id'];
                $item_additional_params = array();
                if (!empty($item['item_name'])) {
                    $item_additional_params[] = array(
                        'parameterName' => 'item_name',
                        'value'         => (string) $item['item_name'],
                    );
                }
                if (!empty($item['item_category'])) {
                    $item_additional_params[] = array(
                        'parameterName' => 'item_category',
                        'value'         => (string) $item['item_category'],
                    );
                }
                if (!empty($item['item_variant'])) {
                    $item_additional_params[] = array(
                        'parameterName' => 'item_variant',
                        'value'         => (string) $item['item_variant'],
                    );
                }

                $cart_item = array(
                    'merchantProductId' => $prod_id,
                    'itemId'            => $prod_id,
                    'quantity'          => (int) $item['quantity'],
                    'unitPrice'         => (float) $item['price'],
                );
                if (!empty($item_additional_params)) {
                    $cart_item['additionalItemParameters'] = $item_additional_params;
                }
                $cart_items[] = $cart_item;
            }
        }

        // Xây dựng đối tượng Event chuẩn google.ads.datamanager.v1.Event
        $event = array(
            'eventName'      => 'purchase',
            'eventTimestamp' => gmdate('Y-m-d\TH:i:s\Z', $job['event_time']),
            'eventSource'    => 'WEB',
            'clientId'       => (string) $job['client_id'],
            'transactionId'  => (string) $order_data['transaction_id'],
            'currency'       => (string) $order_data['currency'],
            'consent'        => array(
                'adUserData'        => 'CONSENT_GRANTED',
                'adPersonalization' => 'CONSENT_GRANTED',
            ),
        );

        if ((float) $order_data['value'] > 0) {
            $event['conversionValue'] = (float) $order_data['value'];
        }

        if ($job['customer_id'] > 0) {
            $event['userId'] = (string) $job['customer_id'];
        }

        // Đính kèm AdIdentifiers (gclid, gbraid, wbraid) nếu có
        $ad_identifiers = array();
        if (!empty($job['gclid']))  $ad_identifiers['gclid']  = $job['gclid'];
        if (!empty($job['gbraid'])) $ad_identifiers['gbraid'] = $job['gbraid'];
        if (!empty($job['wbraid'])) $ad_identifiers['wbraid'] = $job['wbraid'];
        if (!empty($ad_identifiers)) {
            $event['adIdentifiers'] = $ad_identifiers;
        }

        // Đính kèm UserData (Email, SĐT E.164, Địa chỉ đã băm SHA-256 HEX)
        if (!empty($job['user_identifiers'])) {
            $event['userData'] = array(
                'userIdentifiers' => $job['user_identifiers'],
            );
        }

        // Đính kèm CartData
        if (!empty($cart_items)) {
            $cart_data = array(
                'items' => $cart_items,
            );
            if (!empty($order_data['discount']) && (float) $order_data['discount'] > 0) {
                $cart_data['transactionDiscount'] = (float) $order_data['discount'];
            }
            $event['cartData'] = $cart_data;
        }

        // Đính kèm các tham số bổ sung cho GA4 (tax, shipping, coupon, tracking_source)
        $additional_params = array(
            array(
                'parameterName' => 'tracking_source',
                'value'         => 'server_data_manager_api',
            ),
        );
        if (isset($order_data['tax']) && (float) $order_data['tax'] > 0) {
            $additional_params[] = array(
                'parameterName' => 'tax',
                'value'         => (string) $order_data['tax'],
            );
        }
        if (isset($order_data['shipping']) && (float) $order_data['shipping'] > 0) {
            $additional_params[] = array(
                'parameterName' => 'shipping',
                'value'         => (string) $order_data['shipping'],
            );
        }
        if (!empty($order_data['coupon'])) {
            $additional_params[] = array(
                'parameterName' => 'coupon',
                'value'         => (string) $order_data['coupon'],
            );
        }
        $event['additionalEventParameters'] = $additional_params;

        $payload = array(
            'destinations' => array($destination),
            'events'       => array($event),
            'consent'      => array(
                'adUserData'        => 'CONSENT_GRANTED',
                'adPersonalization' => 'CONSENT_GRANTED',
            ),
            'encoding'     => 'HEX',
            'validateOnly' => $validate_only,
        );

        $args = array(
            'body'        => wp_json_encode($payload),
            'headers'     => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type'  => 'application/json',
                'User-Agent'    => 'WooTrack/' . WOO_TRACK_VERSION . '; ' . home_url(),
            ),
            'timeout'     => 6,
            'blocking'    => true,
            'data_format' => 'body',
        );

        $response = wp_remote_post(self::DATA_MANAGER_ENDPOINT, $args);

        if (is_wp_error($response)) {
            if ($is_debug) {
                error_log(sprintf('[WooTrack Google Data Manager Error - %s] Order #%d: %s', $target_label, $job['order_id'], $response->get_error_message()));
            }
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($is_debug) {
            error_log(sprintf('[WooTrack Google Data Manager Response - %s] Order #%d, HTTP %d: %s', $target_label, $job['order_id'], $code, $body));
        }

        if ($code < 200 || $code >= 300) {
            return new WP_Error('google_dm_http_error', sprintf('HTTP %d: %s', $code, $body));
        }

        return json_decode($body, true);
    }

    /**
     * Gửi sự kiện Purchase qua GA4 Measurement Protocol API (Dự phòng / Cấu hình nhanh bằng API Secret)
     */
    private static function dispatch_measurement_protocol_request($ga4_id, $api_secret, $job) {
        $is_debug   = WooTrack_Helper::get_setting('debug_mode', 'no') === 'yes';
        $order_data = $job['order_data'];

        $mp_items = array();
        if (!empty($order_data['items']) && is_array($order_data['items'])) {
            foreach ($order_data['items'] as $item) {
                $mp_item = array(
                    'item_id'   => (string) $item['item_id'],
                    'item_name' => (string) $item['item_name'],
                    'price'     => (float) $item['price'],
                    'quantity'  => (int) $item['quantity'],
                );
                if (!empty($item['item_category'])) {
                    $mp_item['item_category'] = (string) $item['item_category'];
                }
                if (!empty($item['item_variant'])) {
                    $mp_item['item_variant'] = (string) $item['item_variant'];
                }
                $mp_items[] = $mp_item;
            }
        }

        $params = array(
            'transaction_id'       => (string) $order_data['transaction_id'],
            'value'                => (float) $order_data['value'],
            'currency'             => (string) $order_data['currency'],
            'tax'                  => (float) $order_data['tax'],
            'shipping'             => (float) $order_data['shipping'],
            'items'                => $mp_items,
            'tracking_source'      => 'server_measurement_protocol',
            'engagement_time_msec' => 100,
        );
        if (!empty($order_data['coupon'])) {
            $params['coupon'] = (string) $order_data['coupon'];
        }

        $payload = array(
            'client_id'        => (string) $job['client_id'],
            'timestamp_micros' => (int) ($job['event_time'] * 1000000),
            'events'           => array(
                array(
                    'name'   => 'purchase',
                    'params' => $params,
                ),
            ),
        );

        if ($job['customer_id'] > 0) {
            $payload['user_id'] = (string) $job['customer_id'];
        }

        if (!empty($job['ip'])) {
            $payload['ip_override'] = $job['ip'];
        }
        if (!empty($job['ua'])) {
            $payload['user_agent'] = $job['ua'];
        }

        $url = add_query_arg(
            array(
                'measurement_id' => rawurlencode($ga4_id),
                'api_secret'     => rawurlencode($api_secret),
            ),
            self::MP_ENDPOINT
        );

        $args = array(
            'body'        => wp_json_encode($payload),
            'headers'     => array(
                'Content-Type' => 'application/json',
                'User-Agent'   => 'WooTrack/' . WOO_TRACK_VERSION . '; ' . home_url(),
            ),
            'timeout'     => 5,
            'blocking'    => true,
            'data_format' => 'body',
        );

        $response = wp_remote_post($url, $args);

        if (is_wp_error($response)) {
            if ($is_debug) {
                error_log(sprintf('[WooTrack GA4 Measurement Protocol Error] Order #%d: %s', $job['order_id'], $response->get_error_message()));
            }
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($is_debug) {
            error_log(sprintf('[WooTrack GA4 Measurement Protocol Response] Order #%d, HTTP %d', $job['order_id'], $code));
        }

        if ($code < 200 || $code >= 300) {
            return new WP_Error('ga4_mp_http_error', sprintf('HTTP %d', $code));
        }

        return true;
    }

    /**
     * Sinh OAuth 2.0 Bearer Access Token từ khóa JSON của Google Service Account (Sử dụng openssl_sign thuần PHP 8.x, cache 55 phút)
     */
    private static function get_access_token_from_service_account($sa_json) {
        $is_debug = WooTrack_Helper::get_setting('debug_mode', 'no') === 'yes';

        if (!function_exists('openssl_sign')) {
            if ($is_debug) {
                error_log('[WooTrack Google Auth Error] PHP OpenSSL extension is required for Service Account JWT signing.');
            }
            return new WP_Error('missing_openssl', 'PHP OpenSSL extension is not enabled.');
        }

        $creds = json_decode($sa_json, true);
        if (!is_array($creds) || empty($creds['client_email']) || empty($creds['private_key'])) {
            if ($is_debug) {
                error_log('[WooTrack Google Auth Error] Invalid Service Account JSON.');
            }
            return new WP_Error('invalid_sa_json', 'Invalid Service Account JSON.');
        }

        $client_email = trim((string) $creds['client_email']);
        $private_key  = str_replace('\n', "\n", (string) $creds['private_key']);
        $token_uri    = !empty($creds['token_uri']) ? (string) $creds['token_uri'] : self::OAUTH_TOKEN_ENDPOINT;

        // Kiểm tra Token đã được lưu trong bộ nhớ đệm Transient chưa
        $cache_key    = 'woo_track_gdm_tok_' . substr(md5($client_email . $private_key), 0, 16);
        $cached_token = get_transient($cache_key);
        if (!empty($cached_token) && is_string($cached_token)) {
            return $cached_token;
        }

        $now    = time();
        $header = self::base64url_encode(wp_json_encode(array(
            'alg' => 'RS256',
            'typ' => 'JWT',
        )));

        $claims = self::base64url_encode(wp_json_encode(array(
            'iss'   => $client_email,
            'scope' => self::OAUTH_SCOPE,
            'aud'   => $token_uri,
            'iat'   => $now,
            'exp'   => $now + 3600,
        )));

        $signing_input = $header . '.' . $claims;
        $signature     = '';

        $signed = @openssl_sign($signing_input, $signature, $private_key, OPENSSL_ALGO_SHA256);
        if (!$signed) {
            if ($is_debug) {
                error_log('[WooTrack Google Auth Error] Failed to sign JWT with Service Account private_key.');
            }
            return new WP_Error('jwt_sign_failed', 'Failed to sign Service Account JWT.');
        }

        $jwt = $signing_input . '.' . self::base64url_encode($signature);

        $response = wp_remote_post($token_uri, array(
            'body'    => array(
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ),
            'timeout' => 5,
        ));

        if (is_wp_error($response)) {
            if ($is_debug) {
                error_log('[WooTrack Google Auth Error] Token request failed: ' . $response->get_error_message());
            }
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code < 200 || $code >= 300 || empty($body['access_token'])) {
            if ($is_debug) {
                error_log(sprintf('[WooTrack Google Auth Error] HTTP %d: %s', $code, wp_remote_retrieve_body($response)));
            }
            return new WP_Error('oauth_token_error', 'Could not obtain OAuth2 access token from Google.');
        }

        $access_token = (string) $body['access_token'];
        $expires_in   = isset($body['expires_in']) ? max(60, (int) $body['expires_in'] - 300) : 3300;
        set_transient($cache_key, $access_token, $expires_in);

        return $access_token;
    }

    /**
     * Mã hóa Base64 URL-safe theo chuẩn RFC 7515 cho JWT
     */
    private static function base64url_encode($data) {
        return rtrim(strtr(base64_encode((string) $data), '+/', '-_'), '=');
    }

    /**
     * AJAX Handler: Kiểm tra trực tiếp kết nối Google Data Manager API v1 (validateOnly = true)
     * hoặc GA4 Measurement Protocol Debug Endpoint ngay trong trang Cài đặt mà không cần tạo đơn hàng ảo
     */
    public static function ajax_test_google_api() {
        check_ajax_referer('woo_track_test_google_api', 'nonce');

        if (!current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
            wp_send_json_error(array(
                'message' => __('Bạn không có quyền thực hiện thao tác này.', 'woo-track'),
            ));
        }

        $sa_json            = isset($_POST['sa_json']) ? trim(wp_unslash((string) $_POST['sa_json'])) : trim((string) WooTrack_Helper::get_setting('google_service_account_json', ''));
        $ga4_id             = isset($_POST['ga4_id']) ? strtoupper( sanitize_text_field(wp_unslash($_POST['ga4_id'])) ) : trim((string) WooTrack_Helper::get_setting('ga4_id', ''));
        $ga4_property_id    = isset($_POST['ga4_property_id']) ? preg_replace('/\D/', '', sanitize_text_field(wp_unslash($_POST['ga4_property_id']))) : trim((string) WooTrack_Helper::get_setting('ga4_property_id', ''));
        $gads_customer_id   = isset($_POST['gads_customer_id']) ? preg_replace('/\D/', '', sanitize_text_field(wp_unslash($_POST['gads_customer_id']))) : trim((string) WooTrack_Helper::get_setting('gads_customer_id', ''));
        $gads_conversion_id = isset($_POST['gads_conversion_action_id']) ? preg_replace('/\D/', '', sanitize_text_field(wp_unslash($_POST['gads_conversion_action_id']))) : trim((string) WooTrack_Helper::get_setting('gads_conversion_action_id', ''));
        $ga4_api_secret     = isset($_POST['ga4_api_secret']) ? sanitize_text_field(wp_unslash($_POST['ga4_api_secret'])) : trim((string) WooTrack_Helper::get_setting('ga4_api_secret', ''));

        $steps = array();
        $has_error = false;

        // XÂY DỰNG GÓI TIN ĐƠN HÀNG MẪU ĐỂ TEST VALIDATE (KHÔNG GHI VÀO BÁO CÁO THẬT)
        $currency = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'VND';
        $dummy_job = array(
            'order_id'    => 999999,
            'event_time'  => time(),
            'client_id'   => '1234567890.' . time(),
            'customer_id' => 1,
            'gclid'       => 'EAIaIQobChMI_WooTrack_ValidateOnly_Test',
            'gbraid'      => '',
            'wbraid'      => '',
            'order_data'  => array(
                'transaction_id' => 'WOOTRACK_TEST_' . time(),
                'value'          => 150000.0,
                'currency'       => $currency,
                'tax'            => 0.0,
                'shipping'       => 0.0,
                'discount'       => 0.0,
                'coupon'         => '',
                'items'          => array(
                    array(
                        'item_id'       => 'TEST_SKU_01',
                        'item_name'     => 'WooTrack Diagnostics Test Item',
                        'item_category' => 'Software',
                        'price'         => 150000.0,
                        'quantity'      => 1,
                    ),
                ),
            ),
            'user_identifiers' => array(
                array(
                    'emailAddress' => hash('sha256', 'wootrack.diagnostics@gmail.com'),
                ),
                array(
                    'phoneNumber'  => hash('sha256', '+84901234567'),
                ),
            ),
        );

        // TRƯỜNG HỢP 1: CÓ SERVICE ACCOUNT JSON -> KIỂM TRA DATA MANAGER API v1
        if (!empty($sa_json)) {
            $creds = json_decode($sa_json, true);
            if (!is_array($creds) || empty($creds['client_email']) || empty($creds['private_key'])) {
                $steps[] = array(
                    'status'  => 'error',
                    'title'   => '1. Kiểm tra cấu trúc Service Account JSON',
                    'message' => 'Chuỗi JSON không hợp lệ hoặc thiếu trường client_email / private_key. Hãy mở file .json tải từ Google Cloud và copy toàn bộ nội dung.',
                );
                wp_send_json_success(array('steps' => $steps, 'overall' => 'error'));
            }

            $sa_email = trim((string) $creds['client_email']);
            $access_token = self::get_access_token_from_service_account($sa_json);

            if (is_wp_error($access_token) || empty($access_token)) {
                $err_msg = is_wp_error($access_token) ? $access_token->get_error_message() : 'Không lấy được token.';
                $steps[] = array(
                    'status'  => 'error',
                    'title'   => '1. Xác thực OAuth2 với Google Cloud (' . esc_html($sa_email) . ')',
                    'message' => 'Lỗi ký JWT hoặc lấy Access Token: ' . esc_html($err_msg),
                );
                wp_send_json_success(array('steps' => $steps, 'overall' => 'error'));
            }

            $steps[] = array(
                'status'  => 'success',
                'title'   => '1. Xác thực OAuth2 Google Cloud Service Account',
                'message' => sprintf('Thành công! Đã ký RS256 JWT và nhận Bearer Access Token hợp lệ từ Google cho tài khoản: %s', $sa_email),
            );

            $tested_any_destination = false;

            // 2A. Kiểm tra đích đến GA4 (nếu có điền GA4 Property ID)
            if (!empty($ga4_property_id)) {
                if (empty($ga4_id)) {
                    $has_error = true;
                    $steps[] = array(
                        'status'  => 'error',
                        'title'   => '2. Kiểm tra Đích đến GA4 (Data Manager API v1)',
                        'message' => 'Bạn đã điền GA4 Property ID (' . $ga4_property_id . ') nhưng chưa điền GA4 Measurement ID (G-XXXXXXXX) ở Mục 2.',
                    );
                } else {
                    $tested_any_destination = true;
                    $ga4_destination = array(
                        'operatingAccount'     => array(
                            'accountType' => 'GOOGLE_ANALYTICS_PROPERTY',
                            'accountId'   => $ga4_property_id,
                        ),
                        'loginAccount'         => array(
                            'accountType' => 'GOOGLE_ANALYTICS_PROPERTY',
                            'accountId'   => $ga4_property_id,
                        ),
                        'productDestinationId' => $ga4_id,
                    );

                    // Luôn ép validateOnly = true khi bấm nút Test để tuyệt đối không tạo đơn ảo trên GA4
                    $res = self::dispatch_data_manager_request($access_token, $ga4_destination, $dummy_job, 'GA4 Test', true);
                    if (is_wp_error($res)) {
                        $has_error = true;
                        $raw_err   = $res->get_error_message();
                        $hint      = self::explain_google_api_error($raw_err, $sa_email, 'GA4');
                        $steps[]   = array(
                            'status'  => 'error',
                            'title'   => sprintf('2. Kiểm tra GA4 Data Manager API (Property: %s / Stream: %s)', $ga4_property_id, $ga4_id),
                            'message' => $hint . "\nChi tiết kỹ thuật từ Google: " . $raw_err,
                        );
                    } else {
                        $req_id  = isset($res['requestId']) ? $res['requestId'] : 'OK';
                        $steps[] = array(
                            'status'  => 'success',
                            'title'   => sprintf('2. Kiểm tra GA4 Data Manager API (Property: %s / Stream: %s)', $ga4_property_id, $ga4_id),
                            'message' => sprintf('HTTP 200 OK (validateOnly = true) — Google đã xác nhận cấu trúc đơn hàng, quyền truy cập và dữ liệu băm SHA-256 hợp lệ 100%%! (Mã phiên: %s). Dữ liệu test không bị ghi vào báo cáo thật.', $req_id),
                        );
                    }
                }
            }

            // 2B. Kiểm tra đích đến Google Ads trực tiếp (nếu có điền Customer ID & Conversion Action ID)
            if (!empty($gads_customer_id) || !empty($gads_conversion_id)) {
                if (empty($gads_customer_id) || empty($gads_conversion_id)) {
                    $has_error = true;
                    $steps[] = array(
                        'status'  => 'error',
                        'title'   => '3. Kiểm tra Đích đến Google Ads (Data Manager API v1)',
                        'message' => 'Để bắn trực tiếp vào Google Ads, bạn cần điền đủ cả 2 ô: Customer ID (10 chữ số) VÀ Conversion Action ID (hoặc xóa trống cả 2 ô nếu chỉ muốn bắn vào GA4).',
                    );
                } else {
                    $tested_any_destination = true;
                    $gads_destination = array(
                        'operatingAccount'     => array(
                            'accountType' => 'GOOGLE_ADS',
                            'accountId'   => $gads_customer_id,
                        ),
                        'loginAccount'         => array(
                            'accountType' => 'GOOGLE_ADS',
                            'accountId'   => $gads_customer_id,
                        ),
                        'productDestinationId' => $gads_conversion_id,
                    );

                    $res = self::dispatch_data_manager_request($access_token, $gads_destination, $dummy_job, 'Google Ads Test', true);
                    if (is_wp_error($res)) {
                        $has_error = true;
                        $raw_err   = $res->get_error_message();
                        $hint      = self::explain_google_api_error($raw_err, $sa_email, 'ADS');
                        $steps[]   = array(
                            'status'  => 'error',
                            'title'   => sprintf('3. Kiểm tra Google Ads Data Manager API (Customer ID: %s / Conversion ID: %s)', $gads_customer_id, $gads_conversion_id),
                            'message' => $hint . "\nChi tiết kỹ thuật từ Google: " . $raw_err,
                        );
                    } else {
                        $req_id  = isset($res['requestId']) ? $res['requestId'] : 'OK';
                        $steps[] = array(
                            'status'  => 'success',
                            'title'   => sprintf('3. Kiểm tra Google Ads Data Manager API (Customer ID: %s / Action ID: %s)', $gads_customer_id, $gads_conversion_id),
                            'message' => sprintf('HTTP 200 OK (validateOnly = true) — Kết nối trực tiếp vào sự kiện Google Ads thành công 100%%! (Mã phiên: %s). Dữ liệu test không bị ghi vào báo cáo thật.', $req_id),
                        );
                    }
                }
            }

            if (!$tested_any_destination && !$has_error) {
                $has_error = true;
                $steps[] = array(
                    'status'  => 'warning',
                    'title'   => '2. Chưa điền Đích đến (Destination)',
                    'message' => 'Token Service Account đã hoạt động tốt, nhưng bạn cần điền thêm GA4 Property ID (ví dụ: 123456789) và/hoặc cặp Google Ads Customer ID + Conversion Action ID để plugin biết nơi nhận dữ liệu.',
                );
            }

            wp_send_json_success(array(
                'steps'   => $steps,
                'overall' => $has_error ? 'error' : 'success',
            ));
        }

        // TRƯỜNG HỢP 2: KHÔNG DÙNG SERVICE ACCOUNT JSON -> KIỂM TRA GA4 MEASUREMENT PROTOCOL DEBUG ENDPOINT
        if (!empty($ga4_api_secret) && !empty($ga4_id)) {
            $debug_url = add_query_arg(
                array(
                    'measurement_id' => $ga4_id,
                    'api_secret'     => $ga4_api_secret,
                ),
                'https://www.google-analytics.com/debug/mp/collect'
            );

            $mp_payload = array(
                'client_id' => $dummy_job['client_id'],
                'events'    => array(
                    array(
                        'name'   => 'purchase',
                        'params' => array(
                            'transaction_id' => $dummy_job['order_data']['transaction_id'],
                            'value'          => 150000.0,
                            'currency'       => $currency,
                            'items'          => array(
                                array(
                                    'item_id'   => 'TEST_SKU_01',
                                    'item_name' => 'WooTrack MP Validation Item',
                                    'price'     => 150000.0,
                                    'quantity'  => 1,
                                ),
                            ),
                        ),
                    ),
                ),
            );

            $response = wp_remote_post($debug_url, array(
                'body'        => wp_json_encode($mp_payload),
                'headers'     => array('Content-Type' => 'application/json'),
                'timeout'     => 6,
                'data_format' => 'body',
            ));

            if (is_wp_error($response)) {
                $steps[] = array(
                    'status'  => 'error',
                    'title'   => 'Kiểm tra GA4 Measurement Protocol (Debug Server)',
                    'message' => 'Lỗi kết nối mạng: ' . $response->get_error_message(),
                );
                wp_send_json_success(array('steps' => $steps, 'overall' => 'error'));
            }

            $code = wp_remote_retrieve_response_code($response);
            $body = json_decode(wp_remote_retrieve_body($response), true);
            $validation_msgs = isset($body['validationMessages']) && is_array($body['validationMessages']) ? $body['validationMessages'] : array();

            if ($code >= 200 && $code < 300 && empty($validation_msgs)) {
                $steps[] = array(
                    'status'  => 'success',
                    'title'   => sprintf('Kiểm tra GA4 Measurement Protocol (%s)', $ga4_id),
                    'message' => 'HTTP 200 OK — Máy chủ kiểm định của Google xác nhận gói tin Measurement Protocol hợp lệ 100% (0 lỗi validationMessages)!',
                );
                wp_send_json_success(array('steps' => $steps, 'overall' => 'success'));
            } else {
                $steps[] = array(
                    'status'  => 'error',
                    'title'   => sprintf('Kiểm tra GA4 Measurement Protocol (%s)', $ga4_id),
                    'message' => 'Phản hồi từ Google Debug Server: ' . wp_remote_retrieve_body($response),
                );
                wp_send_json_success(array('steps' => $steps, 'overall' => 'error'));
            }
        }

        // Chưa điền bất kỳ thông tin nào
        $steps[] = array(
            'status'  => 'warning',
            'title'   => 'Chưa có thông tin xác thực',
            'message' => 'Vui lòng dán nội dung Service Account JSON (kèm GA4 Property ID hoặc Google Ads ID) HOẶC điền GA4 Measurement Protocol API Secret trước khi bấm kiểm tra.',
        );
        wp_send_json_success(array('steps' => $steps, 'overall' => 'warning'));
    }

    /**
     * Giải thích lỗi trả về từ Google Data Manager API sang tiếng Việt dễ hiểu kèm cách khắc phục
     */
    private static function explain_google_api_error($raw_error, $sa_email, $target) {
        if (strpos($raw_error, 'INVALID_CONVERSION_ACTION_TYPE') !== false) {
            return '⚠️ Sai loại sự kiện (INVALID_CONVERSION_ACTION_TYPE): Sự kiện "purchase_ads" (ID: 7839561741) của bạn có nguồn là "Website" (chỉ dành cho thẻ trình duyệt Google Tag ở Mục 3). Còn đích đến GOOGLE_ADS của Data Manager API chỉ nhận sự kiện có nguồn là "Import / Offline conversions (từ lượt nhấp / API)". Giải pháp: Vì Mục 2 (GA4 Data Manager API) ở trên đã báo XANH Thành công 100%, bạn chỉ cần XÓA TRỐNG 2 ô Customer ID & Conversion Action ID ở mục 3B (hoặc tạo 1 Conversion Action loại Import trên Google Ads nếu muốn bắn tách riêng).';
        }
        if (strpos($raw_error, 'SERVICE_DISABLED') !== false || strpos($raw_error, ' has not been used in project ') !== false || strpos($raw_error, 'it is disabled') !== false) {
            return '⚠️ Chưa bật Google Data Manager API trên Google Cloud! Bạn hãy vào Google Cloud Console -> APIs & Services -> Library -> Tìm "Google Data Manager API" và bấm ENABLE (Bật), đợi 1 phút rồi bấm kiểm tra lại.';
        }
        if (strpos($raw_error, 'PERMISSION_DENIED') !== false || strpos($raw_error, 'HTTP 403') !== false) {
            if ($target === 'GA4') {
                return sprintf('⚠️ Tài khoản GA4 chưa cấp quyền cho Email Service Account! Bạn hãy vào Google Analytics -> Admin -> Property access management -> Bấm dấu (+) thêm email "%s" với quyền Editor (Người chỉnh sửa).', $sa_email);
            }
            return sprintf('⚠️ Tài khoản Google Ads chưa cấp quyền (hoặc chưa liên kết quyền) cho Email Service Account "%s". Nếu Google Ads không cho mời trực tiếp đuôi .iam.gserviceaccount.com, bạn chỉ cần xóa trống 2 ô Customer ID & Conversion Action ID ở mục 3B để dùng qua cổng GA4!', $sa_email);
        }
        if (strpos($raw_error, 'NOT_FOUND') !== false || strpos($raw_error, 'HTTP 404') !== false) {
            return '⚠️ Không tìm thấy ID tài khoản đích! Hãy kiểm tra lại xem GA4 Property ID (chỉ gồm chữ số) hoặc Google Ads Customer ID / Conversion Action ID đã điền chính xác chưa.';
        }
        return '⚠️ Google trả về lỗi khi kiểm tra gói tin.';
    }
}
