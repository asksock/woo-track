<?php
/**
 * WooTrack Product Catalog Feed Generator (Meta Catalog & Google Merchant Center)
 *
 * Xuất nguồn cấp dữ liệu sản phẩm (XML RSS 2.0 & CSV) siêu nhẹ theo chuẩn mới nhất của Meta & Google
 * Tự động đồng bộ 100% định danh sản phẩm (parent_id / id / sku) với Pixel, CAPI, GA4 và Google Ads
 */

if (!defined('ABSPATH')) {
    exit;
}

class WooTrack_Feed {

    /**
     * Khóa lưu bộ nhớ đệm (Transient) cho Feed
     */
    const CACHE_PREFIX = 'woo_track_feed_cache_';

    /**
     * Thời gian lưu cache mặc định (6 giờ) - Tự động xóa ngay lập tức khi cập nhật sản phẩm
     */
    const CACHE_TTL = 21600;

    /**
     * Khởi tạo các hook cho Product Feed
     */
    public static function init() {
        // Bắt request xuất Feed sớm ở template_redirect
        add_action('template_redirect', array(__CLASS__, 'handle_feed_request'), 1);

        // Tự động xóa cache Feed ngay khi Admin thêm/sửa/xóa sản phẩm hoặc đổi cài đặt WooTrack (Realtime Update)
        add_action('save_post_product', array(__CLASS__, 'clear_feed_cache'));
        add_action('woocommerce_update_product', array(__CLASS__, 'clear_feed_cache'));
        add_action('woocommerce_new_product', array(__CLASS__, 'clear_feed_cache'));
        add_action('woocommerce_delete_product', array(__CLASS__, 'clear_feed_cache'));
        add_action('woocommerce_trash_product', array(__CLASS__, 'clear_feed_cache'));
        add_action('update_option_woo_track_settings', array(__CLASS__, 'clear_feed_cache'));
    }

    /**
     * Lấy URL Feed theo nền tảng (meta / google) và định dạng (xml / csv)
     */
    public static function get_feed_url($platform = 'meta', $format = 'xml') {
        $args = array(
            'woo_track_feed' => $platform,
        );
        if ($format === 'csv') {
            $args['format'] = 'csv';
        }
        return add_query_arg($args, home_url('/'));
    }

    /**
     * Xóa toàn bộ bộ nhớ đệm Feed khi có thay đổi sản phẩm hoặc cấu hình
     */
    public static function clear_feed_cache() {
        $platforms = array('meta', 'google', 'tiktok');
        $formats   = array('xml', 'csv');
        $modes     = array('parent_id', 'id', 'sku');

        foreach ($platforms as $p) {
            foreach ($formats as $f) {
                foreach ($modes as $m) {
                    delete_transient(self::CACHE_PREFIX . "{$p}_{$f}_{$m}");
                }
            }
        }
    }

    /**
     * Xử lý yêu cầu truy cập URL Feed (?woo_track_feed=meta, google hoặc tiktok)
     */
    public static function handle_feed_request() {
        if (empty($_GET['woo_track_feed'])) {
            return;
        }

        // Kiểm tra xem tính năng Feed có được bật không
        if (WooTrack_Helper::get_setting('enable_product_feed', 'yes') !== 'yes') {
            status_header(403);
            exit('WooTrack Product Feed is disabled.');
        }

        $platform = strtolower(sanitize_text_field(wp_unslash($_GET['woo_track_feed'])));
        if (!in_array($platform, array('meta', 'google', 'tiktok'), true)) {
            return;
        }

        $format = isset($_GET['format']) ? strtolower(sanitize_text_field(wp_unslash($_GET['format']))) : 'xml';
        if (!in_array($format, array('xml', 'csv'), true)) {
            $format = 'xml';
        }

        $force_refresh   = !empty($_GET['nocache']);
        $identifier_mode = WooTrack_Helper::get_setting('product_identifier', 'parent_id');
        $cache_key       = self::CACHE_PREFIX . "{$platform}_{$format}_{$identifier_mode}";

        // Ngăn LiteSpeed Cache / Cloudflare ép kiểu HTML lên trang XML/CSV
        if (!headers_sent()) {
            header('X-LiteSpeed-Cache-Control: no-cache');
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('X-Robots-Tag: noindex, nofollow');

            if ($format === 'csv') {
                header('Content-Type: text/csv; charset=UTF-8');
                header('Content-Disposition: inline; filename="woo-track-' . $platform . '-feed.csv"');
            } else {
                header('Content-Type: application/xml; charset=UTF-8');
            }
        }

        // Lấy từ bộ nhớ đệm nếu có (0 truy vấn SQL khi Google/Meta quét định kỳ mà sản phẩm không đổi)
        if (!$force_refresh) {
            $cached_output = get_transient($cache_key);
            if ($cached_output !== false && !empty($cached_output)) {
                echo $cached_output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                exit;
            }
        }

        $items  = self::collect_feed_items($platform, $identifier_mode);
        $output = ($format === 'csv')
            ? self::generate_csv_feed($items, $platform)
            : self::generate_xml_feed($items, $platform);

        set_transient($cache_key, $output, self::CACHE_TTL);

        echo $output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }

