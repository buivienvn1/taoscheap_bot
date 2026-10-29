# Kế hoạch tích hợp feed ACB bên thứ ba

## 1. Mục tiêu và trạng thái

- Tích hợp feed lịch sử giao dịch của nhà cung cấp qua HTTPS; không đăng nhập ngân hàng, không khôi phục client ACB trực tiếp hoặc Python probe.
- Tài liệu này là kế hoạch, chưa triển khai mã nguồn và chưa kiểm tra API trực tiếp.
- Theo yêu cầu mới: khóa chống xử lý thanh toán trùng là mã DONATE cộng 6 ký tự chữ/số do bot tạo, không phải ID của nhà cung cấp vì ID có thể thay đổi.
- Dự kiến cron cPanel mỗi phút; khuyến nghị feed thay thế nguồn cũ cho cùng tài khoản. Lịch chạy và chuyển nguồn cần được duyệt trước khi kích hoạt thật.
- Không lưu token hoặc URL chứa token trong tài liệu này. Token đã chia sẻ cần được thu hồi và thay mới.

## 2. Hiện trạng liên quan

- Bộ sinh mã hiện tại: [`Helpers::randomCode()`](../core/Helpers.php:23), mặc định tiền tố DONATE và 6 ký tự ngẫu nhiên; bảng ký tự loại trừ một số ký tự dễ nhầm.
- Chuẩn hóa nội dung hiện tại: [`Helpers::normalizePaymentContent()`](../core/Helpers.php:41).
- Nghiệp vụ thanh toán hiện nằm trong [`payment_webhook.php`](../payment_webhook.php:29), gồm nạp ví, đối soát đơn, giao hàng, hoàn vào ví và outbox.
- Nhánh đơn đã đóng hiện có thể cộng tiền vào ví tại [`payment_webhook.php`](../payment_webhook.php:77). Phải sửa đường đi này khi dùng khóa mã DONATE để giao dịch xuất hiện lại dưới ID mới không cộng tiền lần nữa.
- Production từng chạy migration 007 dù mã nguồn đã hoàn tác. Phải hỗ trợ cả schema cài mới và schema production mở rộng, không xóa dữ liệu cũ.
- Cơ chế deploy hiện tại trong [`.cpanel.yml`](../.cpanel.yml) không tự xóa file đã mất khỏi repository. Cần kiểm tra cron và file ACB cũ còn trên server, không giả định rollback Git đã dọn hết.

## 3. Hợp đồng dữ liệu feed

Dựa trên mẫu người dùng cung cấp:

| Trường phản hồi | Cách sử dụng dự kiến |
| --- | --- |
| status | Chỉ nhận phản hồi thành công đúng cấu trúc |
| transactions | Danh sách giao dịch cần kiểm tra |
| transactionID, transactionNumber | Thông tin tham chiếu/audit, không làm khóa chống cộng tiền trùng |
| amount | Số tiền VND dương; xử lý số nguyên hoặc thập phân chính xác, không dùng số thực để đối soát |
| description | Trích mã DONATE và tra cứu yêu cầu thanh toán bot đã phát hành |
| type | Chỉ tiền vào được xét ghi nhận; tiền ra không tiêu thụ mã thanh toán |
| transactionDate | Thời điểm giao dịch có múi giờ, chuẩn hóa để kiểm tra mốc kích hoạt |
| activeDatetime | Thông tin thời gian phụ, đơn vị mili giây; không tự thay thế thời gian chính khi chưa xác nhận |
| postingDate, effectiveDate | Metadata đối chiếu, không dùng thay thời gian giao dịch một cách suy đoán |
| next_cursor | Cursor opaque; chưa được phép suy diễn là ID hoặc tự chọn tên tham số phân trang |
| stale, incomplete | Nếu bật hoặc thiếu/sai kiểu: không tự ghi nhận tiền, lưu trạng thái để retry/kiểm tra |
| last_sync_at, last_sync_ok_at | Theo dõi sức khỏe đồng bộ; không suy ra feed đầy đủ chỉ từ thời gian gần đây |

### Điểm cần nhà cung cấp xác nhận

