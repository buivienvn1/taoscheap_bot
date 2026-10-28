# Kế hoạch sản phẩm độc lập và mua nhiều đơn vị

## 1. Trạng thái và quyết định mới nhất

Chỉ lập kế hoạch, chưa triển khai. Chính sách dưới đây thay thế đề xuất giữ kho 10 phút và cộng dồn chuyển thiếu trước đó.

- Không giữ kho khi xem sản phẩm, chọn số lượng hoặc tạo QR; không có hạn giữ kho và không cần tác vụ giải phóng kho định kỳ.
- Kho chỉ được bán khi giao dịch thanh toán được xử lý thành công và khóa đủ hàng trong cơ sở dữ liệu.
- Thứ tự ưu tiên là thứ tự xử lý giao dịch thành công, không phải thời điểm khách mở/quét QR hoặc tuyệt đối theo thời điểm chuyển tiền tại ngân hàng.
- Chuyển thiếu: cộng toàn bộ khoản nhận được vào ví, kết thúc đơn với lý do chuyển thiếu; không tích lũy để tự giao đơn đó.
- Thanh toán đủ nhưng thiếu kho: không giao một phần; cộng toàn bộ khoản nhận được vào ví và kết thúc đơn với lý do thiếu kho.
- Chuyển thừa: giao đủ số lượng đã đặt, cộng phần thừa vào ví.
- Khoản chuyển mới vào mã đơn đã kết thúc: cộng vào ví, không giao hàng lần hai. Thông báo ngân hàng gửi lặp của cùng giao dịch không được cộng tiền lại.
- Ví dụ kho có hai đơn vị, ba khách mỗi người thanh toán một đơn vị: hai giao dịch được xử lý thành công trước nhận hàng; giao dịch còn lại nhận thông báo hết hàng và toàn bộ tiền được cộng vào ví.

### Chi tiết đề xuất cần duyệt cùng kế hoạch

- Bỏ giới hạn một đơn QR đang chờ mỗi người vì không còn giữ kho. Tái sử dụng đơn đang chờ nếu trùng người dùng, sản phẩm nội bộ, số lượng và đơn giá chốt. Khác lựa chọn có thể có đơn chờ khác.
- Đơn QR không tự hết hạn. Giá được chốt khi tạo đơn; đơn cũ vẫn dùng giá đó. Nếu cần ngừng bán đơn chờ, quản trị phải hủy rõ ràng và giữ mã để đối soát tiền nhận sau này.
- Sản phẩm bị vô hiệu hóa trước khi xử lý thanh toán: không giao, cộng tiền vào ví.
- Tiền chuyển thiếu của đơn hàng được cộng ví kể cả thấp hơn mức nạp ví tối thiểu; đây là xử lý tiền đã nhận cho đơn, không phải mở rộng chính sách nạp ví thông thường.
- Giữ hành vi lựa chọn thanh toán hiện tại: đủ số dư thì dùng ví; thiếu số dư thì QR toàn bộ tổng đơn, không phối hợp trừ ví và chuyển khoản bù.

## 2. Phạm vi giao diện

### Sản phẩm và quản trị

- Không hiển thị danh mục hoặc gói trong Telegram và các màn quản trị sản phẩm, nhập kho, đơn hàng.
- Mỗi sản phẩm hiển thị có một giá và một nguồn kho. Giữ liên kết biến thể nội bộ để bảo toàn lịch sử và khóa ngoại.
- Tách các gói cũ thành sản phẩm riêng; không chọn ngầm một gói và bỏ các gói khác.
- Giữ danh sách đánh số và số tồn kho. Kho bằng 0 vẫn có thể xem chi tiết nhưng không thanh toán.
- Nguồn API chưa tích hợp không được coi số tồn giả là hàng bán được; hiển thị trạng thái phù hợp và chặn mua cho đến khi có hợp đồng tồn kho/giao hàng thực tế.

### Chi tiết sản phẩm

