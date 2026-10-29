<?php
/**
 * WooTrack Helper Functions
 *
 * Xử lý định dạng dữ liệu cho GA4 và Google Ads
 */

if (!defined('ABSPATH')) {
    exit;
}

class WooTrack_Helper {

    /**
     * Bộ nhớ đệm (Static Cache) lưu cài đặt trong suốt vòng đời 1 request để tránh gọi get_option() lặp lại
     */
    private static $settings_cache = null;

    /**
     * Lấy cài đặt theo key
     */
    public static function get_setting($key, $default = '') {
        if (self::$settings_cache === null) {
            $options = get_option('woo_track_settings', array());
            self::$settings_cache = is_array($options) ? $options : array();
        }
        return isset(self::$settings_cache[$key]) ? self::$settings_cache[$key] : $default;
    }

    /**
     * Làm mới cache cài đặt khi Admin lưu cấu hình mới
     */
    public static function clear_settings_cache() {
        self::$settings_cache = null;
    }

    /**
     * Kiểm tra một sự kiện cụ thể có được bật không
     */
    public static function is_event_enabled($event_name) {
        $key = 'enable_' . $event_name;
        $val = self::get_setting($key, 'yes');
        return ($val === 'yes' || $val === '1' || $val === true);
    }

    /**
     * Lấy ID sản phẩm dựa trên cài đặt (Parent ID, Post/Variation ID hay SKU)
     * Hỗ trợ đồng nhất ID sản phẩm cha cho mô hình bán Extension/Phần mềm chia gói biến thể
     */
    public static function get_product_identifier($product) {
        if (!$product instanceof WC_Product) {
            return '';
        }

        $identifier_type = self::get_setting('product_identifier', 'parent_id');

        if ($identifier_type === 'sku') {
            $sku = $product->get_sku();
            if (!empty($sku)) {
                return (string) $sku;
            }
        }

        if ($identifier_type === 'parent_id' && $product->is_type('variation')) {
            $parent_id = $product->get_parent_id();
            if ($parent_id > 0) {
                return (string) $parent_id;
            }
        }

        return (string) $product->get_id();
    }

    /**
     * Chuẩn hóa chuỗi send_to cho Google Ads (AW-XXXXX/LABEL), tự làm sạch nếu khách dán cả chuỗi
     */
    public static function format_gads_send_to($gads_id, $label) {
        $gads_id = trim((string) $gads_id);
        $label   = trim((string) $label);

        if (empty($gads_id) || empty($label)) {
            return '';
        }

        // Tự động thêm AW- nếu gads_id chỉ chứa số
        if (is_numeric($gads_id)) {
            $gads_id = 'AW-' . $gads_id;
        }

        // Tự bóc tách phần nhãn nếu người dùng dán cả chuỗi dạng 'AW-XXXX/LABEL'
        if (strpos($label, '/') !== false) {
            $parts = explode('/', $label);
            $label = end($parts);
        }

        return rtrim($gads_id, '/') . '/' . ltrim($label, '/');
    }

    /**
     * Format dữ liệu 1 sản phẩm theo chuẩn GA4 / Google Ads Items
     */
    public static function format_product_item($product, $quantity = 1, $index = 1) {
        if (!$product instanceof WC_Product) {
            return array();
        }

        // Với sản phẩm biến thể, lấy danh mục từ sản phẩm cha
        $cat_prod_id = $product->is_type('variation') ? $product->get_parent_id() : $product->get_id();
        $categories  = wc_get_product_terms($cat_prod_id, 'product_cat', array('fields' => 'names'));
        $category    = !empty($categories) && !is_wp_error($categories) ? $categories[0] : '';

        $price   = (float) wc_get_price_to_display($product);
        $prod_id = self::get_product_identifier($product);

        $item = array(
            'id'                       => $prod_id,
            'item_id'                  => $prod_id,
            'item_name'                => $product->get_name(),
            'price'                    => round($price, 2),
            'quantity'                 => (int) $quantity,
            'index'                    => (int) $index,
            'google_business_vertical' => 'retail',
        );

        if (!empty($category)) {
            $item['item_category'] = $category;
        }

        // Nếu là biến thể sản phẩm
        if ($product->is_type('variation')) {
            $raw_attrs = $product->get_attributes();
            if (is_array($raw_attrs)) {
                $clean_attrs = array_filter($raw_attrs, function ($val) {
                    return is_scalar($val) && trim((string) $val) !== '';
                });
                if (!empty($clean_attrs)) {
                    $item['item_variant'] = implode(', ', $clean_attrs);
                }
            }
        }

        return $item;
    }

    /**
     * Chuyển chuỗi về chữ thường hỗ trợ đầy đủ UTF-8 (Tiếng Việt có dấu) trên PHP 8.x
     */
    public static function strtolower_utf8($str) {
        $str = trim((string) $str);
        if ($str === '') {
            return '';
        }
        return function_exists('mb_strtolower') ? mb_strtolower($str, 'UTF-8') : strtolower($str);
    }

