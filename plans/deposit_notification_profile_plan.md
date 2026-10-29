# Kế hoạch cải thiện nạp tiền và Cá nhân

## Phạm vi đã thống nhất
- Ảnh QR: gửi xác nhận dạng chữ thành công rồi xóa ảnh cũ. Tin dự phòng dạng chữ: ưu tiên sửa trực tiếp nếu vẫn là màn thanh toán.
- Thông báo riêng cho một hoặc nhiều Telegram ID admin, dùng tiếng Việt; lịch sử admin nằm trên web quản trị, không xây lịch sử riêng trong Telegram.
- Cá nhân thêm dòng **Tham gia từ: DD/MM/YYYY**.
- Tách **mã giao dịch đối soát** khỏi **mã DONATE**: mã đối soát là mã nghiệp vụ để tra cứu; mã DONATE chỉ là nội dung chuyển khoản.
- User có lịch sử giao dịch và lịch sử đơn hàng trong Telegram; admin có lịch sử nạp tiền và đơn hàng trên web quản trị.
- Đơn hàng dùng chính `order_code` hiện có, định dạng `OD...`, làm Code đối soát; không tạo thêm mã đơn hàng mới.
- Admin chỉ nhận thông báo mua hàng tóm tắt; không gửi nội dung sản phẩm đã giao cho admin. User vẫn nhận nội dung sản phẩm ngay trong thông báo mua hàng thành công.
- Không thay đổi quy tắc cộng tiền, mức tối thiểu hoặc mã thanh toán đang chờ; không tạo lại mã thanh toán chỉ để đổi cách trình bày.

## Kết quả khảo sát
- [DepositHandler](../bot/handlers/DepositHandler.php:53) gửi QR nhưng chưa lưu ID tin trả về; nhánh dự phòng cũng chưa lưu ID thực tế.
- [PaymentProcessor](../core/PaymentProcessor.php:81) đã cộng tiền và tạo outbox trong giao dịch DB, nhưng thông báo chỉ có số tiền.
- [NotificationOutbox](../core/NotificationOutbox.php:20) luôn gửi tin mới và phụ thuộc người nhận là user trong DB; chưa có dọn QR hay người nhận admin độc lập.
- [Telegram](../core/Telegram.php:32) đã có khả năng gửi chữ thay ảnh trong một số trường hợp nhưng thao tác xóa đang nuốt lỗi, chưa đủ để theo dõi retry dọn dẹp.
- [Router](../bot/Router.php:121) giữ nguyên ngày tạo user khi cập nhật thông tin; có thể dùng ngày này cho ngày tham gia, không cần thêm cột ngày mới.
- [Cấu hình ứng dụng](../config/app.php) chưa có danh sách admin Telegram.
- [Webhook thanh toán](../payment_webhook.php:38) giao ngay một outbox, còn [cron feed](../cron/payment_feed.php:17) tạo thông báo để cron outbox xử lý; cần giữ tương thích khi một giao dịch sinh nhiều người nhận.
- [OrderHandler](../bot/handlers/OrderHandler.php:167) đã có `order_code` cho đơn hàng, dùng làm Code `OD...`; thanh toán qua QR còn có `payment_code` là mã nội dung chuyển khoản. Hai mã này có vai trò khác nhau và cần hiển thị đúng ngữ cảnh.
- [Lịch sử đơn hàng hiện tại](../bot/handlers/ProfileHandler.php:33) đã phân trang nhưng mỗi mục đang hiển thị nhiều chi tiết và nội dung giao hàng ngay trong danh sách; cần tách danh sách gọn khỏi màn chi tiết.
- [Trang đơn hàng admin](../admin/modules/orders.php:2) và [trang nạp tiền admin](../admin/modules/deposits.php:7) hiện chỉ hiển thị bảng giới hạn, chưa có bộ lọc, phân trang, chi tiết giao dịch hoặc mã đối soát thống nhất.

