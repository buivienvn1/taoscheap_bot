# Kế hoạch rà soát & Hoàn thiện hệ thống Thông báo Admin (Biến động số dư & Giao hàng)

## 1. Khảo sát hiện trạng & Nguyên nhân vấn đề

### 1.1 Vấn đề đơn hàng kết thúc nhưng admin không nhận được thông báo
- **Ảnh chụp thực tế của User**:
  - Khách nhận được thông báo: *"Đơn OD26103116293823 đã kết thúc. Khoản chuyển mới 3.900 VND đã được cộng vào ví."*
- **Nguyên nhân kỹ thuật**:
  - Đơn hàng thanh toán qua VietQR có hạn thanh toán (`expires_at`, thường là 15 phút).
  - Khi ngân hàng ACB bị trễ feed giao dịch hoặc khách hàng chuyển khoản muộn, cron [`PaymentProcessor::expireDirectOrders()`](core/PaymentProcessor.php:139) hoặc [`PaymentProcessor::expireHybridOrders()`](core/PaymentProcessor.php:90) đã chạy trước và cập nhật trạng thái đơn thành `cancelled` (`result_reason = 'expired'`).
  - Khi cron [`cron/payment_feed.php`](cron/payment_feed.php) quét được giao dịch ngân hàng về sau đó, nó chuyển vào [`PaymentProcessor::processOrder()`](core/PaymentProcessor.php:273):
    ```php
    if ($order['status'] !== 'pending') {
        $this->creditWallet($order, $amount, 'closed_order_payment');
        $outboxId = $this->outbox->enqueue((int) $order['user_id'], 'payment-code:' . $code, 'order_result_closed', $params);
        return $this->result('closed_order_wallet', true, $orderId, null, '0.00', $amount, $outboxId);
    }
    ```
  - **Lỗ hổng**: Hệ thống chỉ gửi thông báo Telegram cho khách hàng (`$this->outbox->enqueue((int) $order['user_id'], ...)`), **hoàn toàn bỏ quên không gọi `$this->outbox->enqueueAdmin(...)`**.
  - Do đó: Tiền thật đã vào tài khoản ACB của Admin, khách được cộng tiền vào ví, nhưng Admin không có bất kỳ thông tin nào để theo dõi.

---

## 2. Bảng tổng hợp rà soát toàn bộ các giao dịch Biến động số dư (`transactions` / `users.balance`)

| STT | Luồng phát sinh biến động số dư | Mã sự kiện / Ghi chú | Đang gửi User? | Đang gửi Admin Telegram? | Mức độ & Tình trạng |
|---|---|---|---|---|---|
| 1 | **Tiền về đơn đã kết thúc/hủy/hết hạn** | `closed_order_payment` | ✅ Có (`order_result_closed`) | ❌ **KHÔNG** | 🔴 **Rất nghiêm trọng**: Tiền thật về bank, cộng ví khách, Admin không hay biết |
| 2 | **Khách chuyển thiếu tiền đơn hàng** | `short_payment` | ✅ Có (`order_result_short_payment`) | ❌ **KHÔNG** | 🔴 **Nghiêm trọng**: Tiền thật về bank, hoàn vào ví khách, Admin không biết |
| 3 | **Đơn bank thanh toán thành công nhưng hết hàng** | `out_of_stock` | ✅ Có (`order_result_out_of_stock`) | ❌ **KHÔNG** | 🔴 **Nghiêm trọng**: Tiền thật về bank, hệ thống hoàn ví khách, Admin không nhận được cảnh báo hết hàng |
| 4 | **Đơn bank thanh toán nhưng sản phẩm ngừng bán** | `product_unavailable` | ✅ Có (`order_result_unavailable`) | ❌ **KHÔNG** | 🔴 **Nghiêm trọng**: Tiền thật về bank, hoàn ví khách, Admin không biết |
| 5 | **Khách chuyển thừa tiền đơn hàng** | `overpayment` | ✅ Có (dòng extra) | ⚠️ **Thiếu chi tiết**: Admin có nhận tin đơn thành công nhưng không có dòng ghi rõ tiền thừa đã cộng ví | 🟡 **Cần cải thiện** |
| 6 | **Mua bằng ví nhưng lỗi kho/NCC** | `fulfillment_failed` | ✅ Có (`just_out_of_stock`) | ❌ **KHÔNG** | 🟡 **Cần bổ sung**: Đơn ví fail, hoàn lại ví, Admin cần biết để kiểm tra nhà cung cấp |
| 7 | **Đơn Hybrid hết hạn (hoàn cọc ví)** | `hybrid_expired` | ✅ Có (`order_expired_wallet_refunded`) | ❌ Không | ⚪ Hoàn cọc nội bộ (không có tiền bank mới) |
| 8 | **Khách tự bấm [❌ Hủy đơn] Hybrid** | `hybrid_cancelled` | ✅ Có | ❌ Không | ⚪ Hoàn cọc nội bộ do khách chủ động hủy |
| 9 | **Thưởng hoa hồng giới thiệu đơn hàng** | `referral_purchase_reward` | ✅ Có (`referral_reward_received`) | ❌ **KHÔNG** | 🟡 **Cần bổ sung**: Admin nên biết khi hệ thống chi trả tiền hoa hồng cho ai |
| 10 | **Nạp tiền ví tự động qua VietQR/ACB** | `deposit_completed` | ✅ Có (`deposit_success_detailed`) | ✅ **CÓ** (`admin_deposit_success`) | 🟢 **Đã có đầy đủ** |
| 11 | **Admin duyệt nạp tiền thủ công trên Web** | `deposit_completed` | ✅ Có | ✅ **CÓ** (`admin_deposit_success`) | 🟢 **Đã có đầy đủ** |
| 12 | **Admin điều chỉnh số dư khách trên Web** | `adjustment` | ❌ Không | ❌ Không | ⚪ Do Admin trực tiếp thực hiện trên Web |