    /**
     * Chuẩn hóa số điện thoại theo mã quốc gia (E.164 có dấu + hoặc chỉ chữ số cho Meta)
     */
    public static function normalize_phone($raw_phone, $country = 'VN', $with_plus = true) {
        $raw_phone = preg_replace('/[^0-9+]/', '', (string) $raw_phone);
        if (empty($raw_phone)) {
            return '';
        }

        $country = strtoupper(trim((string) $country));
        if (empty($country)) {
            $country = 'VN';
        }

        $calling_codes = array(
            'VN' => '84',
            'US' => '1',
            'CA' => '1',
            'GB' => '44',
            'AU' => '61',
            'SG' => '65',
            'MY' => '60',
            'TH' => '66',
            'JP' => '81',
            'KR' => '82',
            'DE' => '49',
            'FR' => '33',
        );
        $prefix = isset($calling_codes[$country]) ? $calling_codes[$country] : '84';

        if (strpos($raw_phone, '+') === 0) {
            $digits = preg_replace('/[^0-9]/', '', substr($raw_phone, 1));
        } elseif (strpos($raw_phone, '00') === 0) {
            $digits = preg_replace('/[^0-9]/', '', substr($raw_phone, 2));
        } else {
            $digits = preg_replace('/[^0-9]/', '', $raw_phone);
            if (strpos($digits, '0') === 0) {
                $digits = $prefix . substr($digits, 1);
            } elseif (strpos($digits, $prefix) !== 0) {
                $digits = $prefix . $digits;
            }
        }

        if (empty($digits)) {
            return '';
        }

        return $with_plus ? ('+' . $digits) : $digits;
    }

    /**
     * Kiểm tra đơn hàng có hợp lệ để ghi nhận chuyển đổi Purchase không (Tránh bắn đơn lỗi/hủy)
     */
    public static function is_order_valid_for_purchase_tracking($order) {
        if (!$order instanceof WC_Order) {
            return false;
        }

        $invalid_statuses = array('failed', 'cancelled', 'refunded', 'trash', 'checkout-draft');
        if ($order->has_status($invalid_statuses)) {
            return false;
        }

        return true;
    }

    /**
     * Lấy đối tượng WC_Order hợp lệ trên trang Thank You (Xác thực order_key chống lộ PII & bắn giả)
     */
    public static function get_valid_thankyou_order($order_id = 0) {
        if ($order_id <= 0) {
            global $wp;
            if (isset($wp->query_vars['order-received'])) {
                $order_id = absint($wp->query_vars['order-received']);
            } elseif (isset($_GET['order-received'])) {
                $order_id = absint($_GET['order-received']);
            }
        }

        if ($order_id <= 0) {
            return null;
        }

        $order = wc_get_order($order_id);
        if (!$order instanceof WC_Order) {
            return null;
        }

        // Xác thực order key từ URL hoặc chủ sở hữu đơn hàng đang đăng nhập
        $order_key = isset($_GET['key']) ? wc_clean(wp_unslash($_GET['key'])) : '';
        if (!empty($order_key)) {
            if (!$order->key_is_valid($order_key)) {
                return null;
            }
        } else {
            $current_user_id = get_current_user_id();
            if ($current_user_id <= 0 || (int) $order->get_customer_id() !== (int) $current_user_id) {
                return null;
            }
        }

        return $order;
    }

    /**
     * Format dữ liệu đơn hàng cho sự kiện purchase
     */
    public static function format_order_data($order) {
        if (!$order instanceof WC_Order) {
            return array();
        }

        $items = array();
        $index = 1;

        foreach ($order->get_items() as $item_id => $item) {
            $product = $item->get_product();
            if ($product) {
                $product_data = self::format_product_item($product, $item->get_quantity(), $index);
                // Ghi đè giá thực tế mua trên đơn hàng
                $product_data['price'] = round((float) $order->get_item_total($item, false, false), 2);
                $items[] = $product_data;
                $index++;
            }
        }

        $coupons = $order->get_coupon_codes();

        return array(
            'transaction_id' => (string) $order->get_order_number(),
            'value'          => round((float) $order->get_total(), 2),
            'currency'       => $order->get_currency(),
            'tax'            => round((float) $order->get_total_tax(), 2),
            'shipping'       => round((float) $order->get_shipping_total(), 2),
            'discount'       => round((float) $order->get_discount_total(), 2),
            'coupon'         => !empty($coupons) ? implode(', ', $coupons) : '',
            'new_customer'   => self::is_new_customer($order),
            'items'          => $items,
        );
    }

