# Referral — Mời bạn mua hàng và nhận thưởng

Tệp dự kiến: `plans/referral_purchase_rewards_plan.md`

## 1. Giao diện

Thêm nút ngoài màn hình chính, đồng thời dùng làm tiêu đề:

- VI: **🎁 Mời bạn & Kiếm tiền**
- EN: **🎁 Refer & Earn**

Màn hình tiếng Việt:

```text
🎁 Mời bạn & Kiếm tiền

Mời bạn mua hàng, nhận thưởng VND.

Thưởng mỗi bạn: {reward}
Đã mời: {invited}
Đã thưởng: {rewarded}
Tổng nhận: {earned}

Link mời:
{referral_link}

⭐️ Cách thức hoạt động
{instructions}
```

Tiếng Anh dùng nội dung tương ứng. Các nút: **Chia sẻ / Share**, **Cập nhật / Refresh**, **Quay lại / Back**.

Link mời: `https://t.me/{bot_username}?start={code}`. Mã ngẫu nhiên 12 ký tự chữ/số, duy nhất và cố định.

`invited` là số bạn mới được ghi nhận; `rewarded` là số bạn đã tạo thưởng được trả; `earned` là tổng tiền thưởng đã cộng ví.

## 2. Quy tắc thưởng

- Thưởng cố định cho người mời khi bạn mới có đơn đầu tiên đủ điều kiện.
- **Không có điều kiện giá trị đơn tối thiểu.** Mọi đơn có thanh toán và giao hàng thành công đều có thể tạo thưởng.
- Mỗi bạn mới chỉ tạo một lần thưởng, không phụ thuộc số lượng sản phẩm hoặc số đơn mua.
- Chỉ ghi nhận tài khoản lần đầu xuất hiện qua link hợp lệ khi chương trình bật. Không tự giới thiệu, đổi người mời hoặc gắn referral cho khách cũ.
- Bạn mới qua link phải xác nhận tham gia kênh để mở menu; khách cũ và người vào trực tiếp không bị chặn.
- Khi đơn hoàn tất, người mua phải vẫn là thành viên kênh.
- Đơn thất bại, hủy, đang xử lý hoặc nạp ví không tạo thưởng.
- Nếu đã rời kênh, đơn đó không tạo thưởng. Vào lại không xét hồi tố đơn cũ; đơn hợp lệ tiếp theo vẫn có thể tạo thưởng.
- Không chờ 24 giờ, không theo dõi rời kênh liên tục và không thu hồi thưởng sau khi trả.
- Tiền cộng ví VND hiện có, dùng mua mọi sản phẩm; không hỗ trợ rút tiền.

## 3. Admin và nội dung đa ngôn ngữ

Cấu hình gồm:

- Bật/tắt; mặc định tắt.
- Tiền thưởng mỗi bạn mới.
- Giới hạn lượt trả thưởng/người mời/ngày và ngân sách chung/ngày.
- ID kênh và link tham gia.
- Nội dung **Cách thức hoạt động**, hỗ trợ `<vi>`, `<en>` và Telegram HTML.

Hướng dẫn mặc định:

```html
<vi>
1. Chia sẻ link cho bạn mới.
2. Bạn tham gia kênh và mua hàng.
3. Nhận {reward} khi đơn hoàn thành và bạn vẫn ở trong kênh.

Mỗi bạn mới được tính thưởng một lần.
</vi>
<en>
1. Share your link with a new friend.
2. Your friend joins the channel and makes a purchase.
3. Earn {reward} when the order is completed and your friend is still a channel member.

Each new friend qualifies once.
</en>
```

- Chỉ hỗ trợ placeholder `{reward}` trong hướng dẫn.
- Tái sử dụng `Helpers::extractMultilingualText()` và `Helpers::sanitizeTelegramHtml()`, giữ fallback ngôn ngữ hiện có.
- Hướng dẫn trống dùng nội dung mặc định trong file ngôn ngữ.
- Tất cả nhãn, nút và thông báo mới có đủ VI/EN; escape dữ liệu động và kiểm tra độ dài Telegram HTML.
- Admin có danh sách phân trang, tìm người mời/bạn mới, đơn liên quan, trạng thái và lý do chưa thưởng. Thao tác ghi có CSRF; duyệt ngoại lệ lưu lý do.
- Không thêm cấu hình, dữ liệu, placeholder hoặc logic kiểm tra ngưỡng giá trị đơn.

## 4. Triển khai và tính nhất quán

- Thêm handler và service referral dùng chung; tích hợp đầy đủ ví, QR, thanh toán kết hợp, kho nội bộ và nguồn API.
- Ghi nhận user mới cùng người mời trong một transaction để tránh webhook đồng thời.
- Sau fulfillment thành công, kiểm tra thành viên ngay trước transaction hoàn tất đơn; lưu kết quả và thời điểm. Không gọi Telegram trong transaction giữ khóa.
- Bot phải có quyền quản trị kênh. Kiểm tra phản ánh trạng thái gần thời điểm hoàn tất, có khoảng trễ trước khi ghi database.
- Telegram lỗi: vẫn giao hàng, giữ thưởng chờ xác minh; không tự dùng kiểm tra muộn để suy ra trạng thái đơn cũ.
- Migration mới bổ sung mã mời, referral, kết quả xét theo đơn, giao dịch thưởng và bộ đếm hạn mức. Cập nhật schema cài mới tương ứng.
- Chốt số tiền thưởng lúc ghi nhận referral; thay đổi cấu hình chỉ áp dụng cho referral mới.
- Ràng buộc duy nhất và khóa database bảo đảm một referral chỉ trả một lần.
- Cộng ví, ghi số dư trước/sau, giao dịch `referral_reward`, trạng thái và notification outbox trong cùng transaction.
- Hết hạn mức thì giữ chờ; worker CLI chạy mỗi phút thử trả ngày sau theo bằng chứng đủ điều kiện đã lưu. Ngày hạn mức theo `Asia/Bangkok`.
- Tắt chương trình dừng ghi nhận/xét mới và bỏ chặn menu; khoản đã đủ điều kiện vẫn được trả theo hạn mức. Bật lại tiếp tục xét referral cũ.
- Không đổi kênh khi còn referral chưa kết thúc. V1 không bổ sung hoàn tiền cho đơn đã giao.

## 5. Kiểm thử và nghiệm thu

- User mới/cũ, link sai, tự mời, nhiều link và webhook đồng thời.
- Đơn giá thấp vẫn tạo thưởng; không còn kiểm tra ngưỡng đơn.
- Đủ ba cách thanh toán; đơn thất bại, hủy hoặc nạp ví không tạo thưởng.
- Điều kiện kênh, callback cũ, rời/vào lại, Telegram lỗi và thiếu quyền.
- Hai đơn hoàn tất đồng thời, retry, hạn mức và đổi ngày không cộng trùng.
- Số dư, lịch sử giao dịch, thông báo, hướng dẫn VI/EN và link chia sẻ.
- Kiểm tra trực quan Admin desktop/mobile, control, overflow, focus, disabled và cache-busting.

Triển khai: sao lưu → migration mới → kiểm thử MySQL/Telegram → cài cron → nhập cấu hình → bật ngân sách nhỏ.

Nghiệm thu: mỗi bạn mới tạo tối đa một thưởng sau đơn giao thành công và đủ điều kiện kênh, không vượt hạn mức hoặc ảnh hưởng giao hàng. Không import lại `install.sql` hay ghi đè thay đổi ngoài nhiệm vụ.