## Các bước triển khai
1. **Dữ liệu và migration**
   - Thêm migration mới sau phiên bản 008; không sửa migration đã chạy. Đồng bộ [schema cài mới](../install.sql) và [hướng dẫn migration](../migrations/README.md).
   - Lưu quan hệ một giao dịch nạp với nhiều tin đã hiển thị: chat, ID tin thực tế, loại ảnh/chữ, trạng thái còn là màn thanh toán, trạng thái dọn dẹp. Không chỉ lưu tin cuối cùng vì user có thể mở nhiều menu.
   - Mở rộng outbox với loại người nhận, đích Telegram và ngôn ngữ riêng; admin không phải đăng ký như khách hàng trong DB. Giữ tương thích bản ghi user cũ và khóa ngoại hiện tại bằng migration phù hợp.
   - Lưu ID tin xác nhận đã gửi cùng tiến độ dọn dẹp; retry xóa ảnh không được gửi lại xác nhận đã ghi nhận thành công.
2. **Cấu hình admin**
   - Thêm danh sách ID admin trong [cấu hình ứng dụng](../config/app.php), mặc định rỗng; kiểm tra ID và loại trùng.
   - Mỗi admin là một thông báo độc lập, ngôn ngữ Việt. Danh sách rỗng không làm lỗi thanh toán.
   - Hướng dẫn admin mở bot trước; bot bị chặn hoặc admin không nhận được không ảnh hưởng cộng tiền và các người nhận khác.
3. **Theo dõi màn nạp tiền**
   - Sửa [DepositHandler](../bot/handlers/DepositHandler.php) để lấy và lưu ID thực tế từ cả gửi ảnh và fallback; vẫn tái sử dụng giao dịch đang chờ và cùng URL QR.
   - Phối hợp [Router](../bot/Router.php) và thao tác Telegram để đánh dấu màn đã chuyển nội dung, tránh sửa/xóa nhầm Menu chính hoặc Cá nhân.
   - Xử lý đồng thời giữa gửi QR, ghi ID và xác nhận tiền: kiểm tra lại trạng thái sau khi ghi tin và đưa QR gửi muộn vào dọn dẹp. Không giữ transaction tài chính mở trong khi gọi Telegram.
4. **Sự kiện nạp thành công**
   - Trong [PaymentProcessor](../core/PaymentProcessor.php:81), lấy số dư ngay sau cộng tiền và thời điểm hoàn tất làm dữ liệu cố định của thông báo.
   - Tạo outbox cho user và từng admin trong cùng transaction DB; khóa chống trùng theo giao dịch, vai trò và người nhận. Chỉ phát khi nạp thực sự hoàn tất, không phát cho giao dịch dưới tối thiểu hoặc đơn hàng được hoàn tiền vào ví.
   - Khi đơn hàng mua thành công và giao đủ hàng, tạo thêm một outbox riêng cho từng admin đã cấu hình. Admin nhận thông báo cho cả đơn thanh toán bằng ví và đơn thanh toán QR; không gửi khi đơn thất bại, chờ xử lý hoặc chỉ hoàn tiền.
   - Thông báo mua hàng cho admin chỉ gồm bản tóm tắt đối soát: khách hàng, Telegram ID, sản phẩm, số lượng, tổng tiền, phương thức, trạng thái, Code `OD...` và thời gian. Không gửi nội dung sản phẩm cho admin và không ghi nội dung sản phẩm vào log.
   - Telegram chỉ được gọi sau commit; lỗi thông báo không đảo ngược hoặc thực hiện lại cộng tiền.
5. **Gửi kết quả và dọn QR**
   - Tin chữ còn hiệu lực: sửa thành xác nhận. Ảnh QR: gửi xác nhận trước, lưu kết quả rồi dọn các ảnh còn hiệu lực của giao dịch.
   - Không còn tin, tin đã bị xóa hoặc đã điều hướng: gửi xác nhận mới, không tác động màn hiện tại.
   - Tin không thay đổi được xem là đã đạt trạng thái đích; phân biệt tin không tồn tại, không thể xóa và lỗi tạm thời.
   - Xóa ảnh thất bại: thử đổi caption thành trạng thái đã thanh toán và bỏ nút thanh toán/QR cũ; theo dõi dọn dẹp riêng, không gửi lại xác nhận chỉ vì xóa thất bại.
   - Cập nhật [NotificationOutbox](../core/NotificationOutbox.php), [Telegram](../core/Telegram.php), [webhook](../payment_webhook.php) và [cron thông báo](../cron/notification_outbox.php). Giữ hoạt động của thông báo đơn hàng và tương thích dữ liệu outbox cũ.
   - Không hứa gửi đúng một lần tuyệt đối: Telegram có thể nhận tin nhưng kết nối timeout trước khi ứng dụng lưu ID. Ghi rõ giới hạn này; bảo đảm chống cộng tiền và tạo sự kiện trùng ở DB.
