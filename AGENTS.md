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

## Giao diện Web và thành phần UI

- Mọi thành phần có vai trò nút bấm (`button`, liên kết mang kiểu nút và `input[type="submit"]`) phải dùng chung quy chuẩn về chiều cao, padding, cỡ chữ, line-height, bo góc và căn giữa nội dung.
- Các control đứng cùng hàng trong toolbar, bộ lọc hoặc nhóm thao tác (`input`, `select`, `button`, liên kết kiểu nút) phải có cùng chiều cao thực tế, cùng `box-sizing: border-box`, không có margin dọc riêng và được căn giữa theo cùng một trục.
- Nhóm thao tác phải dùng layout flex có căn giữa và khoảng cách nhất quán; form nằm trong nhóm thao tác phải dùng `display: inline-flex` và không được tạo margin làm lệch hàng nút.
- Không áp dụng `width: 100%` cho toàn bộ thẻ `input`; phải loại trừ checkbox, radio và các loại input có kích thước nội tại.
- Không dùng selector control chung gây xung đột giữa field nhập liệu và button; style nền của field, button, checkbox/radio và control trong toolbar phải được tách rõ ràng.
- Nút chính, phụ, nguy hiểm và trạng thái vô hiệu hóa phải có class ngữ nghĩa, màu sắc và trạng thái hover/focus nhất quán; không tạo style riêng lẻ ngay trong template.
- Bảng dữ liệu phải nằm trong vùng có thể cuộn ngang trên màn hình hẹp; toolbar và nhóm nút phải cho phép wrap hoặc chuyển sang một cột tại breakpoint phù hợp, không để control bị co méo hoặc tràn khung.
- Màn hình danh sách phải xử lý rõ trạng thái không có dữ liệu, loading (nếu có), lỗi và nội dung dài; không để bảng chỉ còn header mà không có thông báo giải thích.
- Tài nguyên CSS và JavaScript của giao diện quản trị phải có cache-busting dựa trên phiên bản file hoặc commit để bản triển khai mới có hiệu lực ngay.
- Sau mỗi thay đổi giao diện phải kiểm tra trực quan tối thiểu ở desktop và mobile, gồm: chiều cao control, căn hàng, khoảng cách, wrap, overflow, focus bằng bàn phím và trạng thái disabled.

## Thanh toán

- Không tạo mã thanh toán mới khi vẫn còn bản ghi `pending` phù hợp với khóa nghiệp vụ đã định nghĩa.
- Không thay đổi mã thanh toán đang chờ chỉ để thay đổi cách trình bày.
- Nội dung hiển thị phải lấy từ cùng dữ liệu đã dùng để tạo QR.
- Không ghi bot token, mã thanh toán, chat ID, số tài khoản hoặc dữ liệu nhạy cảm vào log.

## Kiểm tra trước khi hoàn tất

- Kiểm tra đủ tiếng Việt và tiếng Anh.
- Kiểm tra tính đồng đều của button/input/select, checkbox/radio, toolbar, nhóm thao tác và khả năng hiển thị trên mobile khi thay đổi giao diện web.
- Không hoàn tất thay đổi CSS chỉ dựa trên việc code hợp lệ; phải đối chiếu giao diện thực tế hoặc ảnh chụp ở các breakpoint liên quan.
- Kiểm tra cả nhánh gửi ảnh thành công và fallback không có ảnh.
- Kiểm tra Telegram HTML hợp lệ và dữ liệu động đã được escape.
- Kiểm tra nút mở QR dùng URL hợp lệ và các nút điều hướng vẫn hoạt động.
- Không stage, reset hoặc ghi đè các thay đổi cục bộ không thuộc nhiệm vụ hiện tại.
