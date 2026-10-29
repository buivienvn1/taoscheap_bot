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

## Migration 007: kiến trúc nhà cung cấp thanh toán

File `007_payment_provider_architecture.sql` bổ sung:

- Khóa idempotency payment event theo `provider + destination_account + provider_event_id`.
- Tiền tệ, chiều giao dịch và thời gian phát sinh chuẩn hóa trên payment event.
- Bảng giao dịch nguồn để audit dữ liệu ACB trước/sau xử lý và giữ hàng đợi `observed`, `review`, `failed`.
- Trạng thái đồng bộ theo từng nhà cung cấp/tài khoản.
- Trạng thái `processing`, thời điểm thử và mã lỗi không nhạy cảm cho notification outbox.

### Trước khi chạy

1. Sao lưu database và tạm dừng webhook/cron thanh toán.
2. Kiểm tra index `uq_payment_event` hiện có đúng tên từ schema chuẩn.
3. Ghi nhận số lượng payment event, tổng số dư, tổng giao dịch ví, đơn pending và deposit pending.
4. Lưu ý DDL MySQL tự commit; transaction trong migration runner không đảm bảo rollback nguyên tử cho các câu `ALTER TABLE`/`CREATE TABLE`.

### Sau khi chạy

1. Xác nhận unique key mới gồm đủ ba cột và không mất payment event lịch sử; dữ liệu cũ có `destination_account=''` để giữ tính duy nhất theo provider/event ID cũ.
2. Xác nhận hai bảng `payment_source_transactions`, `payment_sync_states` và các cột retry outbox đã tồn tại.
3. Chạy ACB với `processing_enabled=false` trước; đối chiếu thủ công ID, chiều giao dịch, số tiền, tiền tệ, thời gian và mô tả.
4. Chỉ đặt `activation_time` và bật xử lý sau khi dữ liệu chỉ đọc đạt yêu cầu. Không sửa/xóa payment event để chạy lại; dùng trạng thái nguồn và migration sửa tiến.
5. Đối chiếu lại tổng số dư, transactions, đơn/deposit pending trước khi mở webhook và cron xử lý.