    /**
     * Kiểm tra khách hàng trong đơn hàng là khách mới (true) hay khách quay lại (false) cho Google Ads
     */
    public static function is_new_customer($order) {
        if (!$order instanceof WC_Order) {
            return true;
        }

        $customer_id   = (int) $order->get_customer_id();
        $billing_email = self::strtolower_utf8($order->get_billing_email());

        $query_args = array(
            'limit'   => 1,
            'return'  => 'ids',
            'exclude' => array($order->get_id()),
            'status'  => array('wc-processing', 'wc-completed', 'processing', 'completed'),
        );

        if ($customer_id > 0) {
            $query_args['customer_id'] = $customer_id;
            $existing = wc_get_orders($query_args);
            if (!empty($existing)) {
                return false;
            }
            unset($query_args['customer_id']);
        }

        if (!empty($billing_email) && is_email($billing_email)) {
            $query_args['billing_email'] = $billing_email;
            $existing_by_email = wc_get_orders($query_args);
            if (!empty($existing_by_email)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Dữ liệu khách hàng cho Google Ads Enhanced Conversions (Dữ liệu người dùng nâng cao)
     * Theo tài liệu chính thức của Google: Truyền dữ liệu dạng chuẩn (E.164 phone, lowercase email) 
     * để thư viện gtag.js tự động chuẩn hóa và băm SHA-256 phía client (tránh lỗi double-hashing).
     */
    public static function format_user_data_for_ads($order) {
        if (!$order instanceof WC_Order) {
            return array();
        }

        $email = self::strtolower_utf8($order->get_billing_email());
        $country = trim(strtoupper((string) $order->get_billing_country()));
        $e164_phone = self::normalize_phone($order->get_billing_phone(), $country, true);

        $user_data = array();

        if (!empty($email) && is_email($email)) {
            $user_data['email'] = $email;
        }

        if (!empty($e164_phone)) {
            $user_data['phone_number'] = $e164_phone;
        }

        $first_name = trim((string) $order->get_billing_first_name());
        $last_name  = trim((string) $order->get_billing_last_name());
        $street     = trim((string) $order->get_billing_address_1());
        $city       = trim((string) $order->get_billing_city());
        $state      = trim((string) $order->get_billing_state());
        $postcode   = trim((string) $order->get_billing_postcode());

        $address = array();
        if (!empty($first_name)) $address['first_name']  = $first_name;
        if (!empty($last_name))  $address['last_name']   = $last_name;
        if (!empty($street))     $address['street']      = $street;
        if (!empty($city))       $address['city']        = $city;
        if (!empty($state))      $address['region']      = $state;
        if (!empty($postcode))   $address['postal_code'] = $postcode;
        if (!empty($country))    $address['country']     = $country;

        if (!empty($address)) {
            $user_data['address'] = $address;
        }

        return $user_data;
    }

    /**
     * Kiểm tra Meta Pixel có được bật và có Pixel ID không
     */
    public static function is_meta_enabled() {
        $pixel_id = trim(self::get_setting('meta_pixel_id', ''));
        $enabled  = self::get_setting('enable_meta_pixel', 'yes') === 'yes';
        return !empty($pixel_id) && $enabled;
    }

    /**
     * Kiểm tra Meta Conversions API (CAPI) có được bật không
     */
    public static function is_meta_capi_enabled() {
        if (!self::is_meta_enabled()) {
            return false;
        }
        $capi_token = trim(self::get_setting('meta_capi_token', ''));
        $enabled    = self::get_setting('enable_meta_capi', 'yes') === 'yes';
        return !empty($capi_token) && $enabled;
    }

    /**
     * Kiểm tra sự kiện Meta có được bật không
     */
    public static function is_meta_event_enabled($event_name) {
        if (!self::is_meta_enabled()) {
            return false;
        }
        $key = 'meta_event_' . strtolower($event_name);
        $val = self::get_setting($key, 'yes');
        return ($val === 'yes' || $val === '1' || $val === true);
    }

    /**
     * Lấy loại nội dung Meta: product hoặc product_group
     */
    public static function get_meta_content_type() {
        return self::get_setting('meta_content_type', 'product');
    }

    /**
     * Lấy địa chỉ IP thật của khách hàng
     */
    public static function get_client_ip() {
        $ip_keys = array('HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR');
        foreach ($ip_keys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip_list = explode(',', sanitize_text_field(wp_unslash($_SERVER[$key])));
                $ip = trim($ip_list[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return '';
    }

    /**
     * Lấy User Agent của trình duyệt
     */
    public static function get_client_user_agent() {
        return isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';
    }

    /**
     * Lấy cookie _fbp của Meta
     */
    public static function get_fbp() {
        return isset($_COOKIE['_fbp']) ? sanitize_text_field(wp_unslash($_COOKIE['_fbp'])) : '';
    }

    /**
     * Lấy cookie _fbc của Meta hoặc dựng từ tham số fbclid nếu có
     */
    public static function get_fbc() {
        $cookie_fbc = !empty($_COOKIE['_fbc']) ? sanitize_text_field(wp_unslash($_COOKIE['_fbc'])) : '';
        if (!empty($_GET['fbclid'])) {
            $fbclid = sanitize_text_field(wp_unslash($_GET['fbclid']));
            $suffix = '.' . $fbclid;
            if (empty($cookie_fbc) || substr($cookie_fbc, -strlen($suffix)) !== $suffix) {
                return 'fb.1.' . (int) round(microtime(true) * 1000) . '.' . $fbclid;
            }
        }
        return $cookie_fbc;
    }

    /**
     * Chuẩn hóa và băm SHA-256 dữ liệu người dùng theo chuẩn Meta CAPI
     */
    public static function hash_meta_value($value, $type = '', $country = 'VN') {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        switch ($type) {
            case 'em':
                $value = self::strtolower_utf8($value);
                break;
            case 'ph':
                $value = self::normalize_phone($value, $country, false);
                break;
            case 'fn':
            case 'ln':
            case 'ct':
            case 'st':
                $value = self::strtolower_utf8(preg_replace('/[\s\p{P}]/u', '', $value));
                break;
            case 'zp':
                $value = self::strtolower_utf8(preg_replace('/[\s-]/', '', $value));
                break;
            case 'country':
                $value = self::strtolower_utf8($value);
                break;
            default:
                $value = self::strtolower_utf8($value);
                break;
        }

        if ($value === '') {
            return '';
        }

        return hash('sha256', $value);
    }

    /**
     * Format mảng contents chuẩn Meta Pixel & CAPI
     */
    public static function format_meta_contents_from_items($items) {
        $contents = array();
        if (empty($items) || !is_array($items)) {
            return $contents;
        }

        foreach ($items as $item) {
            $contents[] = array(
                'id'         => (string) $item['item_id'],
                'quantity'   => (int) $item['quantity'],
                'item_price' => (float) $item['price'],
            );
        }

        return $contents;
    }

    /**
     * Format dữ liệu sản phẩm đơn cho Meta Pixel (ViewContent, AddToCart)
     */
    public static function format_meta_product_data($product, $quantity = 1) {
        if (!$product instanceof WC_Product) {
            return array();
        }

        $item_data = self::format_product_item($product, $quantity, 1);
        $total_val = round((float) $item_data['price'] * (int) $quantity, 2);

        $cat_prod_id = $product->is_type('variation') ? $product->get_parent_id() : $product->get_id();
        $categories  = wc_get_product_terms($cat_prod_id, 'product_cat', array('fields' => 'names'));
        $category    = !empty($categories) && !is_wp_error($categories) ? $categories[0] : '';

        return array(
            'content_name'     => $product->get_name(),
            'content_category' => $category,
            'content_ids'      => array((string) $item_data['item_id']),
            'content_type'     => self::get_meta_content_type(),
            'value'            => $total_val,
            'currency'         => get_woocommerce_currency(),
            'contents'         => array(
                array(
                    'id'         => (string) $item_data['item_id'],
                    'quantity'   => (int) $quantity,
                    'item_price' => (float) $item_data['price'],
                ),
            ),
        );
    }

    /**
     * Format dữ liệu đơn hàng cho Meta (Purchase)
     */
    public static function format_meta_order_data($order) {
        if (!$order instanceof WC_Order) {
            return array();
        }

        $order_data  = self::format_order_data($order);
        $content_ids = array();
        foreach ($order_data['items'] as $item) {
            $content_ids[] = (string) $item['item_id'];
        }
        $content_ids = array_values(array_unique($content_ids));

        return array(
            'content_type' => self::get_meta_content_type(),
            'content_ids'  => $content_ids,
            'contents'     => self::format_meta_contents_from_items($order_data['items']),
            'value'        => (float) $order_data['value'],
            'currency'     => $order_data['currency'],
            'num_items'    => (int) $order->get_item_count(),
            'order_id'     => (string) $order->get_order_number(),
        );
    }

    /**
     * Tạo dữ liệu user_data cho Meta Conversions API (CAPI)
     */
    public static function format_meta_user_data_for_capi($order) {
        if (!$order instanceof WC_Order) {
            return array();
        }

        $user_data = array();

        // Email
        $email = trim((string) $order->get_billing_email());
        if (!empty($email) && is_email($email)) {
            $hashed_email = self::hash_meta_value($email, 'em');
            if (!empty($hashed_email)) {
                $user_data['em'] = array($hashed_email);
            }
        }

        // Phone
        $country = trim((string) $order->get_billing_country());
        $phone   = (string) $order->get_billing_phone();
        if (!empty($phone)) {
            $hashed_phone = self::hash_meta_value($phone, 'ph', $country ?: 'VN');
            if (!empty($hashed_phone)) {
                $user_data['ph'] = array($hashed_phone);
            }
        }

        // First Name
        $fn = trim((string) $order->get_billing_first_name());
        if (!empty($fn)) {
            $hashed_fn = self::hash_meta_value($fn, 'fn');
            if (!empty($hashed_fn)) {
                $user_data['fn'] = array($hashed_fn);
            }
        }

        // Last Name
        $ln = trim((string) $order->get_billing_last_name());
        if (!empty($ln)) {
            $hashed_ln = self::hash_meta_value($ln, 'ln');
            if (!empty($hashed_ln)) {
                $user_data['ln'] = array($hashed_ln);
            }
        }

        // City
        $city = trim((string) $order->get_billing_city());
        if (!empty($city)) {
            $hashed_ct = self::hash_meta_value($city, 'ct');
            if (!empty($hashed_ct)) {
                $user_data['ct'] = array($hashed_ct);
            }
        }

        // State
        $state = trim((string) $order->get_billing_state());
        if (!empty($state)) {
            $hashed_st = self::hash_meta_value($state, 'st');
            if (!empty($hashed_st)) {
                $user_data['st'] = array($hashed_st);
            }
        }

        // Zip
        $zip = trim((string) $order->get_billing_postcode());
        if (!empty($zip)) {
            $hashed_zp = self::hash_meta_value($zip, 'zp');
            if (!empty($hashed_zp)) {
                $user_data['zp'] = array($hashed_zp);
            }
        }

        // Country
        if (!empty($country)) {
            $hashed_country = self::hash_meta_value($country, 'country');
            if (!empty($hashed_country)) {
                $user_data['country'] = array($hashed_country);
            }
        }

        // External ID (Customer ID)
        $customer_id = $order->get_customer_id();
        if ($customer_id > 0) {
            $user_data['external_id'] = array(self::hash_meta_value((string) $customer_id));
        }

        // Kiểm tra xem có đang thực thi trực tiếp trên trình duyệt của khách hàng hay không (Tránh lấy nhầm IP/Cookie của Admin hoặc Webhook)
        $is_customer_request = !is_admin() && !wp_doing_cron() && (!defined('REST_REQUEST') || !REST_REQUEST);

        // Client IP & User Agent (ưu tiên lấy từ order meta đã lưu hoặc thông tin gốc trên WC_Order)
        $saved_ip = (string) $order->get_meta('_woo_track_meta_ip');
        if (empty($saved_ip)) {
            $saved_ip = (string) $order->get_customer_ip_address();
        }
        $ip = !empty($saved_ip) ? $saved_ip : ($is_customer_request ? self::get_client_ip() : '');
        if (!empty($ip)) {
            $user_data['client_ip_address'] = $ip;
        }

        $saved_ua = (string) $order->get_meta('_woo_track_meta_ua');
        if (empty($saved_ua)) {
            $saved_ua = (string) $order->get_customer_user_agent();
        }
        $ua = !empty($saved_ua) ? $saved_ua : ($is_customer_request ? self::get_client_user_agent() : '');
        if (!empty($ua)) {
            $user_data['client_user_agent'] = $ua;
        }

        // Cookie _fbp & _fbc (chỉ fallback sang $_COOKIE nếu là request trực tiếp từ trình duyệt của khách)
        $saved_fbp = (string) $order->get_meta('_woo_track_meta_fbp');
        $fbp = !empty($saved_fbp) ? $saved_fbp : ($is_customer_request ? self::get_fbp() : '');
        if (!empty($fbp)) {
            $user_data['fbp'] = $fbp;
        }

        $saved_fbc = (string) $order->get_meta('_woo_track_meta_fbc');
        $fbc = !empty($saved_fbc) ? $saved_fbc : ($is_customer_request ? self::get_fbc() : '');
        if (!empty($fbc)) {
            $user_data['fbc'] = $fbc;
        }

        return $user_data;
    }

    /**
     * Format dữ liệu Advanced Matching cho Meta Pixel trên trình duyệt (fbq('init', id, {...}))
     */
    public static function format_meta_advanced_matching_browser($order_or_user = null) {
        $data = array();

        if ($order_or_user instanceof WC_Order) {
            $ctry  = self::strtolower_utf8($order_or_user->get_billing_country());
            $email = self::strtolower_utf8($order_or_user->get_billing_email());
            $phone = self::normalize_phone($order_or_user->get_billing_phone(), $ctry ?: 'VN', false);
            $fn    = self::strtolower_utf8($order_or_user->get_billing_first_name());
            $ln    = self::strtolower_utf8($order_or_user->get_billing_last_name());
            $city  = self::strtolower_utf8($order_or_user->get_billing_city());
            $state = self::strtolower_utf8($order_or_user->get_billing_state());
            $zip   = self::strtolower_utf8(preg_replace('/[\s-]/', '', (string) $order_or_user->get_billing_postcode()));

            if (!empty($email) && is_email($email)) $data['em'] = $email;
            if (!empty($phone)) $data['ph'] = $phone;
            if (!empty($fn)) $data['fn'] = $fn;
            if (!empty($ln)) $data['ln'] = $ln;
            if (!empty($city)) $data['ct'] = $city;
            if (!empty($state)) $data['st'] = $state;
            if (!empty($zip)) $data['zp'] = $zip;
            if (!empty($ctry)) $data['country'] = $ctry;

            $customer_id = $order_or_user->get_customer_id();
            if ($customer_id > 0) {
                $data['external_id'] = (string) $customer_id;
            }
        } elseif (is_user_logged_in()) {
            $current_user = wp_get_current_user();
            if ($current_user && $current_user->ID) {
                if (!empty($current_user->user_email)) {
                    $data['em'] = self::strtolower_utf8($current_user->user_email);
                }
                if (!empty($current_user->first_name)) {
                    $data['fn'] = self::strtolower_utf8($current_user->first_name);
                }
                if (!empty($current_user->last_name)) {
                    $data['ln'] = self::strtolower_utf8($current_user->last_name);
                }
                $data['external_id'] = (string) $current_user->ID;
            }
        }

        return $data;
    }

    /**
     * Kiểm tra TikTok Pixel có được bật và có Pixel ID không
     */
    public static function is_tiktok_enabled() {
        $pixel_id = trim((string) self::get_setting('tiktok_pixel_id', ''));
        $enabled  = self::get_setting('enable_tiktok_pixel', 'yes') === 'yes';
        return !empty($pixel_id) && $enabled;
    }

    /**
     * Kiểm tra TikTok Events API (Server-side) có được bật không
     */
    public static function is_tiktok_events_api_enabled() {
        if (!self::is_tiktok_enabled()) {
            return false;
        }
        $token   = trim((string) self::get_setting('tiktok_access_token', ''));
        $enabled = self::get_setting('enable_tiktok_events_api', 'yes') === 'yes';
        return !empty($token) && $enabled;
    }

    /**
     * Kiểm tra sự kiện TikTok có được bật không
     */
    public static function is_tiktok_event_enabled($event_name) {
        if (!self::is_tiktok_enabled()) {
            return false;
        }
        $key = 'tiktok_event_' . strtolower($event_name);
        $val = self::get_setting($key, 'yes');
        return ($val === 'yes' || $val === '1' || $val === true);
    }

    /**
     * Lấy tên sự kiện mua hàng cho TikTok (Purchase hoặc CompletePayment)
     */
    public static function get_tiktok_purchase_event_name() {
        $name = self::get_setting('tiktok_purchase_event_name', 'Purchase');
        return ($name === 'CompletePayment') ? 'CompletePayment' : 'Purchase';
    }

    /**
     * Lấy cookie _ttp của TikTok
     */
    public static function get_ttp() {
        return isset($_COOKIE['_ttp']) ? sanitize_text_field(wp_unslash($_COOKIE['_ttp'])) : '';
    }

    /**
     * Lấy mã ttclid từ cookie hoặc tham số URL
     */
    public static function get_ttclid() {
        if (!empty($_COOKIE['_ttclid'])) {
            return sanitize_text_field(wp_unslash($_COOKIE['_ttclid']));
        }
        if (!empty($_COOKIE['ttclid'])) {
            return sanitize_text_field(wp_unslash($_COOKIE['ttclid']));
        }
        if (!empty($_GET['ttclid'])) {
            return sanitize_text_field(wp_unslash($_GET['ttclid']));
        }
        return '';
    }

    /**
     * Format dữ liệu sản phẩm đơn cho TikTok Pixel (ViewContent, AddToCart, AddToWishlist)
     */
    public static function format_tiktok_product_data($product, $quantity = 1) {
        if (!$product instanceof WC_Product) {
            return array();
        }

        $item_data   = self::format_product_item($product, $quantity, 1);
        $cat_prod_id = $product->is_type('variation') ? $product->get_parent_id() : $product->get_id();
        $categories  = wc_get_product_terms($cat_prod_id, 'product_cat', array('fields' => 'names'));
        $category    = !empty($categories) && !is_wp_error($categories) ? $categories[0] : '';
        $price       = round((float) $item_data['price'], 2);
        $total_val   = round($price * (int) $quantity, 2);

        $raw_desc = $product->get_short_description();
        if (empty($raw_desc)) {
            $raw_desc = $product->get_description();
        }
        $description = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags(strip_shortcodes((string) $raw_desc))));
        if (function_exists('mb_substr')) {
            $description = mb_substr($description, 0, 300, 'UTF-8');
        } else {
            $description = substr($description, 0, 300);
        }

        return array(
            'content_id'       => (string) $item_data['item_id'],
            'content_type'     => 'product',
            'content_name'     => $product->get_name(),
            'content_category' => $category,
            'price'            => $price,
            'value'            => $total_val,
            'currency'         => get_woocommerce_currency(),
            'quantity'         => (int) $quantity,
            'num_items'        => (int) $quantity,
            'description'      => $description,
            'contents'         => array(
                array(
                    'content_id'   => (string) $item_data['item_id'],
                    'content_type' => 'product',
                    'content_name' => $product->get_name(),
                    'price'        => $price,
                    'quantity'     => (int) $quantity,
                ),
            ),
        );
    }

    /**
     * Format dữ liệu đơn hàng cho TikTok Pixel & Events API (Purchase, CompletePayment, InitiateCheckout, PlaceAnOrder)
     */
    public static function format_tiktok_order_data($order) {
        if (!$order instanceof WC_Order) {
            return array();
        }

        $order_data  = self::format_order_data($order);
        $contents    = array();
        $content_ids = array();
        $names       = array();

        foreach ($order_data['items'] as $item) {
            $content_ids[] = (string) $item['item_id'];
            $names[]       = (string) $item['item_name'];
            $contents[]    = array(
                'content_id'   => (string) $item['item_id'],
                'content_type' => 'product',
                'content_name' => (string) $item['item_name'],
                'price'        => (float) $item['price'],
                'quantity'     => (int) $item['quantity'],
            );
        }
        $content_ids = array_values(array_unique($content_ids));

        return array(
            'content_id'   => !empty($content_ids) ? $content_ids[0] : '',
            'content_type' => 'product',
            'content_name' => !empty($names) ? implode(', ', $names) : '',
            'contents'     => $contents,
            'value'        => (float) $order_data['value'],
            'currency'     => $order_data['currency'],
            'num_items'    => (int) $order->get_item_count(),
            'order_id'     => (string) $order->get_order_number(),
        );
    }

    /**
     * Format dữ liệu user matching cho TikTok Events API (Server-side)
     * Yêu cầu email, phone_number, external_id băm SHA-256
     */
    public static function format_tiktok_user_data_for_api($order) {
        if (!$order instanceof WC_Order) {
            return array();
        }

        $user = array();

        // Email
        $email = self::strtolower_utf8($order->get_billing_email());
        if (!empty($email) && is_email($email)) {
            $user['email'] = hash('sha256', $email);
        }

        // Phone: Chuẩn E.164 (có dấu +) băm SHA-256
        $country    = trim(strtoupper((string) $order->get_billing_country())) ?: 'VN';
        $raw_phone  = (string) $order->get_billing_phone();
        $e164_phone = self::normalize_phone($raw_phone, $country, true);
        if (!empty($e164_phone)) {
            $hashed_phone = hash('sha256', $e164_phone);
            $user['phone']        = $hashed_phone;
            $user['phone_number'] = $hashed_phone;
        }

        // External ID
        $customer_id = $order->get_customer_id();
        if ($customer_id > 0) {
            $user['external_id'] = hash('sha256', (string) $customer_id);
        }

        $is_customer_request = !is_admin() && !wp_doing_cron() && (!defined('REST_REQUEST') || !REST_REQUEST);

        // IP & User Agent
        $saved_ip = (string) $order->get_meta('_woo_track_tiktok_ip');
        if (empty($saved_ip)) {
            $saved_ip = (string) $order->get_meta('_woo_track_meta_ip');
        }
        if (empty($saved_ip)) {
            $saved_ip = (string) $order->get_customer_ip_address();
        }
        $ip = !empty($saved_ip) ? $saved_ip : ($is_customer_request ? self::get_client_ip() : '');
        if (!empty($ip)) {
            $user['ip'] = $ip;
        }

        $saved_ua = (string) $order->get_meta('_woo_track_tiktok_ua');
        if (empty($saved_ua)) {
            $saved_ua = (string) $order->get_meta('_woo_track_meta_ua');
        }
        if (empty($saved_ua)) {
            $saved_ua = (string) $order->get_customer_user_agent();
        }
        $ua = !empty($saved_ua) ? $saved_ua : ($is_customer_request ? self::get_client_user_agent() : '');
        if (!empty($ua)) {
            $user['user_agent'] = $ua;
        }

        // Cookie ttp & ttclid
        $saved_ttp = (string) $order->get_meta('_woo_track_tiktok_ttp');
        $ttp = !empty($saved_ttp) ? $saved_ttp : ($is_customer_request ? self::get_ttp() : '');
        if (!empty($ttp)) {
            $user['ttp'] = $ttp;
        }

        $saved_ttclid = (string) $order->get_meta('_woo_track_tiktok_ttclid');
        $ttclid = !empty($saved_ttclid) ? $saved_ttclid : ($is_customer_request ? self::get_ttclid() : '');
        if (!empty($ttclid)) {
            $user['ttclid'] = $ttclid;
        }

        return $user;
    }

    /**
     * Format dữ liệu Advanced Matching cho TikTok Pixel trên trình duyệt (ttq.identify({...}))
     */
    public static function format_tiktok_advanced_matching_browser($order_or_user = null) {
        $data = array();

        if ($order_or_user instanceof WC_Order) {
            $email = self::strtolower_utf8($order_or_user->get_billing_email());
            $country = trim(strtoupper((string) $order_or_user->get_billing_country())) ?: 'VN';
            $e164_phone = self::normalize_phone($order_or_user->get_billing_phone(), $country, true);

            if (!empty($email) && is_email($email)) {
                $data['email'] = hash('sha256', $email);
            }
            if (!empty($e164_phone)) {
                $data['phone_number'] = hash('sha256', $e164_phone);
            }
            $customer_id = $order_or_user->get_customer_id();
            if ($customer_id > 0) {
                $data['external_id'] = hash('sha256', (string) $customer_id);
            }
        } elseif (is_user_logged_in()) {
            $current_user = wp_get_current_user();
            if ($current_user && $current_user->ID) {
                if (!empty($current_user->user_email)) {
                    $data['email'] = hash('sha256', self::strtolower_utf8($current_user->user_email));
                }
                $data['external_id'] = hash('sha256', (string) $current_user->ID);
            }
        }

        return $data;
    }

    /**
     * Kiểm tra Google Server-Side API (Data Manager API / Measurement Protocol) có đang bật và đủ cấu hình không
     */
    public static function is_google_server_api_enabled() {
        $enabled = self::get_setting('enable_google_server_api', 'no') === 'yes';
        if (!$enabled) {
            return false;
        }

        // Kiểm tra cấu hình Data Manager API (Service Account JSON)
        $sa_json = trim((string) self::get_setting('google_service_account_json', ''));
        $ga4_id  = trim((string) self::get_setting('ga4_id', ''));
        $ga4_prop_id = trim((string) self::get_setting('ga4_property_id', ''));
        $gads_cust_id = preg_replace('/[^0-9]/', '', (string) self::get_setting('gads_customer_id', ''));
        $gads_conv_id = preg_replace('/[^0-9]/', '', (string) self::get_setting('gads_conversion_action_id', ''));

        if (!empty($sa_json)) {
            if ((!empty($ga4_id) && !empty($ga4_prop_id)) || (!empty($gads_cust_id) && !empty($gads_conv_id))) {
                return true;
            }
        }

        // Kiểm tra cấu hình dự phòng GA4 Measurement Protocol (API Secret)
        $mp_secret = trim((string) self::get_setting('ga4_api_secret', ''));
        if (!empty($ga4_id) && !empty($mp_secret)) {
            return true;
        }

        return false;
    }

    /**
     * Trích xuất Google Analytics Client ID từ cookie _ga (Ví dụ: GA1.1.123456789.1729680000 -> 123456789.1729680000)
     */
    public static function get_ga_client_id() {
        if (!empty($_COOKIE['_ga'])) {
            $raw_ga = sanitize_text_field(wp_unslash($_COOKIE['_ga']));
            if (preg_match('/^(?:GA\d\.\d\.)?(\d+\.\d+)$/', $raw_ga, $matches)) {
                return $matches[1];
            }
            $parts = explode('.', $raw_ga);
            if (count($parts) >= 4) {
                return $parts[2] . '.' . $parts[3];
            }
        }
        return '';
    }

    /**
     * Lấy Google Click ID (gclid) từ URL, cookie WooTrack hoặc cookie _gcl_aw chuẩn của Google Tag
     */
    public static function get_gclid() {
        if (!empty($_GET['gclid'])) {
            return sanitize_text_field(wp_unslash($_GET['gclid']));
        }
        if (!empty($_COOKIE['_woo_track_gclid'])) {
            return sanitize_text_field(wp_unslash($_COOKIE['_woo_track_gclid']));
        }
        if (!empty($_COOKIE['_gcl_aw'])) {
            $gcl_aw = sanitize_text_field(wp_unslash($_COOKIE['_gcl_aw']));
            $parts  = explode('.', $gcl_aw);
            if (count($parts) >= 3) {
                return implode('.', array_slice($parts, 2));
            }
        }
        return '';
    }

    /**
     * Lấy gbraid (iOS App-to-Web Click ID) của Google Ads
     */
    public static function get_gbraid() {
        if (!empty($_GET['gbraid'])) {
            return sanitize_text_field(wp_unslash($_GET['gbraid']));
        }
        if (!empty($_COOKIE['_woo_track_gbraid'])) {
            return sanitize_text_field(wp_unslash($_COOKIE['_woo_track_gbraid']));
        }
        if (!empty($_COOKIE['_gcl_gb'])) {
            $gcl_gb = sanitize_text_field(wp_unslash($_COOKIE['_gcl_gb']));
            $parts  = explode('.', $gcl_gb);
            if (count($parts) >= 3) {
                return implode('.', array_slice($parts, 2));
            }
        }
        return '';
    }

    /**
     * Lấy wbraid (iOS Web-to-App Click ID) của Google Ads
     */
    public static function get_wbraid() {
        if (!empty($_GET['wbraid'])) {
            return sanitize_text_field(wp_unslash($_GET['wbraid']));
        }
        if (!empty($_COOKIE['_woo_track_wbraid'])) {
            return sanitize_text_field(wp_unslash($_COOKIE['_woo_track_wbraid']));
        }
        return '';
    }

    /**
     * Chuẩn hóa và băm SHA-256 (HEX) cho Email theo đúng đặc tả Google Data Manager API v1:
     * - Chuyển về chữ thường, cắt khoảng trắng
     * - Với gmail.com và googlemail.com: xóa dấu chấm (.) và phần hậu tố (+...) trước ký tự @
     */
    public static function hash_google_email($email) {
        $email = self::strtolower_utf8($email);
        if (empty($email) || !is_email($email)) {
            return '';
        }

        $parts = explode('@', $email, 2);
        if (count($parts) === 2) {
            $local  = $parts[0];
            $domain = $parts[1];
            if ($domain === 'gmail.com' || $domain === 'googlemail.com') {
                $local = preg_replace('/\+.*$/', '', $local);
                $local = str_replace('.', '', $local);
            }
            $email = $local . '@' . $domain;
        }

        return hash('sha256', $email);
    }

    /**
     * Tạo danh sách userIdentifiers chuẩn Google Data Manager API v1 (UserIdentifier[])
     */
    public static function format_google_data_manager_user_identifiers($order) {
        if (!$order instanceof WC_Order) {
            return array();
        }

        $identifiers = array();

        // 1. Email Address (SHA-256 HEX)
        $hashed_email = self::hash_google_email($order->get_billing_email());
        if (!empty($hashed_email)) {
            $identifiers[] = array(
                'emailAddress' => $hashed_email,
            );
        }

        // 2. Phone Number (E.164 có dấu +, SHA-256 HEX)
        $country    = strtoupper(trim((string) $order->get_billing_country())) ?: 'VN';
        $e164_phone = self::normalize_phone($order->get_billing_phone(), $country, true);
        if (!empty($e164_phone)) {
            $identifiers[] = array(
                'phoneNumber' => hash('sha256', $e164_phone),
            );
        }

        // 3. AddressInfo (Chỉ đính kèm khi có đầy đủ 4 trường bắt buộc: givenName, familyName, regionCode, postalCode để tránh lỗi fast-fail)
        $fn  = self::strtolower_utf8(preg_replace('/[\s\p{P}]/u', '', (string) $order->get_billing_first_name()));
        $ln  = self::strtolower_utf8(preg_replace('/[\s\p{P}]/u', '', (string) $order->get_billing_last_name()));
        $zip = trim((string) $order->get_billing_postcode());

        if (!empty($fn) && !empty($ln) && !empty($country) && !empty($zip)) {
            $identifiers[] = array(
                'address' => array(
                    'givenName'  => hash('sha256', $fn),
                    'familyName' => hash('sha256', $ln),
                    'regionCode' => $country,
                    'postalCode' => $zip,
                ),
            );
        }

        return $identifiers;
    }
}