6. **Nội dung và Cá nhân**
   - Tất cả câu chữ/nhãn mới có ở [tiếng Việt](../languages/vi.php) và [tiếng Anh](../languages/en.php); dữ liệu động qua placeholder và escape HTML đúng một lần.
   - QR và fallback dùng chung nội dung nền, giữ thông tin chuyển khoản và URL QR gốc. Chỉ tinh gọn tiêu đề, số emoji và hướng dẫn, không đổi dữ liệu thanh toán.
   - [ProfileHandler](../bot/handlers/ProfileHandler.php:13) thêm ngày tham gia từ ngày tạo bản ghi user, không lấy ngày cập nhật hay suy diễn ngày tạo tài khoản Telegram. Định dạng theo múi giờ ứng dụng sau khi xác minh quy ước thời gian DB; ngày không hợp lệ hiển thị nhãn chưa có thông tin đã dịch.
7. **Thiết kế mã đối soát và lịch sử**
   - Dùng `order_code` hiện có, dạng `OD...`, làm Code đối soát cho giao dịch mua hàng; không tạo thêm một mã thứ hai cho đơn hàng và không đổi Code khi đơn chuyển trạng thái.
   - Bổ sung một mã đối soát riêng cho mỗi lần nạp tiền, định dạng ngắn và dễ đọc, ví dụ `NAP-000125`. Mã này không thay thế `code`/mã DONATE dùng để khớp giao dịch ngân hàng.
   - Nếu cần một khóa nội bộ bất biến, dùng ID bản ghi kết hợp loại nghiệp vụ ở DB; không đưa ID tự tăng thô làm mã hiển thị duy nhất. Mã hiển thị phải có unique index và được sinh trong transaction.
   - Quy ước hiển thị: phần nội dung chính hiển thị trước, dòng cuối dùng `Code: <code>...</code> | thời gian` để dễ copy và đối soát. Với nạp QR, chi tiết có thể hiển thị thêm `Mã chuyển khoản: ...`; không gọi mã DONATE là mã giao dịch.
   - Chuẩn hóa dữ liệu lịch sử từ `deposits`, `orders` và `transactions`: phân biệt `deposit`, `purchase`, `refund`, `adjustment`; không coi `transactions` hiện tại là đủ cho mọi đối soát vì đơn mua trực tiếp không phải lúc nào cũng là biến động số dư ví.
   - User: thêm nút **Lịch sử giao dịch** và **Lịch sử đơn hàng** trong [ProfileHandler](../bot/handlers/ProfileHandler.php:29). Mỗi danh sách tối đa 5 mục/trang, có nút **Trước/Sau**, nút mở chi tiết từng mục và nút quay lại giữ đúng trang.
   - Danh sách đơn hàng chỉ hiển thị `mã đơn · tên sản phẩm` và trạng thái ngắn nếu cần; không hiển thị nội dung giao hàng, giá và thông tin dài trong list. Chi tiết đơn hàng hiển thị mã đối soát, trạng thái, thời gian, sản phẩm, số lượng, đơn giá, tổng tiền, phương thức thanh toán, tiền cộng ví/hoàn tiền và nội dung giao hàng.
   - Danh sách giao dịch hiển thị mã, loại, số tiền tăng/giảm, trạng thái và thời gian; màn chi tiết hiển thị mã DONATE/mã đơn liên quan, số dư trước/sau nếu dữ liệu xác định được và ghi chú đối soát.
   - User chỉ được truy cập bản ghi của chính mình; callback phải kiểm tra `user_id`, mã nghiệp vụ và dữ liệu tồn tại trước khi hiển thị.
   - Admin web: thêm mục lịch sử giao dịch hoặc mở rộng mục nạp tiền/đơn hàng với bộ lọc mã đối soát, mã DONATE, Telegram ID, loại, trạng thái và khoảng thời gian; có phân trang server-side, xem chi tiết và phân biệt nạp tự động với duyệt tay.
   - Admin không nhận callback lịch sử qua Telegram; Telegram admin chỉ nhận thông báo có mã đối soát ở dòng đầu để đối chiếu nhanh với web.
