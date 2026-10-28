# Quy ước phát triển dành cho AI agent

## Đa ngôn ngữ

- Không hard-code văn bản giao diện người dùng trong handler, router, service hoặc template.
- Mọi tiêu đề, nhãn, hướng dẫn, thông báo lỗi và tên nút phải được khai báo trong `languages/vi.php` và `languages/en.php`.
- Khi thêm một khóa dịch, phải thêm khóa tương ứng cho tất cả ngôn ngữ đang hỗ trợ trong cùng thay đổi.
- Dữ liệu động phải truyền qua placeholder của `Language::get()`, không nối trực tiếp vào câu dịch nếu trật tự từ có thể khác giữa các ngôn ngữ.
- Dữ liệu động đưa vào Telegram HTML phải được escape bằng `Helpers::escape()` trước khi truyền vào placeholder hoặc ghép vào nội dung.
- Không đặt dữ liệu cấu hình như tên ngân hàng, số tài khoản, tên chủ tài khoản, URL hoặc mã thanh toán trong file ngôn ngữ.

## Đồng bộ giao diện Telegram

- Nội dung cốt lõi phải nhất quán giữa caption ảnh và tin nhắn văn bản dự phòng.
- Nên tạo một biến nội dung nền rồi tái sử dụng cho cả luồng gửi ảnh và fallback, tránh xây dựng hai bản nội dung riêng.
- Khi ảnh QR không gửi được, phải giữ đầy đủ thông tin thanh toán và cung cấp nút URL để người dùng mở QR bên ngoài Telegram.
- Nút URL phải dùng chính URL QR đã tạo cho giao dịch, không tạo lại mã hoặc URL khác trong nhánh fallback.
- Văn bản và callback của nút điều hướng phải lấy từ file ngôn ngữ.

## Thanh toán

- Không tạo mã thanh toán mới khi vẫn còn bản ghi `pending` phù hợp với khóa nghiệp vụ đã định nghĩa.
- Không thay đổi mã thanh toán đang chờ chỉ để thay đổi cách trình bày.
- Nội dung hiển thị phải lấy từ cùng dữ liệu đã dùng để tạo QR.
- Không ghi bot token, mã thanh toán, chat ID, số tài khoản hoặc dữ liệu nhạy cảm vào log.

## Kiểm tra trước khi hoàn tất

- Kiểm tra đủ tiếng Việt và tiếng Anh.
- Kiểm tra cả nhánh gửi ảnh thành công và fallback không có ảnh.
- Kiểm tra Telegram HTML hợp lệ và dữ liệu động đã được escape.
- Kiểm tra nút mở QR dùng URL hợp lệ và các nút điều hướng vẫn hoạt động.
- Không stage, reset hoặc ghi đè các thay đổi cục bộ không thuộc nhiệm vụ hiện tại.
