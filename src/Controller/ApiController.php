<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Request;
use App\Http\Response;
use App\Invoice\Exception\ValidationException;
use App\Invoice\InvoiceData;
use App\Service\ZugferdService;

/**
 * JSON-Schnittstelle.
 *
 *   GET  /api/v1/health
 *   POST /api/v1/invoices          Rechnung + PDF -> ZUGFeRD-PDF
 *   POST /api/v1/invoices/xml      Rechnung -> nur XML (keine PDF nötig)
 *   POST /api/v1/invoices/validate Rechnung prüfen, nichts erzeugen
 *
 * Body-Varianten für POST:
 *   a) application/json:  { ...InvoiceData..., "pdf": { "base64": "...", "filename": "rechnung.pdf" } }
 *   b) multipart/form-data: Feld "data" (JSON-String) + Datei "pdf"
 *
 * Antwortformat über Query ?format=json|pdf|xml oder Accept-Header:
 *   json (Standard): { "filename", "xmlFilename", "pdfBase64", "xml", "totals" }
 *   pdf:             application/pdf (Binär, Download)
 *   xml:             application/xml
 */
final class ApiController
{
    /** @var ZugferdService */
    private $zugferd;

    public function __construct(ZugferdService $zugferd)
    {
        $this->zugferd = $zugferd;
    }

    public function health(): Response
    {
        return Response::json(['status' => 'ok', 'time' => date(DATE_ATOM)]);
    }

    public function validate(Request $request): Response
    {
        [$payload] = $this->payload($request);
        $data = InvoiceData::fromArray($payload);
        $this->zugferd->buildXml($data); // XSD-Prüfung inklusive

        return Response::json(['valid' => true, 'profile' => $data->profile, 'totals' => $data->totals()]);
    }

    public function xml(Request $request): Response
    {
        [$payload] = $this->payload($request);
        $data = InvoiceData::fromArray($payload);
        $xml = $this->zugferd->buildXml($data);

        return $this->respond($request, $data, $xml, null);
    }

    public function create(Request $request): Response
    {
        [$payload, $pdfBinary] = $this->payload($request);
        if ($pdfBinary === null) {
            throw new ValidationException(['pdf' => 'PDF fehlt. Entweder "pdf.base64" im JSON oder Datei "pdf" als multipart senden.']);
        }

        $data = InvoiceData::fromArray($payload);
        $xml = $this->zugferd->buildXml($data);
        $pdf = $this->zugferd->buildPdf($data, $pdfBinary);

        return $this->respond($request, $data, $xml, $pdf);
    }

    /**
     * @return array{0: array<string,mixed>, 1: ?string}
     */
    private function payload(Request $request): array
    {
        $pdf = null;

        if ($request->isJson()) {
            $json = json_decode($request->body, true);
            if (!is_array($json)) {
                throw new ValidationException(['body' => 'Ungültiges JSON: ' . json_last_error_msg()]);
            }
            if (isset($json['pdf']) && is_array($json['pdf']) && isset($json['pdf']['base64'])) {
                $pdf = base64_decode(preg_replace('/^data:.*?;base64,/', '', (string) $json['pdf']['base64']) ?? '', true);
                if ($pdf === false) {
                    throw new ValidationException(['pdf.base64' => 'PDF ist kein gültiges Base64.']);
                }
            }
            unset($json['pdf']);

            return [$json, $pdf];
        }

        // multipart/form-data
        $raw = $request->post['data'] ?? null;
        if (!is_string($raw)) {
            throw new ValidationException(['data' => 'Feld "data" (JSON-String) fehlt oder Content-Type ist nicht application/json.']);
        }
        $json = json_decode($raw, true);
        if (!is_array($json)) {
            throw new ValidationException(['data' => 'Feld "data" enthält kein gültiges JSON: ' . json_last_error_msg()]);
        }
        $file = $request->files['pdf'] ?? null;
        if (is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && is_uploaded_file($file['tmp_name'])) {
            $pdf = (string) file_get_contents($file['tmp_name']);
        }
        unset($json['pdf']);

        return [$json, $pdf];
    }

    private function respond(Request $request, InvoiceData $data, string $xml, ?string $pdf): Response
    {
        $format = strtolower((string) ($request->query['format'] ?? ''));
        $accept = (string) $request->header('accept');
        if ($format === '') {
            if (strpos($accept, 'application/pdf') !== false && $pdf !== null) {
                $format = 'pdf';
            } elseif (strpos($accept, 'xml') !== false) {
                $format = 'xml';
            } else {
                $format = 'json';
            }
        }

        $base = preg_replace('/[^A-Za-z0-9._-]+/', '_', $data->number) ?: 'rechnung';
        $xmlName = $this->zugferd->xmlFilename($data);

        switch ($format) {
            case 'pdf':
                return $pdf !== null
                    ? Response::file($pdf, "$base-zugferd.pdf", 'application/pdf')
                    : Response::json(['error' => 'Für dieses Endpoint wird keine PDF erzeugt.'], 400);
            case 'xml':
                return Response::file($xml, $xmlName, 'application/xml; charset=utf-8');
            default:
                return Response::json([
                    'filename' => "$base-zugferd.pdf",
                    'xmlFilename' => $xmlName,
                    'profile' => $data->profile,
                    'totals' => $data->totals(),
                    'xml' => $xml,
                    'pdfBase64' => $pdf !== null ? base64_encode($pdf) : null,
                ]);
        }
    }
}