1. Token gắn với đúng một tài khoản nhận VND của shop hay nhiều tài khoản.
2. Feed trả toàn bộ lịch sử hay cửa sổ giới hạn; giới hạn bản ghi và thời gian lưu.
3. Tham số lấy trang tiếp, hướng sắp xếp, thời hạn cursor, hành vi khi ID thay đổi hoặc dữ liệu được đồng bộ lại.
4. Cách lấy bù sau downtime và xử lý giao dịch đến muộn.
5. Ý nghĩa chính xác của cờ dữ liệu cũ/chưa đầy đủ, định dạng lỗi, rate limit và thông tin retry.
6. Chính sách sửa nội dung, số tiền, thời gian của giao dịch đã trả về.

Không dùng hợp đồng endpoint lịch sử trước đây để tự suy ra hợp đồng feed mới.

## 4. Quy tắc chống trùng theo mã DONATE

### 4.1. Nguyên tắc bắt buộc

- Mỗi mã thanh toán bot đã phát hành chỉ được tạo tác động tài chính tự động tối đa một lần trong toàn hệ thống, kể cả đổi provider, đổi token hoặc đổi ID API.
- Khóa duy nhất là mã DONATE đã chuẩn hóa; không ghép thêm ID nhà cung cấp, số tiền hoặc tên nguồn để tạo một khóa mới có thể cho phép cộng lại.
- Mã phải tồn tại trong dữ liệu bot, trỏ đến đúng một yêu cầu thanh toán. Không cộng tiền chỉ vì nội dung có hình thức giống mã.
- Mã duy nhất xuyên suốt nạp ví và mua hàng, không chỉ duy nhất trong từng bảng; không tái sử dụng mã cũ.
- Khi sinh mã mới, dùng registry/ràng buộc duy nhất ở database và sinh lại khi va chạm. Không thay mã của yêu cầu pending chỉ để phục vụ tích hợp mới.
- Quét các mã đã có để tìm va chạm hoặc một mã gắn nhiều bản ghi; trường hợp mơ hồ phải cách ly, không chọn bản ghi đầu tiên.

### 4.2. Trích mã và kiểm tra dữ liệu

- Xây dựng parser theo đúng định dạng mã bot phát hành; cho phép nội dung ngân hàng bao quanh mã theo quy tắc có kiểm thử.
- Tránh cách tìm chuỗi con khiến mã dài hơn bị nhận nhầm hoặc việc xóa ký tự nối hai đoạn văn bản thành mã giả.
- Không có mã: lưu kết quả không khớp, không tác động tài chính.
- Nhiều mã hợp lệ hoặc nhiều yêu cầu cùng mã: chuyển kiểm tra thủ công.
- Tiền ra, số tiền sai, thiếu trường thiết yếu, trạng thái nguồn không an toàn: không chiếm/tiêu thụ mã.

### 4.3. Ghi nhận nguyên tử

1. Xác thực dữ liệu nguồn, chiều tiền, mã bot và điều kiện kích hoạt.
2. Mở transaction database; chiếm khóa duy nhất theo mã và khóa bản ghi nghiệp vụ liên quan.
3. Nếu mã đã có kết quả tài chính cuối: không cộng ví, giao hàng hoặc tạo thông báo thành công lần nữa.
4. Nếu chưa xử lý: thực hiện đúng nghiệp vụ nạp ví/đơn/kho, ghi kết quả xử lý và enqueue thông báo trong cùng transaction.
5. Commit thành công mới coi mã đã được sử dụng. Nếu lỗi và rollback thì lần cron sau được retry.
6. Gửi Telegram ngoài transaction. Thất bại thông báo chỉ retry outbox, không chạy lại tác động tài chính.

Các nguồn thanh toán còn được bật phải dùng cùng khóa nghiệp vụ này. Không để webhook cũ đi vòng qua cơ chế chống trùng.

### 4.4. Giới hạn quan trọng và thay đổi nghiệp vụ

Mã DONATE là khóa yêu cầu thanh toán, không phải bằng chứng nhận diện một chuyển khoản ngân hàng duy nhất.

