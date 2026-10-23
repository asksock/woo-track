# WooTrack - All-in-One Tracker for WooCommerce (Google & Meta)

**WooTrack** là bộ plugin WordPress độc lập, chuyên sâu dành riêng cho **WooCommerce** (hỗ trợ chuẩn **PHP 8.x+**, **HPOS - High-Performance Order Storage** và **Cart/Checkout Blocks**). Plugin giúp tích hợp đồng bộ và chính xác toàn bộ hệ sinh thái **Google** (**Google Tag Manager**, **Google Analytics 4**, **Google Ads**) và **Meta (Facebook)** (**Meta Pixel** & **Conversions API - CAPI `v22.0`**) với cơ chế **Khử trùng lặp đa tầng (Multi-layer Deduplication)** mà không cần cài đặt thêm bất kỳ plugin bên thứ ba nào.

---

## 📑 Mục lục

1. [Kiến trúc Thư mục & Vai trò từng Module](#-1-kiến-trúc-thư-mục--vai-trò-từng-module)
2. [Cơ chế hoạt động Hệ sinh thái Google (GTM, GA4, Google Ads)](#-2-cơ-chế-hoạt-động-hệ-sinh-thái-google-gtm-ga4-google-ads)
3. [Cơ chế hoạt động Hệ sinh thái Meta (Pixel & Conversions API v22.0)](#-3-cơ-chế-hoạt-động-hệ-sinh-thái-meta-pixel--conversions-api-v220)
4. [Luồng xử lý chi tiết từng Sự kiện trong Phễu E-commerce](#-4-luồng-xử-lý-chi-tiết-từng-sự-kiện-trong-phễu-e-commerce)
5. [Hệ thống Chống trùng lặp, Chống bắn sai & Bảo mật 6 lớp](#-5-hệ-thống-chống-trùng-lặp-chống-bắn-sai--bảo-mật-6-lớp)
6. [Hướng dẫn Cấu hình trong WordPress & Tài khoản Quảng cáo](#-6-hướng-dẫn-cấu-hình-trong-wordpress--tài-khoản-quảng-cáo)
7. [Hướng dẫn Kiểm thử (Debug Mode & Meta Test Events)](#-7-hướng-dẫn-kiểm-thử-debug-mode--meta-test-events)

---

## 📂 1. Kiến trúc Thư mục & Vai trò từng Module

```text
woo-track/
├── woo-track.php                     # Bootstrap chính: Khai báo tương thích HPOS/Blocks, kiểm tra WooCommerce & khởi chạy
├── README.md                         # Tài liệu kiến trúc & cơ chế hoạt động chi tiết
├── assets/
│   └── js/
│       └── woo-track.js              # Engine Frontend: Đồng bộ cookie _fbp/_fbc, điều phối gtag/dataLayer/fbq & bắt sự kiện DOM/AJAX
└── includes/
    ├── class-woo-track-helper.php    # Lớp tiện ích lõi: Chuẩn hóa dữ liệu E-commerce, chuẩn hóa SĐT E.164, UTF-8 & băm SHA-256
    ├── class-woo-track-meta-capi.php # Lớp Server-side CAPI: Bắt fbclid, lưu Order Meta, khóa Atomic Lock & gửi Graph API v22.0
    ├── class-woo-track-settings.php  # Lớp Quản trị (Admin UI): Quản lý và làm sạch (sanitize) toàn bộ cấu hình trong WooCommerce
    └── class-woo-track-tracker.php   # Lớp Điều phối (Orchestrator): Chèn thẻ <head>/<body>, bắt vòng đời trang & gom hàng đợi sự kiện
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

### 2.3. Tương thích Kép: GA4 Ecommerce + Google Ads Dynamic Remarketing & Cart Data
Trong `WooTrack_Helper::format_product_item()`, mỗi sản phẩm trong mảng `items` được đóng gói đồng thời các trường của cả GA4 lẫn Google Ads:
```json
{
  "id": "1042",
  "item_id": "1042",
  "item_name": "Áo Polo Nam Cao Cấp",
  "price": 350000,
  "quantity": 2,
  "index": 1,
  "google_business_vertical": "retail",
  "item_category": "Áo Nam",
  "item_variant": "xanh-navy, size-l"
}
```
* **`item_id`**: Phục vụ chuẩn báo cáo của **GA4**.
* **`id` & `google_business_vertical: "retail"`**: Phục vụ chuẩn **Google Ads Dynamic Remarketing** và **Conversions with Cart Data (CwCD)** để đối chiếu trực tiếp với nguồn cấp dữ liệu trên **Google Merchant Center (GMC)**.
* Người dùng có thể chọn dùng **Post ID** (ví dụ `1042`) hoặc **Mã SKU** trong trang cài đặt để khớp 100% với Feed sản phẩm trên GMC.

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

## 🛒 4. Luồng xử lý chi tiết từng Sự kiện trong Phễu E-commerce

| Sự kiện (Google / Meta) | Hook / Cơ chế kích hoạt | Dữ liệu thu thập & Cách vận hành |
| :--- | :--- | :--- |
| **1. Xem trang**<br>(`page_view` / `PageView`) | `wp_head` (Độ ưu tiên `1`) | Tự động kích hoạt ngay trên `<head>` ở mọi trang qua `gtag('config', ...)` và `fbq('track', 'PageView')`. |
| **2. Xem danh sách SP**<br>(`view_item_list`) | `is_shop()` hoặc `is_product_taxonomy()` trong hook `wp` | Thu thập tối đa 12 sản phẩm đầu tiên trong danh sách hiện tại (`$wp_query->posts`), tự động phân biệt danh sách Cửa hàng (`shop`), Danh mục (`cat_{id}`) hoặc Thẻ (`tag_{id}`). |
| **3. Xem chi tiết SP**<br>(`view_item` / `ViewContent`) | `is_product()` trong hook `wp` | Lấy thông tin chi tiết sản phẩm hiện tại (ID/SKU, tên, danh mục, giá hiển thị, tiền tệ) và đẩy vào `window.wooTrackPayload` để bắn ngay khi trang tải xong. |
| **4. Tìm kiếm SP**<br>(`Search` - Meta) | `is_search()` trong hook `wp` | Lấy từ khóa tìm kiếm `get_search_query()` cùng danh sách ID/SKU của tối đa 10 sản phẩm kết quả đầu tiên gửi vào `search_string` và `content_ids`. |
| **5. Thêm vào giỏ hàng**<br>(`add_to_cart` / `AddToCart`) | Kết hợp **Server Hook** + **AJAX Fragments** + **DOM Click** | Hỗ trợ mọi kiểu thêm giỏ hàng trên WooCommerce:<br>• **Nút AJAX ngoài trang Shop/Danh mục:** Filter `woocommerce_loop_add_to_cart_link` (dùng `preg_replace_callback` an toàn) gắn dữ liệu vào thuộc tính `data-woo-track-item` & `data-woo-track-meta-item`. Đồng thời hook `woocommerce_add_to_cart` + `woocommerce_add_to_cart_fragments` trả về dữ liệu chính xác từ server qua phản hồi AJAX.<br>• **Trang chi tiết sản phẩm (Single Product) & Biến thể (Variations):** Lắng nghe sự kiện click nút `.single_add_to_cart_button`, tự động tra cứu giá (`display_price`) và `SKU` của đúng biến thể đang chọn từ `product_variations`. Đồng thời lưu dự phòng vào `WC()->session` nếu form thực hiện tải lại trang. |
| **6. Bắt đầu thanh toán**<br>(`begin_checkout` / `InitiateCheckout`) | `is_checkout() && !is_order_received_page()` | Duyệt toàn bộ giỏ hàng `WC()->cart->get_cart()`, tính tổng tiền (`total`) và tổng số lượng thực tế của tất cả sản phẩm (`$total_qty` cho `num_items`). Nếu có cấu hình nhãn Google Ads *Begin Checkout Label*, tự động bắn thêm sự kiện `conversion` phụ cho Google Ads. |
| **7. Mua hàng thành công**<br>(`purchase` / `Purchase` + `conversion`) | Trang Cảm ơn (`order-received` / `woocommerce_thankyou`) & Hook dự phòng (`woocommerce_payment_complete`, `woocommerce_order_status_completed`) | Bắn đồng thời lên **GA4** (`purchase`), **Google Ads** (`conversion` kèm Cart Data & Enhanced Conversions), **Meta Pixel** (`Purchase` kèm `eventID`) và **Meta CAPI** (`Purchase` kèm `event_id`). |

---

## 🛡️ 5. Hệ thống Chống trùng lặp, Chống bắn sai & Bảo mật 6 lớp

Plugin được thiết kế với **6 lớp bảo vệ độc lập** để đảm bảo số liệu quảng cáo không bao giờ bị ảo hoặc trùng lặp:

1. **Lớp 1 - Xác thực quyền truy cập đơn hàng (`WooTrack_Helper::get_valid_thankyou_order`):**
   - Trên trang nhận đơn hàng (`/checkout/order-received/{id}/`), plugin kiểm tra nghiêm ngặt mã khóa đơn hàng `?key=wc_order_...` thông qua `$order->key_is_valid($order_key)` (hoặc xác thực ID chủ sở hữu đang đăng nhập).
   - Ngăn chặn hoàn toàn việc kẻ xấu hoặc bot quét ID đơn hàng tuần tự để đánh cắp thông tin cá nhân (Email, SĐT, Địa chỉ) trong mã nguồn trang hoặc kích hoạt bắn chuyển đổi giả.
2. **Lớp 2 - Chặn đơn hàng thanh toán lỗi hoặc bị hủy (`is_order_valid_for_purchase_tracking`):**
   - Khi khách hàng thanh toán qua cổng online (VNPay, MoMo, PayPal, Stripe...) nhưng bấm hủy hoặc giao dịch thất bại, WooCommerce vẫn chuyển hướng về URL `order-received` với trạng thái đơn là `failed` hoặc `cancelled`.
   - Plugin tự động kiểm tra và **từ chối bắn sự kiện `Purchase`** đối với các trạng thái `failed`, `cancelled`, `refunded`, `trash`, `checkout-draft`. Nếu sau đó khách bấm thanh toán lại và thành công, đơn hàng vẫn được ghi nhận bình thường.
3. **Lớp 3 - Chống tải lại trang F5 (Refresh Protection):**
   - Ngay khi đơn hàng được ghi nhận lần đầu tiên, plugin lưu timestamp vào Order Meta `_woo_track_purchase_tracked` và `_woo_track_meta_capi_tracked`.
   - Khi khách hàng mở lại link trang Cảm ơn trên điện thoại, chia sẻ link, hoặc nhấn F5 tải lại trang nhiều lần, plugin sẽ phát hiện cờ này và chặn tuyệt đối việc bắn lại sự kiện.
4. **Lớp 4 - Khóa nguyên tử chống xung đột luồng song song (Atomic Lock cho CAPI):**
   - Với các cổng thanh toán tự động (như Webhook QR SePay/Casso, IPN của VNPay/MoMo), thường xảy ra trường hợp **Server Webhook gọi vào duyệt đơn** cùng một mili-giây với lúc **Trình duyệt của khách vừa chuyển về Trang Cảm ơn**.
   - Hàm `WooTrack_Meta_CAPI::send_purchase()` thiết lập khóa `set_transient('woo_track_capi_lock_' . $order_id, 'sending', 60)` và lưu `_woo_track_meta_capi_tracked` **trước khi** thực hiện cuộc gọi HTTP `wp_remote_post()` sang Facebook. Luồng thứ 2 chạy song song sẽ thấy khóa và dừng lại ngay lập tức. (Nếu cuộc gọi HTTP gặp lỗi mạng, khóa sẽ được tự động gỡ để cho phép gửi bù sau đó).
5. **Lớp 5 - Bảo vệ cửa sổ khử trùng lặp 48 giờ của Meta (`maybe_send_delayed_purchase`):**
   - Meta chỉ gộp (Deduplicate) sự kiện Pixel và CAPI trong vòng **48 giờ**.
   - Nếu một đơn hàng đã được bắn qua Pixel trình duyệt lúc mới đặt (`_woo_track_purchase_tracked` đã có), nhưng vài ngày sau (quá 48 giờ) Admin mới chuyển trạng thái đơn sang *Hoàn thành*, hàm `maybe_send_delayed_purchase()` sẽ tự động nhận diện đơn đã quá 48 giờ và **không bắn bù CAPI** nữa để tránh bị Meta tính thành đơn hàng thứ 2. (Ngược lại, nếu lúc đặt hàng khách dùng AdBlock khiến Pixel chưa từng bắn, CAPI vẫn sẽ gửi bù an toàn khi đơn hoàn tất).
6. **Lớp 6 - Chống bắn đúp & bắn giả nút Thêm vào giỏ hàng (`isDuplicateAddToCart`):**
   - Tự động vô hiệu hóa sự kiện click nếu nút *Thêm vào giỏ hàng* đang ở trạng thái `.disabled`, `.wc-variation-selection-needed` (chưa chọn thuộc tính biến thể), `.wc-variation-is-unavailable` (hết hàng) hoặc form chưa điền đủ trường bắt buộc (`checkValidity()`).
   - Kết hợp bộ nhớ đệm 2 tầng: **In-memory (`2.5 giây`)** trên cùng trang và **`sessionStorage` (`30 giây` có tự động giải phóng khóa `consumeRecentAdd`)** giữa các lần chuyển trang, đảm bảo khi submit form truyền thống (dù hosting phản hồi chậm), sự kiện `AddToCart` chỉ nổ đúng 1 lần duy nhất.

---

## 🛠️ 6. Hướng dẫn Cấu hình trong WordPress & Tài khoản Quảng cáo

### 6.1. Cấu hình trong Quản trị WordPress
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

### 6.2. Các cài đặt BẮT BUỘC kiểm tra trên Meta Ads & Google Ads
* **Trên Meta Events Manager (Trình quản lý sự kiện $\rightarrow$ Cài đặt):**
  * ❌ **PHẢI TẮT:** *"Theo dõi sự kiện tự động không cần mã" (Track events automatically without code)* để tránh Meta tự ý đếm thêm sự kiện khi khách bấm nút thanh toán/mua hàng.
  * ✅ **PHẢI BẬT:** *"Khớp nâng cao tự động" (Automatic Advanced Matching)* và bật tất cả các trường thông tin khách hàng (Email, SĐT, Họ tên, Thành phố, Quốc gia, External ID).
* **Trên Google Ads (`Mục tiêu -> Chuyển đổi -> Cài đặt`):**
  * ✅ **PHẢI BẬT:** *"Lượt chuyển đổi nâng cao cho web" (Enhanced Conversions for web)* $\rightarrow$ Chọn phương thức **Thẻ Google (Google tag)**.
  * ⚠️ **LƯU Ý TRÁNH TRÙNG ĐƠN:** Nếu bạn vừa điền **Google Ads Purchase Label** trong WooTrack, vừa liên kết tài khoản GA4 để **Import sự kiện `purchase` từ GA4** sang Google Ads, hãy đặt hành động chuyển đổi trực tiếp của Google Ads làm **"Chính" (Primary)** và chuyển hành động `purchase` nhập từ GA4 sang **"Phụ" (Secondary)**.

---

## 🔍 7. Hướng dẫn Kiểm thử (Debug Mode & Meta Test Events)

1. Vào **WooCommerce $\rightarrow$ WooTrack (Google Suite)**:
   - Tích chọn **Bật Debug Console** ở mục cuối cùng.
   - Nếu muốn kiểm thử Meta CAPI thời gian thực, copy mã `TESTxxxxx` trong tab **Thử nghiệm sự kiện (Test Events)** của Meta Events Manager dán vào ô **Test Event Code** rồi lưu lại.
2. Mở trình duyệt và nhấn `F12` $\rightarrow$ chuyển sang tab **Console**:
   - Mọi sự kiện Google sẽ in log nhãn màu xanh lam: `[WooTrack Google 🚀] <tên_sự_kiện> <payload>`.
   - Mọi sự kiện Meta Pixel sẽ in log nhãn màu xanh đậm: `[WooTrack Meta 🎯] <tên_sự_kiện> <payload> { eventID: ... }`.
3. Thực hiện đặt thử 1 đơn hàng:
   - Trong tab **Thử nghiệm sự kiện** của Meta, bạn sẽ thấy sự kiện `Purchase` đến từ cả **Trình duyệt (Browser)** và **Máy chủ (Server)** với cùng một mã `event_id` (`order_xxx`) và trạng thái **Đã khử trùng lặp (Deduplicated)**.
   - Thử nhấn `F5` tải lại trang Cảm ơn: Console sẽ hiển thị thông báo `purchase_duplicate_blocked` và không gửi lại sự kiện.
4. **Quan trọng:** Sau khi kiểm thử xong, hãy **xóa trắng ô Test Event Code** và **tắt Debug Mode** rồi bấm Lưu cấu hình để chạy thực tế.
