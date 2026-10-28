# Quy tắc cập nhật cơ sở dữ liệu

1. Chỉ thêm file SQL mới theo thứ tự phiên bản, ví dụ `002_...sql`, `003_...sql`, `004_...sql`.
2. Không sửa file migration đã chạy trên production; thay đổi tiếp theo phải nằm trong migration phiên bản mới.
3. Không dùng `DROP TABLE`, `TRUNCATE` hoặc xóa dữ liệu lịch sử.
4. Sao lưu database trước khi cập nhật.
5. Chạy từng file bằng phpMyAdmin và kiểm tra kết quả trước khi chạy file tiếp theo.
6. Thay đổi dữ liệu mặc định phải dùng câu lệnh có thể chạy lại an toàn.
7. `install.sql` là schema cho cài đặt mới và phải được đồng bộ với migration mới nhất.

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