8. **Thông báo và dữ liệu đối soát**
   - Thông báo user nạp tiền đặt mã đối soát lên đầu, sau đó số tiền, số dư sau giao dịch, trạng thái và thời gian; mã DONATE chỉ hiển thị ở dòng mã chuyển khoản khi hữu ích.
   - Thông báo admin đặt mã đối soát lên đầu, sau đó loại giao dịch, user/Telegram ID, số tiền, số dư sau giao dịch, mã DONATE hoặc mã đơn hàng liên quan, phương thức thanh toán, thời gian và trạng thái xử lý.
   - Thông báo mua hàng thành công dùng `order_code` dạng `OD...` làm `Code` đối soát ở dòng cuối cùng cùng thời gian; tiếp theo là sản phẩm, số lượng, tổng tiền, phương thức và trạng thái giao hàng. Nội dung hàng giao hiển thị cho user ngay trong thông báo thành công sau khi escape và áp dụng giới hạn độ dài an toàn; admin chỉ nhận bản tóm tắt, không nhận nội dung sản phẩm.
   - Với thông báo nạp tiền, đặt phần kết thúc theo mẫu `Code: NAP-000125 | 29/10/2026 15:30`; với thông báo admin nạp tiền, thông tin khách hàng, Telegram ID, số tiền, số dư và mã chuyển khoản nằm trước dòng Code. Không dùng cụm “Mã giao dịch” ở mẫu hiển thị nếu quy ước giao diện đã thống nhất là `Code`.
   - Chụp số dư trước/sau, thời điểm hoàn tất và snapshot tên sản phẩm trong dữ liệu sự kiện để lịch sử không bị thay đổi khi user đổi tên hoặc sản phẩm bị sửa.
   - Thống nhất cả luồng mua bằng ví, mua bằng QR, nạp tự động và duyệt nạp thủ công; cùng một giao dịch chỉ tạo một sự kiện lịch sử và một thông báo cho mỗi người nhận.
9. **Kiểm thử và tài liệu**
   - Bổ sung kiểm thử phù hợp với [bộ kiểm thử thanh toán](../tests/payment_feed_test.php): giao dịch trùng, nhiều worker, retry từng admin, rollback, không có admin, admin chặn bot, thiếu username và tên có ký tự HTML.
   - Kiểm tra ảnh thành công, fallback không ảnh, mở QR nhiều lần, điều hướng trước khi nhận tiền, nhận tiền đồng thời gửi QR, tin bị xóa, xóa ảnh thất bại, gửi thành công nhưng cập nhật DB thất bại.
   - Kiểm tra admin nhận được thông báo mua hàng tóm tắt ở cả luồng ví và QR, có Code `OD...`, nhưng không chứa nội dung sản phẩm; user vẫn nhận nội dung sản phẩm trong thông báo thành công.
   - Kiểm tra mã nạp tăng/duy nhất, mã đơn cũ không đổi, mã DONATE không bị dùng nhầm làm mã đối soát, cùng một sự kiện không xuất hiện hai lần trong lịch sử.
   - Kiểm tra list đơn hàng chỉ có mã + tiêu đề, nút chi tiết đúng bản ghi, phân trang 5 mục, quay lại đúng trang và nội dung giao hàng không lọt vào danh sách.
   - Kiểm tra lịch sử giao dịch phân biệt nạp, mua, hoàn tiền, cộng ví; lọc admin theo mã, user, trạng thái, thời gian và phân trang không tải toàn bộ dữ liệu.
   - Kiểm tra bản ghi cũ không có ID tin và tham số thông báo mới; không làm hỏng thông báo đơn hàng. Không phát lại thông báo cho toàn bộ giao dịch lịch sử.
   - Kiểm tra ngày tham gia không đổi sau các lần tương tác, ngày không hợp lệ và ranh giới múi giờ.
   - Xem thực tế Telegram desktop/mobile bằng tiếng Việt và Anh, kiểm tra HTML, khoảng cách, giới hạn tin nhắn và nút điều hướng.
   - Cập nhật [README](../README.md) về migration, danh sách admin, điều kiện nhận tin, quy ước mã đối soát, các màn lịch sử và cron retry; không log token, mã thanh toán, chat ID hoặc tài khoản ngân hàng; không ghi đè thay đổi cục bộ ngoài phạm vi.

