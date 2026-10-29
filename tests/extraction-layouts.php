<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';
function checkLayout(bool $value, string $message): void {
    if (!$value) { throw new RuntimeException($message); }
}
function pagesFromRows(array $rows): array {
    $words = [];
    foreach ($rows as $i => $row) {
        $x = 20;
        foreach (explode(' ', $row) as $word) {
            $width = mb_strlen($word, 'UTF-8') * 5;
            $words[] = ['text' => $word, 'box' => [$x, 20 + $i * 12, $x + $width, 30 + $i * 12], 'method' => 'pdf_text', 'confidence' => null];
            $x += $width + 4;
        }
    }
    return [['number' => 1, 'width' => 600, 'height' => 840, 'words' => $words, 'warnings' => []]];
}
$extractor = new App\Extraction\InvoiceExtractor();
$r = $extractor->extract(pagesFromRows(['Bei Zahlung bitte Rechnungsnummer angeben:', 'Rechnungsnummer: 123 456 7890', 'Datum: 04.03.2025', 'Geburtsdatum: 05.06.1970', 'Rechnungsbetrag netto: 100,00 €', 'zzgl. 19 % MwSt. 19,00 €', 'Rechnungsbetrag brutto: 119,00 €']));
checkLayout($r['fields']['invoice.number']['value'] === '123 456 7890', 'Spaced invoice number, no instruction text');
checkLayout(count($r['fields']['invoice.number']['candidates']) === 1, 'No false positive number');
checkLayout($r['fields']['invoice.date']['value'] === '2025-03-04', 'Header date excludes birthday');
checkLayout($r['checks'][0]['status'] === 'passed', 'Explicit net/tax/gross');
checkLayout($r['fields']['invoice.currency']['value'] === 'EUR', 'Euro symbol normalized');
$r = $extractor->extract(pagesFromRows(['Rechnung', 'Nummer: INV-123 vom 04.03.2025', 'Zwischensumme (netto) 10,00 €', 'zzgl. 7 % Mehrwertsteuer reduziert 0,70 €', 'Zahlbetrag 10,70 €']));
checkLayout($r['fields']['invoice.number']['value'] === 'INV-123' && $r['checks'][0]['status'] === 'passed', 'Number/vom and reduced VAT');
$r = $extractor->extract(pagesFromRows(['Rechnung', 'Rechnungsnummer: INV-123', 'Rechnungsdatum: 04.03.2025', 'Netto-Rechnungsendbetrag: 100,00', 'zzgl. 19% Umsatzsteuer auf 100,00 EUR: 19,00', 'Rechnungsendbetrag: 119,00']));
checkLayout($r['fields']['invoice.number']['value'] === 'INV-123', 'Invoice heading does not hide labeled number');
checkLayout($r['checks'][0]['status'] === 'passed', 'Tax amount selected instead of basis');
$r = $extractor->extract(pagesFromRows(['Rechnungsnummer: INV-123', 'Rechnungs-Nr.: LAB-456']));
checkLayout($r['documentStatus'] === 'ambiguous_invoice_identity' && $r['fields']['invoice.number']['value'] === null, 'Attachment number stays ambiguous');
$r = $extractor->extract(pagesFromRows(['Anbei erhalten Sie die korrigierte Rechnung.', '04.03.2025']));
checkLayout($r['documentStatus'] === 'unclassified' && $r['fields']['invoice.date']['value'] === null, 'Cover letter without invented date');
$r = $extractor->extract(pagesFromRows(['Vertragsnr. Leistung Zeitraum Total EUR UST', '987654 Service 01.01.2025-31.01.2025 11,76 19%']));
checkLayout(count($r['lineCandidates']) === 1 && !isset($r['lineCandidates'][0]['fields']['quantity']), 'No invented service quantity');
checkLayout($r['lineCandidates'][0]['amountBasis'] === 'unknown' && $r['lines'] === [], 'Unknown basis is not treated as net');
$r = $extractor->extract(pagesFromRows(['Zeitraum Details Verrechnungsnr. Netto', '1. Service erstattet 0044 1234 -4,15 €']));
checkLayout($r['lineCandidates'][0]['fields']['amount']['value'] === '-4.15' && $r['lineCandidates'][0]['amountBasis'] === 'net', 'Negative net candidate');
$r = $extractor->extract(pagesFromRows(['IBAN DE175001051754274XXXXX']));
checkLayout($r['fields']['payment.iban']['status'] === 'invalid', 'Masked IBAN rejected');
$r = $extractor->extract(pagesFromRows(['IBAN DE 89 3704 0044 0532 0130 00']));
checkLayout($r['fields']['payment.iban']['value'] === 'DE89370400440532013000', 'IBAN spaces and checksum');
$r = $extractor->extract(pagesFromRows(['Pos Menge Art.-Nr. Text Einzelpreis Rabatt Gesamtpreis', '1 6 ART123 Artikel mit Folgezeile 9,4533 € 56,72 €']));
checkLayout($r['lineCandidates'][0]['fields']['unitPrice']['value'] === '9.4533' && $r['lineCandidates'][0]['fields']['amount']['value'] === '56.72', 'Four-decimal price retained');
checkLayout(!isset($r['lineCandidates'][0]['fields']['taxRate']) && $r['lineCandidates'][0]['amountBasis'] === 'unknown', 'No tax or basis guessed from merchandise');
$r = $extractor->extract(pagesFromRows(['Position Einzelpreis Menge Preis', '(brutto)', 'Eintritt Erwachsene 4,00 € 1 4,00 €', 'Eintritt Erwachsene 4,00 € 1 4,00 €']));
checkLayout(count($r['lineCandidates']) === 2 && $r['lineCandidates'][0]['fields']['quantity']['value'] === '1', 'Repeated equal ticket rows retained');
$r = $extractor->extract(pagesFromRows(['Beschreibung Betrag', 'Artikel: Individueller Druck 13,24 €', 'Stück: 1 x 2 Stück']));
checkLayout(!isset($r['lineCandidates'][0]['fields']['quantity']) && $r['lineCandidates'][0]['fields']['amount']['value'] === '13.24', 'Ambiguous package quantity not guessed');
echo "OK: layout regressions, invoice identity, partial lines, tax basis, masked IBAN\n";
