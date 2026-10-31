# Kế hoạch khắc phục lỗi duy trì phiên đăng nhập Admin (admin_session_days)

## 1. Nguyên nhân phân tích được

Qua việc rà soát code tại [`admin/index.php`](admin/index.php) và kiến trúc hosting cPanel/Linux, việc set `'admin_session_days' => 14` trong [`config/app.php`](config/app.php:19) vẫn bị văng phiên sau một thời gian ngắn do các nguyên nhân sau:

1. **Cơ chế Garbage Collection (GC) của hệ điều hành / cPanel dọn dẹp file session:**
   - [`admin/index.php`](admin/index.php:10) chỉ gọi `ini_set('session.gc_maxlifetime', (string) $adminSessionLifetime)` mà **không cấu hình thư mục lưu session riêng (`session_save_path()`)**.
   - Session được lưu vào thư mục chia sẻ mặc định của hệ thống (`/tmp` hoặc `/var/cpanel/php/sessions/...`).
   - cPanel và các bản phân phối Linux có tiến trình dọn dẹp hệ thống (cronjob `clean_user_php_sessions` hoặc systemd timer) chạy mỗi 15–30 phút. Tiến trình này đọc `session.gc_maxlifetime` từ `php.ini` gốc của hệ thống (mặc định là **1440 giây = 24 phút**) và tự động xóa mọi file session cũ hơn 24 phút.
   - Bất kỳ website/script nào khác trên cùng server kích hoạt PHP GC cũng sẽ xóa các session cũ hơn 24 phút trong `/tmp`.
   - Kết quả: Khi bạn để máy hoặc tắt máy quá ~24 phút, file session trên máy chủ đã bị hệ điều hành xóa bỏ. Khi truy cập lại, server không tìm thấy session id tương ứng nên coi như chưa đăng nhập.

2. **Cookie session không được gia hạn trượt (Sliding Expiration):**
   - [`session_set_cookie_params()`](admin/index.php:15) đặt lifetime cho cookie lần đầu khởi tạo. Sau đó, khi người dùng tiếp tục thao tác hoặc quay lại, PHP mặc định **không** tự động gửi lại header `Set-Cookie` để gia hạn thêm 14 ngày tính từ thời điểm truy cập mới nhất.

3. **Tên Cookie mặc định (`PHPSESSID`) dễ xung đột:**
   - Dùng tên mặc định có thể bị xung đột hoặc ghi đè bởi các ứng dụng hoặc session khác chạy trên cùng domain/subdomain/localhost.

4. **Lưu ý cấu hình triển khai cPanel ([`.cpanel.yml`](.cpanel.yml:11)):**
   - File [`.cpanel.yml`](.cpanel.yml:11) có tham số loại trừ `--exclude='config/app.php'`, nên nếu bạn mới thêm cấu hình ở local mà chưa sửa trực tiếp trên cPanel, hosting sẽ vẫn dùng file `config/app.php` cũ (dù code có fallback `?? 14`).

---

## 2. Giải pháp kỹ thuật chi tiết

### Bước 1: Lưu trữ Session trong thư mục riêng của dự án
- Tạo thư mục riêng cho session: `storage/sessions` nằm ngoài tầm quét của cron cPanel/hệ thống.
- Thiết lập quyền và file bảo vệ chặn truy cập trực tiếp từ web qua HTTP (`.htaccess` hoặc `storage/.htaccess` với `Require all denied`).
- Thêm đường dẫn `storage/sessions` vào [`admin/index.php`](admin/index.php:8) thông qua `session_save_path($sessionDir)`.

### Bước 2: Tách biệt tên Cookie Admin Console
- Đặt `session_name('taos_admin_session')` trước khi `session_start()` để cô lập cookie, tránh mọi xung đột với `PHPSESSID` của các ứng dụng khác.

### Bước 3: Cơ chế gia hạn trượt (Sliding Expiration) và kiểm tra quá hạn
- Sau khi `session_start()`, nếu admin đã đăng nhập thành công:
  - Kiểm tra xem thời gian từ lần cuối truy cập (`$_SESSION['admin_last_seen']`) có vượt quá `$adminSessionLifetime` hay không. Nếu vượt quá, chủ động hủy session.
  - Cập nhật `$_SESSION['admin_last_seen'] = time()`.
  - Gửi lại cookie phiên với hạn mới (`Expires = time() + $adminSessionLifetime`) để liên tục duy trì 14 ngày kể từ lần tương tác gần nhất của admin.

### Bước 4: Bảo vệ thư mục lưu trữ và cấu hình deploy
- Cập nhật [`.htaccess`](.htaccess:11) ở thư mục gốc để chặn truy cập web vào thư mục `storage/`.
- Cập nhật [`.gitignore`](.gitignore) để bỏ qua các file session phát sinh trong `storage/sessions/`.
- Cập nhật [`.cpanel.yml`](.cpanel.yml) (nếu cần) để tránh việc rsync xoá nhầm thư mục `storage/sessions/`.

---

## 3. Danh sách công việc triển khai (Actionable Todos)
- [ ] 1. Cấu hình thư mục lưu session `storage/sessions` và bảo vệ trong `.htaccess`
- [ ] 2. Cập nhật `session_save_path()`, `session_name()` và thiết lập thời hạn phiên trong `admin/index.php`
- [ ] 3. Bổ sung cơ chế Sliding Expiration và kiểm tra timeout theo `_SESSION['admin_last_seen']` trong `admin/index.php` và `admin/auth.php`
- [ ] 4. Thêm `storage/sessions/` vào `.gitignore` và điều chỉnh `.cpanel.yml`
- [ ] 5. Kiểm tra hoạt động của phiên đăng nhập và tạo/duy trì session file
