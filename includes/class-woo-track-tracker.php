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
    private $meta_events = array();
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

        // 5. Bắt sự kiện thêm vào giỏ hàng từ server (cả form submit thường lẫn AJAX)
        add_action('woocommerce_add_to_cart', array($this, 'on_woocommerce_add_to_cart'), 10, 6);
        add_filter('woocommerce_add_to_cart_fragments', array($this, 'add_tracking_to_fragments'), 10, 1);

        // 6. Ghi nhận sự kiện khi đơn hàng hoàn tất (Trang Thank You qua hook chuẩn)
        add_action('woocommerce_thankyou', array($this, 'track_purchase_event'), 10, 1);

        // 7. In dataLayer payload vào footer (priority 15 chạy TRƯỚC priority 20 của scripts)
        add_action('wp_footer', array($this, 'render_footer_events'), 15);
    }

    /**
     * Chèn mã GTM, Thẻ Google (gtag.js) và Meta Pixel độc lập vào <head>
     */
    public function inject_head_tags() {
        $gtm_id          = WooTrack_Helper::get_setting('gtm_id', '');
        $ga4_id          = WooTrack_Helper::get_setting('ga4_id', '');
        $gads_id         = WooTrack_Helper::get_setting('gads_id', '');
        $enhanced        = WooTrack_Helper::get_setting('enable_enhanced_conversions', 'no') === 'yes';
        $consent_mode_v2 = WooTrack_Helper::get_setting('enable_consent_mode_v2', 'yes') === 'yes';

        // 0. Khởi tạo tín hiệu Google Consent Mode v2 trước khi nạp GTM / gtag.js
        if ($consent_mode_v2 && (!empty($gtm_id) || !empty($ga4_id) || !empty($gads_id))) : ?>
<!-- Google Consent Mode v2 (WooTrack) -->
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('consent', 'default', {
    'ad_storage': 'granted',
    'ad_user_data': 'granted',
    'ad_personalization': 'granted',
    'analytics_storage': 'granted'
  });
</script>
<!-- End Google Consent Mode v2 -->
        <?php endif;

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

        // 3. Chèn Meta Pixel Base Code
        if (WooTrack_Helper::is_meta_enabled()) :
            $meta_pixel_id = WooTrack_Helper::get_setting('meta_pixel_id', '');
            $adv_matching  = WooTrack_Helper::get_setting('enable_meta_advanced_matching', 'yes') === 'yes';

            $current_order = null;
            if (is_checkout() && is_order_received_page()) {
                $current_order = WooTrack_Helper::get_valid_thankyou_order();
            }

            $user_init_data = $adv_matching ? WooTrack_Helper::format_meta_advanced_matching_browser($current_order) : array();
            ?>
<!-- Meta Pixel Code (WooTrack) -->
<script>
!function(f,b,e,v,n,t,s)
{if(f.fbq)return;n=f.fbq=function(){n.callMethod?
n.callMethod.apply(n,arguments):n.queue.push(arguments)};
if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
n.queue=[];t=b.createElement(e);t.async=!0;
t.src=v;s=b.getElementsByTagName(e)[0];
s.parentNode.insertBefore(t,s)}(window, document,'script',
'https://connect.facebook.net/en_US/fbevents.js');
fbq('init', '<?php echo esc_js($meta_pixel_id); ?>'<?php echo !empty($user_init_data) ? ', ' . wp_json_encode($user_init_data) : ''; ?>);
fbq('track', 'PageView');
</script>
<!-- End Meta Pixel Code -->
        <?php endif;
    }

    /**
     * Chèn thẻ GTM & Meta Pixel <noscript> ngay sau thẻ mở <body>
     */
    public function inject_body_tags() {
        $gtm_id = WooTrack_Helper::get_setting('gtm_id', '');
        if (!empty($gtm_id)) : ?>
<!-- Google Tag Manager (noscript) (WooTrack) -->
<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=<?php echo esc_attr($gtm_id); ?>"
height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
<!-- End Google Tag Manager (noscript) -->
        <?php endif;

        if (WooTrack_Helper::is_meta_enabled()) :
            $meta_pixel_id = WooTrack_Helper::get_setting('meta_pixel_id', '');
            ?>
<!-- Meta Pixel (noscript) (WooTrack) -->
<noscript><img height="1" width="1" style="display:none"
src="https://www.facebook.com/tr?id=<?php echo esc_attr($meta_pixel_id); ?>&ev=PageView&noscript=1"
/></noscript>
<!-- End Meta Pixel (noscript) -->
        <?php endif;
    }

    /**
     * Tải script theo dõi Frontend
     */
    public function enqueue_scripts() {
        if (is_admin()) {
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
            'gtm_id'                        => WooTrack_Helper::get_setting('gtm_id', ''),
            'ga4_id'                        => WooTrack_Helper::get_setting('ga4_id', ''),
            'gads_id'                       => WooTrack_Helper::get_setting('gads_id', ''),
            'purchase_label'                => WooTrack_Helper::get_setting('purchase_label', ''),
            'begin_checkout_label'          => WooTrack_Helper::get_setting('begin_checkout_label', ''),
            'add_to_cart_label'             => WooTrack_Helper::get_setting('add_to_cart_label', ''),
            'currency'                      => get_woocommerce_currency(),
            'debug_mode'                    => WooTrack_Helper::get_setting('debug_mode', 'no') === 'yes',
            'product_identifier'            => WooTrack_Helper::get_setting('product_identifier', 'parent_id'),
            'enable_enhanced_conversions'   => WooTrack_Helper::get_setting('enable_enhanced_conversions', 'no') === 'yes',
            'google_events'                 => array(
                'view_item_list' => WooTrack_Helper::is_event_enabled('view_item_list'),
                'view_item'      => WooTrack_Helper::is_event_enabled('view_item'),
                'add_to_cart'    => WooTrack_Helper::is_event_enabled('add_to_cart'),
                'begin_checkout' => WooTrack_Helper::is_event_enabled('begin_checkout'),
                'purchase'       => WooTrack_Helper::is_event_enabled('purchase'),
            ),
            // Meta (Facebook) Config
            'meta_pixel_id'                 => WooTrack_Helper::get_setting('meta_pixel_id', ''),
            'enable_meta_pixel'             => WooTrack_Helper::is_meta_enabled(),
            'enable_meta_capi'              => WooTrack_Helper::is_meta_capi_enabled(),
            'enable_meta_advanced_matching' => WooTrack_Helper::get_setting('enable_meta_advanced_matching', 'yes') === 'yes',
            'meta_content_type'             => WooTrack_Helper::get_meta_content_type(),
            'meta_events'                   => array(
                'view_content'      => WooTrack_Helper::is_meta_event_enabled('view_content'),
                'add_to_cart'       => WooTrack_Helper::is_meta_event_enabled('add_to_cart'),
                'initiate_checkout' => WooTrack_Helper::is_meta_event_enabled('initiate_checkout'),
                'purchase'          => WooTrack_Helper::is_meta_event_enabled('purchase'),
                'search'            => WooTrack_Helper::is_meta_event_enabled('search'),
            ),
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

        // 0. Bắt sự kiện add_to_cart lưu trong session (khi thêm vào giỏ bằng form submit thường chuyển trang, hiệu lực trong 30s)
        if (function_exists('WC') && WC()->session) {
            $now = time();
            if (WooTrack_Helper::is_event_enabled('add_to_cart')) {
                $pending_add_to_cart = WC()->session->get('woo_track_pending_add_to_cart');
                WC()->session->__unset('woo_track_pending_add_to_cart');
                if (
                    !empty($pending_add_to_cart) &&
                    is_array($pending_add_to_cart) &&
                    (!isset($pending_add_to_cart['timestamp']) || ($now - (int) $pending_add_to_cart['timestamp']) <= 30)
                ) {
                    unset($pending_add_to_cart['timestamp']);
                    $this->events[] = $pending_add_to_cart;
                }
            }

            if (WooTrack_Helper::is_meta_event_enabled('add_to_cart')) {
                $pending_meta_add_to_cart = WC()->session->get('woo_track_pending_meta_add_to_cart');
                WC()->session->__unset('woo_track_pending_meta_add_to_cart');
                if (
                    !empty($pending_meta_add_to_cart) &&
                    is_array($pending_meta_add_to_cart) &&
                    (!isset($pending_meta_add_to_cart['timestamp']) || ($now - (int) $pending_meta_add_to_cart['timestamp']) <= 30)
                ) {
                    unset($pending_meta_add_to_cart['timestamp']);
                    $this->meta_events[] = $pending_meta_add_to_cart;
                }
            }
        }

        // 1. Sự kiện view_item / ViewContent (Trang chi tiết sản phẩm)
        if (is_product()) {
            $this->prepare_view_item_event();
        }

        // 2. Sự kiện view_item_list (Trang danh sách / Shop / Danh mục)
        if ((is_shop() || is_product_taxonomy()) && WooTrack_Helper::is_event_enabled('view_item_list')) {
            $this->prepare_view_item_list_event();
        }

        // 3. Sự kiện begin_checkout / InitiateCheckout (Trang thanh toán, không bao gồm trang nhận đơn)
        if (is_checkout() && !is_order_received_page()) {
            $this->prepare_begin_checkout_event();
        }

        // 4. Sự kiện Search (Tìm kiếm sản phẩm trên website)
        if (is_search() && WooTrack_Helper::is_meta_event_enabled('search')) {
            $search_query = get_search_query();
            if (!empty($search_query)) {
                global $wp_query;
                $search_content_ids = array();
                if (!empty($wp_query->posts)) {
                    $search_posts = array_slice($wp_query->posts, 0, 10);
                    foreach ($search_posts as $sp) {
                        $sp_prod = wc_get_product($sp->ID);
                        if ($sp_prod) {
                            $search_content_ids[] = (string) WooTrack_Helper::get_product_identifier($sp_prod);
                        }
                    }
                }

                $this->meta_events[] = array(
                    'event' => 'Search',
                    'data'  => array(
                        'search_string' => $search_query,
                        'content_type'  => WooTrack_Helper::get_meta_content_type(),
                        'content_ids'   => $search_content_ids,
                    )
                );
            }
        }

        // 5. Sự kiện purchase (Trang nhận đơn hàng / Cảm ơn)
        if (is_checkout() && is_order_received_page()) {
            $valid_order = WooTrack_Helper::get_valid_thankyou_order();
            if ($valid_order instanceof WC_Order) {
                $this->track_purchase_event($valid_order->get_id());
            }
        }
    }

    /**
     * Chuẩn bị dữ liệu cho view_item (GA4) và ViewContent (Meta)
     */
    private function prepare_view_item_event() {
        global $post;
        if (!$post) return;

        $product = wc_get_product($post->ID);
        if (!$product) return;

        if (WooTrack_Helper::is_event_enabled('view_item')) {
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

        if (WooTrack_Helper::is_meta_event_enabled('view_content')) {
            $meta_prod = WooTrack_Helper::format_meta_product_data($product, 1);
            $this->meta_events[] = array(
                'event' => 'ViewContent',
                'data'  => $meta_prod
            );
        }
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
        if (!function_exists('WC') || !WC()->cart || WC()->cart->is_empty()) {
            return;
        }

        // Tránh bị add lặp lại nếu đã có trong events hoặc meta_events
        foreach ($this->events as $ev) {
            if ($ev['event'] === 'begin_checkout') {
                return;
            }
        }
        foreach ($this->meta_events as $mev) {
            if ($mev['event'] === 'InitiateCheckout') {
                return;
            }
        }

        $items     = array();
        $index     = 1;
        $total_qty = 0;

        foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
            $product = isset($cart_item['data']) ? $cart_item['data'] : null;
            if ($product && $product instanceof WC_Product) {
                $qty        = max(1, (int) $cart_item['quantity']);
                $total_qty += $qty;
                $item_data  = WooTrack_Helper::format_product_item($product, $qty, $index);
                $items[]    = $item_data;
                $index++;
            }
        }

        if (empty($items)) {
            return;
        }

        WC()->cart->calculate_totals();
        $checkout_total = (float) WC()->cart->total;
        if ($checkout_total <= 0) {
            $checkout_total = (float) WC()->cart->get_cart_contents_total();
        }
        $currency = get_woocommerce_currency();

        if (WooTrack_Helper::is_event_enabled('begin_checkout')) {
            $this->events[] = array(
                'event' => 'begin_checkout',
                'data'  => array(
                    'currency' => $currency,
                    'value'    => round($checkout_total, 2),
                    'items'    => $items
                )
            );

            // Bắn chuyển đổi phụ cho Google Ads nếu có cấu hình nhãn Begin Checkout
            $gads_id              = WooTrack_Helper::get_setting('gads_id', '');
            $begin_checkout_label = WooTrack_Helper::get_setting('begin_checkout_label', '');

            if (!empty($gads_id) && !empty($begin_checkout_label)) {
                $conversion_send_to = WooTrack_Helper::format_gads_send_to($gads_id, $begin_checkout_label);
                if (!empty($conversion_send_to)) {
                    $this->events[] = array(
                        'event' => 'conversion',
                        'data'  => array(
                            'send_to'  => $conversion_send_to,
                            'value'    => round($checkout_total, 2),
                            'currency' => $currency
                        )
                    );
                }
            }
        }

        // Sự kiện InitiateCheckout cho Meta Pixel
        if (WooTrack_Helper::is_meta_event_enabled('initiate_checkout')) {
            $meta_contents    = array();
            $meta_content_ids = array();
            foreach ($items as $it) {
                $meta_content_ids[] = (string) $it['item_id'];
                $meta_contents[] = array(
                    'id'         => (string) $it['item_id'],
                    'quantity'   => (int) $it['quantity'],
                    'item_price' => (float) $it['price']
                );
            }
            $meta_content_ids = array_values(array_unique($meta_content_ids));

            $this->meta_events[] = array(
                'event' => 'InitiateCheckout',
                'data'  => array(
                    'content_type' => WooTrack_Helper::get_meta_content_type(),
                    'content_ids'  => $meta_content_ids,
                    'contents'     => $meta_contents,
                    'num_items'    => $total_qty > 0 ? $total_qty : count($items),
                    'value'        => round($checkout_total, 2),
                    'currency'     => $currency
                )
            );
        }
    }

    /**
     * Gắn dữ liệu JSON vào nút thêm vào giỏ hàng trên loop (Hỗ trợ AJAX add_to_cart cho Google & Meta)
     * Sử dụng preg_replace_callback để tránh lỗi backreference ($1, $2) khi tên sản phẩm chứa ký tự $
     */
    public function add_tracking_data_to_loop_button($html, $product, $args = array()) {
        if (!$product instanceof WC_Product) {
            return $html;
        }

        $extra_attrs = '';

        if (WooTrack_Helper::is_event_enabled('add_to_cart')) {
            $item_data    = WooTrack_Helper::format_product_item($product, 1, 1);
            $json_data    = esc_attr(wp_json_encode($item_data));
            $extra_attrs .= ' data-woo-track-item="' . $json_data . '"';
        }

        if (WooTrack_Helper::is_meta_event_enabled('add_to_cart')) {
            $meta_data    = WooTrack_Helper::format_meta_product_data($product, 1);
            $json_meta    = esc_attr(wp_json_encode($meta_data));
            $extra_attrs .= ' data-woo-track-meta-item="' . $json_meta . '"';
        }

        if (!empty($extra_attrs)) {
            $html = preg_replace_callback(
                '/(<a\b[^>]*)(class=["\'][^"\']*["\'])/i',
                function ($matches) use ($extra_attrs) {
                    return $matches[1] . $extra_attrs . ' ' . $matches[2];
                },
                (string) $html,
                1
            );
        }

        return $html;
    }

    /**
     * Bắt sự kiện thêm vào giỏ hàng từ server (cả form submit thường lẫn API)
     */
    public function on_woocommerce_add_to_cart($cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data) {
        $target_id = !empty($variation_id) ? $variation_id : $product_id;
        $product = wc_get_product($target_id);
        if (!$product) {
            $product = wc_get_product($product_id);
        }
        if (!$product) {
            return;
        }

        $is_ajax_or_rest = wp_doing_ajax() || !empty($_REQUEST['wc-ajax']) || (defined('REST_REQUEST') && REST_REQUEST);

        // 1. Google E-commerce add_to_cart
        if (WooTrack_Helper::is_event_enabled('add_to_cart')) {
            $item_data = WooTrack_Helper::format_product_item($product, $quantity, 1);
            $total_value = (float) $item_data['price'] * (int) $quantity;

            $event_payload = array(
                'event'     => 'add_to_cart',
                'timestamp' => time(),
                'data'      => array(
                    'currency' => get_woocommerce_currency(),
                    'value'    => round($total_value, 2),
                    'items'    => array($item_data)
                )
            );

            // Chuyển đổi phụ cho Google Ads nếu có nhãn
            $gads_id           = WooTrack_Helper::get_setting('gads_id', '');
            $add_to_cart_label = WooTrack_Helper::get_setting('add_to_cart_label', '');
            if (!empty($gads_id) && !empty($add_to_cart_label)) {
                $conversion_send_to = WooTrack_Helper::format_gads_send_to($gads_id, $add_to_cart_label);
                if (!empty($conversion_send_to)) {
                    $event_payload['conversion_data'] = array(
                        'send_to'  => $conversion_send_to,
                        'value'    => round($total_value, 2),
                        'currency' => get_woocommerce_currency()
                    );
                }
            }

            // Chỉ lưu vào session nếu là form submit tải lại trang (không lưu khi gọi qua AJAX/REST để tránh tồn đọng session)
            if (!$is_ajax_or_rest && function_exists('WC') && WC()->session) {
                WC()->session->set('woo_track_pending_add_to_cart', $event_payload);
            }

            $GLOBALS['woo_track_last_added_item'] = $event_payload;
        }

        // 2. Meta Pixel AddToCart
        if (WooTrack_Helper::is_meta_event_enabled('add_to_cart')) {
            $meta_product_data = WooTrack_Helper::format_meta_product_data($product, $quantity);
            $meta_payload = array(
                'event'     => 'AddToCart',
                'timestamp' => time(),
                'data'      => $meta_product_data
            );

            if (!$is_ajax_or_rest && function_exists('WC') && WC()->session) {
                WC()->session->set('woo_track_pending_meta_add_to_cart', $meta_payload);
            }

            $GLOBALS['woo_track_last_added_meta_item'] = $meta_payload;
        }
    }

    /**
     * Đính kèm dữ liệu vào phản hồi AJAX add to cart fragments
     */
    public function add_tracking_to_fragments($fragments) {
        if (!empty($GLOBALS['woo_track_last_added_item'])) {
            $fragments['woo_track_add_to_cart'] = $GLOBALS['woo_track_last_added_item'];
            if (function_exists('WC') && WC()->session) {
                WC()->session->__unset('woo_track_pending_add_to_cart');
            }
        }

        if (!empty($GLOBALS['woo_track_last_added_meta_item'])) {
            $fragments['woo_track_meta_add_to_cart'] = $GLOBALS['woo_track_last_added_meta_item'];
            if (function_exists('WC') && WC()->session) {
                WC()->session->__unset('woo_track_pending_meta_add_to_cart');
            }
        }

        return $fragments;
    }

    /**
     * Ghi nhận sự kiện purchase trên trang Cảm ơn (Thank You page) cho Google và Meta (Browser + CAPI)
     */
    public function track_purchase_event($order_id) {
        if (empty($order_id)) {
            return;
        }

        $order_id = absint($order_id);

        // Tránh chạy lặp lại trong cùng 1 request
        if (in_array($order_id, $this->tracked_order_ids, true)) {
            return;
        }

        // Xác thực order_key nếu đang ở trang order-received trên trình duyệt để chống lộ dữ liệu
        if (is_checkout() && is_order_received_page()) {
            $order = WooTrack_Helper::get_valid_thankyou_order($order_id);
        } else {
            $order = wc_get_order($order_id);
        }

        if (!$order instanceof WC_Order) {
            return;
        }

        $this->tracked_order_ids[] = $order_id;
        $is_debug = WooTrack_Helper::get_setting('debug_mode', 'no') === 'yes';

        // Không ghi nhận chuyển đổi cho các đơn hàng thanh toán thất bại, bị hủy hoặc hoàn tiền
        if (!WooTrack_Helper::is_order_valid_for_purchase_tracking($order)) {
            if ($is_debug) {
                $this->events[] = array(
                    'event' => 'purchase_invalid_status_skipped',
                    'data'  => array(
                        'order_id' => $order_id,
                        'status'   => $order->get_status(),
                        'note'     => 'Đơn hàng #' . $order_id . ' đang ở trạng thái "' . $order->get_status() . '" nên không bắn sự kiện Purchase.'
                    )
                );
            }
            return;
        }

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

        // Event ID đồng nhất cho cả Meta Pixel (Browser) và Conversions API (Server) để khử trùng lặp (Deduplication)
        $event_id    = 'order_' . $order->get_id();
        $has_tracked = false;

        // 1. Google Analytics 4 & Google Ads Purchase
        if (WooTrack_Helper::is_event_enabled('purchase')) {
            $order_data = WooTrack_Helper::format_order_data($order);

            $this->events[] = array(
                'event' => 'purchase',
                'data'  => $order_data
            );
            $has_tracked = true;

            // Sự kiện conversion cho Google Ads (nếu có cấu hình Ads ID & Label)
            $gads_id        = WooTrack_Helper::get_setting('gads_id', '');
            $purchase_label = WooTrack_Helper::get_setting('purchase_label', '');

            if (!empty($gads_id) && !empty($purchase_label)) {
                $conversion_send_to = WooTrack_Helper::format_gads_send_to($gads_id, $purchase_label);
                if (!empty($conversion_send_to)) {
                    $this->events[] = array(
                        'event' => 'conversion',
                        'data'  => array(
                            'send_to'        => $conversion_send_to,
                            'value'          => $order_data['value'],
                            'currency'       => $order_data['currency'],
                            'transaction_id' => $order_data['transaction_id'],
                            'discount'       => $order_data['discount'],
                            'new_customer'   => $order_data['new_customer'],
                            'items'          => $order_data['items'],
                        )
                    );
                }
            }

            // Dữ liệu người dùng nâng cao Google Enhanced Conversions
            if (WooTrack_Helper::get_setting('enable_enhanced_conversions', 'no') === 'yes') {
                $this->user_data = WooTrack_Helper::format_user_data_for_ads($order);
            }
        }

        // 2. Meta Pixel Purchase phía trình duyệt
        if (WooTrack_Helper::is_meta_event_enabled('purchase')) {
            $meta_order_data = WooTrack_Helper::format_meta_order_data($order);
            $this->meta_events[] = array(
                'event'    => 'Purchase',
                'data'     => $meta_order_data,
                'event_id' => $event_id
            );
            $has_tracked = true;
        }

        // 3. Meta Conversions API (CAPI) phía máy chủ (Đẩy vào hàng đợi ngầm shutdown, gộp chung 1 lần $order->save() bên dưới)
        if (WooTrack_Helper::is_meta_capi_enabled() && WooTrack_Helper::is_meta_event_enabled('purchase')) {
            WooTrack_Meta_CAPI::send_purchase($order, $event_id, true, false);
            $has_tracked = true;
        }

        // Đánh dấu đơn hàng này đã được ghi nhận trên cơ sở dữ liệu (Lưu gộp 1 lần duy nhất)
        if ($has_tracked) {
            $order->update_meta_data('_woo_track_purchase_tracked', current_time('mysql'));
            $order->save();
        }
    }

    public function render_footer_events() {
        // Đảm bảo begin_checkout / InitiateCheckout luôn được chuẩn bị trên trang checkout
        if (is_checkout() && !is_order_received_page()) {
            $has_begin_checkout = false;
            foreach ($this->events as $ev) {
                if ($ev['event'] === 'begin_checkout') {
                    $has_begin_checkout = true;
                    break;
                }
            }
            if (!$has_begin_checkout) {
                $this->prepare_begin_checkout_event();
            }
        }

        $single_product_data = null;
        $meta_single_product_data = null;
        if (is_product()) {
            global $post;
            if ($post) {
                $current_prod = wc_get_product($post->ID);
                if ($current_prod) {
                    if (WooTrack_Helper::is_event_enabled('add_to_cart')) {
                        $single_product_data = WooTrack_Helper::format_product_item($current_prod, 1, 1);
                    }
                    if (WooTrack_Helper::is_meta_event_enabled('add_to_cart')) {
                        $meta_single_product_data = WooTrack_Helper::format_meta_product_data($current_prod, 1);
                    }
                }
            }
        }

        $payload = array(
            'events'              => $this->events,
            'meta_events'         => $this->meta_events,
            'user_data'           => $this->user_data,
            'single_product'      => $single_product_data,
            'meta_single_product' => $meta_single_product_data
        );

        ?>
        <script type="text/javascript" id="woo-track-initial-data">
            window.wooTrackPayload = <?php echo wp_json_encode($payload); ?>;
        </script>
        <?php
    }
}