- Tiêu đề CHI TIẾT SẢN PHẨM in đậm trong khối trích dẫn.
- Dòng tên in đậm, giữ tiền tố hiển thị ## theo mẫu người dùng.
- Các dòng Giá, Kho, Đã bán có tiền tố ╰.
- Mục Mô tả in đậm, bên dưới là nội dung sản phẩm và hướng dẫn sử dụng.
- Mục Chi tiết đặt hàng in đậm, bên dưới là Số lượng và Tổng.
- Đã bán là số đơn vị thực sự giao thành công, không phải số đơn hàng. Không tự tạo số liệu minh họa 963.
- Bộ chọn: ➖ / Số lượng: N / ➕. Nhãn giữa chỉ hiển thị hoặc phản hồi nhẹ, không tạo đơn.
- Chọn 5 và Chọn 10 chỉ xuất hiện khi đủ kho tương ứng; đặt số lượng thành 5/10, không cộng thêm và không mua ngay.
- Mặc định số lượng 1; làm mới giữ lựa chọn. Kho giảm dưới số lượng đang chọn phải báo và yêu cầu điều chỉnh, không tự mua ít hơn.
- Kiểm tra giá ở máy chủ trước xác nhận; giá thay đổi trước khi tạo đơn phải yêu cầu xác nhận lại.
- Có nút thanh toán, quay lại danh sách và làm mới; xem/chọn số lượng không tạo mã thanh toán.

### Màn QR

- Hiển thị sản phẩm, số lượng, đơn giá, tổng tiền, ngân hàng/tài khoản, nội dung chuyển khoản và hướng dẫn.
- Cảnh báo rõ: QR không giữ hàng; nếu khi xử lý thanh toán không đủ kho, tiền được cộng vào ví.
- Mở lại cùng đơn giữ nguyên mã và URL QR. Mã mới dùng tiền tố DONATE; không đổi mã lịch sử.
- Nội dung cốt lõi dùng chung cho ảnh và tin nhắn dự phòng. Dự phòng có nút mở đúng URL QR của đơn.
- Giữ tối ưu ảnh QR và bộ nhớ đệm hiện có; không ghi dữ liệu thanh toán nhạy cảm vào log.

## 3. Các điểm ảnh hưởng đã rà soát

- [Danh sách và chi tiết sản phẩm](../bot/handlers/ProductHandler.php): hiện liệt kê nhiều gói, cần chuyển sang một sản phẩm/một giá và bộ chọn số lượng.
- [Định tuyến Telegram](../bot/Router.php): bổ sung thao tác số lượng và xác nhận; callback mua cũ không được gây mua ngoài ý muốn.
- [Thanh toán đơn](../bot/handlers/OrderHandler.php): hiện mua một đơn vị, chưa chống bấm mua lặp ở mức yêu cầu.
- [Webhook thanh toán](../payment_webhook.php): hiện chỉ tìm đơn đang chờ, bỏ qua chuyển thiếu và giao một đơn vị; cần phân bổ tiền theo từng sự kiện và nhận diện mã đơn đã kết thúc.
- [Quản trị sản phẩm](../admin/modules/products.php): gộp biểu mẫu sản phẩm/gói thành biểu mẫu sản phẩm có giá và nguồn hàng.
- [Quản trị kho](../admin/modules/stocks.php): hiện nhập theo gói và có cột gói; đổi sang tên sản phẩm, giữ ánh xạ nội bộ và kiểm tra nguồn hàng hợp lệ khi nhập.
- [Quản trị đơn](../admin/modules/orders.php): hiện hiển thị tên gói; thêm tên sản phẩm chốt, số lượng, đơn giá, tổng tiền và kết quả xử lý tiền.
- [Lịch sử Telegram](../bot/handlers/ProfileHandler.php): hiện chỉ lấy đơn hoàn tất và một nội dung giao; bổ sung chi tiết nhiều đơn vị, lịch sử chuyển vào ví và phân trang/chia tin để không vượt giới hạn Telegram.
- [Nạp ví](../bot/handlers/DepositHandler.php): giữ luồng riêng và chính sách nạp hiện tại, không áp quy tắc số lượng đơn hàng vào nạp ví.

