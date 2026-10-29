# TAOS Shop Bot

Bot bán sản phẩm số dùng PHP 8.1+, MySQL, Telegram Webhook và Web Admin. Không cần Composer hoặc Node.js.

## Yêu cầu

- Hosting cPanel có PHP 8.1/8.2, MySQL, HTTPS.
- PHP extensions: PDO MySQL, cURL, JSON, mbstring.
- Telegram Bot Token từ BotFather.
- Tài khoản VietQR và webhook biến động số dư (mặc định payload SePay).

## Cài đặt trên cPanel

1. Tạo database và user MySQL trong cPanel.
2. Mở phpMyAdmin, chọn database rồi import `install.sql`.
3. Đưa mã nguồn lên thư mục domain có HTTPS (xem mục bên dưới để thiết lập tự động hóa qua Git/GitHub thay vì upload thủ công).
4. Sửa `config/database.php` theo database cPanel.
5. Sửa `config/app.php`:
   - `app_url`: URL không có dấu `/` cuối.
   - `bot_token`: Telegram Bot Token.
   - `telegram_secret`: chuỗi bí mật dài, ngẫu nhiên.
   - `payment_secret`: secret dùng xác thực webhook thanh toán.
   - `admin_password_hash`: password hash của quản trị viên.
6. Sửa `config/bank.php` theo tài khoản nhận tiền.
7. Mở `/admin/`, đăng nhập và bấm **Kích hoạt Webhook** trong Cài đặt.

## Triển khai tự động lên cPanel (CI/CD Không Cần Copy Thủ Công)

Hệ thống hỗ trợ 2 giải pháp tự động hóa khi bạn push code lên GitHub:

### Cách 1: Dùng cPanel Git™ Version Control (Khuyên Dùng)
1. Trong cPanel, tìm mục **Git™ Version Control** -> Nhấn **Create**.
2. Nhập URL repository: `https://github.com/buivienvn1/taoscheap_bot.git`.
3. Nhập đường dẫn thư mục lưu repository, ví dụ `/home/qthangne/taoscheap_bot`. Đây chỉ là nơi chứa bản clone Git, không phải Document Root của website.
4. File `.cpanel.yml` sẽ tự động sao chép code sang Document Root của subdomain: `/home/qthangne/taoscheap.qthang.net`.
5. Vào tab **Manage** của repository trong cPanel -> chọn tab **Deploy HEAD Commit** để copy **Webhook URL**.
6. Vào GitHub repository -> **Settings** -> **Webhooks** -> **Add webhook**:
   - **Payload URL**: Dán URL Webhook của cPanel.
   - **Content type**: `application/json`.
   - **Events**: Just the `push` event.
7. **Xong**: Mỗi lần push code lên GitHub, cPanel sẽ tự động cập nhật code mới nhất mà không ảnh hưởng tới các file cấu hình nhạy cảm.

### Cách 2: Dùng GitHub Actions qua FTP
Nếu cPanel không bật Git Version Control:
1. Thêm 3 Secrets trong GitHub repository (**Settings** -> **Secrets and variables** -> **Actions**):
   - `FTP_SERVER`: Địa chỉ host FTP (ví dụ: `ftp.domain.com` hoặc IP host).
   - `FTP_USERNAME`: Tài khoản FTP cPanel.
   - `FTP_PASSWORD`: Mật khẩu tài khoản FTP.
2. File workflow `.github/workflows/deploy.yml` sẽ tự động chạy mỗi khi push code lên nhánh `main`, đẩy các file cập nhật sang cPanel và tự động giữ nguyên các file config trên host.

## Tạo mật khẩu quản trị

Trong cPanel Terminal chạy:

```bash
php -r "echo password_hash('MAT_KHAU_CUA_BAN', PASSWORD_DEFAULT), PHP_EOL;"
```

Sao chép kết quả vào `admin_password_hash` trong `config/app.php`.

## Webhook thanh toán SePay

- URL: `https://ten-mien-cua-ban.com/payment_webhook.php`
- Authentication: header `Authorization: Bearer PAYMENT_SECRET`
- `PAYMENT_SECRET` phải giống `payment_secret` trong `config/app.php`.
- Webhook có chống xử lý trùng bằng ID giao dịch của nhà cung cấp.

Hệ thống nhận hai loại thanh toán:

1. **Nạp ví**: Nội dung `Donate` + 6 ký tự chữ/số. Mỗi khách giữ một mã chờ đến khi dùng thành công. Số tiền tối thiểu 2.000đ.
2. **Mua trực tiếp**: QR có đúng số tiền đơn. Nếu sản phẩm hết sau khi khách thanh toán, tiền tự động được cộng vào ví.

## Vận hành

1. Vào **Sản phẩm**, thêm sản phẩm và các gói giá.
2. Chọn nguồn `manual` để dùng kho nội bộ.
3. Vào **Kho hàng**, chọn gói rồi dán key/tài khoản; mỗi dòng là một sản phẩm.
4. Theo dõi đơn, tiền nạp và số dư khách trong Admin.

Nguồn API đã có interface và lớp nền trong `suppliers/`. Mỗi nhà cung cấp cần một driver riêng sau khi có API docs.

## Cập nhật an toàn

- Không import lại `install.sql` khi nâng cấp.
- Sao lưu database trước mỗi lần cập nhật.
- Chỉ chạy file migration mới trong `migrations/` theo thứ tự số.
- Không sửa migration đã chạy và không dùng `DROP TABLE`.

## Bảo mật

- Không commit hoặc chia sẻ các file trong `config/` sau khi điền secret.
- Dùng HTTPS bắt buộc.
- Đặt secret dài, khác nhau cho Telegram và payment webhook.
- Nên đổi tên đường dẫn Admin hoặc bật cPanel Directory Privacy nếu cần thêm lớp bảo vệ.
