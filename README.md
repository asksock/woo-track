# WooTrack - All-in-One Tracker for WooCommerce (Google, Meta & TikTok)

**WooTrack** là bộ plugin WordPress độc lập, chuyên sâu dành riêng cho **WooCommerce** (hỗ trợ chuẩn **PHP 8.x+**, **HPOS - High-Performance Order Storage** và **Cart/Checkout Blocks**). Plugin giúp tích hợp đồng bộ và chính xác toàn bộ hệ sinh thái **Google** (**Google Tag Manager**, **Google Analytics 4**, **Google Ads**), **Meta (Facebook)** (**Meta Pixel** & **Conversions API - CAPI `v22.0`**) và **TikTok Ads** (**TikTok Pixel** & **TikTok Events API `v1.3`**) với cơ chế **Khử trùng lặp đa tầng (Multi-layer Deduplication)** mà không cần cài đặt thêm bất kỳ plugin bên thứ ba nào.

---

## 📑 Mục lục

1. [Kiến trúc Thư mục & Vai trò từng Module](#-1-kiến-trúc-thư-mục--vai-trò-từng-module)
2. [Cơ chế hoạt động Hệ sinh thái Google (GTM, GA4, Google Ads)](#-2-cơ-chế-hoạt-động-hệ-sinh-thái-google-gtm-ga4-google-ads)
3. [Cơ chế hoạt động Hệ sinh thái Meta (Pixel & Conversions API v22.0)](#-3-cơ-chế-hoạt-động-hệ-sinh-thái-meta-pixel--conversions-api-v220)
4. [Cơ chế hoạt động Hệ sinh thái TikTok Ads (Pixel & Events API v1.3)](#-4-cơ-chế-hoạt-động-hệ-sinh-thái-tiktok-ads-pixel--events-api-v13)
5. [Luồng xử lý chi tiết từng Sự kiện trong Phễu E-commerce](#-5-luồng-xử-lý-chi-tiết-từng-sự-kiện-trong-phễu-e-commerce)
6. [Hệ thống Chống trùng lặp, Chống bắn sai & Bảo mật 6 lớp](#-6-hệ-thống-chống-trùng-lặp-chống-bắn-sai--bảo-mật-6-lớp)
7. [Hướng dẫn Cấu hình trong WordPress & Tài khoản Quảng cáo](#-7-hướng-dẫn-cấu-hình-trong-wordpress--tài-khoản-quảng-cáo)
8. [Hướng dẫn Kiểm thử (Debug Mode & Test Events)](#-8-hướng-dẫn-kiểm-thử-debug-mode--test-events)

---

## 📂 1. Kiến trúc Thư mục & Vai trò từng Module

```text
woo-track/
├── woo-track.php                        # Bootstrap chính: Khai báo tương thích HPOS/Blocks, kiểm tra WooCommerce & khởi chạy
├── README.md                            # Tài liệu kiến trúc & cơ chế hoạt động chi tiết
├── assets/
│   └── js/
│       └── woo-track.js                 # Engine Frontend: Đồng bộ cookie _fbp/_fbc/ttp/ttclid, điều phối gtag/fbq/ttq & bắt sự kiện DOM/AJAX
└── includes/
    ├── class-woo-track-helper.php       # Lớp tiện ích lõi: Chuẩn hóa dữ liệu E-commerce, chuẩn hóa SĐT E.164, UTF-8 & băm SHA-256
    ├── class-woo-track-meta-capi.php    # Lớp Server-side Meta CAPI: Bắt fbclid, lưu Order Meta, khóa Atomic Lock & gửi Graph API v22.0
    ├── class-woo-track-tiktok-events.php# Lớp Server-side TikTok Events API: Bắt ttclid/_ttp, lưu Order Meta, khóa Atomic Lock & gửi Events API v1.3
    ├── class-woo-track-feed.php         # Lớp Product Feed: Xuất XML/CSV chuẩn mới nhất cho Meta Catalog, Google Merchant Center & TikTok Catalog
    ├── class-woo-track-settings.php     # Lớp Quản trị (Admin UI): Quản lý và làm sạch (sanitize) toàn bộ cấu hình trong WooCommerce
    └── class-woo-track-tracker.php      # Lớp Điều phối (Orchestrator): Chèn thẻ <head>/<body>, bắt vòng đời trang & gom hàng đợi sự kiện
```

---

## 🌐 2. Cơ chế hoạt động Hệ sinh thái Google (GTM, GA4, Google Ads)

### 2.1. Thứ tự khởi tạo thẻ chuẩn xác trong `<head>` và `<body>`
Khi trang web tải, hàm `WooTrack_Tracker::inject_head_tags()` (độ ưu tiên `1` trong `wp_head`) thực thi theo đúng thứ tự nghiêm ngặt của Google:

1. **Google Consent Mode v2 (Khởi tạo đầu tiên):**
   - Trước khi bất kỳ thư viện `gtm.js` hay `gtag.js` nào được nạp, plugin khởi tạo `window.dataLayer` và đẩy lệnh:
     ```javascript
     gtag('consent', 'default', {
       'ad_storage': 'granted',
       'ad_user_data': 'granted',
       'ad_personalization': 'granted',
       'analytics_storage': 'granted'
     });
     ```
   - Cơ chế này đảm bảo tài khoản Google Ads và GA4 nhận đầy đủ tín hiệu đồng thuận theo chuẩn mới nhất, giúp **Enhanced Conversions** và tệp **Dynamic Remarketing** hoạt động tối đa công suất mà không bị cảnh báo chẩn đoán.
2. **Google Tag Manager (`GTM-XXXXXXX`):**
   - Nếu có cấu hình GTM ID, chèn đoạn script vùng chứa vào `<head>` và thẻ `<noscript><iframe>` ngay sau thẻ mở `<body>` (qua hook `wp_body_open` độ ưu tiên `1`).
3. **Thẻ Google độc lập (`gtag.js`):**
   - Nếu người dùng cấu hình trực tiếp **GA4 Measurement ID (`G-...`)** và/hoặc **Google Ads Tag ID (`AW-...` / `GT-...`)**, plugin tự động nạp thư viện `https://www.googletagmanager.com/gtag/js` và gọi `gtag('config', ...)`.
   - Nếu bật *Enhanced Conversions*, lệnh config của Google Ads được tự động gắn cờ `{'allow_enhanced_conversions': true}`.

### 2.2. Cơ chế Bắn sự kiện Kép (Dual Dispatch: `dataLayer` + `gtag`)
Trong `assets/js/woo-track.js`, hàm `sendGtagEvent(eventName, eventData)` xử lý thông minh dựa trên cấu hình:
* **Với Google Tag Manager (`dataLayer`):**
  * Luôn đẩy `window.dataLayer.push({ ecommerce: null })` trước mỗi sự kiện E-commerce để xóa sạch trạng thái giỏ hàng cũ trong bộ nhớ GTM, ngăn chặn lỗi rò rỉ dữ liệu giữa các sự kiện liên tiếp.
  * Sau đó đẩy `{ event: eventName, ecommerce: eventData }` theo đúng schema GA4 Ecommerce.
* **Với GA4 & Google Ads trực tiếp (`gtag`):**
  * Gọi trực tiếp `gtag('event', eventName, eventData)` để truyền dữ liệu thẳng về máy chủ Google.

### 2.3. Tương thích Kép: GA4 Ecommerce + Google Ads Dynamic Remarketing & Cart Data + 3 Chế độ Định danh Sản phẩm
Trong `WooTrack_Helper::format_product_item()`, mỗi sản phẩm trong mảng `items` được đóng gói đồng thời các trường của cả GA4 lẫn Google Ads:
```json
{
  "id": "1042",
  "item_id": "1042",
  "item_name": "Extension Automation Pro",
  "price": 350000,
  "quantity": 1,
  "index": 1,
  "google_business_vertical": "retail",
  "item_category": "Extensions",
  "item_variant": "5-thiet-bi"
}
```
* **`item_id`**: Phục vụ chuẩn báo cáo của **GA4**.
* **`id` & `google_business_vertical: "retail"`**: Phục vụ chuẩn **Google Ads Dynamic Remarketing** và **Conversions with Cart Data (CwCD)** để đối chiếu trực tiếp với nguồn cấp dữ liệu trên **Google Merchant Center (GMC)**.
* **3 Chế độ Định danh Sản phẩm (`product_identifier`) linh hoạt:**
  1. **`parent_id` (Mặc định - Tối ưu cho sản phẩm số / Extension / Phần mềm chia nhiều gói thiết bị):** Luôn gửi **ID của Sản phẩm cha** xuyên suốt từ bước `ViewContent` / `view_item` $\rightarrow$ `AddToCart` $\rightarrow$ `InitiateCheckout` $\rightarrow$ `Purchase` để phễu chuyển đổi sản phẩm không bị đứt gãy, trong khi **Giá tiền (`price` / `value` / `item_price`)** và **Tên gói (`item_variant`)** vẫn tự động lấy chính xác 100% theo gói biến thể khách chọn (ví dụ: *Gói 2 thiết bị*, *Gói 5 thiết bị*). Nếu giỏ hàng có nhiều gói của cùng 1 sản phẩm cha, mảng `content_ids` sẽ tự động được lọc duy nhất (`array_unique`).
  2. **`id` (Variation / Post ID):** Gửi ID riêng biệt của từng biến thể sản phẩm (phù hợp với shop thời trang/vật lý tách từng biến thể trên Google Merchant Center).
  3. **`sku` (Mã SKU):** Gửi mã SKU của sản phẩm/biến thể (nếu trống sẽ tự động fallback về ID).

### 2.4. Cơ chế Thu nạp Khách hàng mới (`new_customer`) & Dữ liệu Giỏ hàng (`discount`, `items`)
Khi phát sinh đơn hàng (`purchase`), hàm `WooTrack_Helper::format_order_data()` và `is_new_customer($order)` tự động:
1. Truy vấn nhanh cơ sở dữ liệu đơn hàng của WooCommerce (`wc_get_orders` với `limit => 1`) theo `customer_id` hoặc `billing_email` (loại trừ đơn hiện tại, chỉ xét các đơn có trạng thái `processing` hoặc `completed`).
2. Nếu khách chưa từng có đơn thành công trước đó $\rightarrow$ trả về `'new_customer' => true`, ngược lại trả về `false`.
3. Gửi đầy đủ `transaction_id`, `value`, `currency`, `tax`, `shipping`, `discount`, `coupon`, `new_customer` và mảng `items` vào cả sự kiện `purchase` (GA4) lẫn sự kiện `conversion` (Google Ads), giúp chiến dịch **Performance Max (PMax)** tối ưu giá thầu thu nạp khách hàng mới và tính toán lợi nhuận giỏ hàng.

### 2.5. Cơ chế Google Ads Enhanced Conversions (Dữ liệu người dùng nâng cao)
Trên trang Cảm ơn, nếu bật *Enhanced Conversions*, hàm `WooTrack_Helper::format_user_data_for_ads($order)` trích xuất và chuẩn hóa thông tin từ đơn hàng:
* **Email:** Chuyển về chữ thường UTF-8, cắt khoảng trắng thừa.
* **Số điện thoại (`phone_number`):** Tự động chuẩn hóa về định dạng quốc tế **E.164** có dấu `+` (ví dụ: `0901234567` tại Việt Nam tự động chuyển thành `+84901234567`; hỗ trợ tự động nhận diện mã vùng theo quốc gia thanh toán `VN`, `US`, `CA`, `GB`, `AU`, `SG`, `MY`, `TH`, `JP`, `KR`, `DE`, `FR`).
* **Địa chỉ (`address`):** `first_name`, `last_name`, `street`, `city`, `region`, `postal_code`, `country`.
* Dữ liệu này được gọi qua `gtag('set', 'user_data', payload.user_data)` và đẩy vào `dataLayer` trước khi lệnh `purchase` / `conversion` được bắn đi, để thư viện `gtag.js` tự động băm SHA-256 phía trình duyệt (tránh lỗi băm kép *double-hashing*).

---

## 🎯 3. Cơ chế hoạt động Hệ sinh thái Meta (Pixel & Conversions API v22.0)

### 3.1. Cơ chế Bắt và Bảo toàn Định danh `_fbp` & `_fbc` Vượt qua Page Cache & Proxy
Một trong những nguyên nhân lớn nhất khiến điểm chất lượng sự kiện (EMQ) trên Meta bị thấp là mất cookie `_fbc` (Click ID khi khách bấm quảng cáo có `?fbclid=...`) và `_fbp` (Browser ID) do các plugin tạo Cache HTML tĩnh (WP Rocket, LiteSpeed Cache) hoặc tường lửa Cloudflare/Nginx lọc cookie. WooTrack giải quyết triệt để qua **3 lớp đồng bộ**:

1. **Lớp 1 - Phía Server (`WooTrack_Meta_CAPI::capture_fbclid` ở hook `init`):**
   - Ngay khi URL có tham số `?fbclid=...`, PHP tạo chuỗi chuẩn Meta `fb.1.{timestamp_ms}.{fbclid}` và lưu vào cookie `_fbc` (thời hạn 90 ngày). Nếu khách click vào một quảng cáo mới có `fbclid` khác với `_fbc` cũ, plugin tự động cập nhật `_fbc` mới.
2. **Lớp 2 - Phía Trình duyệt (`syncMetaCookiesAndCheckoutFields` trong `woo-track.js`):**
   - Kể cả khi trang đích được trả về từ bộ nhớ đệm HTML tĩnh (PHP không chạy), JavaScript trên trình duyệt vẫn đọc `URLSearchParams(window.location.search)` để lấy `fbclid` và ghi trực tiếp vào `document.cookie` (`_fbc`).
3. **Lớp 3 - Đính kèm vào Form Thanh toán (`form.checkout`):**
   - Khi khách ở trang Checkout, JavaScript tự động đọc giá trị `_fbp` và `_fbc` từ `document.cookie` và chèn vào 2 trường ẩn `<input type="hidden" name="woo_track_fbp">` và `woo_track_fbc` trong form thanh toán.
   - Khi đơn hàng được tạo (hỗ trợ cả Classic Checkout và WooCommerce Checkout Blocks qua các hook `woocommerce_checkout_update_order_meta`, `woocommerce_store_api_checkout_update_order_meta`, `woocommerce_checkout_order_created`), hàm `save_checkout_meta_data()` sẽ lưu vĩnh viễn 4 thông số của khách vào Order Meta:
     - `_woo_track_meta_fbp`
     - `_woo_track_meta_fbc`
     - `_woo_track_meta_ip` (IP thực của khách qua các header `HTTP_CF_CONNECTING_IP`, `HTTP_X_REAL_IP`, `HTTP_X_FORWARDED_FOR`, `REMOTE_ADDR`)
     - `_woo_track_meta_ua` (User-Agent trình duyệt của khách)

### 3.2. Cách ly Ngữ cảnh Khách hàng vs Quản trị viên / Webhook
Trong `WooTrack_Helper::format_meta_user_data_for_capi($order)`:
* Khi gửi sự kiện CAPI lên Meta, plugin ưu tiên đọc `fbp`, `fbc`, `client_ip_address`, `client_user_agent` từ Order Meta đã lưu lúc khách đặt hàng (hoặc `$order->get_customer_ip_address()` và `$order->get_customer_user_agent()` gốc của WooCommerce).
* Plugin kiểm tra biến `$is_customer_request = !is_admin() && !wp_doing_cron() && (!defined('REST_REQUEST') || !REST_REQUEST)`.
* Nhờ đó, khi Quản trị viên (Admin) bấm duyệt đơn hàng sang trạng thái *Hoàn thành* trong `wp-admin` hoặc khi cổng thanh toán gọi IPN Webhook, plugin **tuyệt đối không bao giờ lấy nhầm địa chỉ IP, trình duyệt hay cookie Facebook của Admin** để gắn cho khách mua hàng.

### 3.3. Chuẩn hóa Tiếng Việt UTF-8 & Băm SHA-256 theo chuẩn Graph API `v22.0`
Hàm `WooTrack_Helper::hash_meta_value($value, $type, $country)` xử lý chuẩn hóa trước khi băm `hash('sha256', $value)`:
* Sử dụng `mb_strtolower($str, 'UTF-8')` thay vì `strtolower()` thông thường của PHP, đảm bảo các ký tự chữ hoa tiếng Việt có dấu (như `Đ`, `Á`, `Ấ`, ` Nguyễn`, `Hồ Chí Minh`) được chuyển về chữ thường UTF-8 chính xác 100% trước khi băm SHA-256.
* **Quy tắc làm sạch theo từng loại trường của Meta:**
  * `em` (Email): Chữ thường UTF-8, xóa khoảng trắng hai đầu.
  * `ph` (Số điện thoại): Chỉ giữ lại chữ số và chuyển số `0` ở đầu thành mã quốc gia (ví dụ: `84901234567`).
  * `fn`, `ln`, `ct`, `st` (Họ, Tên, Thành phố, Tỉnh/Bang): Chữ thường UTF-8, loại bỏ toàn bộ dấu câu và khoảng trắng (`preg_replace('/[\s\p{P}]/u', '', $value)`).
  * `zp` (Mã bưu chính): Chữ thường, bỏ khoảng trắng và dấu gạch ngang.
  * `country` (Quốc gia): Mã ISO 2 ký tự viết thường (ví dụ: `vn`, `us`).
  * `external_id`: ID tài khoản khách hàng đã băm SHA-256.
* Nếu giá trị sau khi làm sạch là chuỗi rỗng, hàm trả về `''` và loại bỏ khỏi payload (tuyệt đối không băm chuỗi rỗng).
* Toàn bộ các trường đã băm được đóng gói dưới dạng mảng `array($hashed_value)` theo đúng quy định của **Meta Graph API `v22.0`**.

### 3.4. Cơ chế Khử trùng lặp (Event Deduplication) giữa Meta Pixel và CAPI
Khi khách hoàn tất đơn hàng trên trang Cảm ơn:
1. Plugin sinh một mã định danh sự kiện duy nhất: **`$event_id = 'order_' . $order->get_id()`** (ví dụ: `order_1058`).
2. **Phía Trình duyệt (Meta Pixel):**
   - Gọi lệnh:
     ```javascript
     fbq('track', 'Purchase', customData, { eventID: 'order_1058' });
     ```
3. **Phía Máy chủ (Meta Conversions API):**
   - Gửi HTTP POST tới `https://graph.facebook.com/v22.0/{PIXEL_ID}/events` với payload:
     ```json
     {
       "data": [
         {
           "event_name": "Purchase",
           "event_time": 1729680000,
           "event_id": "order_1058",
           "event_source_url": "https://example.com/checkout/order-received/1058/?key=wc_order_xxx",
           "action_source": "website",
           "user_data": { ... },
           "custom_data": { ... }
         }
       ]
     }
     ```
4. **Đồng bộ `event_time`:** `WooTrack_Meta_CAPI::send_purchase()` lấy timestamp lúc tạo đơn hàng (`$order->get_date_created()->getTimestamp()`, miễn là trong vòng 7 ngày). Khi Meta nhận được cả 2 sự kiện từ Trình duyệt và Máy chủ có cùng `event_name: "Purchase"` và cùng `event_id: "order_1058"`, Meta sẽ **hợp nhất (Deduplicate) thành 1 chuyển đổi duy nhất**, đồng thời cộng gộp điểm dữ liệu để đạt điểm **Event Match Quality (EMQ)** cao nhất.

### 3.5. Cơ chế Hàng đợi Ngầm (Zero-Latency Shutdown Queue) & Static Cache
Để việc gửi CAPI Server-to-Server sang máy chủ quốc tế `graph.facebook.com` **không làm chậm quá trình bấm nút Đặt hàng hoặc tải trang Cảm ơn (0ms độ trễ)**:
1. **Gom dữ liệu & Khóa tức thì trong `< 1ms`:** Khi `WooTrack_Meta_CAPI::send_purchase()` được gọi, plugin trích xuất ngay toàn bộ ngữ cảnh của khách (`user_data`, `custom_data`), thiết lập khóa chống trùng và gộp chung 1 lần `$order->save()` duy nhất, sau đó đẩy gói tin vào hàng đợi bộ nhớ `$shutdown_queue`.
2. **Đóng kết nối trình duyệt trước khi gọi API (`process_shutdown_queue`):** Ở cuối vòng đời trang (hook `shutdown` độ ưu tiên `999`), plugin gọi `fastcgi_finish_request()` (PHP-FPM) hoặc `litespeed_finish_request()` (LiteSpeed Server) để **trả hoàn tất 100% giao diện trang Cảm ơn về cho trình duyệt khách hàng ngay lập tức**, sau đó máy chủ mới âm thầm thực hiện cuộc gọi HTTP POST sang Meta Graph API ở hậu trường.
3. **Bộ nhớ đệm cấu hình (`WooTrack_Helper::$settings_cache`):** Toàn bộ cấu hình plugin chỉ đọc từ Database (`get_option`) đúng 1 lần duy nhất trong suốt vòng đời mỗi request.

---

## 🎵 4. Cơ chế hoạt động Hệ sinh thái TikTok Ads (Pixel & Events API v1.3)

### 4.1. Cơ chế Bắt và Bảo toàn Định danh `_ttp` & `ttclid`
Tương tự cơ chế của Meta, TikTok sử dụng cookie đệ nhất `_ttp` (Pixel Cookie ID) và tham số nhấp chuột `ttclid` (TikTok Click ID) để liên kết chính xác chuyển đổi về chiến dịch quảng cáo:
1. **Lớp 1 - Phía Server (`WooTrack_TikTok_Events::capture_ttclid` ở hook `init`):**
   - Khi URL có tham số `?ttclid=...`, PHP tự động trích xuất và lưu vào cookie `_ttclid` và `ttclid` (thời hạn 90 ngày).
2. **Lớp 2 - Phía Trình duyệt (`syncTrackingCookiesAndCheckoutFields` trong `woo-track.js`):**
   - Đọc trực tiếp `ttclid` từ URL và `_ttp` từ `document.cookie` để lưu trữ đồng bộ ngay cả khi trang được phân phối qua bộ nhớ đệm HTML tĩnh (Page Cache).
3. **Lớp 3 - Lưu vào Order Meta khi Khách Đặt hàng:**
   - Tại trang Checkout, JavaScript tự động inject giá trị `_ttp` và `ttclid` vào các input ẩn `woo_track_ttp` và `woo_track_ttclid`.
   - Plugin lưu vĩnh viễn vào Order Meta (hỗ trợ cả Classic Checkout lẫn WooCommerce Checkout Blocks):
     - `_woo_track_tiktok_ttp`
     - `_woo_track_tiktok_ttclid`
     - `_woo_track_meta_ip` (IP khách hàng thực tế)
     - `_woo_track_meta_ua` (User-Agent trình duyệt khách hàng)

### 4.2. Khớp Nâng Cao (Advanced Matching) & Băm SHA-256 theo chuẩn TikTok API v1.3
Hàm `WooTrack_Helper::format_tiktok_user_data_for_api($order)` xử lý thông tin định danh khách hàng:
* **Email:** Chuẩn hóa chữ thường UTF-8, cắt khoảng trắng và băm `hash('sha256', $email)`.
* **Số điện thoại (`phone_number` / `phone`):** Chuẩn hóa về định dạng quốc tế **E.164** có dấu `+` (ví dụ: `+84901234567`) và băm `hash('sha256', $e164_phone)`. TikTok API chấp nhận cả trường `phone` lẫn `phone_number`.
* **Mã định danh bên ngoài (`external_id`):** ID tài khoản WordPress của khách hàng (nếu có) được băm SHA-256.
* **IP & User-Agent:** Lấy trực tiếp từ Order Meta đã lưu lúc khách đặt hàng, đảm bảo tính cách ly ngữ cảnh (tuyệt đối không lấy nhầm IP hay User-Agent của Quản trị viên duyệt đơn).
* **Phía Trình duyệt:** Gọi `ttq.identify({ email: '...', phone_number: '...', external_id: '...' })` để thư viện TikTok Pixel tự động băm an toàn trên máy khách.

### 4.3. Khử trùng lặp Đơn hàng (Deduplication) giữa TikTok Pixel và Events API
1. Plugin sinh một mã định danh duy nhất: **`$event_id = 'order_' . $order->get_id()`** (ví dụ: `order_1058`).
2. **Phía Trình duyệt (TikTok Pixel):**
   ```javascript
   ttq.track('Purchase', eventPayload, { event_id: 'order_1058' });
   ```
3. **Phía Máy chủ (TikTok Events API v1.3):**
   - Gửi HTTP POST tới `https://business-api.tiktok.com/open_api/v1.3/event/track/` với cấu trúc:
   ```json
   {
     "event_source": "web",
     "event_source_id": "{TIKTOK_PIXEL_ID}",
     "data": [
       {
         "event": "Purchase",
         "event_id": "order_1058",
         "event_time": 1729680000,
         "user": {
           "email": "...",
           "phone_number": "...",
           "ip": "...",
           "user_agent": "...",
           "ttp": "...",
           "ttclid": "..."
         },
         "properties": {
           "value": 350000,
           "currency": "VND",
           "contents": [ ... ]
         }
       }
     ]
   }
   ```
4. **Tùy chọn Tên sự kiện Mua hàng (`Purchase` vs `CompletePayment`):**
   - TikTok đã chuẩn hóa tên sự kiện mua hàng thành `Purchase` (đồng nhất với Meta). Tuy nhiên, một số tài khoản TikTok Ads tạo sự kiện dưới tên cũ `CompletePayment`. WooTrack cung cấp tùy chọn chuyển đổi trong cài đặt; plugin đảm bảo **tên sự kiện phía Trình duyệt và Máy chủ luôn khớp nhau 100%**, giúp TikTok khử trùng lặp chính xác không bị đếm đúp.

### 4.4. 8 Sự kiện Tiêu chuẩn TikTok được hỗ trợ toàn diện
1. `ViewContent`: Trang chi tiết sản phẩm.
2. `AddToCart`: Nút AJAX danh mục, biến thể và form chi tiết sản phẩm.
3. `InitiateCheckout`: Trang thanh toán đơn hàng.
4. `AddPaymentInfo`: Khi khách hàng chọn phương thức thanh toán tại trang Checkout.
5. `PlaceAnOrder`: Khi khách hàng nhấn nút Đặt hàng tại trang Checkout.
6. `Purchase` (hoặc `CompletePayment`): Trang Cảm ơn và Server Events API.
7. `Search`: Trang kết quả tìm kiếm sản phẩm.
8. `AddToWishlist`: Khi khách nhấn nút Thêm vào danh sách yêu thích.

### 4.5. Cơ chế Hàng đợi Ngầm (Zero-Latency Shutdown Queue)
Giống như Meta CAPI, cuộc gọi HTTP POST gửi Events API sang TikTok được đưa vào hàng đợi `$shutdown_queue` và chỉ thực thi sau khi lệnh `fastcgi_finish_request()` / `litespeed_finish_request()` đã hoàn tất đóng kết nối với trình duyệt khách hàng, bảo đảm **0ms độ trễ** cho trải nghiệm người dùng.

---

## 🛒 5. Luồng xử lý chi tiết từng Sự kiện trong Phễu E-commerce

| Sự kiện (Google / Meta / TikTok) | Hook / Cơ chế kích hoạt | Dữ liệu thu thập & Cách vận hành |
| :--- | :--- | :--- |
| **1. Xem trang**<br>(`page_view` / `PageView`) | `wp_head` (Độ ưu tiên `1`) | Tự động kích hoạt ngay trên `<head>` ở mọi trang qua `gtag('config', ...)`, `fbq('track', 'PageView')` và `ttq.page()`. |
| **2. Xem danh sách SP**<br>(`view_item_list`) | `is_shop()` hoặc `is_product_taxonomy()` trong hook `wp` | Thu thập tối đa 12 sản phẩm đầu tiên trong danh sách hiện tại (`$wp_query->posts`), tự động phân biệt danh sách Cửa hàng (`shop`), Danh mục (`cat_{id}`) hoặc Thẻ (`tag_{id}`). |
| **3. Xem chi tiết SP**<br>(`view_item` / `ViewContent`) | `is_product()` trong hook `wp` | Lấy thông tin chi tiết sản phẩm hiện tại (ID/SKU, tên, danh mục, giá hiển thị, tiền tệ) và đẩy vào `window.wooTrackPayload` để bắn ngay khi trang tải xong cho Google, Meta và TikTok. |
| **4. Tìm kiếm SP**<br>(`Search` - Meta & TikTok) | `is_search()` trong hook `wp` | Lấy từ khóa tìm kiếm `get_search_query()` cùng danh sách ID/SKU của tối đa 10 sản phẩm kết quả đầu tiên gửi vào `search_string` và `content_ids`. |
| **5. Thêm vào giỏ hàng**<br>(`add_to_cart` / `AddToCart`) | Kết hợp **Server Hook** + **AJAX Fragments** + **DOM Click** + **`found_variation`** | Hỗ trợ mọi kiểu thêm giỏ hàng trên WooCommerce:<br>• **Nút AJAX ngoài trang Shop/Danh mục:** Filter `woocommerce_loop_add_to_cart_link` (dùng `preg_replace_callback` an toàn) gắn dữ liệu vào thuộc tính `data-woo-track-item`, `data-woo-track-meta-item` & `data-woo-track-tiktok-item`. Đồng thời hook `woocommerce_add_to_cart` + `woocommerce_add_to_cart_fragments` trả về dữ liệu chính xác từ server qua phản hồi AJAX.<br>• **Trang chi tiết sản phẩm (Single Product) & Biến thể (Variations):** Lắng nghe sự kiện `found_variation` của WooCommerce và sự kiện click nút `.single_add_to_cart_button`, tự động tra cứu giá (`display_price`) và `SKU` của đúng biến thể đang chọn (hỗ trợ cả sản phẩm có > 30 biến thể nạp qua AJAX). Đồng thời chỉ lưu dự phòng vào `WC()->session` (với hạn sử dụng 30 giây) nếu form thực hiện submit tải lại trang truyền thống. Bắn đồng thời cho Google, Meta và TikTok. |
| **6. Bắt đầu thanh toán**<br>(`begin_checkout` / `InitiateCheckout`) | `is_checkout() && !is_order_received_page()` | Duyệt toàn bộ giỏ hàng `WC()->cart->get_cart()`, tính tổng tiền (`total`) và tổng số lượng thực tế của tất cả sản phẩm (`$total_qty` cho `num_items`). Bắn đồng thời cho Google, Meta và TikTok. Nếu có cấu hình nhãn Google Ads *Begin Checkout Label*, tự động bắn thêm sự kiện `conversion` phụ cho Google Ads. |
| **7. Thêm thông tin thanh toán**<br>(`AddPaymentInfo` - TikTok) | Sự kiện chọn cổng thanh toán tại trang Checkout | Lắng nghe thay đổi chọn `input[name="payment_method"]`, tự động gửi thông tin tổng giá trị và sản phẩm giỏ hàng sang TikTok. |
| **8. Đặt hàng**<br>(`PlaceAnOrder` - TikTok) | Sự kiện submit form đặt hàng tại Checkout | Khi khách nhấn nút Đặt hàng, tự động bắn sự kiện `PlaceAnOrder` sang TikTok trước khi chuyển hướng sang cổng thanh toán. |
| **9. Thêm vào DS yêu thích**<br>(`AddToWishlist` - TikTok) | Nút click Wishlist (YITH, TI Wishlist, Smart Wishlist...) | Lắng nghe click các nút thêm wishlist phổ biến, tự động gửi `AddToWishlist` kèm thông tin sản phẩm sang TikTok. |
| **10. Mua hàng thành công**<br>(`purchase` / `Purchase` / `CompletePayment` + `conversion`) | Trang Cảm ơn (`order-received` / `woocommerce_thankyou`) & Hook dự phòng (`woocommerce_payment_complete`, `woocommerce_order_status_completed`) | Bắn đồng thời lên **GA4** (`purchase`), **Google Ads** (`conversion` kèm Cart Data & Enhanced Conversions), **Meta Pixel** (`Purchase` kèm `eventID`), **Meta CAPI** (`Purchase` kèm `event_id`), **TikTok Pixel** (`Purchase` kèm `event_id`) và **TikTok Events API** (`Purchase` kèm `event_id`). |

---

## 🛡️ 6. Hệ thống Chống trùng lặp, Chống bắn sai & Bảo mật 6 lớp

Plugin được thiết kế với **6 lớp bảo vệ độc lập** để đảm bảo số liệu quảng cáo không bao giờ bị ảo hoặc trùng lặp:

1. **Lớp 1 - Xác thực quyền truy cập đơn hàng (`WooTrack_Helper::get_valid_thankyou_order`):**
   - Trên trang nhận đơn hàng (`/checkout/order-received/{id}/`), plugin kiểm tra nghiêm ngặt mã khóa đơn hàng `?key=wc_order_...` thông qua `$order->key_is_valid($order_key)` (hoặc xác thực ID chủ sở hữu đang đăng nhập).
   - Ngăn chặn hoàn toàn việc kẻ xấu hoặc bot quét ID đơn hàng tuần tự để đánh cắp thông tin cá nhân (Email, SĐT, Địa chỉ) trong mã nguồn trang hoặc kích hoạt bắn chuyển đổi giả.
2. **Lớp 2 - Chặn đơn hàng thanh toán lỗi hoặc bị hủy (`is_order_valid_for_purchase_tracking`):**
   - Khi khách hàng thanh toán qua cổng online (VNPay, MoMo, PayPal, Stripe...) nhưng bấm hủy hoặc giao dịch thất bại, WooCommerce vẫn chuyển hướng về URL `order-received` với trạng thái đơn là `failed` hoặc `cancelled`.
   - Plugin tự động kiểm tra và **từ chối bắn sự kiện `Purchase`** đối với các trạng thái `failed`, `cancelled`, `refunded`, `trash`, `checkout-draft`. Nếu sau đó khách bấm thanh toán lại và thành công, đơn hàng vẫn được ghi nhận bình thường.
3. **Lớp 3 - Chống tải lại trang F5 (Refresh Protection):**
   - Ngay khi đơn hàng được ghi nhận lần đầu tiên, plugin lưu timestamp vào Order Meta `_woo_track_purchase_tracked`, `_woo_track_meta_capi_tracked` và `_woo_track_tiktok_api_tracked`.
   - Khi khách hàng mở lại link trang Cảm ơn trên điện thoại, chia sẻ link, hoặc nhấn F5 tải lại trang nhiều lần, plugin sẽ phát hiện cờ này và chặn tuyệt đối việc bắn lại sự kiện cho cả Google, Meta và TikTok.
4. **Lớp 4 - Khóa nguyên tử chống xung đột luồng song song (Atomic Lock cho CAPI & TikTok API):**
   - Với các cổng thanh toán tự động (như Webhook QR SePay/Casso, IPN của VNPay/MoMo), thường xảy ra trường hợp **Server Webhook gọi vào duyệt đơn** cùng một mili-giây với lúc **Trình duyệt của khách vừa chuyển về Trang Cảm ơn**.
   - Cả Meta CAPI và TikTok Events API đều thiết lập khóa transient nguyên tử (`woo_track_capi_lock_{$order_id}` và `woo_track_tiktok_lock_{$order_id}`) và ghi nhận Order Meta **trước khi** thực hiện cuộc gọi HTTP. Luồng thứ 2 chạy song song sẽ thấy khóa và dừng lại ngay lập tức.
5. **Lớp 5 - Bảo vệ cửa sổ khử trùng lặp 48 giờ (`maybe_send_delayed_purchase`):**
   - Meta và TikTok chỉ gộp (Deduplicate) sự kiện Pixel và Server API trong vòng **48 giờ**.
   - Nếu một đơn hàng đã được bắn qua Pixel trình duyệt lúc mới đặt, nhưng vài ngày sau (quá 48 giờ) Admin mới chuyển trạng thái đơn sang *Hoàn thành*, plugin sẽ tự động nhận diện đơn đã quá 48 giờ và **không bắn bù Server API** nữa để tránh bị nền tảng tính thành đơn hàng thứ 2. (Ngược lại, nếu lúc đặt hàng khách dùng AdBlock khiến Pixel chưa từng bắn, Server API vẫn sẽ gửi bù an toàn khi đơn hoàn tất).
6. **Lớp 6 - Chống bắn đúp & bắn giả nút Thêm vào giỏ hàng (`isDuplicateAddToCart` & Session TTL):**
   - Tự động vô hiệu hóa sự kiện click nếu nút *Thêm vào giỏ hàng* đang ở trạng thái `.disabled`, `.wc-variation-selection-needed` (chưa chọn thuộc tính biến thể), `.wc-variation-is-unavailable` (hết hàng) hoặc form chưa điền đủ trường bắt buộc (`checkValidity()`).
   - Kết hợp bộ nhớ đệm 2 tầng: **In-memory (`2.5 giây`)** trên cùng trang và **`sessionStorage` (`30 giây` có tự động giải phóng khóa `consumeRecentAdd`)** giữa các lần chuyển trang, cùng cơ chế chỉ lưu `WC()->session` cho request không phải AJAX/REST với thời hạn tự hủy **30 giây**, đảm bảo sự kiện `AddToCart` chỉ nổ đúng 1 lần duy nhất trong mọi tình huống trên mọi nền tảng.

---

## 🛠️ 7. Hướng dẫn Cấu hình trong WordPress & Tài khoản Quảng cáo

### 7.1. Cấu hình trong Quản trị WordPress
Truy cập **WooCommerce $\rightarrow$ WooTrack (Google Suite)**:
1. **Google Tag Manager (GTM):** Nhập `GTM-XXXXXXX` (Để trống nếu bạn dùng trực tiếp GA4 & Google Ads).
2. **Google Analytics 4 (GA4):** Nhập Measurement ID `G-XXXXXXXXXX`.
3. **Google Ads:**
   - **Google Ads / Google Tag ID:** Nhập `AW-XXXXXXXXXX` (hoặc `GT-XXXXXX`).
   - **Purchase / Begin Checkout / Add to Cart Conversion Label:** Nhập chuỗi nhãn chuyển đổi (phần sau dấu `/` của `send_to`).
   - **Enhanced Conversions:** Tích chọn ✅.
   - **Google Consent Mode v2:** Tích chọn ✅.
4. **Meta (Facebook) Pixel & Conversions API (CAPI):**
   - **Meta Pixel ID / Dataset ID:** Nhập ID Pixel (ví dụ: `123456789012345`).
   - **Kích hoạt Meta Pixel:** Tích chọn ✅.
   - **Kích hoạt Conversions API (CAPI):** Tích chọn ✅ và dán **CAPI Access Token** (lấy tại *Meta Events Manager $\rightarrow$ Cài đặt $\rightarrow$ Tạo Access Token*).
   - **Advanced Matching:** Tích chọn ✅.
5. **TikTok Ads (Pixel & Events API v1.3):**
   - **TikTok Pixel ID:** Nhập Pixel ID (ví dụ: `CXXXXXXXXXXXXXXXXX`).
   - **Kích hoạt TikTok Pixel:** Tích chọn ✅.
   - **Kích hoạt TikTok Events API:** Tích chọn ✅ và dán **TikTok Access Token** (lấy tại *TikTok Events Manager $\rightarrow$ Quản lý Pixel $\rightarrow$ Cài đặt $\rightarrow$ Tạo Access Token*).
   - **Tên sự kiện Mua hàng:** Chọn `Purchase` (Mặc định chuẩn) hoặc `CompletePayment`.
   - **Bật các sự kiện:** Tích chọn các sự kiện bạn muốn theo dõi (`ViewContent`, `AddToCart`, `InitiateCheckout`, `AddPaymentInfo`, `PlaceAnOrder`, `Purchase`, `Search`, `AddToWishlist`).
   - **Advanced Matching:** Tích chọn ✅.
6. **Cấu hình Định danh Sản phẩm (`product_identifier`):**
   - Chọn **`ID Sản phẩm cha (Parent ID)`** (Mặc định khuyên dùng cho sản phẩm số / Extension có nhiều gói biến thể 2 thiết bị, 5 thiết bị...), hoặc chọn *Variation/Post ID* / *Mã SKU* tùy theo cấu trúc Catalog của bạn.
7. **Nguồn Cấp Dữ Liệu Sản Phẩm (`WooTrack_Feed` - XML / CSV Feed cho Meta, Google & TikTok):**
   - **Meta Catalog XML / CSV:** `/?woo_track_feed=meta` và `/?woo_track_feed=meta&format=csv` (Chuẩn `<g:availability>in stock</g:availability>` của Meta).
   - **Google Merchant Center XML / CSV:** `/?woo_track_feed=google` và `/?woo_track_feed=google&format=csv` (Chuẩn `<g:availability>in_stock</g:availability>` và `<g:identifier_exists>no</g:identifier_exists>` của Google).
   - **TikTok Catalog XML / CSV Feed:** `/?woo_track_feed=tiktok` và `/?woo_track_feed=tiktok&format=csv` (Chuẩn Google RSS 2.0 được TikTok hỗ trợ native, đồng bộ 100% ID sản phẩm với TikTok Pixel & Events API).
   - **Cơ chế Smart Cache + Realtime Invalidation (0% tải CPU):** Lưu bộ đệm Transient 6 giờ giúp bot quét trong 1ms mà không truy vấn SQL lặp lại; tự động xóa cache và cập nhật Realtime ngay lập tức khi Admin thêm/sửa/xóa sản phẩm.

### 7.2. Các cài đặt BẮT BUỘC kiểm tra trên Meta Ads, Google Ads & TikTok Ads
* **Trên Meta Events Manager (Trình quản lý sự kiện $\rightarrow$ Cài đặt):**
  * ❌ **PHẢI TẮT:** *"Theo dõi sự kiện tự động không cần mã" (Track events automatically without code)* để tránh Meta tự ý đếm thêm sự kiện khi khách bấm nút thanh toán/mua hàng.
  * ✅ **PHẢI BẬT:** *"Khớp nâng cao tự động" (Automatic Advanced Matching)* và bật tất cả các trường thông tin khách hàng (Email, SĐT, Họ tên, Thành phố, Quốc gia, External ID).
* **Trên Google Ads (`Mục tiêu -> Chuyển đổi -> Cài đặt`):**
  * ✅ **PHẢI BẬT:** *"Lượt chuyển đổi nâng cao cho web" (Enhanced Conversions for web)* $\rightarrow$ Chọn phương thức **Thẻ Google (Google tag)**.
  * ⚠️ **LƯU Ý TRÁNH TRÙNG ĐƠN:** Nếu bạn vừa điền **Google Ads Purchase Label** trong WooTrack, vừa liên kết tài khoản GA4 để **Import sự kiện `purchase` từ GA4** sang Google Ads, hãy đặt hành động chuyển đổi trực tiếp của Google Ads làm **"Chính" (Primary)** và chuyển hành động `purchase` nhập từ GA4 sang **"Phụ" (Secondary)**.
* **Trên TikTok Events Manager (Trình quản lý sự kiện $\rightarrow$ Pixel $\rightarrow$ Cài đặt):**
  * ✅ **PHẢI BẬT:** *"Khớp nâng cao tự động" (Automatic Advanced Matching)*: Bật Email và Số điện thoại.
  * ❌ **TẮT:** Các bộ lọc/công cụ tạo sự kiện không cần mã (Event Setup Tool) trên giao diện TikTok nếu bạn đã cấu hình sự kiện trong WooTrack để tránh bị nhân đôi số liệu.

---

## 🔍 8. Hướng dẫn Kiểm thử (Debug Mode & Test Events)

1. Vào **WooCommerce $\rightarrow$ WooTrack (Google Suite)**:
   - Tích chọn **Bật Debug Console** ở mục cuối cùng.
   - Nếu muốn kiểm thử Meta CAPI thời gian thực: copy mã `TESTxxxxx` trong tab **Thử nghiệm sự kiện (Test Events)** của Meta Events Manager dán vào ô **Test Event Code** của Meta.
   - Nếu muốn kiểm thử TikTok Events API thời gian thực: copy mã test trong tab **Test Events** của TikTok Events Manager dán vào ô **TikTok Test Event Code**.
   - Bấm **Lưu thay đổi**.
2. Mở trình duyệt và nhấn `F12` $\rightarrow$ chuyển sang tab **Console**:
   - Mọi sự kiện Google sẽ in log nhãn màu xanh lam: `[WooTrack Google 🚀] <tên_sự_kiện> <payload>`.
   - Mọi sự kiện Meta Pixel sẽ in log nhãn màu xanh đậm: `[WooTrack Meta 🎯] <tên_sự_kiện> <payload> { eventID: ... }`.
   - Mọi sự kiện TikTok Pixel sẽ in log nhãn màu tím đen: `[WooTrack TikTok 🎵] <tên_sự_kiện> <payload> { event_id: ... }`.
3. Thực hiện đặt thử 1 đơn hàng:
   - Trong tab **Test Events** của Meta và TikTok, bạn sẽ thấy sự kiện `Purchase` đến từ cả **Trình duyệt (Browser)** và **Máy chủ (Server)** với cùng một mã `event_id` (`order_xxx`) và trạng thái **Đã khử trùng lặp (Deduplicated)**.
   - Thử nhấn `F5` tải lại trang Cảm ơn: Console sẽ hiển thị thông báo `purchase_duplicate_blocked` và không gửi lại sự kiện cho bất kỳ nền tảng nào.
4. **Quan trọng:** Sau khi kiểm thử xong, hãy **xóa trắng ô Test Event Code** và **tắt Debug Mode** rồi bấm Lưu cấu hình để plugin vận hành chính thức ở môi trường Live.