## 4. Dữ liệu và migration

1. Sao lưu và lập báo cáo trước migration: sản phẩm, biến thể, kho còn/đã bán, đơn, số dư, tổng sổ giao dịch và đơn QR đang chờ.
2. Thiết lập ánh xạ một sản phẩm hiển thị với một biến thể nội bộ. Giữ nguyên ID biến thể và liên kết của kho/đơn; tách các biến thể còn lại sang sản phẩm mới.
3. Chốt quy tắc tên bằng tên sản phẩm và tên gói cũ, tránh lặp tên; bảo toàn mô tả, tính năng, thứ tự, trạng thái và nguồn hàng. Báo cáo riêng sản phẩm không có biến thể hoặc dữ liệu bất thường.
4. Bổ sung số lượng, đơn giá chốt, tên sản phẩm chốt và khóa yêu cầu mua duy nhất. Đơn lịch sử mặc định số lượng 1, đơn giá bằng tổng tiền cũ; giữ nội dung giao lịch sử.
5. Lưu chi tiết từng đơn vị đã giao và liên kết stock với đơn. Duy trì khả năng đọc nội dung giao cũ, không làm mất thông tin tài khoản/key.
6. Bổ sung liên kết sự kiện thanh toán với đơn, kết quả xử lý, số tiền dùng mua, số tiền cộng ví và lý do. Có ràng buộc chống phân bổ cùng sự kiện hai lần.
7. Dùng trạng thái kết thúc cùng mã lý do để phân biệt đã giao, chuyển thiếu vào ví, thiếu kho vào ví, hủy hoặc ngừng bán; không cần trạng thái giữ kho/hết hạn giữ kho.
8. Bảo đảm mã thanh toán không mơ hồ giữa nạp ví và đơn; cân nhắc bảng đăng ký mã dùng chung có ràng buộc duy nhất, backfill và báo cáo xung đột trước khi bật luồng mới.
9. Migration có phiên bản, chạy lại không tạo trùng sản phẩm/chi tiết giao. Không thay mã đơn QR đang chờ hoặc diễn giải lại các sự kiện cũ chưa đối soát.
10. Đồng bộ bộ cài mới [install.sql](../install.sql), migration mới và [hướng dẫn migration](../migrations/README.md). Không chỉ sửa migration khởi tạo đã chạy trên production.

## 5. Xử lý mua và thanh toán an toàn

### Chọn số lượng và tạo yêu cầu

- Trạng thái theo người dùng và màn hình, có phiên bản để loại thao tác cũ hoặc đến sai thứ tự.
- Kiểm tra quyền sở hữu, số lượng nguyên dương và giới hạn hợp lý phía máy chủ; không tin giá/tổng tiền từ callback.
- Khóa yêu cầu mua dùng một lần: bấm đôi trả lại kết quả cùng yêu cầu, không tạo hai lần trừ tiền. Khách muốn mua lần mới phải tạo yêu cầu mới.
- Callback mua từ giao diện cũ đưa về màn xác nhận mới, không thực hiện mua ngay.

### Mua bằng ví

- Trong một giao dịch cơ sở dữ liệu: khóa người dùng, kiểm tra yêu cầu mua, đọc lại số dư và khóa đủ số dòng kho theo thứ tự xác định.
- Không đủ số dư/kho thì không trừ tiền, không bán một phần.
- Đủ điều kiện: trừ đúng tổng tiền, đánh dấu toàn bộ kho đã bán, ghi đơn/chi tiết giao/sổ giao dịch và kết quả yêu cầu mua cùng lúc.

### Thanh toán QR