## Mẫu nội dung đề xuất
Các số và tên sau chỉ là ví dụ trình bày.

### User — nạp tiền
> ✅ **Nạp tiền thành công**
>
> Đã cộng: **2.000đ**
> Số dư: **12.000đ**
>
> Code: `NAP-000125` | 29/10/2026 15:30

Nút: **Cá nhân** · **Menu chính**.

### Admin — Telegram
> 💰 **Nạp tiền thành công**
>
> Khách hàng: Nguyễn An · @nguyenan
> Telegram ID: 123456789
> Số tiền: **2.000đ** · Số dư: **12.000đ**
> Mã chuyển khoản: `DONATE...`
>
> Code: `NAP-000125` | 29/10/2026 15:30

Dùng Code để tìm trên web quản trị. Mã chuyển khoản chỉ dùng đối soát với sao kê ngân hàng; không thay thế Code. Không có username thì bỏ phần username.

### User — mua hàng thành công
> ✅ **Mua hàng thành công**
>
> Sản phẩm: Tên sản phẩm
> Số lượng: **1**
> Tổng tiền: **2.000đ**
> Trạng thái: Đã giao hàng
>
> Code: `OD-AB12CD34` | 29/10/2026 15:31
>
> Nội dung sản phẩm:
> <code>nội dung sản phẩm</code>

### Admin — mua hàng thành công
> 🛒 **Mua hàng thành công**
>
> Khách hàng: Nguyễn An · @nguyenan
> Telegram ID: 123456789
> Sản phẩm: Tên sản phẩm
> Số lượng: **1**
> Tổng tiền: **2.000đ**
> Trạng thái: Đã giao hàng
>
> Code: `OD-AB12CD34` | 29/10/2026 15:31

Admin chỉ nhận bản tóm tắt để biết giao dịch đã hoàn tất; không gửi nội dung sản phẩm. User nhận nội dung sản phẩm ngay trong thông báo mua thành công và tại chi tiết đơn hàng.

### Cá nhân
> Tham gia từ: **29/10/2026**

## Tiêu chí hoàn tất
- User nhận kết quả rõ ràng; QR được dọn hoặc đánh dấu đã thanh toán khi Telegram không cho xóa; không sửa/xóa màn không còn thuộc giao dịch.
- Mỗi admin được cấu hình có thông báo và retry riêng, không phụ thuộc user nhận tin thành công; lịch sử admin tra cứu được trên web.
- Mọi thông báo nạp và mua hàng có dòng `Code: ... | thời gian` ở cuối phần thông tin chính; Code nạp dạng `NAP-...`, Code mua hàng là `order_code` dạng `OD...`; mã DONATE vẫn là mã chuyển khoản, không bị dùng lẫn.
- Mỗi đơn hàng giao thành công tạo thông báo cho user và từng admin; lỗi một admin không ảnh hưởng user hoặc admin khác.
- Nội dung sản phẩm chỉ xuất hiện trong thông báo/chi tiết dành cho user; thông báo admin chỉ chứa thông tin tóm tắt để đối soát.
- Tiền chỉ được cộng một lần; số dư trong thông báo là số dư sau chính giao dịch đó.
- User có hai lịch sử tách biệt: giao dịch tài chính và đơn hàng; danh sách đơn hàng gọn, chi tiết mở bằng nút, tối đa 5 mục/trang và phân trang đúng.
- Lịch sử admin có bộ lọc, phân trang, chi tiết và phân biệt các loại biến động; không lộ nội dung hàng giao không cần thiết.
- Ngày tham gia lấy từ dữ liệu thật, hiển thị ngắn gọn; đủ hai ngôn ngữ.
- Kế hoạch đã mở rộng sang luồng đối soát và lịch sử user/admin; hiện chưa sửa mã nguồn ứng dụng.
