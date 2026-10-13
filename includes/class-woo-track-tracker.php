<?php
/**
 * WooTrack Event Tracker
 *
 * Chèn độc lập mã GTM, GA4, Google Ads và điều phối bắn sự kiện E-commerce
 */

if (!defined('ABSPATH')) {
    exit;
}

class WooTrack_Tracker {

    private $events = array();
    private $user_data = null;
    private $tracked_order_ids = array();

    public function __construct() {
        // 1. Chèn mã vào <head> và <body>
        add_action('wp_head', array($this, 'inject_head_tags'), 1);
        add_action('wp_body_open', array($this, 'inject_body_tags'), 1);

        // 2. Enqueue script & localized data
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));

        // 3. Hook các sự kiện theo vòng đời trang
        add_action('wp', array($this, 'detect_page_events'));

        // 4. Gắn dữ liệu tracking vào nút Add to cart trên loop (Shop/Archive)
        add_filter('woocommerce_loop_add_to_cart_link', array($this, 'add_tracking_data_to_loop_button'), 10, 3);

        // 5. Ghi nhận sự kiện khi đơn hàng hoàn tất (Trang Thank You qua hook chuẩn)
        add_action('woocommerce_thankyou', array($this, 'track_purchase_event'), 10, 1);

        // 6. In dataLayer payload vào footer (priority 15 chạy TRƯỚC priority 20 của scripts)
        add_action('wp_footer', array($this, 'render_footer_events'), 15);
    }

    /**
     * Chèn mã GTM và Thẻ Google (gtag.js) độc lập vào <head>
     */
    public function inject_head_tags() {
        $gtm_id   = WooTrack_Helper::get_setting('gtm_id', '');
        $ga4_id   = WooTrack_Helper::get_setting('ga4_id', '');
        $gads_id  = WooTrack_Helper::get_setting('gads_id', '');
        $enhanced = WooTrack_Helper::get_setting('enable_enhanced_conversions', 'no') === 'yes';

        // 1. Chèn Google Tag Manager (GTM)
        if (!empty($gtm_id)) : ?>
<!-- Google Tag Manager (WooTrack) -->
<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
})(window,document,'script','dataLayer','<?php echo esc_js($gtm_id); ?>');</script>
<!-- End Google Tag Manager -->
        <?php endif;

        // 2. Chèn Thẻ Google (gtag.js) cho GA4 và/hoặc Google Ads nếu có cấu hình
        if (!empty($ga4_id) || !empty($gads_id)) :
            $primary_id = !empty($ga4_id) ? $ga4_id : $gads_id;
            ?>
<!-- Google tag (gtag.js) (WooTrack) -->
<script async src="https://www.googletagmanager.com/gtag/js?id=<?php echo esc_attr($primary_id); ?>"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());

  <?php if (!empty($ga4_id)) : ?>
  gtag('config', '<?php echo esc_js($ga4_id); ?>');
  <?php endif; ?>

  <?php if (!empty($gads_id)) : ?>
  gtag('config', '<?php echo esc_js($gads_id); ?>', <?php echo $enhanced ? "{'allow_enhanced_conversions': true}" : '{}'; ?>);
  <?php endif; ?>
