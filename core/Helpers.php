<?php

declare(strict_types=1);

final class Helpers
{
    public static function money(float|int|string $amount): string
    {
        return number_format((float) $amount, 0, ',', '.') . 'đ';
    }

    public static function escape(?string $text): string
    {
        return htmlspecialchars($text ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function sanitizeTelegramHtml(?string $text): string
    {
        $allowedTags = '<b><strong><i><em><u><ins><s><strike><del><tg-spoiler><a><code><pre><blockquote>';
        return strip_tags($text ?? '', $allowedTags);
    }

    public static function randomCode(string $prefix = 'DONATE', int $length = 6): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $value = '';
        $max = strlen($alphabet) - 1;

        for ($index = 0; $index < $length; $index++) {
            $value .= $alphabet[random_int(0, $max)];
        }

        return $prefix . $value;
    }

    public static function orderCode(): string
    {
        return 'OD' . date('ymdHis') . random_int(10, 99);
    }

    public static function normalizePaymentContent(string $content): string
    {
        return strtoupper(preg_replace('/[^A-Z0-9]/i', '', $content) ?? '');
    }
}