---

## 3. Rà soát các luồng Giao hàng thành công (Fulfillment)

| STT | Luồng giao hàng thành công | Đang gửi User? | Đang gửi Admin Telegram? | Nhận định |
|---|---|---|---|---|
| 1 | **Mua bằng ví thành công (Kho nội bộ hoặc API)** | ✅ Có | ✅ **CÓ** ([`OrderHandler::enqueueWalletOrderAdmins()`](bot/handlers/OrderHandler.php:256)) | Hoạt động chuẩn xác. |
| 2 | **Mua qua VietQR/Direct thành công** | ✅ Có | ✅ **CÓ** ([`PaymentProcessor::enqueueOrderAdmins()`](core/PaymentProcessor.php:344)) | Hoạt động chuẩn xác. |
| 3 | **Trường hợp API nhà cung cấp trả về `pending`** (`supplier_fulfillment_pending`) | ⚠️ Chờ xử lý | ❌ **KHÔNG** | 🔴 **Lưu ý rủi ro kiến trúc**: Hiện tại nếu NCC trả về pending, đơn vẫn ở `processing`. Hệ thống chưa có cron tự động thăm dò để chuyển sang completed. Tuy nhiên các nhà cung cấp hiện tại (như TunVnPreHub) chủ yếu là tức thời. |

---

## 4. Giải pháp triển khai & Thiết kế chi tiết

### 4.1 Bổ sung thông báo Admin khi đơn đóng nhận được tiền (`closed_order_payment`)
- **Vị trí**: [`core/PaymentProcessor.php:273`](core/PaymentProcessor.php:273)
- **Hành động**: Gọi `$this->outbox->enqueueAdmin(...)` gửi tới tất cả Admin với template mới `admin_order_closed_payment`:
  ```
  ⚠️ TIỀN VÀO ĐƠN ĐÃ ĐÓNG · #:code

  Khách hàng: :customer
  Telegram ID: :telegram_id
  Số tiền chuyển: :amount (Đã cộng vào ví)
  Số dư ví sau cộng: :balance
  Mã chuyển khoản: :payment_code
  Trạng thái đơn: :order_status (:order_reason)

  Thời gian: :time
  ```

### 4.2 Bổ sung thông báo Admin khi đơn gặp sự cố hoàn tiền vào ví (`refundOrder`)
- **Vị trí**: [`core/PaymentProcessor.php:334`](core/PaymentProcessor.php:334) trong hàm `refundOrder()`:
  - Bổ sung thông báo Admin với template `admin_order_refund_notice`:
    ```
    ⚠️ ĐƠN HÀNG HOÀN VÍ · #:code

    Lý do: :reason_text
    Sản phẩm: :product × :quantity
    Khách hàng: :customer · :telegram_id
    Số tiền đã cộng vào ví: :amount
    Mã chuyển khoản: :payment_code

    Thời gian: :time
    ```
  - Trong đó `:reason_text` được dịch rõ ràng:
    - `short_payment`: Chuyển thiếu tiền đơn hàng
    - `out_of_stock`: Hết hàng trong kho/NCC
    - `product_unavailable`: Sản phẩm ngừng kinh doanh

### 4.3 Cải thiện thông báo Admin khi chuyển thừa tiền (`overpayment`)
- **Vị trí**: [`core/PaymentProcessor.php:344`](core/PaymentProcessor.php:344) `enqueueOrderAdmins()`:
  - Khi đơn hàng có chuyển thừa (`(float) $extra > 0`), hiển thị thêm dòng:
    `Tiền thừa cộng ví: +:extra`

### 4.4 Bổ sung thông báo Admin khi đơn ví bị lỗi fulfillment (`fulfillment_failed`)
- **Vị trí**: [`bot/handlers/OrderHandler.php:218`](bot/handlers/OrderHandler.php:218)
- Gửi thông báo Admin báo đơn ví không giao được và đã hoàn tiền ví cho khách để Admin kiểm tra kho.

### 4.5 Bổ sung thông báo Admin khi chi trả hoa hồng giới thiệu
- **Vị trí**: [`core/ReferralService.php:179`](core/ReferralService.php:179)
- Gửi thông báo Admin `admin_referral_reward_paid`:
  ```
  🎁 CHI TRẢ HOA HỒNG GIỚI THIỆU · #:code

  Người nhận: :customer · :telegram_id
  Số tiền thưởng: :reward
  Số dư ví sau nhận: :balance
  Đơn hàng phát sinh: #:order_code
  Thời gian: :time
  ```

### 4.6 Đa ngôn ngữ (i18n)
- Khai báo đầy đủ các key trên trong [`languages/vi.php`](languages/vi.php) và [`languages/en.php`](languages/en.php).
- Tuân thủ quy chuẩn `Helpers::escape()` cho dữ liệu động đưa vào Telegram HTML.