</script>
<!-- End Google tag -->
        <?php endif;
    }

    /**
     * Chèn thẻ GTM <noscript> ngay sau thẻ mở <body>
     */
    public function inject_body_tags() {
        $gtm_id = WooTrack_Helper::get_setting('gtm_id', '');
        if (!empty($gtm_id)) : ?>
<!-- Google Tag Manager (noscript) (WooTrack) -->
<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=<?php echo esc_attr($gtm_id); ?>"
height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
<!-- End Google Tag Manager (noscript) -->
        <?php endif;
    }

    /**
     * Tải script theo dõi Frontend
     */
    public function enqueue_scripts() {
        if (!is_woocommerce() && !is_cart() && !is_checkout()) {
            return;
        }

        wp_enqueue_script(
            'woo-track-script',
            WOO_TRACK_URL . 'assets/js/woo-track.js',
            array('jquery'),
            WOO_TRACK_VERSION,
            true
        );

        $config = array(
            'gtm_id'                      => WooTrack_Helper::get_setting('gtm_id', ''),
            'ga4_id'                      => WooTrack_Helper::get_setting('ga4_id', ''),
            'gads_id'                     => WooTrack_Helper::get_setting('gads_id', ''),
            'purchase_label'              => WooTrack_Helper::get_setting('purchase_label', ''),
            'begin_checkout_label'        => WooTrack_Helper::get_setting('begin_checkout_label', ''),
            'add_to_cart_label'           => WooTrack_Helper::get_setting('add_to_cart_label', ''),
            'currency'                    => get_woocommerce_currency(),
            'debug_mode'                  => WooTrack_Helper::get_setting('debug_mode', 'no') === 'yes',
            'product_identifier'          => WooTrack_Helper::get_setting('product_identifier', 'id'),
            'enable_enhanced_conversions' => WooTrack_Helper::get_setting('enable_enhanced_conversions', 'no') === 'yes',
        );

        wp_localize_script('woo-track-script', 'wooTrackConfig', $config);
    }

    /**
     * Tự động phát hiện loại trang và chuẩn bị sự kiện tương ứng
     */
    public function detect_page_events() {
        if (is_admin()) {
            return;
        }

        // 1. Sự kiện view_item (Trang chi tiết sản phẩm)
        if (is_product() && WooTrack_Helper::is_event_enabled('view_item')) {
            $this->prepare_view_item_event();
        }

        // 2. Sự kiện view_item_list (Trang danh sách / Shop / Danh mục)
        if ((is_shop() || is_product_taxonomy()) && WooTrack_Helper::is_event_enabled('view_item_list')) {
            $this->prepare_view_item_list_event();
        }

        // 3. Sự kiện begin_checkout (Trang thanh toán, không bao gồm trang nhận đơn)
        if (is_checkout() && !is_order_received_page() && WooTrack_Helper::is_event_enabled('begin_checkout')) {
            $this->prepare_begin_checkout_event();
        }

        // 4. Sự kiện purchase (Trang nhận đơn hàng / Cảm ơn)
        if (is_checkout() && is_order_received_page() && WooTrack_Helper::is_event_enabled('purchase')) {
            global $wp;
            $order_id = 0;
            if (isset($wp->query_vars['order-received'])) {
                $order_id = absint($wp->query_vars['order-received']);
            } elseif (isset($_GET['order-received'])) {
                $order_id = absint($_GET['order-received']);
            }
            if ($order_id > 0) {
                $this->track_purchase_event($order_id);
            }
        }
    }

    /**
     * Chuẩn bị dữ liệu cho view_item
     */
    private function prepare_view_item_event() {
        global $post;
        if (!$post) return;

        $product = wc_get_product($post->ID);
        if (!$product) return;

        $item_data = WooTrack_Helper::format_product_item($product, 1, 1);

        $this->events[] = array(
            'event' => 'view_item',
            'data'  => array(
                'currency' => get_woocommerce_currency(),
                'value'    => $item_data['price'],
                'items'    => array($item_data)
            )
        );
    }

    /**
     * Chuẩn bị dữ liệu cho view_item_list
     */
    private function prepare_view_item_list_event() {
        global $wp_query;

        $list_name = 'Cửa hàng';
        $list_id   = 'shop';

        if (is_product_category()) {
            $current_cat = get_queried_object();
            if ($current_cat) {
                $list_name = $current_cat->name;
                $list_id   = 'cat_' . $current_cat->term_id;
            }
        } elseif (is_product_tag()) {
            $current_tag = get_queried_object();
            if ($current_tag) {
                $list_name = $current_tag->name;
                $list_id   = 'tag_' . $current_tag->term_id;
            }
        }

        $items = array();
        $index = 1;

        if (!empty($wp_query->posts)) {
            $posts_to_track = array_slice($wp_query->posts, 0, 12);
            foreach ($posts_to_track as $p) {
                $product = wc_get_product($p->ID);
                if ($product) {
                    $items[] = WooTrack_Helper::format_product_item($product, 1, $index);
                    $index++;
                }
            }
        }

        if (!empty($items)) {
            $this->events[] = array(
                'event' => 'view_item_list',
                'data'  => array(
                    'item_list_id'   => $list_id,
                    'item_list_name' => $list_name,
                    'items'          => $items
                )
            );
        }
    }

    /**
     * Chuẩn bị dữ liệu cho begin_checkout
     */
    private function prepare_begin_checkout_event() {
        if (!WC()->cart || WC()->cart->is_empty()) {
            return;
        }

        $items = array();
        $index = 1;

        foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
            $product = $cart_item['data'];
            if ($product) {
                $item_data = WooTrack_Helper::format_product_item($product, $cart_item['quantity'], $index);
                $items[] = $item_data;
                $index++;
            }
        }

        $checkout_total = (float) WC()->cart->get_total('edit');
        $currency       = get_woocommerce_currency();

        $this->events[] = array(
            'event' => 'begin_checkout',
            'data'  => array(
                'currency' => $currency,
                'value'    => $checkout_total,
                'items'    => $items
            )
        );

        // Bắn chuyển đổi phụ cho Google Ads nếu có cấu hình nhãn Begin Checkout
        $gads_id              = WooTrack_Helper::get_setting('gads_id', '');
        $begin_checkout_label = WooTrack_Helper::get_setting('begin_checkout_label', '');

        if (!empty($gads_id) && !empty($begin_checkout_label)) {
            $conversion_send_to = rtrim($gads_id, '/') . '/' . ltrim($begin_checkout_label, '/');
            $this->events[] = array(
                'event' => 'conversion',
                'data'  => array(
                    'send_to'  => $conversion_send_to,
                    'value'    => $checkout_total,
                    'currency' => $currency
                )
            );
        }
    }

    /**
     * Gắn dữ liệu JSON vào nút thêm vào giỏ hàng trên loop (Hỗ trợ AJAX add_to_cart)
     */
    public function add_tracking_data_to_loop_button($html, $product, $args = array()) {
        if (!WooTrack_Helper::is_event_enabled('add_to_cart') || !$product instanceof WC_Product) {
            return $html;
        }

        $item_data = WooTrack_Helper::format_product_item($product, 1, 1);
        $json_data = esc_attr(wp_json_encode($item_data));

        return preg_replace('/(<a\b[^>]*)(class=["\'][^"\']*["\'])/i', '$1 data-woo-track-item="' . $json_data . '" $2', $html);
    }

    /**
     * Ghi nhận sự kiện purchase trên trang Cảm ơn (Thank You page)
     */
    public function track_purchase_event($order_id) {
        if (!WooTrack_Helper::is_event_enabled('purchase') || empty($order_id)) {
            return;
        }

        // Tránh chạy lặp lại trong cùng 1 request
        if (in_array($order_id, $this->tracked_order_ids, true)) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $this->tracked_order_ids[] = $order_id;
        $is_debug = WooTrack_Helper::get_setting('debug_mode', 'no') === 'yes';

        // Kiểm tra xem đơn hàng này đã từng được ghi nhận sự kiện chuyển đổi chưa
        $already_tracked = $order->get_meta('_woo_track_purchase_tracked');
        if (!empty($already_tracked)) {
            if ($is_debug) {
                $this->events[] = array(
                    'event' => 'purchase_duplicate_blocked',
                    'data'  => array(
                        'order_id'   => $order_id,
                        'tracked_at' => $already_tracked,
                        'note'       => 'Đơn hàng #' . $order_id . ' đã từng được track lúc ' . $already_tracked . '. Cơ chế chống trùng lặp đã chặn không gửi lại đơn này.'
                    )
                );
            }
            return;
        }

        // Lấy dữ liệu đơn hàng định dạng chuẩn
        $order_data = WooTrack_Helper::format_order_data($order);

        // Sự kiện purchase cho GA4 & Google Ads chung
        $this->events[] = array(
            'event' => 'purchase',
            'data'  => $order_data
        );

        // Sự kiện conversion cho Google Ads (nếu có cấu hình Ads ID & Label)
        $gads_id        = WooTrack_Helper::get_setting('gads_id', '');
        $purchase_label = WooTrack_Helper::get_setting('purchase_label', '');

        if (!empty($gads_id) && !empty($purchase_label)) {
            $conversion_send_to = rtrim($gads_id, '/') . '/' . ltrim($purchase_label, '/');
            $this->events[] = array(
                'event' => 'conversion',
                'data'  => array(
                    'send_to'        => $conversion_send_to,
                    'value'          => $order_data['value'],
                    'currency'       => $order_data['currency'],
                    'transaction_id' => $order_data['transaction_id']
                )
            );
        }

        // Dữ liệu người dùng nâng cao (Enhanced Conversions)
        if (WooTrack_Helper::get_setting('enable_enhanced_conversions', 'no') === 'yes') {
            $this->user_data = WooTrack_Helper::format_user_data_for_ads($order);
        }

        // Đánh dấu đơn hàng này đã được ghi nhận
        $order->update_meta_data('_woo_track_purchase_tracked', current_time('mysql'));
        $order->save();
    }

    /**
     * Xuất các sự kiện đã chuẩn bị ra footer dưới dạng JS data object
     */
    public function render_footer_events() {
        $single_product_data = null;
        if (is_product() && WooTrack_Helper::is_event_enabled('add_to_cart')) {
            global $post;
            if ($post) {
                $current_prod = wc_get_product($post->ID);
                if ($current_prod) {
                    $single_product_data = WooTrack_Helper::format_product_item($current_prod, 1, 1);
                }
            }
        }

        $payload = array(
            'events'         => $this->events,
            'user_data'      => $this->user_data,
            'single_product' => $single_product_data
        );

        ?>
        <script type="text/javascript" id="woo-track-initial-data">
            window.wooTrackPayload = <?php echo wp_json_encode($payload); ?>;
        </script>
        <?php
    }
}
