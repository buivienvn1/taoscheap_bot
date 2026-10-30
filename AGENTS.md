# Quy ước phát triển dành cho AI agent

## Đa ngôn ngữ

- Không hard-code văn bản giao diện người dùng trong handler, router, service hoặc template.
- Mọi tiêu đề, nhãn, hướng dẫn, thông báo lỗi và tên nút phải được khai báo trong `languages/vi.php` và `languages/en.php`.
- Khi thêm một khóa dịch, phải thêm khóa tương ứng cho tất cả ngôn ngữ đang hỗ trợ trong cùng thay đổi.
- Dữ liệu động phải truyền qua placeholder của `Language::get()`, không nối trực tiếp vào câu dịch nếu trật tự từ có thể khác giữa các ngôn ngữ.
- Dữ liệu động đưa vào Telegram HTML phải được escape bằng `Helpers::escape()` trước khi truyền vào placeholder hoặc ghép vào nội dung.
- Không đặt dữ liệu cấu hình như tên ngân hàng, số tài khoản, tên chủ tài khoản, URL hoặc mã thanh toán trong file ngôn ngữ.

## Clean Code và Thiết kế Hệ thống

- Code luôn đơn giản, tường minh (KISS), dễ đọc, dễ nâng cấp và bảo trì khi cần; tránh over-engineering hoặc tạo abstraction dư thừa.
- Tách bạch rõ trách nhiệm, ưu tiên hàm/phương thức tập trung giải quyết một nhiệm vụ duy nhất.
- Đặt tên biến, hàm, lớp mang tính ngữ nghĩa; tái sử dụng các hàm/dịch vụ dùng chung, tránh trùng lặp logic.

## Trải nghiệm UI/UX và Nội dung

- Câu từ, văn bản giao diện (tin nhắn bot, thông báo, nhãn, hướng dẫn) luôn ngắn gọn, súc tích, đi thẳng vào trọng tâm.
- Kiến trúc và thiết kế luồng người dùng luôn tối giản: giảm thiểu tối đa các bước/thao tác thừa, luồng xử lý trực quan, mượt mà và dễ hiểu nhất cho người dùng.
- Văn bản và callback của các nút điều hướng phải lấy từ file ngôn ngữ, nhất quán theo ngữ cảnh người dùng.

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

## Kiểm tra trước khi hoàn tất

- Kiểm tra đủ tiếng Việt và tiếng Anh.
- Kiểm tra tính đồng đều của button/input/select, checkbox/radio, toolbar, nhóm thao tác và khả năng hiển thị trên mobile khi thay đổi giao diện web.
- Không hoàn tất thay đổi CSS chỉ dựa trên việc code hợp lệ; phải đối chiếu giao diện thực tế hoặc ảnh chụp ở các breakpoint liên quan.
- Kiểm tra Telegram HTML hợp lệ và dữ liệu động đã được escape.
- Kiểm tra các nút điều hướng và callback hoạt động chính xác.
- Không stage, reset hoặc ghi đè các thay đổi cục bộ không thuộc nhiệm vụ hiện tại.
