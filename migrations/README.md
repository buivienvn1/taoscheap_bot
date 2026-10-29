# Quy tắc cập nhật cơ sở dữ liệu

1. Chỉ thêm file SQL mới theo thứ tự phiên bản, ví dụ `005_...sql`, `006_...sql`.
2. Không sửa file migration đã chạy trên production; thay đổi tiếp theo phải nằm trong migration phiên bản mới.
3. Không dùng `DROP TABLE`, `TRUNCATE` hoặc xóa dữ liệu lịch sử.
4. Sao lưu database trước khi cập nhật.
5. Từ migration `005`, SQL phải tương thích PDO và không dùng `DELIMITER`, stored procedure hoặc lệnh dành riêng cho MySQL CLI.
6. Deploy cPanel tự gọi `migrate.php`; có thể kiểm tra/chạy thủ công tại trang Cài đặt trong Admin.
7. Thay đổi dữ liệu mặc định phải dùng câu lệnh có thể chạy lại an toàn.
8. `install.sql` là schema cho cài đặt mới và phải được đồng bộ với migration mới nhất.
9. Bảng `schema_migrations` lưu tên file, checksum và thời điểm thực thi; không được sửa/xóa lịch sử này trên production.

## Cơ chế baseline 001–004

- Các migration `001–004` có cú pháp cũ dành cho phpMyAdmin/MySQL CLI, bao gồm `DELIMITER` trong migration 004.
- Runner tự nhận diện đầy đủ bảng/cột của schema 004 và ghi baseline `001–004` mà không chạy lại SQL, phù hợp database vừa import `install.sql`.
- Nếu database cũ chưa đạt schema 004, runner dừng an toàn và yêu cầu nâng schema cũ trước; không tự đoán hoặc biến đổi dữ liệu tiền/kho.

## Migration 004: sản phẩm độc lập và checkout số lượng

File `004_product_quantity_checkout.sql` thực hiện các thay đổi sau:

- Giữ nguyên ID variant nội bộ, stock, order, mã thanh toán và lịch sử hiện có.
- Gán một `source_variant_id` cho mỗi sản phẩm; các variant còn lại được tách thành sản phẩm hiển thị riêng. Chạy lại không tạo sản phẩm trùng nhờ unique key.
- Bổ sung snapshot tên sản phẩm, số lượng, đơn giá, request token và kết quả xử lý tiền cho đơn hàng.
- Backfill đơn cũ với số lượng `1`, đơn giá bằng tổng cũ và tạo delivery từ stock lịch sử.
- Bổ sung audit phân bổ payment event, delivery từng đơn vị, request checkout idempotent, lựa chọn số lượng theo màn hình và notification outbox.
- Không tạo cơ chế giữ kho hoặc hết hạn QR. Đơn QR pending phù hợp được tái sử dụng.

### Trước khi chạy

1. Dừng ngắn các thao tác checkout/webhook ghi dữ liệu.
2. Sao lưu đầy đủ database.
3. Ghi nhận số lượng sản phẩm, variant, stock còn/đã bán, đơn theo trạng thái, số dư người dùng, tổng transactions và danh sách QR pending.
4. Kiểm tra xung đột mã giữa `orders.payment_code` và `deposits.code`; xử lý thủ công nếu có mã trùng.

### Sau khi chạy

1. Đối chiếu tổng stock còn/đã bán và tổng số dư với báo cáo trước migration.
2. Xác nhận mọi sản phẩm bán được có đúng một `source_variant_id`; ghi nhận riêng sản phẩm không có variant.
3. Xác nhận đơn lịch sử có `quantity`, `unit_price`, `product_name`; stock đã bán vẫn liên kết đúng order.
4. Xác nhận QR pending giữ nguyên `payment_code`, giá chốt và trạng thái.
5. Kiểm tra unique key payment event, request token và stock delivery trước khi mở lại webhook/checkout.
6. Không rollback bằng cách phục hồi database cũ sau khi đã nhận giao dịch mới; dùng migration sửa tiến để tránh mất dữ liệu tiền.

## Migration 008: registry mã thanh toán và feed bên thứ ba

- Số 008 được dùng có chủ đích vì production từng ghi nhận migration 007 dù file không còn trong repository.
- Migration tạo registry unique toàn cục theo mã `DONATE` và backfill cả nạp ví/đơn hàng. Mã va chạm bị đưa vào `review`; đơn đã đóng nhưng không có bằng chứng tác động tài chính cũng bị review.
- Không bật xử lý feed sau migration. Cấu hình mẫu mặc định `enabled=false`, `processing=false`; chỉ bật sau khi đổi token, xác nhận tài khoản, đối chiếu read-only và đặt `activation_at`.
- Dừng ngắn checkout và webhook khi chạy migration, sao lưu database, rồi kiểm tra các dòng `ambiguous`/`review` trước rollout.
- Worker feed chỉ đọc một trang khi chưa có hợp đồng cursor. Không dùng ID provider làm checkpoint hoặc khóa chống trùng.

## Migration 009: thông báo, lịch sử và vòng đời QR

File `009_notification_history.sql`:

- Bổ sung mã đối soát nạp tiền `NAP-xxxxxx`, snapshot số dư trước/sau và index lịch sử. Mã đơn hàng tiếp tục dùng `OD...`; `DONATE...` chỉ dùng để ghép giao dịch ngân hàng.
- Mở rộng notification outbox theo từng người nhận, ngôn ngữ, thao tác gửi/sửa và message ID đích. Mỗi admin có event key và retry độc lập.
- Tạo `payment_message_links` để lưu đúng tin chữ/ảnh QR đã gửi, tránh sửa hoặc xóa màn hình mà người dùng đã điều hướng sang, đồng thời retry cleanup mà không gửi lại thông báo thành công.
- Backfill mã NAP từ ID khoản nạp và backfill mã tham chiếu transaction từ deposit/order hiện có. Snapshot số dư lịch sử chỉ được điền khi nguồn đã có dữ liệu đáng tin cậy.

### Trước khi chạy

1. Sao lưu database và tạm dừng webhook, payment feed, checkout và cron notification.
2. Xác nhận migration 008 đã hoàn tất; kiểm tra không có tác vụ outbox đang ở trạng thái `processing` chưa kết thúc.
3. Ghi nhận tổng số dư, số deposit/order/transaction theo trạng thái và số outbox pending/failed.

### Sau khi chạy

1. Kiểm tra `reference_code` của deposit không trùng và có dạng `NAP-xxxxxx`; order code hiện có vẫn giữ nguyên.
2. Cấu hình `admin_telegram_ids`; mỗi admin phải mở bot và bấm Start trước khi nhận thông báo.
3. Chạy cron notification và xác nhận từng recipient retry độc lập; lỗi xóa QR không làm gửi lại thông báo thành công.
4. Thử cả nạp tự động, duyệt tay, mua ví, mua QR, fallback không gửi được ảnh, lịch sử Telegram và bộ lọc Admin trước khi mở lại toàn bộ traffic.
5. Đối chiếu lại tổng số dư và số giao dịch; không rollback bằng cách import database cũ sau khi hệ thống đã nhận giao dịch mới.
