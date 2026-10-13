# WooTrack - Google Analytics 4 & Google Ads Tracker for WooCommerce

Plugin WordPress dành cho WooCommerce giúp theo dõi toàn diện các sự kiện E-commerce cho **Google Analytics 4 (GA4)** và **Google Ads (Chuyển đổi & Dynamic Remarketing)**. Được thiết kế tương thích 100% với website đã cài sẵn **Site Kit by Google**.

---

## 🚀 Tính năng nổi bật

1. **Tương thích hoàn hảo với Google Site Kit:**
   - Tận dụng trực tiếp thư viện `gtag.js` do Site Kit nạp vào trang web.
   - Không nạp lại script dư thừa, không làm chậm tốc độ tải trang (PageSpeed).
   - Tự động bổ sung `gtag('config', 'AW-XXXXXXXXX')` cho tài khoản Google Ads nếu bạn nhập Conversion ID.

2. **Theo dõi đầy đủ các sự kiện E-commerce chuẩn:**
   - `view_item_list`: Xem danh mục sản phẩm hoặc trang Shop.
   - `view_item`: Xem trang chi tiết sản phẩm (cần thiết cho Google Ads Dynamic Remarketing).
   - `add_to_cart`: Thêm sản phẩm vào giỏ hàng (hỗ trợ cả nút AJAX ngoài danh mục và nút trong trang chi tiết).
   - `begin_checkout`: Bắt đầu thanh toán.
   - `purchase`: Đơn hàng thành công (Trang cảm ơn).

3. **Chống trùng lặp chuyển đổi (Deduplication):**
   - Đánh dấu trạng thái đơn hàng trong cơ sở dữ liệu (`_woo_track_purchase_tracked`).
   - Khi khách hàng F5 hoặc xem lại trang Cảm ơn (`woocommerce_thankyou`), sự kiện `purchase` sẽ **không bị bắn lại**, đảm bảo chỉ số ROAS không bị sai lệch.

4. **Hỗ trợ Google Ads Enhanced Conversions (Dữ liệu người dùng nâng cao):**
   - Tự động băm (hash SHA-256) Email, Số điện thoại và Địa chỉ khi khách đặt hàng.
   - Giúp Google Ads so khớp chính xác chuyển đổi ngay cả khi trình duyệt chặn cookie bên thứ 3 (iOS/Safari).

5. **Đồng bộ với Google Merchant Center (GMC):**
   - Cho phép chọn định danh sản phẩm (`item_id`) gửi đi là **Post ID** hoặc **SKU** để khớp chính xác với nguồn cấp dữ liệu sản phẩm trong chiến dịch Google Shopping / PMax.

6. **Chế độ kiểm thử (Debug Mode):**
   - Bật chế độ Debug để theo dõi trực tiếp các sự kiện và tham số được bắn ra console trình duyệt (`F12`).

---

## 📂 Cấu trúc thư mục Plugin

```text
woo-track/
├── woo-track.php                     # File kích hoạt chính của plugin
├── README.md                         # Hướng dẫn sử dụng
├── assets/
│   └── js/
│       └── woo-track.js              # Script xử lý frontend và bắn sự kiện gtag
└── includes/
    ├── class-woo-track-helper.php    # Hàm format dữ liệu chuẩn GA4 & Ads
    ├── class-woo-track-settings.php  # Giao diện cài đặt trong WP-Admin
    └── class-woo-track-tracker.php   # Bắt các hook của WooCommerce
```

---

## 🛠️ Hướng dẫn cài đặt & Cấu hình

### 1. Cài đặt vào WordPress
1. Copy toàn bộ thư mục `woo-track` vào thư mục `wp-content/plugins/` trên website của bạn.
2. Hoặc nén thư mục `woo-track` thành file `.zip` rồi vào **Quản trị WordPress > Plugin > Cài mới > Tải plugin lên**.
3. Nhấn **Kích hoạt (Activate)**.

### 2. Cấu hình thông số
1. Vào **WooCommerce > WooTrack (Google)** trên menu quản trị.
2. Nhập các thông tin:
   - **Google Ads / Google Tag ID:** Nhập mã thẻ tài khoản của bạn (ví dụ: `AW-123456789` hoặc `GT-XXXXXX`).
   - **Purchase Conversion Label:** 
     - *Nếu Google Ads hiển thị dạng mẫu mới dựa trên sự kiện (ví dụ: `Event: Purchase: yourdomain.com`):* Bạn **để trống ô này**. Google Tag sẽ tự động bắt sự kiện `purchase` trực tiếp!
     - *Nếu tạo hành động chuyển đổi dạng thủ công truyền thống:* Nhập chuỗi nhãn chuyển đổi từ mã cài đặt Google Ads.
   - **Định danh sản phẩm (item_id):** Chọn *Post ID* hoặc *SKU* tùy theo cách bạn nạp dữ liệu lên Google Merchant Center.
   - **Enhanced Conversions:** Đánh dấu chọn (Khuyên dùng) để tự động gửi thông tin Email/SĐT băm bảo mật.
3. Nhấn **Lưu Cấu Hình WooTrack**.

---

## 🔍 Cách kiểm tra hoạt động

1. Bật tuỳ chọn **Bật Debug Console** trong trang cài đặt của WooTrack.
2. Mở website bán hàng ở chế độ ẩn danh hoặc F12.
3. Mở tab **Console** trong Developer Tools:
   - Khi vào trang sản phẩm, bạn sẽ thấy thông báo: `[WooTrack 🚀] view_item`.
   - Khi bấm thêm vào giỏ, bạn sẽ thấy: `[WooTrack 🚀] add_to_cart`.
   - Khi hoàn tất đặt hàng, bạn sẽ thấy: `[WooTrack 🚀] purchase` và `[WooTrack 🚀] conversion`.
4. Bạn cũng có thể cài tiện ích mở rộng **Google Tag Assistant** trên Chrome để kiểm tra tín hiệu thực tế gửi về Google.
