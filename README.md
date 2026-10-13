# WooTrack - All-in-One Google Tracker for WooCommerce

Bộ plugin WordPress độc lập chuyên dụng cho WooCommerce giúp kết nối và theo dõi toàn diện hệ sinh thái Google: **Google Tag Manager (GTM)**, **Google Analytics 4 (GA4)** và **Google Ads (Chuyển đổi & Dynamic Remarketing)** mà **HOÀN TOÀN KHÔNG CẦN CÀI GOOGLE SITE KIT** hay bất kỳ plugin bên thứ ba nào khác.

---

## 🚀 Tính năng nổi bật (Phiên bản 1.1.0)

1. **Hoạt động độc lập 100%:**
   - Tự động chèn mã **Google Tag Manager (GTM)** chuẩn Google vào `<head>` (độ ưu tiên 1) và ngay sau thẻ mở `<body>` (`wp_body_open`).
   - Tự động nạp thư viện **`gtag.js`** cho **Google Analytics 4** và **Google Ads** nếu cấu hình trực tiếp.

2. **Cơ chế Bắn sự kiện Kép (Dual Dispatch):**
   - Vừa gọi **`gtag('event', ...)`** cho GA4 và Google Ads nhận chuyển đổi tức thời.
   - Vừa đẩy **`window.dataLayer.push({ event: ..., ecommerce: ... })`** theo cấu trúc chuẩn E-commerce để GTM có thể bắt trọn vẹn dữ liệu.

3. **Theo dõi trọn vẹn phễu Thương mại điện tử (WooCommerce E-commerce):**
   - `view_item_list`: Xem trang Shop / Danh mục sản phẩm.
   - `view_item`: Xem chi tiết sản phẩm (Hỗ trợ Dynamic Remarketing cho Google Ads).
   - `add_to_cart`: Thêm vào giỏ hàng (hỗ trợ cả nút AJAX ngoài loop và form trong trang chi tiết).
   - `begin_checkout`: Bắt đầu thanh toán (có hỗ trợ Conversion Label riêng).
   - `purchase`: Mua hàng thành công (Trang cảm ơn).

4. **Chống trùng lặp chuyển đổi (Deduplication):**
   - Ghi nhớ trạng thái đơn hàng trong database (`_woo_track_purchase_tracked`).
   - Khi khách hàng hoặc quản trị viên F5 tải lại trang Cảm ơn, sự kiện `purchase` sẽ **không bao giờ bị bắn lại**, đảm bảo chỉ số ROAS và doanh thu không bị sai lệch.

5. **Google Ads Enhanced Conversions (Dữ liệu nâng cao chuẩn Google):**
   - Tự động chuẩn hóa Email và Số điện thoại (chuẩn quốc tế **E.164** dạng `+84xxxxxxxxx`), địa chỉ khách hàng.
   - Kích hoạt chế độ `allow_enhanced_conversions: true` để trình duyệt tự động băm SHA-256 an toàn, tránh lỗi double-hashing.

6. **Tương thích hoàn toàn với WooCommerce hiện đại:**
   - Đã khai báo chứng nhận tương thích với **HPOS (High-Performance Order Storage)** và **Cart/Checkout Blocks**.

---

## 📂 Cấu trúc Plugin

```text
woo-track/
├── woo-track.php                     # File bootstrap chính (v1.1.0)
├── README.md                         # Hướng dẫn sử dụng
├── assets/
│   └── js/
│       └── woo-track.js              # Script frontend bắt sự kiện, gtag & dataLayer push
└── includes/
    ├── class-woo-track-helper.php    # Hàm format dữ liệu E-commerce & User Data
    ├── class-woo-track-settings.php  # Giao diện cài đặt 3 mã độc lập trong Admin
    └── class-woo-track-tracker.php   # Tự động chèn mã <head>, <body> và bắt hook đơn hàng
```

---

## 🛠️ Hướng dẫn Cấu hình trong Quản trị WordPress

Vào **WooCommerce > WooTrack (Google Suite)**:

### 1. Google Tag Manager (GTM)
* Nhập mã Container ID của bạn: ví dụ `GTM-XXXXXXX`.
* *(Để trống nếu bạn không dùng GTM mà dùng thẳng GA4 & Ads).*

### 2. Google Analytics 4 (GA4)
* Nhập mã đo lường GA4: ví dụ `G-XXXXXXXXXX`.
* Plugin sẽ tự nạp thư viện `gtag.js` và gửi mọi sự kiện mua sắm vào GA4.

### 3. Google Ads
* **Google Ads / Google Tag ID:** Nhập `AW-18508451909` (hoặc `GT-XXXXXX`).
* **Purchase Conversion Label:** Nhập nhãn mua hàng (hoặc để trống nếu dùng mẫu `Event: Purchase`).
* **Begin Checkout Conversion Label:** Nhập nhãn bắt đầu thanh toán (ví dụ `1_OBCJDolpsdEMWgwvlE`).
* **Enhanced Conversions:** Tích chọn ✅.

---

## 🔍 Kiểm tra hoạt động
1. Bật **Debug Console** trong cài đặt của WooTrack.
2. Mở trình duyệt ẩn danh, nhấn `F12` > chọn tab **Console**.
3. Bạn sẽ thấy các log trực tiếp `[WooTrack 🚀]` xuất hiện khi xem sản phẩm, thêm vào giỏ, và đặt hàng.
