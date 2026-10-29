<?php

declare(strict_types=1);

require_once __DIR__ . '/../core/Helpers.php';

$assertSame = static function (string $expected, string $actual, string $case): void {
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$case}\nExpected: {$expected}\nActual:   {$actual}\n");
        exit(1);
    }
    echo "PASS: {$case}\n";
};

$input = <<<'TEXT'
<en>
- Open the link and click the "Activate Plan" button.
- No payment method or card required.
</en>
<vi>
- Mở liên kết và nhấn nút "Activate Plan".
- Không cần phương thức thanh toán hoặc thẻ ngân hàng.
</vi>
TEXT;

$assertSame(
    "- Mở liên kết và nhấn nút \"Activate Plan\".\n- Không cần phương thức thanh toán hoặc thẻ ngân hàng.",
    Helpers::extractMultilingualText($input, 'vi'),
    'Chọn nội dung tiếng Việt từ mẫu quản trị'
);
$assertSame(
    "- Open the link and click the \"Activate Plan\" button.\n- No payment method or card required.",
    Helpers::extractMultilingualText($input, 'en'),
    'Chọn nội dung tiếng Anh từ mẫu quản trị'
);
$assertSame(
    'English fallback',
    Helpers::extractMultilingualText('<en>English fallback</en>', 'vi'),
    'Fallback sang ngôn ngữ còn lại'
);
$assertSame(
    'Nội dung sản phẩm cũ',
    Helpers::extractMultilingualText('Nội dung sản phẩm cũ', 'en'),
    'Tương thích nội dung cũ không có thẻ ngôn ngữ'
);
$assertSame(
    '<b>Đậm</b> và <i>nghiêng</i>' . "\n" . '<blockquote>Trích dẫn</blockquote>',
    Helpers::sanitizeTelegramHtml('<b>Đậm</b> và <i>nghiêng</i><br><blockquote>Trích dẫn</blockquote>'),
    'Giữ Telegram HTML và chuyển br thành xuống dòng'
);
$assertSame(
    '5 ' . chr(38) . 'lt; 10 ' . chr(38) . 'amp; 10 ' . chr(38) . 'gt; 5',
    Helpers::sanitizeTelegramHtml('5 < 10 & 10 > 5'),
    'Escape ký tự đặc biệt trong văn bản thường'
);
$assertSame(
    '<a href="https://example.com/?a=1' . chr(38) . 'amp;b=2">Liên kết</a> nguy hiểm',
    Helpers::sanitizeTelegramHtml('<a href="https://example.com/?a=1&b=2">Liên kết</a> <script>nguy hiểm</script>'),
    'Giữ liên kết an toàn và loại bỏ thẻ không hợp lệ'
);

echo "All multilingual description tests passed.\n";
