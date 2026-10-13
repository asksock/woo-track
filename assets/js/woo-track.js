/**
 * WooTrack - Google Analytics 4, Google Ads & Google Tag Manager Frontend Tracker
 */

(function ($) {
    'use strict';

    // Đảm bảo dataLayer và hàm gtag luôn sẵn sàng
    window.dataLayer = window.dataLayer || [];
    if (typeof window.gtag !== 'function') {
        window.gtag = function () {
            window.dataLayer.push(arguments);
        };
    }

    var config = window.wooTrackConfig || {};

    /**
     * Lấy dữ liệu payload động từ window (tránh lỗi cache do thứ tự nạp script)
     */
    function getPayload() {
        return window.wooTrackPayload || { events: [], user_data: null, single_product: null };
    }

    /**
     * Hàm in log hỗ trợ kiểm tra khi bật Debug Mode
     */
    function debugLog(eventName, eventData) {
        if (config.debug_mode) {
            console.log(
                '%c[WooTrack 🚀] ' + eventName,
                'background: #1a73e8; color: #fff; font-weight: bold; padding: 2px 6px; border-radius: 3px;',
                eventData
            );
        }
    }

    /**
     * Hàm bắn sự kiện an toàn cho cả gtag (GA4, Google Ads) và dataLayer (GTM)
     */
    function sendGtagEvent(eventName, eventData) {
        // 1. Bắn qua gtag cho GA4 và Google Ads
        gtag('event', eventName, eventData);

        // 2. Đẩy vào dataLayer chuẩn E-commerce cho Google Tag Manager (GTM)
        if (config.gtm_id) {
            window.dataLayer.push({
                event: eventName,
                ecommerce: eventData
            });
        }

        debugLog(eventName, eventData);
    }

    /**
     * Khởi tạo cấu hình ban đầu
     */
    function initTrackerConfig() {
        var payload = getPayload();

        // Kích hoạt Enhanced Conversions toàn cục nếu người dùng bật tính năng này
        if (config.enable_enhanced_conversions) {
            gtag('set', { 'allow_enhanced_conversions': true });
        }

        // Thiết lập Enhanced Conversions User Data nếu có
        if (payload.user_data) {
            gtag('set', 'user_data', payload.user_data);
            debugLog('Set Enhanced Conversions user_data', payload.user_data);
        }

        // Bắn các sự kiện chuẩn bị sẵn từ Server (view_item, view_item_list, begin_checkout, purchase)
        if (payload.events && payload.events.length > 0) {
            $.each(payload.events, function (index, item) {
                sendGtagEvent(item.event, item.data);
            });
        }
    }

    /**
     * Lắng nghe sự kiện thêm vào giỏ hàng (Add to Cart)
     */
    function setupAddToCartListeners() {
        // 1. AJAX Add to cart trên trang Danh mục / Shop
        $(document.body).on('added_to_cart', function (event, fragments, cart_hash, $button) {
            if (!$button || !$button.length) {
                return;
            }

            var itemDataAttr = $button.attr('data-woo-track-item');
            var item = null;

            if (itemDataAttr) {
                try {
                    item = JSON.parse(itemDataAttr);
                } catch (e) {
                    item = null;
                }
            }

            // Fallback nếu không có attribute JSON
            if (!item) {
                var productId = $button.data('product_id') || $button.val();
                var quantity  = $button.data('quantity') || 1;
                item = {
                    item_id: String(productId),
                    quantity: parseInt(quantity, 10) || 1
                };
            }

            triggerAddToCartEvent(item);
        });

        // 2. Thêm vào giỏ hàng trên trang Chi tiết sản phẩm (Single Product Form)
        $('form.cart').on('click', '.single_add_to_cart_button', function () {
            var $form = $(this).closest('form.cart');
            var payload = getPayload();
            var baseItem = payload.single_product;

            if (!baseItem) {
                return;
            }

            var qty = parseInt($form.find('input[name="quantity"]').val(), 10) || 1;
            var itemCopy = $.extend({}, baseItem);
            itemCopy.quantity = qty;

            // Xử lý sản phẩm có biến thể (Variable Product)
            var variationId = $form.find('input[name="variation_id"]').val();
            if (variationId && parseInt(variationId, 10) > 0) {
                if (config.product_identifier !== 'sku') {
                    itemCopy.item_id = String(variationId);
                }
            }

            triggerAddToCartEvent(itemCopy);
        });
    }

    /**
     * Bắn sự kiện add_to_cart cho GA4, GTM và Google Ads Conversion (nếu có label)
     */
    function triggerAddToCartEvent(item) {
        if (!item) return;

        var totalValue = (parseFloat(item.price) || 0) * (parseInt(item.quantity, 10) || 1);

        // Bắn sự kiện E-commerce
        var eventData = {
            currency: config.currency || 'VND',
            value: totalValue,
            items: [item]
        };
        sendGtagEvent('add_to_cart', eventData);

        // Bắn chuyển đổi phụ Google Ads nếu có cấu hình nhãn Add to cart
        if (config.gads_id && config.add_to_cart_label) {
            var conversionSendTo = config.gads_id.replace(/\/$/, '') + '/' + config.add_to_cart_label.replace(/^\//, '');
            sendGtagEvent('conversion', {
                send_to: conversionSendTo,
                value: totalValue,
                currency: config.currency || 'VND'
            });
        }
    }

    /**
     * Khởi chạy khi DOM sẵn sàng
     */
    $(document).ready(function () {
        initTrackerConfig();
        setupAddToCartListeners();
    });

})(jQuery);
