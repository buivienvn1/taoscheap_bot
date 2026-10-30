<?php

declare(strict_types=1);

final class Language
{
    private array $messages;
    private string $code;

    public function __construct(string $code = 'vi')
    {
        $this->code = in_array($code, ['vi', 'en'], true) ? $code : 'vi';
        $this->messages = require __DIR__ . '/../languages/' . $this->code . '.php';
    }

    public function code(): string
    {
        return $this->code;
    }

    public function get(string $key, array $replace = []): string
    {
        $message = $this->messages[$key] ?? $key;
        $placeholders = [];

        foreach ($replace as $name => $value) {
            $placeholders[':' . $name] = (string) $value;
        }

        return strtr($message, $placeholders);
    }
}