- Nếu khách thực sự chuyển hai lần cùng mã, hệ thống chỉ tự ghi nhận một lần; lần còn lại cần đối soát thủ công.
- Nếu khách chuyển thiếu rồi chuyển thêm cùng mã, không tự cộng lần bổ sung theo kế hoạch này. Hướng dẫn dùng yêu cầu/mã mới hoặc hỗ trợ đối soát.
- Cùng mã xuất hiện lại dưới ID mới nhưng dữ liệu tương đương: không tác động tài chính lần nữa.
- Cùng mã nhưng số tiền/nội dung/thời gian khác: đánh dấu cần kiểm tra; không coi đó chắc chắn là giao dịch mới hay chắc chắn là bản sao.
- Fingerprint dữ liệu có thể giảm bản ghi audit lặp, nhưng không thay thế khóa mã DONATE và không đủ chứng minh hai giao dịch là một.
- Giữ xử lý thiếu/thừa/hết hàng cho lần ghi nhận hợp lệ đầu tiên; không giữ hành vi tự cộng tiền nhiều lần cho cùng mã của đơn đã đóng.
- Quy tắc khoản dưới mức nạp tối thiểu và thời điểm mã được coi là đã dùng phải chốt rõ: đề xuất lưu review, không tạo tác động tài chính và không tự gom nhiều lần chuyển.

## 5. Cấu trúc triển khai

### 5.1. Cấu hình và HTTP client

- Cấu hình riêng trên server: host/path feed, token, tài khoản đã xác nhận, timeout, giới hạn phản hồi, trạng thái bật/tắt, chế độ chỉ đọc, mốc kích hoạt.
- Không commit bí mật; bảo vệ khỏi truy cập HTTP và không ghi URL đầy đủ vào log.
- Xác minh TLS, không tự theo redirect chứa token, chỉ gọi host được cấu hình.
- Lỗi xác thực: dừng xử lý và cảnh báo. Rate limit/server lỗi: backoff có giới hạn, tôn trọng thông tin retry hợp lệ.
- Log chỉ gồm mã lỗi an toàn, thời gian chạy và số lượng; không log token, nội dung chuyển khoản, tài khoản, chat ID hoặc mã thanh toán.

### 5.2. Adapter và polling

- Adapter chuyển dữ liệu feed sang dữ liệu thanh toán nội bộ đã kiểm tra.
- Cron có khóa chống chạy chồng, giới hạn số trang/thời gian mỗi lượt.
- Khi chưa có hợp đồng cursor, không triển khai checkpoint dựa trên ID cao nhất.
- Nếu là cửa sổ lịch sử: đọc chồng lặp có kiểm soát và dùng khóa DONATE để chống tác động lặp; phải có cảnh báo nguy cơ mất dữ liệu khi khoảng gián đoạn vượt phạm vi feed.
- Lưu giao dịch quan sát và tiến độ an toàn trước khi tiến checkpoint; lỗi giữa chừng phải có thể đọc lại.
- Feed cũ/chưa đầy đủ không làm mất checkpoint hoặc tiêu thụ mã; chạy lại khi nguồn phục hồi.

### 5.3. Bộ xử lý nghiệp vụ và outbox

- Tách nghiệp vụ từ [`payment_webhook.php`](../payment_webhook.php) thành service dùng chung, không gọi HTTP vòng lại chính webhook.
- Adapter chỉ phụ trách nguồn dữ liệu, không tự cộng ví hoặc giao hàng.
- Khóa mã, cập nhật ví/đơn/kho và outbox cùng transaction; kiểm tra deadlock/retry an toàn.
- Outbox có retry độc lập và chống worker lấy cùng một bản ghi. Telegram có thể nhận trùng thông báo nếu gửi thành công rồi process chết trước khi lưu trạng thái; không cam kết exactly-once ở tầng Telegram.
- Nội dung mới dùng [`languages/vi.php`](../languages/vi.php) và [`languages/en.php`](../languages/en.php), dữ liệu động được escape.

## 6. Database và chuyển đổi lịch sử

- Tạo migration mới sau số 007, không tái sử dụng migration đã từng chạy.
- Thiết kế registry mã và sổ kết quả xử lý theo mã với unique constraint toàn cục; liên kết yêu cầu nạp/đơn, trạng thái, số tiền đã xử lý và thời điểm.
- Audit nguồn lưu ID nhà cung cấp như metadata, có chính sách lưu giữ và bảo vệ dữ liệu nhạy cảm; không lưu token trong payload.
- Kiểm tra schema thực tế trước khi thêm cột/index để hỗ trợ cả database sạch và database đã có 007.
- Kiểm tra DDL tự commit trong [`core/MigrationRunner.php`](../core/MigrationRunner.php:53); migration phải có đường phục hồi sau thực thi một phần, không dựa vào rollback DDL.
- Backfill mã đã xử lý từ đơn/nạp và sự kiện thanh toán cũ trước khi bật nguồn mới, tránh feed phát lại khoản đã ghi nhận trước triển khai.
- Trạng thái đơn đã đóng không tự chứng minh đã nhận tiền. Dùng lịch sử tác động tài chính để phân loại; thiếu bằng chứng thì review, không tự gán kết quả.
- Mã pending vẫn giữ nguyên; mã trùng/mơ hồ bị chặn và lập danh sách xử lý trước rollout.
- Không xóa bảng/cột hoặc lịch sử migration 007.