    /**
     * Thu thập danh sách sản phẩm chuẩn hóa theo nền tảng (Meta hoặc Google) và chế độ định danh
     */
    private static function collect_feed_items($platform, $identifier_mode) {
        $feed_items = array();
        $currency   = get_woocommerce_currency();
        $brand_name = wp_strip_all_tags(get_bloginfo('name'));

        $query_args = array(
            'status' => 'publish',
            'limit'  => -1,
            'orderby' => 'ID',
            'order'   => 'ASC',
        );

        $products = wc_get_products($query_args);
        if (empty($products)) {
            return $feed_items;
        }

        foreach ($products as $product) {
            if (!$product instanceof WC_Product || !$product->is_visible()) {
                continue;
            }

            // Nếu người dùng chọn chế độ 'id' (tách từng biến thể riêng biệt) và đây là sản phẩm biến thể
            if ($identifier_mode === 'id' && $product->is_type('variable')) {
                $variations = $product->get_available_variations('objects');
                if (!empty($variations)) {
                    foreach ($variations as $variation) {
                        if ($variation instanceof WC_Product_Variation && $variation->is_purchasable()) {
                            $row = self::build_single_item_row($variation, $product, $platform, $currency, $brand_name);
                            if (!empty($row)) {
                                $feed_items[] = $row;
                            }
                        }
                    }
                    continue;
                }
            }

            // Mặc định ('parent_id' hoặc 'sku' hoặc sản phẩm đơn): Xuất theo sản phẩm chính, khớp 100% với Pixel & CAPI
            $row = self::build_single_item_row($product, null, $platform, $currency, $brand_name);
            if (!empty($row)) {
                $feed_items[] = $row;
            }
        }

        return $feed_items;
    }

