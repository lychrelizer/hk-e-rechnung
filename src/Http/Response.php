<?php

declare(strict_types=1);

namespace App\Http;

final class Response
{
    /** @var string */
    public $body;
    /** @var int */
    public $status;
    /** @var array<string,string> */
    public $headers;

    /** @param array<string,string> $headers */
    public function __construct(string $body = '', int $status = 200, array $headers = ['Content-Type' => 'text/html; charset=utf-8'])
    {
        $this->body = $body;
        $this->status = $status;
        $this->headers = $headers;
    }

    /** @param array<string,mixed> $data */
    public static function json(array $data, int $status = 200): self
    {
        return new self(
            (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            $status,
            ['Content-Type' => 'application/json; charset=utf-8']
        );
    }

    public static function html(string $html, int $status = 200): self
    {
        return new self($html, $status);
    }

    public static function file(string $binary, string $filename, string $mime): self
    {
        return new self($binary, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'attachment; filename="' . addcslashes($filename, '"\\') . '"',
            'Content-Length' => (string) strlen($binary),
        ]);
    }

    public static function redirect(string $to): self
    {
        return new self('', 302, ['Location' => $to]);
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $k => $v) {
            header("$k: $v");
        }
        echo $this->body;
    }
}