1. Xác thực webhook; kiểm tra nhà cung cấp, mã sự kiện, chiều tiền, số tiền và nội dung thanh toán.
2. Ghi nhận mã sự kiện duy nhất. Chỉ bỏ qua đúng lỗi trùng sự kiện, không che các lỗi ràng buộc khác.
3. Đối chiếu mã chính xác và không mơ hồ, kể cả đơn đã kết thúc; sự kiện không nhận diện được phải được lưu để đối soát, không tự gán cho người dùng.
4. Khóa người dùng và đơn theo một thứ tự thống nhất với luồng ví; đọc lại trạng thái sau khi khóa.
5. Đơn đã kết thúc: khoản chuyển mới cộng ví đúng một lần; không giao lại.
6. Đơn đang chờ nhưng khoản nhận nhỏ hơn tổng đơn: cộng toàn bộ vào ví, kết thúc đơn, thông báo chuyển thiếu; không giữ số tiền chờ bổ sung.
7. Khoản nhận đủ: kiểm tra trạng thái bán và khóa đủ số lượng kho chưa bán. Nếu thiếu bất kỳ đơn vị nào, không lấy một phần, cộng toàn bộ khoản nhận vào ví và kết thúc đơn.
8. Đủ kho: giao đủ số lượng, ghi sổ mua hàng; phần thừa cộng ví. Mọi thay đổi dữ liệu hoàn tất trong cùng giao dịch.
9. Ghi hàng đợi thông báo bền vững trong giao dịch; gửi Telegram sau khi commit. Thử lại thông báo không chạy lại trừ tiền/cộng ví/giao kho. Thông báo có mã đơn để nhận biết nếu gửi trùng do lỗi mạng.

### Ràng buộc chung

- Dùng số tiền thập phân chính xác hoặc số nguyên theo đơn vị tiền nhỏ nhất; tránh sai số dấu phẩy động khi so sánh và ghi sổ.
- Khóa kho theo thứ tự ID nhất quán; áp dụng retry có giới hạn cho deadlock mà vẫn giữ khóa chống lặp.
- Không thực hiện gọi mạng Telegram trong lúc đang giữ khóa cơ sở dữ liệu.
- Một stock chỉ thuộc một đơn đã giao. Tổng tiền nhận của mỗi sự kiện phải bằng tiền mua cộng tiền vào ví, hoặc thuộc trạng thái chờ đối soát rõ ràng.

## 6. Đa ngôn ngữ và bảo mật

- Mọi nội dung giao diện mới/sửa khai báo đồng thời trong [tiếng Việt](../languages/vi.php) và [tiếng Anh](../languages/en.php), kể cả quản trị và thông báo webhook.
- Dùng placeholder và escape dữ liệu động trước khi đưa vào HTML Telegram; không đưa tài khoản ngân hàng, URL hoặc mã thanh toán vào bản dịch.
- Nhãn nút được dịch; định danh callback giữ ổn định, không dịch. Làm rõ điểm này trong [quy ước agent](../AGENTS.md) nếu sửa tài liệu.
- Quản trị giữ xác thực, chống CSRF và kiểm tra dữ liệu đầu vào; nội dung giao hàng chỉ hiển thị cho đúng người dùng hoặc quản trị có quyền.
- Không ghi token, mã thanh toán, chat ID, số tài khoản hoặc nội dung kho vào log chẩn đoán.

## 7. Thứ tự triển khai