    /**
     * Xây dựng dữ liệu chi tiết cho 1 dòng sản phẩm trong Feed
     *
     * @param WC_Product $product Sản phẩm hoặc biến thể
     * @param WC_Product|null $parent_product Sản phẩm cha (nếu $product là biến thể)
     * @param string $platform 'meta' hoặc 'google'
     * @param string $currency Mã tiền tệ (VND, USD...)
     * @param string $brand_name Tên thương hiệu mặc định
     * @return array
     */
    private static function build_single_item_row($product, $parent_product, $platform, $currency, $brand_name) {
        $item_id = WooTrack_Helper::get_product_identifier($product);
        if (empty($item_id)) {
            return array();
        }

        $base_product = $parent_product instanceof WC_Product ? $parent_product : $product;
        $group_id     = (string) $base_product->get_id();

        // Tiêu đề sản phẩm
        $title = wp_strip_all_tags((string) $product->get_name());
        if (empty($title)) {
            $title = wp_strip_all_tags((string) $base_product->get_title());
        }

        // Mô tả sản phẩm (ưu tiên mô tả ngắn -> mô tả chi tiết -> tiêu đề)
        $raw_desc = $product->get_short_description();
        if (empty($raw_desc)) {
            $raw_desc = $product->get_description();
        }
        if (empty($raw_desc) && $parent_product) {
            $raw_desc = $parent_product->get_short_description() ?: $parent_product->get_description();
        }
        $description = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags(strip_shortcodes((string) $raw_desc))));
        if (empty($description)) {
            $description = $title;
        }
        if (function_exists('mb_substr')) {
            $description = mb_substr($description, 0, 4900, 'UTF-8');
        } else {
            $description = substr($description, 0, 4900);
        }

        // Đường dẫn sản phẩm
        $link = $product->get_permalink();

        // Hình ảnh đại diện & Hình ảnh phụ
        $image_id = $product->get_image_id();
        if (!$image_id && $parent_product) {
            $image_id = $parent_product->get_image_id();
        }
        $image_link = $image_id ? wp_get_attachment_image_url($image_id, 'full') : wc_placeholder_img_src('full');

        $gallery_ids = $base_product->get_gallery_image_ids();
        $additional_image = '';
        if (!empty($gallery_ids) && is_array($gallery_ids)) {
            $first_gal_url = wp_get_attachment_image_url($gallery_ids[0], 'full');
            if ($first_gal_url) {
                $additional_image = $first_gal_url;
            }
        }

        // Xử lý giá gốc (regular_price) và giá khuyến mãi (sale_price) cho cả sản phẩm đơn lẫn sản phẩm nhiều gói biến thể
        if ($product->is_type('variable') && $product instanceof WC_Product_Variable) {
            $regular_price = (float) $product->get_variation_regular_price('min', true);
            $sale_price    = (float) $product->get_variation_sale_price('min', true);
            $active_price  = (float) $product->get_variation_price('min', true);
        } else {
            $regular_price = (float) wc_get_price_to_display($product, array('price' => $product->get_regular_price()));
            $sale_price    = $product->is_on_sale() ? (float) wc_get_price_to_display($product, array('price' => $product->get_sale_price())) : 0.0;
            $active_price  = (float) wc_get_price_to_display($product);
        }

        if ($regular_price <= 0 && $active_price > 0) {
            $regular_price = $active_price;
        }

        $formatted_price      = self::format_feed_price($regular_price, $currency);
        $formatted_sale_price = ($sale_price > 0 && $sale_price < $regular_price)
            ? self::format_feed_price($sale_price, $currency)
            : '';

        // Trạng thái tồn kho: Google yêu cầu "in_stock" / "out_of_stock" (dấu gạch dưới), Meta & TikTok yêu cầu "in stock" / "out of stock" (có dấu cách)
        $in_stock = $product->is_in_stock();
        if ($platform === 'google') {
            $availability = $in_stock ? 'in_stock' : 'out_of_stock';
        } else {
            $availability = $in_stock ? 'in stock' : 'out of stock';
        }

        // Danh mục sản phẩm (product_type & google_product_category)
        $categories              = wc_get_product_terms($base_product->get_id(), 'product_cat', array('fields' => 'names'));
        $product_type            = (!empty($categories) && !is_wp_error($categories)) ? implode(' > ', $categories) : 'Software > Extensions';
        $google_product_category = (!empty($categories) && !is_wp_error($categories)) ? implode(' > ', $categories) : 'Software > Computer Software';

        $sku = (string) $product->get_sku();

        return array(
            'id'                      => (string) $item_id,
            'item_group_id'           => $group_id,
            'title'                   => $title,
            'description'             => $description,
            'link'                    => $link,
            'image_link'              => (string) $image_link,
            'additional_image_link'   => (string) $additional_image,
            'availability'            => $availability,
            'condition'               => 'new',
            'price'                   => $formatted_price,
            'sale_price'              => $formatted_sale_price,
            'brand'                   => $brand_name,
            'product_type'            => $product_type,
            'google_product_category' => $google_product_category,
            'mpn'                     => !empty($sku) ? $sku : (string) $item_id,
            'identifier_exists'       => 'no',
        );
    }

    /**
     * Format giá kèm mã tiền tệ chuẩn ISO 4217 (Ví dụ: 350000 VND hoặc 29.99 USD)
     */
    private static function format_feed_price($amount, $currency) {
        $no_decimal_currencies = array('VND', 'JPY', 'KRW', 'IDR', 'CLP', 'VUV');
        if (in_array(strtoupper($currency), $no_decimal_currencies, true)) {
            return number_format((float) $amount, 0, '.', '') . ' ' . strtoupper($currency);
        }
        return number_format((float) $amount, 2, '.', '') . ' ' . strtoupper($currency);
    }

    /**
     * Bọc chuỗi an toàn cho thẻ XML
     */
    private static function xml_cdata($str) {
        $str = (string) $str;
        $str = preg_replace('/[^\x09\x0A\x0D\x20-\x{D7FF}\x{E000}-\x{FFFD}]+/u', ' ', $str);
        return '<![CDATA[' . str_replace(']]>', ']]]]><![CDATA[>', (string) $str) . ']]>';
    }

    /**
     * Tạo nội dung XML Feed (RSS 2.0 với namespace g: chuẩn Google Merchant Center & Meta Catalog)
     */
    private static function generate_xml_feed($items, $platform) {
        $site_title = wp_strip_all_tags(get_bloginfo('name'));
        $site_url   = home_url('/');
        $site_desc  = wp_strip_all_tags(get_bloginfo('description')) ?: $site_title;

        $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0">' . "\n";
        $xml .= "  <channel>\n";
        $xml .= '    <title>' . self::xml_cdata($site_title . ' - ' . strtoupper($platform) . ' Product Feed') . "</title>\n";
        $xml .= '    <link>' . esc_url($site_url) . "</link>\n";
        $xml .= '    <description>' . self::xml_cdata($site_desc) . "</description>\n";

        foreach ($items as $item) {
            $xml .= "    <item>\n";
            $xml .= '      <g:id>' . esc_xml($item['id']) . "</g:id>\n";
            $xml .= '      <g:item_group_id>' . esc_xml($item['item_group_id']) . "</g:item_group_id>\n";
            $xml .= '      <g:title>' . self::xml_cdata($item['title']) . "</g:title>\n";
            $xml .= '      <g:description>' . self::xml_cdata($item['description']) . "</g:description>\n";
            $xml .= '      <g:link>' . esc_url($item['link']) . "</g:link>\n";
            $xml .= '      <g:image_link>' . esc_url($item['image_link']) . "</g:image_link>\n";

            if (!empty($item['additional_image_link'])) {
                $xml .= '      <g:additional_image_link>' . esc_url($item['additional_image_link']) . "</g:additional_image_link>\n";
            }

            $xml .= '      <g:availability>' . esc_xml($item['availability']) . "</g:availability>\n";
            $xml .= '      <g:condition>' . esc_xml($item['condition']) . "</g:condition>\n";
            $xml .= '      <g:price>' . esc_xml($item['price']) . "</g:price>\n";

            if (!empty($item['sale_price'])) {
                $xml .= '      <g:sale_price>' . esc_xml($item['sale_price']) . "</g:sale_price>\n";
            }

            $xml .= '      <g:brand>' . self::xml_cdata($item['brand']) . "</g:brand>\n";
            $xml .= '      <g:product_type>' . self::xml_cdata($item['product_type']) . "</g:product_type>\n";

            if (!empty($item['google_product_category'])) {
                $xml .= '      <g:google_product_category>' . self::xml_cdata($item['google_product_category']) . "</g:google_product_category>\n";
            }

            $xml .= '      <g:mpn>' . esc_xml($item['mpn']) . "</g:mpn>\n";

            if ($platform === 'google') {
                $xml .= '      <g:identifier_exists>' . esc_xml($item['identifier_exists']) . "</g:identifier_exists>\n";
            }

            $xml .= "    </item>\n";
        }

        $xml .= "  </channel>\n";
        $xml .= "</rss>\n";

        return $xml;
    }

    /**
     * Tạo nội dung CSV Feed chuẩn cho Meta Catalog, Google Merchant Center & TikTok Catalog
     */
    private static function generate_csv_feed($items, $platform) {
        $id_column = ($platform === 'tiktok') ? 'sku_id' : 'id';

        $headers = array(
            $id_column,
            'item_group_id',
            'title',
            'description',
            'availability',
            'condition',
            'price',
            'sale_price',
            'link',
            'image_link',
            'additional_image_link',
            'brand',
            'product_type',
            'google_product_category',
            'mpn',
        );

        if ($platform === 'google') {
            $headers[] = 'identifier_exists';
        }

        $fh = fopen('php://temp', 'r+');
        // Thêm UTF-8 BOM để hiển thị đúng tiếng Việt trên Excel nếu tải về kiểm tra
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, $headers);

        foreach ($items as $item) {
            $row = array(
                $item['id'],
                $item['item_group_id'],
                $item['title'],
                $item['description'],
                $item['availability'],
                $item['condition'],
                $item['price'],
                $item['sale_price'],
                $item['link'],
                $item['image_link'],
                $item['additional_image_link'],
                $item['brand'],
                $item['product_type'],
                $item['google_product_category'],
                $item['mpn'],
            );
            if ($platform === 'google') {
                $row[] = $item['identifier_exists'];
            }
            fputcsv($fh, $row);
        }

        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);

        return (string) $csv;
    }
}
