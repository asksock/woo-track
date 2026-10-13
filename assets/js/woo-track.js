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
     * Tránh nhân đôi sự kiện trên dòng thời gian Tag Assistant
     */
    function sendGtagEvent(eventName, eventData) {
        // Chuẩn hóa dataLayer cho GTM: xóa ecommerce cũ trước khi nạp dữ liệu mới
        if (config.gtm_id) {
            window.dataLayer.push({ ecommerce: null });
        }

        // Bắn qua gtag (gtag sẽ tự động đồng bộ vào dataLayer chuẩn xác mà không sinh event trùng)
        gtag('event', eventName, eventData);

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
        // 1. AJAX Add to cart (Hỗ trợ cả fragments từ server và nút bấm loop)
        $(document.body).on('added_to_cart', function (event, fragments, cart_hash, $button) {
            // Nếu có dữ liệu chuẩn từ server gửi qua fragments
            if (fragments && fragments.woo_track_add_to_cart) {
                var serverPayload = fragments.woo_track_add_to_cart;
                sendGtagEvent(serverPayload.event, serverPayload.data);
                if (serverPayload.conversion_data) {
                    sendGtagEvent('conversion', serverPayload.conversion_data);
                }
                return;
            }

            // Fallback lấy dữ liệu từ nút bấm
            var item = null;
            if ($button && $button.length) {
                var itemDataAttr = $button.attr('data-woo-track-item');
                if (itemDataAttr) {
                    try {
                        item = JSON.parse(itemDataAttr);
                    } catch (e) {
                        item = null;
                    }
                }

                if (!item) {
                    var productId = $button.data('product_id') || $button.val();
                    var quantity  = $button.data('quantity') || 1;
                    if (productId) {
                        item = {
                            item_id: String(productId),
                            quantity: parseInt(quantity, 10) || 1
                        };
                    }
                }
            }

            if (item) {
                triggerAddToCartEvent(item);
            }
        });

        // 2. Thêm vào giỏ hàng trên trang Chi tiết sản phẩm hoặc Popup
        $(document).on('click', '.single_add_to_cart_button, button[name="add-to-cart"]', function () {
            var $btn  = $(this);
            var $form = $btn.closest('form');
            var payload = getPayload();
            var baseItem = payload.single_product;

            var qty = 1;
            if ($form.length && $form.find('input[name="quantity"]').length) {
                qty = parseInt($form.find('input[name="quantity"]').val(), 10) || 1;
            }

            var item = null;

            if (baseItem) {
                item = $.extend({}, baseItem);
                item.quantity = qty;

                var variationId = $form.find('input[name="variation_id"]').val();
                if (variationId && parseInt(variationId, 10) > 0) {
                    if (config.product_identifier !== 'sku') {
                        item.item_id = String(variationId);
                    }
                }
            } else {
                // Fallback nếu không có sẵn baseItem
                var prodId = $btn.val() || $form.find('input[name="add-to-cart"]').val() || $form.find('input[name="product_id"]').val();
                if (prodId) {
                    item = {
                        item_id: String(prodId),
                        item_name: $('.product_title, h1.entry-title').first().text().trim() || 'Product',
                        quantity: qty
                    };
                }
            }

            if (item) {
                triggerAddToCartEvent(item);
            }
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
     * Bắt sự kiện click Tiến hành thanh toán từ giỏ hàng (Proceed to Checkout)
     */
    function setupCheckoutListeners() {
        $(document).on('click', '.checkout-button, a.checkout-button', function () {
            sendGtagEvent('begin_checkout', {
                currency: config.currency || 'VND'
            });

            if (config.gads_id && config.begin_checkout_label) {
                var conversionSendTo = config.gads_id.replace(/\/$/, '') + '/' + config.begin_checkout_label.replace(/^\//, '');
                sendGtagEvent('conversion', {
                    send_to: conversionSendTo,
                    currency: config.currency || 'VND'
                });
            }
        });
    }

    /**
     * Khởi chạy khi DOM sẵn sàng
     */
    $(document).ready(function () {
        initTrackerConfig();
        setupAddToCartListeners();
        setupCheckoutListeners();
    });

})(jQuery);
