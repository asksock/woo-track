/**
 * WooTrack - Google (GA4, Ads, GTM) & Meta (Pixel, Conversions API) Frontend Tracker
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
        return window.wooTrackPayload || {
            events: [],
            meta_events: [],
            user_data: null,
            single_product: null,
            meta_single_product: null
        };
    }

    /**
     * Hàm in log Google hỗ trợ kiểm tra khi bật Debug Mode
     */
    function debugLog(eventName, eventData) {
        if (config.debug_mode) {
            console.log(
                '%c[WooTrack Google 🚀] ' + eventName,
                'background: #1a73e8; color: #fff; font-weight: bold; padding: 2px 6px; border-radius: 3px;',
                eventData
            );
        }
    }

    /**
     * Hàm in log Meta hỗ trợ kiểm tra khi bật Debug Mode
     */
    function debugMetaLog(eventName, eventData, eventParams) {
        if (config.debug_mode) {
            console.log(
                '%c[WooTrack Meta 🎯] ' + eventName,
                'background: #0866ff; color: #fff; font-weight: bold; padding: 2px 6px; border-radius: 3px;',
                eventData,
                eventParams ? eventParams : ''
            );
        }
    }

    /**
     * Chuẩn hóa chuỗi send_to cho Google Ads
     */
    function getGadsSendTo(label) {
        if (!config.gads_id || !label) return '';
        var cleanLabel = String(label).split('/').pop().trim();
        var gadsId = String(config.gads_id).trim();
        if (/^\d+$/.test(gadsId)) {
            gadsId = 'AW-' + gadsId;
        }
        return gadsId.replace(/\/$/, '') + '/' + cleanLabel;
    }

    /**
     * Hàm bắn sự kiện an toàn cho cả gtag (GA4, Google Ads) và dataLayer (GTM)
     * Đảm bảo cấu hình chuẩn cho cả việc dùng GTM độc lập hoặc dùng trực tiếp GA4/Google Ads
     */
    function sendGtagEvent(eventName, eventData) {
        // 1. Nếu có GTM, đẩy cấu trúc chuẩn GTM GA4 Ecommerce
        if (config.gtm_id) {
            window.dataLayer.push({ ecommerce: null });
            if (eventName === 'conversion') {
                // Sự kiện conversion của Google Ads không lồng vào ecommerce
                window.dataLayer.push($.extend({ event: 'conversion' }, eventData));
            } else {
                window.dataLayer.push({
                    event: eventName,
                    ecommerce: eventData
                });
            }
        }

        // 2. Bắn qua gtag nếu có GA4 hoặc Google Ads nạp trực tiếp
        if (config.ga4_id || config.gads_id) {
            gtag('event', eventName, eventData);
        } else if (!config.gtm_id) {
            gtag('event', eventName, eventData);
        }

        debugLog(eventName, eventData);
    }

    /**
     * Hàm bắn sự kiện an toàn cho Meta Pixel (fbq)
     * Hỗ trợ eventID để Meta tự động khử trùng lặp (Deduplication) với Conversions API (CAPI)
     */
    function sendMetaPixelEvent(eventName, eventData, eventParams) {
        if (typeof window.fbq !== 'function' || !config.enable_meta_pixel || !config.meta_pixel_id) {
            return;
        }

        if (eventParams && typeof eventParams === 'object') {
            window.fbq('track', eventName, eventData, eventParams);
        } else {
            window.fbq('track', eventName, eventData);
        }

        debugMetaLog(eventName, eventData, eventParams);
    }

    /**
     * Khởi tạo cấu hình ban đầu
     */
    function initTrackerConfig() {
        var payload = getPayload();

        // 1. Google Enhanced Conversions toàn cục nếu người dùng bật tính năng này
        if (config.enable_enhanced_conversions) {
            gtag('set', { 'allow_enhanced_conversions': true });
        }

        // Thiết lập Enhanced Conversions User Data nếu có
        if (payload.user_data) {
            gtag('set', 'user_data', payload.user_data);
            if (config.gtm_id) {
                window.dataLayer.push({ user_data: payload.user_data });
            }
            debugLog('Set Enhanced Conversions user_data', payload.user_data);
        }

        // Bắn các sự kiện Google chuẩn bị sẵn từ Server (view_item, view_item_list, begin_checkout, purchase)
        if (payload.events && payload.events.length > 0) {
            $.each(payload.events, function (index, item) {
                if (item.event === 'purchase_duplicate_blocked' || item.event === 'purchase_invalid_status_skipped') {
                    debugLog(item.event, item.data);
                    return;
                }
                if (item.event === 'add_to_cart') {
                    var gId = (item.data && item.data.items && item.data.items[0]) ? item.data.items[0].item_id : null;
                    if (gId && isDuplicateAddToCart('g_' + gId, true)) {
                        debugLog('Suppressed duplicate add_to_cart from page redirect', item.data);
                        return;
                    }
                }
                sendGtagEvent(item.event, item.data);
                if (item.event === 'add_to_cart' && item.conversion_data) {
                    sendGtagEvent('conversion', item.conversion_data);
                }
            });
        }

        // 2. Bắn các sự kiện Meta chuẩn bị sẵn từ Server (ViewContent, InitiateCheckout, Purchase, Search, AddToCart)
        if (payload.meta_events && payload.meta_events.length > 0) {
            $.each(payload.meta_events, function (index, item) {
                if (item.event === 'AddToCart') {
                    var mId = null;
                    if (item.data && item.data.contents && item.data.contents[0]) {
                        mId = item.data.contents[0].id;
                    } else if (item.data && item.data.content_ids && item.data.content_ids[0]) {
                        mId = item.data.content_ids[0];
                    }
                    if (mId && isDuplicateAddToCart('m_' + mId, true)) {
                        debugMetaLog('Suppressed duplicate AddToCart from page redirect', item.data);
                        return;
                    }
                }
                var eventParams = item.event_id ? { eventID: item.event_id } : undefined;
                sendMetaPixelEvent(item.event, item.data, eventParams);
            });
        }
    }

    var lastAddedItems = {};

    function getRecentAdds() {
        try {
            var data = sessionStorage.getItem('woo_track_recent_adds');
            return data ? JSON.parse(data) : {};
        } catch (e) {
            return {};
        }
    }

    function setRecentAdd(key) {
        try {
            var adds = getRecentAdds();
            var now = Date.now();
            adds[key] = now;
            for (var k in adds) {
                if (now - adds[k] > 30000) {
                    delete adds[k];
                }
            }
            sessionStorage.setItem('woo_track_recent_adds', JSON.stringify(adds));
        } catch (e) {}
    }

    function consumeRecentAdd(key) {
        try {
            var adds = getRecentAdds();
            if (adds[key]) {
                delete adds[key];
                sessionStorage.setItem('woo_track_recent_adds', JSON.stringify(adds));
            }
        } catch (e) {}
    }

    /**
     * Kiểm tra chống bắn trùng sự kiện Add to cart (in-memory + sessionStorage cross-page)
     */
    function isDuplicateAddToCart(key, fromRedirect) {
        if (!key) return false;
        var now = Date.now();
        if (lastAddedItems[key] && (now - lastAddedItems[key] < 2500)) {
            return true;
        }
        var recent = getRecentAdds();
        if (recent[key] && (now - recent[key] < 30000)) {
            if (fromRedirect) {
                consumeRecentAdd(key);
            }
            return true;
        }

        lastAddedItems[key] = now;
        if (!fromRedirect) {
            setRecentAdd(key);
        }
        return false;
    }

    /**
     * Kiểm tra sự kiện add_to_cart của Google có được bật trong cài đặt không
     */
    function isGoogleAddToCartEnabled() {
        if (config.google_events && typeof config.google_events.add_to_cart !== 'undefined') {
            return Boolean(config.google_events.add_to_cart);
        }
        return true;
    }

    /**
     * Lắng nghe sự kiện thêm vào giỏ hàng (Add to Cart) cho cả Google và Meta
     */
    function setupAddToCartListeners() {
        // 1. AJAX Add to cart (Hỗ trợ cả fragments từ server và nút bấm loop)
        $(document.body).on('added_to_cart', function (event, fragments, cart_hash, $button) {
            var googleHandled = false;
            var metaHandled   = false;

            // Nếu có dữ liệu Google từ server gửi qua fragments
            if (fragments && fragments.woo_track_add_to_cart) {
                if (isGoogleAddToCartEnabled()) {
                    var serverPayload = fragments.woo_track_add_to_cart;
                    var gItemId = (serverPayload.data && serverPayload.data.items && serverPayload.data.items[0]) ? serverPayload.data.items[0].item_id : null;
                    if (!isDuplicateAddToCart('g_' + gItemId)) {
                        sendGtagEvent(serverPayload.event, serverPayload.data);
                        if (serverPayload.conversion_data) {
                            sendGtagEvent('conversion', serverPayload.conversion_data);
                        }
                    }
                }
                googleHandled = true;
            }

            // Nếu có dữ liệu Meta từ server gửi qua fragments
            if (fragments && fragments.woo_track_meta_add_to_cart) {
                if (config.enable_meta_pixel && config.meta_events && config.meta_events.add_to_cart) {
                    var metaPayload = fragments.woo_track_meta_add_to_cart;
                    var mItemId = (metaPayload.data && metaPayload.data.contents && metaPayload.data.contents[0]) ? metaPayload.data.contents[0].id : null;
                    if (!isDuplicateAddToCart('m_' + mItemId)) {
                        sendMetaPixelEvent(metaPayload.event, metaPayload.data);
                    }
                }
                metaHandled = true;
            }

            // Fallback lấy dữ liệu từ nút bấm nếu fragments không chứa đủ
            if ((!googleHandled || !metaHandled) && $button && $button.length) {
                // Google Item
                if (!googleHandled && isGoogleAddToCartEnabled()) {
                    var item = null;
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

                    if (item && !isDuplicateAddToCart('g_' + item.item_id)) {
                        triggerGoogleAddToCartEvent(item);
                    }
                }

                // Meta Item
                if (!metaHandled && config.enable_meta_pixel && config.meta_events && config.meta_events.add_to_cart) {
                    var metaItem = null;
                    var metaDataAttr = $button.attr('data-woo-track-meta-item');
                    if (metaDataAttr) {
                        try {
                            metaItem = JSON.parse(metaDataAttr);
                        } catch (e) {
                            metaItem = null;
                        }
                    }

                    if (metaItem) {
                        var mBtnId = (metaItem.contents && metaItem.contents[0]) ? metaItem.contents[0].id : (metaItem.content_ids ? metaItem.content_ids[0] : null);
                        if (!isDuplicateAddToCart('m_' + mBtnId)) {
                            sendMetaPixelEvent('AddToCart', metaItem);
                        }
                    }
                }
            }
        });

        // Lưu thông tin biến thể đang chọn khi WooCommerce kích hoạt found_variation (hỗ trợ cả SP có > 30 biến thể dùng AJAX)
        $(document).on('found_variation', 'form.variations_form', function (event, variation) {
            $(this).data('woo_track_selected_variation', variation);
        });
        $(document).on('reset_data', 'form.variations_form', function () {
            $(this).removeData('woo_track_selected_variation');
        });

        // 2. Thêm vào giỏ hàng trên trang Chi tiết sản phẩm hoặc Popup
        $(document).on('click', '.single_add_to_cart_button, button[name="add-to-cart"]', function () {
            var $btn  = $(this);

            // Chặn bắn sự kiện nếu nút đang bị vô hiệu hóa (chưa chọn biến thể hoặc hết hàng)
            if ($btn.is('.disabled, .wc-variation-selection-needed, .wc-variation-is-unavailable') || $btn.prop('disabled')) {
                return;
            }

            var $form = $btn.closest('form');
            if ($form.length && typeof $form[0].checkValidity === 'function' && !$form[0].checkValidity()) {
                return;
            }

            var payload = getPayload();
            var baseItem = payload.single_product;
            var metaBaseItem = payload.meta_single_product;

            var qty = 1;
            if ($form.length && $form.find('input[name="quantity"]').length) {
                qty = parseInt($form.find('input[name="quantity"]').val(), 10) || 1;
            }

            var variationId = $form.find('input[name="variation_id"]').val();
            var hasVariation = variationId && parseInt(variationId, 10) > 0;
            var selectedVariation = null;

            // Lấy giá và SKU thực tế của biến thể từ sự kiện found_variation hoặc mảng product_variations
            if (hasVariation && $form.length) {
                var cachedVar = $form.data('woo_track_selected_variation');
                if (cachedVar && String(cachedVar.variation_id) === String(variationId)) {
                    selectedVariation = cachedVar;
                } else {
                    var variationsData = $form.data('product_variations');
                    if ($.isArray(variationsData)) {
                        for (var i = 0; i < variationsData.length; i++) {
                            if (String(variationsData[i].variation_id) === String(variationId)) {
                                selectedVariation = variationsData[i];
                                break;
                            }
                        }
                    }
                }
            }

            // Xác định ID theo cài đặt: 'parent_id' (giữ nguyên ID sản phẩm cha), 'id' (dùng ID biến thể), hoặc 'sku' (dùng SKU biến thể)
            var identifierMode = config.product_identifier || 'parent_id';
            var resolvedVarId = null;
            if (hasVariation) {
                if (identifierMode === 'id') {
                    resolvedVarId = String(variationId);
                } else if (identifierMode === 'sku' && selectedVariation && selectedVariation.sku) {
                    resolvedVarId = String(selectedVariation.sku);
                }
            }

            // Xử lý Google Add to Cart
            var item = null;
            if (isGoogleAddToCartEnabled()) {
                if (baseItem) {
                    item = $.extend({}, baseItem);
                    item.quantity = qty;
                    if (hasVariation) {
                        if (resolvedVarId) {
                            item.id = resolvedVarId;
                            item.item_id = resolvedVarId;
                        }
                        if (selectedVariation && typeof selectedVariation.display_price !== 'undefined') {
                            item.price = parseFloat(selectedVariation.display_price) || item.price;
                        }
                    }
                } else {
                    var prodId = $btn.val() || $form.find('input[name="add-to-cart"]').val() || $form.find('input[name="product_id"]').val();
                    if (prodId) {
                        var finalId = resolvedVarId || String(prodId);
                        item = {
                            id: finalId,
                            item_id: finalId,
                            item_name: $('.product_title, h1.entry-title').first().text().trim() || 'Product',
                            quantity: qty
                        };
                    }
                }

                if (item && !isDuplicateAddToCart('g_' + item.item_id)) {
                    triggerGoogleAddToCartEvent(item);
                }
            }

            // Xử lý Meta AddToCart
            if (config.enable_meta_pixel && config.meta_events && config.meta_events.add_to_cart) {
                if (metaBaseItem) {
                    var metaItem = $.extend(true, {}, metaBaseItem);
                    var price = 0;
                    if (metaItem.contents && metaItem.contents.length > 0) {
                        if (selectedVariation && typeof selectedVariation.display_price !== 'undefined') {
                            metaItem.contents[0].item_price = parseFloat(selectedVariation.display_price) || metaItem.contents[0].item_price;
                        }
                        price = metaItem.contents[0].item_price || 0;
                        metaItem.contents[0].quantity = qty;
                        if (hasVariation && resolvedVarId) {
                            metaItem.contents[0].id = resolvedVarId;
                            metaItem.content_ids = [resolvedVarId];
                        }
                    }
                    metaItem.value = parseFloat((price * qty).toFixed(2));
                    var mClickId = (metaItem.contents && metaItem.contents[0]) ? metaItem.contents[0].id : null;
                    if (!isDuplicateAddToCart('m_' + mClickId)) {
                        sendMetaPixelEvent('AddToCart', metaItem);
                    }
                } else if (item) {
                    // Fallback Meta AddToCart từ item
                    var fallbackPrice = parseFloat(item.price) || 0;
                    var fallbackTotal = parseFloat((fallbackPrice * qty).toFixed(2));
                    if (!isDuplicateAddToCart('m_' + item.item_id)) {
                        sendMetaPixelEvent('AddToCart', {
                            content_type: config.meta_content_type || 'product',
                            content_ids: [item.item_id],
                            contents: [{
                                id: item.item_id,
                                quantity: qty,
                                item_price: fallbackPrice
                            }],
                            value: fallbackTotal,
                            currency: config.currency || 'VND'
                        });
                    }
                }
            }
        });
    }

    /**
     * Bắn sự kiện add_to_cart cho GA4, GTM và Google Ads Conversion (nếu có label)
     */
    function triggerGoogleAddToCartEvent(item) {
        if (!item) return;

        var totalValue = (parseFloat(item.price) || 0) * (parseInt(item.quantity, 10) || 1);

        // Bắn sự kiện E-commerce cho Google
        var eventData = {
            currency: config.currency || 'VND',
            value: totalValue,
            items: [item]
        };
        sendGtagEvent('add_to_cart', eventData);

        // Bắn chuyển đổi phụ Google Ads nếu có cấu hình nhãn Add to cart
        if (config.gads_id && config.add_to_cart_label) {
            var conversionSendTo = getGadsSendTo(config.add_to_cart_label);
            if (conversionSendTo) {
                sendGtagEvent('conversion', {
                    send_to: conversionSendTo,
                    value: totalValue,
                    currency: config.currency || 'VND'
                });
            }
        }
    }

    /**
     * Đọc giá trị cookie theo tên
     */
    function getCookieValue(name) {
        var match = document.cookie.match(new RegExp('(^|;\\s*)' + name + '=([^;]*)'));
        return match ? decodeURIComponent(match[2]) : '';
    }

    /**
     * Tự động bắt fbclid lưu vào cookie _fbc trên trình duyệt (vượt qua HTML Page Cache)
     * và đính kèm _fbp / _fbc vào form Checkout để đảm bảo CAPI luôn nhận đủ thông tin
     */
    function syncMetaCookiesAndCheckoutFields() {
        try {
            if (window.location && window.location.search) {
                var urlParams = new URLSearchParams(window.location.search);
                var fbclid = urlParams.get('fbclid');
                if (fbclid) {
                    var currentFbc = getCookieValue('_fbc');
                    var suffix = '.' + fbclid;
                    if (!currentFbc || currentFbc.slice(-suffix.length) !== suffix) {
                        var newFbc = 'fb.1.' + Date.now() + '.' + fbclid;
                        var expires = new Date(Date.now() + 90 * 86400 * 1000).toUTCString();
                        var secure = window.location.protocol === 'https:' ? '; Secure' : '';
                        document.cookie = '_fbc=' + encodeURIComponent(newFbc) + '; expires=' + expires + '; path=/; SameSite=Lax' + secure;
                    }
                }
            }
        } catch (e) {}

        function injectCheckoutInputs() {
            var $checkoutForm = $('form.checkout, form#order_review');
            if (!$checkoutForm.length) return;

            var fbp = getCookieValue('_fbp');
            var fbc = getCookieValue('_fbc');

            if (fbp) {
                if (!$checkoutForm.find('input[name="woo_track_fbp"]').length) {
                    $checkoutForm.append($('<input>', { type: 'hidden', name: 'woo_track_fbp', value: fbp }));
                } else {
                    $checkoutForm.find('input[name="woo_track_fbp"]').val(fbp);
                }
            }
            if (fbc) {
                if (!$checkoutForm.find('input[name="woo_track_fbc"]').length) {
                    $checkoutForm.append($('<input>', { type: 'hidden', name: 'woo_track_fbc', value: fbc }));
                } else {
                    $checkoutForm.find('input[name="woo_track_fbc"]').val(fbc);
                }
            }
        }

        injectCheckoutInputs();
        $(document.body).on('updated_checkout', injectCheckoutInputs);
        setTimeout(injectCheckoutInputs, 1500);
    }

    /**
     * Khởi chạy khi DOM sẵn sàng
     */
    $(document).ready(function () {
        syncMetaCookiesAndCheckoutFields();
        initTrackerConfig();
        setupAddToCartListeners();
    });

})(jQuery);
