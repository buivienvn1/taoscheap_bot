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
        $text = preg_replace('/<br\s*\/?>/iu', "\n", $text ?? '') ?? '';
        $parts = preg_split('/(<\s*\/?\s*[a-z][^>]*>)/iu', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $result = '';

        foreach ($parts as $part) {
            if ($part === '') continue;
            if ($part[0] !== '<') {
                $decoded = html_entity_decode($part, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $result .= htmlspecialchars($decoded, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
                continue;
            }

            if (preg_match('/^<\s*\/\s*(b|strong|i|em|u|ins|s|strike|del|tg-spoiler|code|pre|blockquote)\s*>$/iu', $part, $match)) {
                $result .= '</' . strtolower($match[1]) . '>';
                continue;
            }
            if (preg_match('/^<\s*(b|strong|i|em|u|ins|s|strike|del|tg-spoiler|code|pre|blockquote)\s*>$/iu', $part, $match)) {
                $result .= '<' . strtolower($match[1]) . '>';
                continue;
            }
            if (preg_match('/^<\s*\/\s*a\s*>$/iu', $part)) {
                $result .= '</a>';
                continue;
            }
            if (preg_match('/^<\s*a\s+href\s*=\s*(["\'])(.*?)\1\s*>$/iu', $part, $match)) {
                $url = html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if (preg_match('/^(?:https?:\/\/|tg:\/\/)/i', $url)) {
                    $result .= '<a href="' . htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">';
                }
            }
        }

        return trim($result);
    }

    public static function extractMultilingualText(?string $text, string $language): string
    {
        $text = trim($text ?? '');
        if ($text === '') return '';

        $blocks = ['vi' => [], 'en' => []];
        $hasLanguageTags = false;
        foreach (array_keys($blocks) as $code) {
            if (preg_match_all('/<' . $code . '\s*>(.*?)<\/\s*' . $code . '\s*>/isu', $text, $matches)) {
                $hasLanguageTags = true;
                $blocks[$code] = array_values(array_filter(
                    array_map(static fn (string $value): string => trim($value), $matches[1]),
                    static fn (string $value): bool => $value !== ''
                ));
            }
        }

        if (!$hasLanguageTags) return $text;

        $language = strtolower($language) === 'en' ? 'en' : 'vi';
        if ($blocks[$language] !== []) return implode("\n", $blocks[$language]);

        $fallback = $language === 'vi' ? 'en' : 'vi';
        return implode("\n", $blocks[$fallback]);
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

    public static function adminTelegramIds(array $app): array
    {
        $ids = [];
        foreach (($app['admin_telegram_ids'] ?? []) as $value) {
            $raw = trim((string) $value);
            if (!preg_match('/^-?[1-9]\d*$/', $raw)) continue;
            $id = (int) $raw;
            if ($id !== 0) $ids[(string) $id] = $id;
        }
        return array_values($ids);
    }

    public static function dateTime(?string $value): string
    {
        if ($value === null || trim($value) === '') return '';
        try {
            return (new DateTimeImmutable($value))->format('d/m/Y H:i');
        } catch (Throwable) {
            return '';
        }
    }
}
