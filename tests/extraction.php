<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';
use App\Extraction\InvoiceExtractor;
use App\Extraction\PdfTextReader;

function expect($condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
}
$dir = sys_get_temp_dir() . '/invoice-tests-' . bin2hex(random_bytes(8));
if (!is_dir($dir)) { mkdir($dir, 0700, true); }
function fixture(string $path, array $rows): void {
    $pdf = new FPDF();
    $pdf->AddPage(); $pdf->SetFont('Helvetica', '', 12);
    foreach ($rows as $row) { $pdf->Cell(0, 10, iconv('UTF-8', 'Windows-1252', $row), 0, 1); }
    $pdf->Output('F', $path);
}
$rows = ['SYNTHETISCHE TESTRECHNUNG', 'Rechnungsnummer: RE-2026-001', 'Rechnungsdatum: 19.10.2026', 'Währung: EUR', 'Pos Beschreibung Menge Einheit Einzelpreis MwSt Betrag', '1 Beratung 2 Std 100,00 19 % 200,00', '2 Buch 1 Stk 50,00 7 % 50,00', 'Nettosumme: 250,00', 'MwSt 19 % 38,00', 'MwSt 7 % 3,50', 'Gesamtbetrag: 291,50'];
// Multiple tax amounts are retained as conflicting candidates, never silently added.
fixture($dir . '/mixed-tax.pdf', $rows);
$simple = $rows;
$simple[6] = '2 Beratung 1 Std 50,00 19 % 50,00';
$simple[8] = 'MwSt 19 % 47,50'; unset($simple[9]); $simple[10] = 'Gesamtbetrag: 297,50';
fixture($dir . '/digital.pdf', $simple);
$bad = $simple; $bad[10] = 'Gesamtbetrag: 999,00'; $bad[] = 'Rechnungsnummer: ANDERE-2';
fixture($dir . '/contradiction.pdf', $bad);
$reader = new PdfTextReader(); $extractor = new InvoiceExtractor();
$digital = $extractor->extract($reader->read($dir . '/digital.pdf'));
expect($digital['fields']['invoice.number']['value'] === 'RE-2026-001', 'Invoice number');
expect($digital['fields']['invoice.date']['value'] === '2026-10-19', 'Date');
expect(count($digital['lines']) === 2, 'Two positions');
foreach ($digital['checks'] as $check) { expect($check['status'] === 'passed', 'Arithmetic ' . $check['name']); }
expect($digital['fields']['seller.name']['status'] === 'missing', 'No invented seller');
expect($digital['fields']['invoice.number']['candidates'][0]['page'] === 1, 'Page evidence');
$conflict = $extractor->extract($reader->read($dir . '/contradiction.pdf'));
expect($conflict['fields']['invoice.number']['status'] === 'conflict', 'Conflict surfaced');
expect($conflict['checks'][0]['status'] === 'failed', 'Wrong total detected');
$mixed = $extractor->extract($reader->read($dir . '/mixed-tax.pdf'));
expect($mixed['fields']['totals.tax']['status'] === 'conflict', 'Multiple tax amounts not collapsed');
expect($mixed['checks'][0]['status'] === 'not_checkable', 'Ambiguous tax not checked as certain');
PdfTextReader::command(['pdftoppm', '-scale-to', '2500', '-singlefile', '-png', $dir . '/digital.pdf', $dir . '/scan']);
$pdf = new FPDF(); $pdf->AddPage(); $pdf->Image($dir . '/scan.png', 0, 0, 210, 297); $pdf->Output('F', $dir . '/scan.pdf');
$scanPages = $reader->read($dir . '/scan.pdf');
$scan = $extractor->extract($scanPages);
expect($scanPages[0]['words'][0]['method'] === 'ocr', 'Scan triggers OCR');
expect($scan['fields']['invoice.number']['value'] === 'RE-2026-001', 'OCR invoice number');
expect(count($scan['lines']) === 2, 'OCR positions');
expect($scan['fields']['invoice.number']['candidates'][0]['ocrConfidence'] !== null, 'OCR confidence');
expect(InvoiceExtractor::decimal('1.234,56') === '1234.56', 'German decimal');
expect(InvoiceExtractor::decimal('1,234.56') === null, 'Ambiguous number rejected');
$empty = $extractor->extract([]);
expect($empty['fields']['invoice.date']['value'] === null, 'No default date');
$forced = $reader->read($dir . '/digital.pdf', true);
expect(count($forced[0]['nativeWords']) > 0 && $forced[0]['words'][0]['method'] === 'ocr', 'Forced OCR preserves native evidence');
$report = $digital; $error = null;
$e = function ($value) { return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
ob_start(); require dirname(__DIR__) . '/templates/extraction.php'; $html = ob_get_clean();
expect(strpos($html, 'RE-2026-001') !== false && strpos($html, 'needs_review') !== false, 'Review template renders evidence');
echo "OK: digital PDF, scan/OCR, mixed tax ambiguity, contradictions, provenance, arithmetic, missing values\n";
foreach (glob($dir . '/*') as $generatedFile) { unlink($generatedFile); }
rmdir($dir);