- [ ] Duyệt chính sách mới và các chi tiết đề xuất ở mục 1.
- [ ] Rà soát schema, supplier, ngôn ngữ quản trị và cơ chế triển khai thực tế trước khi chốt migration.
- [ ] Viết migration và công cụ báo cáo đối chiếu, thử trên bản sao dữ liệu.
- [ ] Xây dựng xử lý giao dịch chung, khóa yêu cầu mua và phân bổ sự kiện thanh toán chống lặp.
- [ ] Đơn giản hóa quản trị sản phẩm/kho theo ánh xạ nội bộ.
- [ ] Cập nhật Telegram danh sách, chi tiết, chọn số lượng và xác nhận.
- [ ] Triển khai mua ví nhiều đơn vị, giao tất cả hoặc không giao.
- [ ] Triển khai tạo/tái sử dụng QR không giữ kho và cảnh báo phù hợp.
- [ ] Triển khai webhook chuyển thiếu/thiếu kho/chuyển thừa/mã đơn đã kết thúc.
- [ ] Triển khai hàng đợi thông báo, hiển thị lịch sử và quản trị đơn nhiều đơn vị.
- [ ] Hoàn thiện bản dịch, giới hạn Telegram và nhánh ảnh/dự phòng.
- [ ] Chạy đầy đủ kiểm thử mục 8 và kiểm tra cú pháp PHP bằng môi trường thực tế.
- [ ] Duyệt kết quả, sao lưu, triển khai có kiểm soát và đối chiếu dữ liệu.

## 8. Tiêu chí nghiệm thu

- Migration chạy lại không tạo trùng; ID biến thể, kho, lịch sử, mã QR và số dư được bảo toàn; tổng số lượng trước/sau đối chiếu đúng.
- Không còn gói/danh mục trên các giao diện thuộc phạm vi; nguồn hàng nội bộ vẫn hoạt động đúng.
- Kiểm tra kho 0/1/4/5/9/10; chọn nhanh đặt đúng số lượng; tổng tiền và đã bán tính theo đơn vị thực.
- Giá đổi, kho giảm, callback cũ, callback sai người dùng, bấm nhanh và gửi lại cùng yêu cầu không gây mua sai hoặc mua hai lần.
- Kho hai đơn vị, ba đơn QR một đơn vị thanh toán đồng thời: đúng hai đơn nhận hàng, một khoản được cộng ví, không bán trùng stock.
- Đặt ba đơn vị nhưng chỉ còn hai khi xử lý QR: không giao hai đơn vị, toàn bộ tiền vào ví.
- Hai luồng ví và QR tranh cùng kho: chỉ một luồng có thể bán mỗi stock.
- Chuyển thiếu 100.000đ cho đơn 129.000đ: cộng 100.000đ vào ví và kết thúc đơn; chuyển tiếp 29.000đ vào mã cũ chỉ cộng ví, không giao đơn cũ.
- Chuyển dư, đơn hủy, sản phẩm ngừng bán và nhiều khoản chuyển thật vào cùng mã đều được phân bổ đúng; gửi lại cùng sự kiện không thay số dư/kho.
- Thông báo lỗi sau commit có thể thử lại mà không gây giao/trừ/cộng tiền lần hai.
- Kiểm tra cả hai ngôn ngữ, HTML hợp lệ, nội dung giao dài, phân trang lịch sử và quyền truy cập.
- Ảnh QR và dự phòng cùng dữ liệu; nút mở QR dùng đúng URL; làm mới giữ mã và xác nhận bộ nhớ đệm hoạt động bằng quan sát thực tế.

## 9. Triển khai và giới hạn

- Không thay đổi mã nguồn ứng dụng trong giai đoạn lập kế hoạch này.
- Bảo toàn thay đổi cục bộ không thuộc nhiệm vụ, đặc biệt [Helpers](../core/Helpers.php); không stage/reset toàn bộ tùy tiện.
- Dừng ngắn thao tác ghi/checkout khi cần migration; bố trí webhook thử lại hoặc lưu đệm có xác thực để không mất sự kiện thanh toán.
- Kiểm tra workflow tự triển khai thật sự, không giả định đã chạy lint. Chỉ báo kiểm thử thành công khi có kết quả thực tế.
- Trước mở lại bán: đối chiếu số dư, tồn kho, đơn chờ và sự kiện; xác nhận phiên bản production, thử giao dịch kiểm soát.
- Rollback phải xét dữ liệu đơn nhiều đơn vị đã phát sinh; không khôi phục cơ sở dữ liệu cũ làm mất giao dịch tiền. Ưu tiên migration bổ sung và sửa tiến khi đã có dữ liệu mới.
