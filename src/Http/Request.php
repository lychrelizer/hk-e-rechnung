<?php

declare(strict_types=1);

namespace App\Http;

final class Request
{
    /** @var string */
    public $method;
    /** @var string */
    public $path;
    /** @var array<string,mixed> */
    public $query;
    /** @var array<string,mixed> */
    public $post;
    /** @var array<string,mixed> */
    public $files;
    /** @var array<string,string> */
    public $headers;
    /** @var string */
    public $body;

    /**
     * @param array<string,mixed>  $query
     * @param array<string,mixed>  $post
     * @param array<string,mixed>  $files
     * @param array<string,string> $headers
     */
    public function __construct(
        string $method,
        string $path,
        array $query,
        array $post,
        array $files,
        array $headers,
        string $body
    ) {
        $this->method = $method;
        $this->path = $path;
        $this->query = $query;
        $this->post = $post;
        $this->files = $files;
        $this->headers = $headers;
        $this->body = $body;
    }

    public static function fromGlobals(): self
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = (string) parse_url($uri, PHP_URL_PATH);
        $path = '/' . trim($path, '/');

        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (strpos($k, 'HTTP_') === 0) {
                $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = (string) $v;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }

        return new self(
            strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            $path,
            $_GET,
            $_POST,
            $_FILES,
            $headers,
            (string) file_get_contents('php://input')
        );
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function isJson(): bool
    {
        return strpos((string) $this->header('content-type'), 'json') !== false;
    }

    public function wantsJson(): bool
    {
        return $this->isJson()
            || strpos((string) $this->header('accept'), 'application/json') !== false
            || strpos($this->path, '/api/') === 0;
    }
}
