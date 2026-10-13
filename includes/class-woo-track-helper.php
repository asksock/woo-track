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
     * Lấy cài đặt theo key
     */
    public static function get_setting($key, $default = '') {
        $options = get_option('woo_track_settings', array());
        return isset($options[$key]) ? $options[$key] : $default;
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
     * Lấy ID sản phẩm dựa trên cài đặt (Post ID hay SKU)
     * Thích hợp với Google Merchant Center (GMC) cho Dynamic Remarketing
     */
    public static function get_product_identifier($product) {
        if (!$product instanceof WC_Product) {
            return '';
        }

        $identifier_type = self::get_setting('product_identifier', 'id');

        if ($identifier_type === 'sku') {
            $sku = $product->get_sku();
            if (!empty($sku)) {
                return $sku;
            }
        }

        return (string) $product->get_id();
    }

    /**
     * Format dữ liệu 1 sản phẩm theo chuẩn GA4 / Google Ads Items
     */
    public static function format_product_item($product, $quantity = 1, $index = 1) {
        if (!$product instanceof WC_Product) {
            return array();
        }

        $categories = wc_get_product_terms($product->get_id(), 'product_cat', array('fields' => 'names'));
        $category = !empty($categories) && !is_wp_error($categories) ? $categories[0] : '';

        $price = (float) wc_get_price_to_display($product);

        $item = array(
            'item_id'       => self::get_product_identifier($product),
            'item_name'     => $product->get_name(),
            'price'         => round($price, 2),
            'quantity'      => (int) $quantity,
            'index'         => (int) $index,
        );

        if (!empty($category)) {
            $item['item_category'] = $category;
        }

        // Nếu là biến thể sản phẩm
        if ($product->is_type('variation')) {
            $parent_id = $product->get_parent_id();
            $item['item_variant'] = implode(', ', $product->get_attributes());
        }

        return $item;
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
            'coupon'         => !empty($coupons) ? implode(', ', $coupons) : '',
            'items'          => $items,
        );
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

        $email = trim(strtolower($order->get_billing_email()));
        $raw_phone = preg_replace('/[^0-9+]/', '', $order->get_billing_phone());

        $user_data = array();

        if (!empty($email) && is_email($email)) {
            $user_data['email'] = $email;
        }

        if (!empty($raw_phone)) {
            // Chuẩn hóa số điện thoại theo chuẩn quốc tế E.164 của Google
            $country = strtoupper($order->get_billing_country());
            $e164_phone = $raw_phone;

            if (strpos($e164_phone, '+') !== 0) {
                if (substr($e164_phone, 0, 1) === '0') {
                    // Mặc định số VN nếu quốc gia là VN hoặc để trống
                    $prefix = ($country === 'VN' || empty($country)) ? '+84' : '+84';
                    $e164_phone = $prefix . substr($e164_phone, 1);
                } else {
                    $e164_phone = '+84' . $e164_phone;
                }
            }
            $user_data['phone_number'] = $e164_phone;
        }

        $first_name = trim($order->get_billing_first_name());
        $last_name  = trim($order->get_billing_last_name());
        $street     = trim($order->get_billing_address_1());
        $city       = trim($order->get_billing_city());
        $state      = trim($order->get_billing_state());
        $country    = trim(strtoupper($order->get_billing_country()));
        $postcode   = trim($order->get_billing_postcode());

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
}
