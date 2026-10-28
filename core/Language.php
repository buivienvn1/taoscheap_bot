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

        foreach ($replace as $name => $value) {
            $message = str_replace(':' . $name, (string) $value, $message);
        }

        return $message;
    }
}
