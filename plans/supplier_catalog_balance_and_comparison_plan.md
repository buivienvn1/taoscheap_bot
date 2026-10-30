# Kế hoạch quản lý nhà cung cấp và chọn nguồn hàng

## 1. Mục tiêu

Giúp admin xem số dư nhà cung cấp (NCC), tìm sản phẩm theo tên, so sánh giá và chọn nguồn mà không cần nhớ mã sản phẩm.

## 2. Giao diện và cách sử dụng

### Trang Nhà cung cấp — thêm mới

Gồm ba phần:

- **Tình trạng NCC:** số dư, trạng thái kết nối, thời điểm cập nhật và nút làm mới từng NCC.
- **Danh sách sản phẩm:** tìm theo tên/mã, lọc theo NCC hoặc hàng còn kho; hiển thị giá gốc, giá quy đổi VND và tồn kho tham khảo. Có phân trang.
- **Tỷ giá tham chiếu:** admin nhập tỷ giá để quy đổi và so sánh giá.

Luồng so sánh: **Tìm sản phẩm → chọn các sản phẩm cùng loại → xem giá → chọn nguồn chính hoặc dự phòng → lưu.**

Chỉ đánh dấu giá thấp nhất trong nhóm admin xác nhận cùng gói, thời hạn và điều kiện sử dụng. Không tự coi các sản phẩm có tên gần giống nhau là cùng loại.

### Trang Kho hàng — cải tiến phần chọn nguồn

- Chọn NCC rồi tìm sản phẩm bằng tên; mỗi kết quả có mã, giá và tồn kho.
- Khi chọn sản phẩm, hệ thống tự điền mã nguồn.
- Vẫn cho nhập mã thủ công nếu danh mục chưa có sản phẩm đó.
- Có nút sang trang NCC để tra cứu và so sánh cho sản phẩm shop đang cấu hình.
- Admin kiểm tra và bấm lưu; hệ thống không tự đổi nguồn sau khi đồng bộ.

## 3. Cách xử lý dữ liệu

### Lưu dữ liệu để trang mở nhanh

Lưu số dư và danh mục NCC vào DB. Khi mở trang hoặc tìm kiếm, chỉ đọc dữ liệu đã lưu. Nút làm mới mới gọi API; có thể thêm cron sau nếu cần.

Tạo ba bảng:

| Bảng | Mục đích |
| --- | --- |
| `supplier_balances` | Số dư theo NCC và loại tiền |
| `supplier_products` | Mã, tên, giá gốc, tiền tệ, giá quy đổi và tồn kho |
| `supplier_sync_state` | Lần cập nhật, lỗi và trạng thái đang đồng bộ |

### Quy tắc cần giữ

- Đồng bộ từng NCC; số dư và danh mục xử lý độc lập. Một phần lỗi không làm mất phần đã cập nhật tốt.
- API lỗi thì giữ dữ liệu cũ và báo rõ. Sản phẩm bị NCC gỡ chỉ được đánh dấu ngừng cung cấp sau lần đồng bộ đầy đủ, thành công.
- Có timeout và chặn đồng bộ trùng; kết quả cũ không được ghi đè kết quả mới.
- Giữ giá gốc và tiền tệ. Thiếu giá hoặc tỷ giá thì không xếp hạng; không mặc định USD bằng USDT.
- Kiểm tra giá theo số lượng: giá quảng cáo “từ” hoặc giá sỉ chưa chắc là giá mua một sản phẩm. Đổi tỷ giá phải tính lại giá quy đổi.
- Chỉ so sánh sản phẩm đang cung cấp, đủ tồn và có dữ liệu mới, đáng tin cậy. Hiển thị rõ đây là giá tham khảo.
- Khi lưu nguồn, kiểm tra NCC/mã sản phẩm, ngăn nguồn chính và phụ trùng nhau, tránh hai tab ghi đè âm thầm. Gán nhanh chỉ sửa nguồn được chọn.

## 4. Các bước triển khai

### Bước 1 — Lấy và lưu dữ liệu NCC

- [ ] Đối chiếu tài liệu Sandromania và Cuppon'sHub để xác định đúng giá, tồn kho và giá sỉ.
- [ ] Thêm `getProducts()` và cách kiểm tra trạng thái cấu hình vào lớp NCC; dùng chung cách đọc dữ liệu khi phù hợp.
- [ ] Thêm migration tạo ba bảng trên, cập nhật `install.sql`.
- [ ] Viết xử lý đồng bộ số dư/danh mục và quy đổi giá; đăng ký service trong `bootstrap.php`.

### Bước 2 — Làm trang Nhà cung cấp

- [ ] Thêm menu và `admin/modules/suppliers.php`.
- [ ] Hiển thị số dư, trạng thái, danh mục, tìm kiếm, phân trang và nút đồng bộ.
- [ ] Thêm chỉnh tỷ giá, so sánh nhóm sản phẩm đã chọn và gán nguồn cho sản phẩm shop.

### Bước 3 — Cải tiến chọn nguồn tại Kho hàng

- [ ] Thay ô chỉ nhập mã bằng bộ chọn tìm được theo tên, kèm lựa chọn nhập mã thủ công.
- [ ] Dùng chung xử lý lưu nguồn với trang NCC; giữ thứ tự kho tay → API chính → API dự phòng.
- [ ] Hiển thị được nguồn cũ dù sản phẩm không còn trong danh mục.

### Bước 4 — Hoàn thiện và kiểm tra

- [ ] Đưa toàn bộ nội dung mới vào `languages/vi.php` và `languages/en.php`; thêm lựa chọn ngôn ngữ cho Admin.
- [ ] Bảo vệ thao tác ghi bằng đăng nhập, CSRF, kiểm tra đầu vào và escape dữ liệu hiển thị.
- [ ] Kiểm tra API lỗi/chậm, dữ liệu cũ, sản phẩm bị gỡ, giá sỉ, thiếu tỷ giá và thao tác đồng thời.
- [ ] Kiểm tra trực quan desktop/mobile: control đồng đều, bảng cuộn được, nút không lệch, dùng được bàn phím và trạng thái disabled. CSS/JS có cache-busting.
- [ ] Kiểm tra luồng kho và giao hàng hiện tại vẫn hoạt động.

## 5. Điều kiện hoàn tất và phần làm sau

Hoàn tất khi admin xem được số dư và độ mới dữ liệu, tìm theo tên để chọn nguồn, so sánh đúng nhóm sản phẩm và lưu nguồn chính/phụ; cả tiếng Việt, tiếng Anh và giao diện mobile đều đã kiểm tra.

Để sau: tự gợi ý nguồn từ các mapping đã xác nhận, cảnh báo lợi nhuận và thay đổi tồn kho hiển thị trên bot. Phần tồn kho cần thiết kế riêng vì hệ thống hiện yêu cầu một nguồn giao đủ số lượng đơn, nên không thể cộng tồn các nguồn rồi coi là số lượng mua được.
