<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Request;
use App\Http\Response;
use App\Invoice\Exception\ValidationException;
use App\Invoice\InvoiceData;
use App\Service\ZugferdService;
use App\Support\Env;

/**
 * Web-Oberfläche: Formular + PDF-Upload -> Download der ZUGFeRD-Rechnung.
 */
final class WebController
{
    /** @var ZugferdService */
    private $zugferd;
    /** @var string */
    private $templateDir;

    public function __construct(ZugferdService $zugferd, string $templateDir)
    {
        $this->zugferd = $zugferd;
        $this->templateDir = $templateDir;
    }

    public function form(Request $request): Response
    {
        return $this->render('form', [
            'values' => $this->defaults(),
            'errors' => [],
        ]);
    }

    public function extract(Request $request): Response
    {
        $report = null;
        $error = null;
        if ($request->method === 'POST') {
            try {
                $file = $request->files['pdf'] ?? [];
                if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
                    throw new \RuntimeException('Bitte eine PDF-Datei hochladen (maximal 20 MiB).');
                }
                $pages = (new \App\Extraction\PdfTextReader())->read($file['tmp_name'], isset($request->post['forceOcr']));
                $report = (new \App\Extraction\InvoiceExtractor())->extract($pages);
                $report['sourceSha256'] = hash_file('sha256', $file['tmp_name']);
                if (isset($request->post['download'])) {
                    return Response::file((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), 'extraktion.json', 'application/json');
                }
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }
        return $this->render('extraction', ['report' => $report, 'error' => $error], $error ? 422 : 200);
    }

    public function convert(Request $request): Response
    {
        $values = $request->post;
        $errors = [];

        // Leere Positionen (vom dynamischen Formular) entfernen
        $lines = [];
        foreach (($values['lines'] ?? []) as $line) {
            if (is_array($line) && trim((string) ($line['description'] ?? '')) !== '') {
                $lines[] = $line;
            }
        }
        $values['lines'] = $lines;

        $output = (string) ($values['output'] ?? 'pdf');
        $file = $request->files['pdf'] ?? null;
        $pdfBinary = null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            if ($output !== 'xml') {
                $errors['pdf'] = 'Bitte die Original-Rechnung als PDF hochladen.';
            }
        } elseif ($file['error'] !== UPLOAD_ERR_OK) {
            $errors['pdf'] = 'Upload fehlgeschlagen (Fehlercode ' . $file['error'] . ', ggf. upload_max_filesize prüfen).';
        } elseif (!is_uploaded_file($file['tmp_name'])) {
            $errors['pdf'] = 'Ungültiger Upload.';
        } else {
            $pdfBinary = (string) file_get_contents($file['tmp_name']);
            if (strpos($pdfBinary, '%PDF') !== 0) {
                $errors['pdf'] = 'Die Datei ist keine PDF-Datei.';
            }
        }

        try {
            $data = InvoiceData::fromArray($values);
            if ($errors !== []) {
                throw new ValidationException($errors);
            }
            if ($output === 'xml') {
                $xml = $this->zugferd->buildXml($data);

                return Response::file($xml, $this->zugferd->xmlFilename($data), 'application/xml; charset=utf-8');
            }

            $pdf = $this->zugferd->buildPdf($data, (string) $pdfBinary);
            $base = preg_replace('/[^A-Za-z0-9._-]+/', '_', $data->number) ?: 'rechnung';

            return Response::file($pdf, "$base-zugferd.pdf", 'application/pdf');
        } catch (ValidationException $e) {
            return $this->render('form', [
                'values' => $values,
                'errors' => $errors + $e->getErrors(),
            ], 422);
        }
    }

    /**
     * Vorbelegung aus .env (SELLER_*), damit die eigenen Firmendaten nicht jedes Mal eingetippt werden müssen.
     *
     * @return array<string,mixed>
     */
    private function defaults(): array
    {
        $seller = [];
        foreach (['NAME' => 'name', 'STREET' => 'street', 'ZIP' => 'zip', 'CITY' => 'city', 'COUNTRY' => 'country',
            'VAT_ID' => 'vatId', 'TAX_NUMBER' => 'taxNumber', 'EMAIL' => 'email', 'PHONE' => 'phone', 'CONTACT' => 'contact',
            'IBAN' => 'iban', 'BIC' => 'bic', 'ACCOUNT_HOLDER' => 'accountHolder'] as $env => $key) {
            $v = Env::get('SELLER_' . $env);
            if ($v !== null) {
                $seller[$key] = $v;
            }
        }

        return [
            'profile' => Env::get('DEFAULT_PROFILE', 'EN16931'),
            'invoice' => [
                'date' => date('Y-m-d'),
                'currency' => 'EUR',
                'typeCode' => '380',
                'paymentTerms' => Env::get('DEFAULT_PAYMENT_TERMS', ''),
            ],
            'seller' => $seller + ['country' => 'DE'],
            'buyer' => ['country' => 'DE'],
            'lines' => [['quantity' => 1, 'unit' => 'H87', 'taxRate' => 19, 'taxCategory' => 'S']],
        ];
    }

    /** @param array<string,mixed> $vars */
    private function render(string $template, array $vars, int $status = 200): Response
    {
        $file = $this->templateDir . '/' . $template . '.php';
        $vars['e'] = static function ($v): string {
            return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        };
        $vars['v'] = static function (array $values, string $path, string $default = '') use ($vars): string {
            $cur = $values;
            foreach (explode('.', $path) as $seg) {
                if (!is_array($cur) || !array_key_exists($seg, $cur)) {
                    return $vars['e']($default);
                }
                $cur = $cur[$seg];
            }

            return $vars['e'](is_array($cur) ? '' : $cur);
        };

        extract($vars, EXTR_SKIP);
        ob_start();
        require $file;
        $content = (string) ob_get_clean();

        ob_start();
        require $this->templateDir . '/layout.php';

        return Response::html((string) ob_get_clean(), $status);
    }
}
