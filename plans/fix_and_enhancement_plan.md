# Kế hoạch khắc phục lỗi và nâng cấp hệ thống Shop Telegram & Web Admin

## 1. Phân tích nguyên nhân sự cố

### 1.1. Lỗi 2 tin nhắn khi nạp tiền (Ảnh 1)
- **Nguyên nhân chính**:
  1. Trong [`core/Telegram.php`](../core/Telegram.php:412), `CURLOPT_TIMEOUT` được đặt cố định là 10 giây cho toàn bộ request.
  2. Khi upload ảnh QR qua multipart (`sendPhoto`), do độ trễ kết nối từ cPanel tới máy chủ Telegram, request mất hơn 10 giây:
     ```
     [28-Oct-2026 22:36:25] QR upload failed: Telegram API error: Operation timed out after 10001 milliseconds with 0 bytes received
     ```
  3. **Trạng thái bất định**: cURL phía server PHP hủy kết nối ở mốc 10.001s, nhưng thực tế Telegram API đã nhận đủ file ảnh và hoàn tất gửi tin nhắn vào chat (tin nhắn ảnh lúc 10:36).
  4. Phía PHP gặp exception timeout nên nhảy vào khối `catch` trong [`bot/handlers/DepositHandler.php`](../bot/handlers/DepositHandler.php:57), thực hiện lệnh `editMessage()` biến tin nhắn gốc (lúc 10:35) thành tin nhắn văn bản dự phòng ("Không thể hiển thị ảnh QR...").
  5. Đồng thời, [`bot/Router.php`](../bot/Router.php:45) chưa gọi `answerCallbackQuery()`, khiến Telegram client ở trạng thái chờ phản hồi callback.
- **Hậu quả**: Xuất hiện đồng thời 2 tin nhắn: tin nhắn chữ dự phòng (sửa từ tin cũ) và tin nhắn ảnh mới (Telegram gửi thành công).

### 1.2. Lỗi giao diện trang Cài đặt (Ảnh 2)
- **Nguyên nhân chính**:
  - Tại [`admin/modules/settings.php`](../admin/modules/settings.php:10), văn bản hướng dẫn chứa các thẻ HTML chưa được escape:
    ```html
    <small>Hỗ trợ HTML Telegram: <b>, <i>, <u>, <s>, <tg-spoiler>, <a>, <code>, <pre>, <blockquote>.</small>
    ```
  - Trình duyệt thông dịch trực tiếp các thẻ này, đặc biệt là `<pre>` và `<blockquote>`, dẫn đến toàn bộ phần tử phía sau bị biến dạng, thụt lề sâu và font monospace vỡ khung.
- **Hậu quả**: Màn hình cài đặt hiển thị lệch lạc, chồng chéo.

---

## 2. Kế hoạch giải quyết và nâng cấp

### Giai đoạn 1: Khắc phục lỗi cấp bách
1. **Sửa lỗi gửi ảnh QR & Callback**:
   - Tách biệt timeout: Đặt timeout mặc định cho API text/callback (10s) và timeout riêng cho upload file/photo (30s) trong [`core/Telegram.php`](../core/Telegram.php).
   - Thêm gọi `answerCallbackQuery()` ngay đầu hàm xử lý callback trong [`bot/Router.php`](../bot/Router.php).
   - Tối ưu hàm `replaceWithPhoto()`: Nếu upload thất bại do timeout, kiểm tra an toàn hoặc dọn dẹp tin nhắn cũ để không bao giờ bị hiện 2 tin nhắn trùng lặp.
2. **Sửa màn hình Cài đặt Web Admin**:
   - Escape toàn bộ các thẻ HTML trong hướng dẫn sang `<b>`, `<i>`, `<pre>`, `<blockquote>`...
   - Tái cấu trúc form cài đặt thành các nhóm rõ ràng (Thông tin Shop, Nội dung Giới thiệu đa ngôn ngữ, Hỗ trợ, Webhook).

### Giai đoạn 2: Tự động hóa Migration khi Deploy
1. **Xây dựng migration runner tự động (`migrate.php`)**:
   - Tạo bảng `migrations (id, migration, executed_at)` nếu chưa có.
   - Tự động quét thư mục [`migrations/`](../migrations/) theo thứ tự file tăng dần (`001_...`, `002_...`, `003_...`, `004_...`).
   - Thực thi các file SQL chưa chạy bên trong transaction (hoặc theo khối an toàn), ghi log lịch sử thực thi.
