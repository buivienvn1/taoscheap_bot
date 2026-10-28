# Quy tắc cập nhật cơ sở dữ liệu

1. Chỉ thêm file SQL mới theo thứ tự: `002_...sql`, `003_...sql`.
2. Không sửa file migration đã chạy trên production.
3. Không dùng `DROP TABLE`, `TRUNCATE` hoặc xóa dữ liệu lịch sử.
4. Sao lưu database trước khi cập nhật.
5. Chạy từng file bằng phpMyAdmin và kiểm tra kết quả trước khi chạy file tiếp theo.
6. Thay đổi dữ liệu mặc định phải dùng `INSERT ... ON DUPLICATE KEY UPDATE`.
