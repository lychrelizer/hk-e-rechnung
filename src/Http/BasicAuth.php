<?php

declare(strict_types=1);

namespace App\Http;

/**
 * HTTP Basic Auth für die JSON-API. Zugangsdaten kommen aus .env (API_USER / API_PASSWORD).
 * API_PASSWORD darf ein Klartext-Passwort oder ein password_hash()-Hash sein.
 */
final class BasicAuth
{
    /** @var string */
    private $user;
    /** @var string */
    private $password;

    public function __construct(string $user, string $password)
    {
        $this->user = $user;
        $this->password = $password;
    }

    public function check(Request $request): bool
    {
        if ($this->user === '' || $this->password === '') {
            return false; // nicht konfiguriert -> alles abweisen
        }

        [$user, $pass] = $this->credentials($request);
        if ($user === null || $pass === null) {
            return false;
        }

        $userOk = hash_equals($this->user, $user);
        $algo = password_get_info($this->password)['algo'];
        // PHP <7.4 liefert 0 fuer einen nicht erkannten Hash, PHP >=7.4 liefert null.
        $passOk = $algo
            ? password_verify($pass, $this->password)
            : hash_equals($this->password, $pass);

        return $userOk && $passOk;
    }

    public function challenge(): Response
    {
        return new Response(
            (string) json_encode(['error' => 'Unauthorized', 'message' => 'HTTP Basic Auth erforderlich.']),
            401,
            [
                'Content-Type' => 'application/json; charset=utf-8',
                'WWW-Authenticate' => 'Basic realm="E-Rechnung API", charset="UTF-8"',
            ]
        );
    }

    /** @return array{0: ?string, 1: ?string} */
    private function credentials(Request $request): array
    {
        // PHP-FPM / Apache mit mod_php
        if (isset($_SERVER['PHP_AUTH_USER'])) {
            return [(string) $_SERVER['PHP_AUTH_USER'], (string) ($_SERVER['PHP_AUTH_PW'] ?? '')];
        }
        // CGI/FastCGI: Header muss ggf. per RewriteRule durchgereicht werden
        $header = $request->header('authorization') ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null);
        if ($header === null || !preg_match('/^Basic\s+(.+)$/i', trim((string) $header), $m)) {
            return [null, null];
        }
        $decoded = base64_decode($m[1], true);
        if ($decoded === false || strpos($decoded, ':') === false) {
            return [null, null];
        }
        [$u, $p] = explode(':', $decoded, 2);

        return [$u, $p];
    }
}