## 7. Kiểm thử và tiêu chí nghiệm thu

- ID API thay đổi nhưng cùng DONATE: ví, đơn, kho và thông báo nghiệp vụ chỉ thay đổi một lần.
- Hai cron hoặc webhook/cron đồng thời với cùng mã: chỉ một transaction ghi nhận tiền.
- Cùng mã nhưng hai khoản thực tế khác nhau: khoản tiếp theo được đưa vào review, không tự cộng lại.
- Mã giả, mã không tồn tại, nhiều mã, mã dài hơn chuẩn, mã trùng giữa nạp và đơn: không ghi nhận nhầm.
- Sinh mã va chạm: retry an toàn; yêu cầu pending không bị đổi mã.
- Tiền vào/ra, tiền âm/không hợp lệ, thiếu trường, thay đổi dữ liệu, giao dịch đến muộn.
- Nguồn cũ/chưa đầy đủ, lỗi HTTP, JSON sai, rate limit, timeout, nhiều trang và cursor lặp.
- Crash trước/sau commit; hết hàng; thiếu/thừa tiền; đơn đã đóng; Telegram lỗi rồi retry.
- Backfill ngăn ghi nhận lại thanh toán trước triển khai, kể cả ID nhà cung cấp đổi.
- Kiểm tra cả schema cài mới và production có 007; migration chạy lại sau thất bại một phần.
- Kiểm tra đủ tiếng Việt/Anh, Telegram HTML và dữ liệu động đã escape.

## 8. Rollout và rollback

1. Thay token đã chia sẻ; xác nhận tài khoản, VND và hợp đồng feed.
2. Sao lưu database; kiểm tra cron/file ACB cũ còn trên server.
3. Chạy migration và backfill; giải quyết mã trùng hoặc dữ liệu lịch sử mơ hồ.
4. Deploy chế độ chỉ đọc; đối chiếu feed với giao dịch thực, chưa cộng tiền/giao hàng.
5. Chốt mốc kích hoạt và danh sách khoản pending tại thời điểm chuyển nguồn. Không tự ghi nhận toàn bộ lịch sử cũ.
6. Tắt nguồn cũ cho cùng tài khoản hoặc buộc mọi nguồn đi qua service chung trước khi bật thật.
7. Bật ghi nhận, kiểm tra giao dịch kiểm soát và chạy lặp để xác nhận chống trùng theo mã.
8. Theo dõi sức khỏe nguồn, outbox và danh sách review.
9. Rollback bằng cách tắt polling/ghi nhận mới; giữ sổ chống trùng và dữ liệu tài chính. Không phục hồi webhook cũ bỏ qua khóa mã khi nguồn cũ còn hoạt động.

## 9. Checklist thực hiện

- [ ] Duyệt quy tắc một mã DONATE chỉ tự ghi nhận một lần, khoản bổ sung cùng mã đưa vào review.
- [ ] Chốt hợp đồng feed và phương án cron/chuyển nguồn.
- [ ] Thiết kế cấu hình bí mật và HTTP client bảo mật.
- [ ] Khảo sát toàn bộ nơi sinh mã, schema và dữ liệu lịch sử; thiết kế registry/backfill.
- [ ] Viết migration tương thích hai trạng thái schema và phục hồi DDL một phần.
- [ ] Tách service nghiệp vụ, áp dụng khóa DONATE chung cho mọi nguồn được bật.
- [ ] Triển khai adapter, polling, lưu audit và checkpoint đúng hợp đồng.
- [ ] Bổ sung retry outbox, cảnh báo và nội dung song ngữ cần thiết.
- [ ] Chạy bộ kiểm thử và đối chiếu chỉ đọc.
- [ ] Duyệt mốc kích hoạt, deploy, kiểm tra thực tế và chuẩn bị rollback.
