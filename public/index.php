<?php

declare(strict_types=1);

use App\Controller\ApiController;
use App\Controller\WebController;
use App\Http\BasicAuth;
use App\Http\Request;
use App\Http\Response;
use App\Invoice\Exception\ValidationException;
use App\Service\ZugferdService;
use App\Support\Env;

require dirname(__DIR__) . '/vendor/autoload.php';

Env::load(dirname(__DIR__) . '/.env');

$debug = Env::bool('APP_DEBUG', false);
error_reporting(E_ALL);
ini_set('display_errors', $debug ? '1' : '0');

$request = Request::fromGlobals();
$zugferd = new ZugferdService(Env::get('CREATOR_TOOL', 'E-Rechnung Converter'));
$auth = new BasicAuth((string) Env::get('API_USER', ''), (string) Env::get('API_PASSWORD', ''));

try {
    $response = route($request, $zugferd, $auth);
} catch (ValidationException $e) {
    $response = Response::json(['error' => $e->getMessage(), 'errors' => $e->getErrors()], 422);
} catch (Throwable $e) {
    error_log(get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    $status = $e->getCode() >= 400 && $e->getCode() < 600 ? (int) $e->getCode() : 500;
    $payload = ['error' => $status === 500 && !$debug ? 'Interner Fehler' : $e->getMessage()];
    if ($debug) {
        $payload['exception'] = get_class($e);
        $payload['trace'] = explode("\n", $e->getTraceAsString());
    }
    $response = $request->wantsJson()
        ? Response::json($payload, $status)
        : Response::html('<h1>Fehler ' . $status . '</h1><p>' . htmlspecialchars($payload['error']) . '</p>', $status);
}

$response->send();

function route(Request $request, ZugferdService $zugferd, BasicAuth $auth): Response
{
    $path = $request->path;
    $method = $request->method;

    // ---- JSON-API (Basic Auth) ----
    if (strpos($path, '/api/') === 0) {
        $api = new ApiController($zugferd);

        if ($path === '/api/v1/health' && $method === 'GET') {
            return $api->health();
        }
        if (!$auth->check($request)) {
            return $auth->challenge();
        }
        if ($method !== 'POST') {
            return Response::json(['error' => 'Method Not Allowed'], 405);
        }

        switch ($path) {
            case '/api/v1/invoices':
                return $api->create($request);
            case '/api/v1/invoices/xml':
                return $api->xml($request);
            case '/api/v1/invoices/validate':
                return $api->validate($request);
            default:
                return Response::json(['error' => 'Not Found'], 404);
        }
    }

    // ---- Web-Oberfläche (optional mit denselben Zugangsdaten schützen) ----
    if (Env::bool('WEB_AUTH_ENABLED', false) && !$auth->check($request)) {
        return $auth->challenge();
    }

    $web = new WebController($zugferd, dirname(__DIR__) . '/templates');

    if ($path === '/extract' && in_array($method, ['GET', 'POST'], true)) {
        return $web->extract($request);
    }

    if ($path === '/' && $method === 'GET') {
        return $web->form($request);
    }
    if ($path === '/convert' && $method === 'POST') {
        return $web->convert($request);
    }
    if ($path === '/convert') {
        return Response::redirect('/');
    }

    return Response::html('<h1>404</h1><p><a href="/">Zur Startseite</a></p>', 404);
}