2. **Tích hợp vào quy trình CI/CD cPanel**:
   - Cập nhật [`.cpanel.yml`](../.cpanel.yml) thêm bước gọi `php migrate.php` ngay sau khi rsync hoàn tất.
   - Thêm nút "Kiểm tra & Chạy Migration" trực tiếp trên Web Admin để chủ shop có thể chủ động kiểm tra trạng thái bất cứ lúc nào.

### Giai đoạn 3: Tính năng Sửa / Xóa cho Sản phẩm và Kho hàng
1. **Quản trị Sản phẩm (`admin/modules/products.php`)**:
   - Bổ sung nút **Sửa**: Khi bấm, tải toàn bộ thông tin sản phẩm (tên, giá, mô tả, tính năng, loại nguồn hàng, mã provider, thứ tự sắp xếp, trạng thái) vào form chỉnh sửa.
   - Bổ sung nút **Xóa / Ẩn**:
     - Nếu sản phẩm đã phát sinh đơn hàng trong `orders`: Ngăn xóa cứng để bảo vệ tính toàn vẹn dữ liệu kế toán; chuyển sang ẩn trạng thái (`status = 0`) hoặc cảnh báo rõ ràng.
     - Nếu sản phẩm chưa có đơn hàng: Cho phép xóa hoàn toàn khỏi `products` và `variants`.
2. **Quản trị Kho hàng (`admin/modules/stocks.php`)**:
   - Bổ sung bảng danh sách chi tiết các mã tồn kho của từng sản phẩm.
   - Bộ lọc xem theo sản phẩm và trạng thái (`Còn hàng` / `Đã bán`).
   - Bổ sung nút **Sửa**: Cho phép sửa nội dung tài khoản/key khi còn ở trạng thái chưa bán.
   - Bổ sung nút **Xóa**: Cho phép xóa dòng kho chưa bán.
   - Bổ sung tính năng **Xóa hàng loạt**: Xóa tất cả mã chưa bán của một sản phẩm.
   - Tính năng **Lọc trùng**: Khi nhập kho hàng loạt, tự động loại bỏ các dòng bị trùng với kho đã có trong hệ thống.

---

## 3. Các ý tưởng & Đề xuất nâng tầm hệ sinh thái Shop Bot Telegram

Dưới góc nhìn chuyên gia thiết kế các hệ thống bán hàng tự động qua Telegram Bot:

1. **Cảnh báo hết kho tự động qua Telegram Admin (Low-stock Alerts)**:
   - Khi kho của một sản phẩm giảm xuống dưới ngưỡng (ví dụ: < 3 đơn vị hoặc = 0), bot tự động gửi thông báo riêng đến tài khoản Telegram của admin để kịp thời nhập thêm hàng.
2. **Nạp tiền nhanh bằng các gói mệnh giá (Quick Top-up)**:
   - Thay vì để khách chuyển khoản số tiền bất kỳ, thêm các nút chọn nhanh: `10.000đ`, `20.000đ`, `50.000đ`, `100.000đ`, `200.000đ`, `500.000đ`. Khi khách bấm, QR được tạo sẵn số tiền chính xác, khách chỉ cần quét và xác nhận trên app ngân hàng trong 3 giây.
3. **Quản lý định dạng tài khoản thông minh (Multi-format Stock Parser)**:
   - Hỗ trợ phân tích linh hoạt các định dạng tài khoản: `Email|Password`, `Email|Password|2FA`, `Key`, `Cookie JSON`. Khi bot giao hàng cho khách, tự động format hiển thị đẹp mắt, có nút copy riêng cho từng thành phần (User, Pass, 2FA code).
4. **Hệ thống Gửi thông báo hàng loạt (Broadcast Tool)**:
   - Cho phép admin soạn tin nhắn văn bản hoặc kèm ảnh trên Web Admin và bấm gửi đồng loạt đến tất cả người dùng đã từng tương tác với bot (thông báo hàng mới về, khuyến mãi, bảo trì).
5. **Mã giảm giá & Khuyến mãi (Voucher / Coupon System)**:
   - Quản trị viên tạo mã giảm giá (% hoặc số tiền cố định), thiết lập số lượt dùng và hạn sử dụng. Khách nhập mã giảm giá khi thanh toán qua ví.
6. **Báo cáo & Thống kê doanh thu trực quan (Analytics Dashboard)**:
   - Thống kê doanh thu hôm nay, tuần này, tháng này, biểu đồ số lượng bán theo từng mặt hàng để nhận biết sản phẩm nào đang là "ngựa chiến" đem lại nhiều lợi nhuận nhất.
