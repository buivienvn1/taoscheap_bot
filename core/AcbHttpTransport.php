<?php

declare(strict_types=1);

interface AcbHttpTransport
{
    /**
     * @param list<string> $headers
     * @return array{status:int,body:string}
     */
    public function send(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        int $connectTimeout,
        int $requestTimeout,
    ): array;
}
