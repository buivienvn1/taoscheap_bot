<?php

declare(strict_types=1);

final class Language
{
    private array $messages;

    public function __construct(string $code = 'vi')
    {
        $safeCode = in_array($code, ['vi', 'en'], true) ? $code : 'vi';
        $this->messages = require __DIR__ . '/../languages/' . $safeCode . '.php';
    }

    public function get(string $key, array $replace = []): string
    {
        $message = $this->messages[$key] ?? $key;

        foreach ($replace as $name => $value) {
            $message = str_replace(':' . $name, (string) $value, $message);
        }

        return $message;
    }
}
