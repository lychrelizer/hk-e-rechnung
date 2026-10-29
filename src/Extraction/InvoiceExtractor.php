<?php
declare(strict_types=1);
namespace App\Extraction;

/** Conservative German invoice heuristics; evidence is never a confirmed invoice. */
final class InvoiceExtractor
{
    public static function decimal(string $raw): ?string
    {
        $raw = str_replace(["\xc2\xa0", ' '], '', $raw);
        if (!preg_match('/^-?(?:\d+|\d{1,3}(?:\.\d{3})+)(?:,\d{1,4})?$/D', $raw)) { return null; }
        return str_replace(',', '.', str_replace('.', '', $raw));
    }

    public function extract(array $pages): array
    {
        $result = ['schemaVersion' => 2, 'pages' => $pages, 'fields' => [], 'lines' => [], 'lineCandidates' => [], 'checks' => [], 'warnings' => ['Automatische Vorschläge sind nicht bestätigt. Verkäufer und Käufer werden ohne eindeutige Rollenbezeichnung nicht zugeordnet.']];
        $patterns = [
            'invoice.number' => '/\b(?:Rechnungs(?:nummer|[- ]?Nr\.?)|Rechnung(?:\s+Nr\.?)?|RG[- .]*(?:Nummer|Nr\.?))\s*:?\s*((?=[A-Z0-9\/._-]*\d)[A-Z0-9][A-Z0-9\/._-]*(?: +\d{2,})*)/iu',
            'invoice.date' => '/\b(?:Rechnungsdatum|Belegdatum)\s*:?\s*(\d{2}\.\d{2}\.\d{4})/iu',
            'invoice.dueDate' => '/\b(?:Fällig am|Fälligkeitsdatum)\s*:?\s*(\d{2}\.\d{2}\.\d{4})/iu',
            'invoice.deliveryDate' => '/\b(?:Lieferdatum|Leistungsdatum)\s*:?\s*(\d{2}\.\d{2}\.\d{4})/iu',
            'invoice.currency' => '/(\b(?:EUR|USD|CHF|GBP)\b|€)/u',
            'payment.iban' => '/\bIBAN\s*:?\s*(DE\s*\d{2}(?:\s*\d){18}(?!\s*\d)|[A-Z]{2}\s*\d{2}(?:\s?[A-Z0-9]){11,30})\b/u',
            'totals.net' => '/^(?:Nettosumme|Gesamt netto|Nettobetrag|Summe netto|Netto-Rechnungsendbetrag|Rechnungsbetrag netto|Zwischensumme\s*\(netto\))\s*:?\s*(-?[\d.]+,\d{2})\b/iu',
            'totals.tax' => '/^(?:Positionen\s+)?(?:zzgl\.\s*)?(?:(?:Umsatzsteuer|Mehrwertsteuer|MwSt\.?|USt\.?)\s+\d+(?:,\d+)?\s*%|\+?\d+(?:,\d+)?\s*%\s*(?:Umsatzsteuer|Mehrwertsteuer|MwSt\.?|USt\.?))(?:\s+reduziert)?(?:\s+auf\s+[\d.]+,\d{2}\s*(?:EUR|€)?)?\s*:?\s*(-?[\d.]+,\d{2})\s*(?:EUR|€)?\s*$/iu',
            'totals.gross' => '/^(?:Bruttobetrag|Gesamtbetrag|Rechnungsbetrag(?: brutto)?|Gesamt brutto|Rechnungsendbetrag|Endsumme|Zahlbetrag)\s*:?\s*(-?[\d.]+,\d{2})\b/iu',
        ];
        foreach ($pages as $page) {
            foreach ($page['warnings'] as $warning) { $result['warnings'][] = 'Seite ' . $page['number'] . ': ' . $warning; }
            $pageRows = $this->rows($page['words']);
            $previousText = '';
            $table = null;
            $tableHeader = [];
            foreach ($pageRows as $words) {
                $text = implode(' ', array_column($words, 'text'));
                // Layout families are activated by table headings, never by the vendor name.
                if (preg_match('/^Pos\.?\s+Menge\s+Art\.-Nr\.\s+Text\s+Einzelpreis/u', $text)) { $table = 'quantity_article'; $tableHeader = $words; }
                elseif (preg_match('/^Position\s+Einzelpreis\s+Menge\s+Preis/u', $text)) { $table = 'ticket'; $tableHeader = $words; }
                elseif (preg_match('/^Vertragsnr\.\s+Leistung\s+Zeitraum\s+Total\s+EUR\s+UST/u', $text)) { $table = 'service_period'; $tableHeader = $words; }
                elseif (preg_match('/^Zeitraum\b.*\bDetails\b.*\bNetto$/u', $text)) { $table = 'net_amount'; $tableHeader = $words; }
                elseif (preg_match('/^Beschreibung\s+Betrag$/u', $text)) { $table = 'article_amount'; $tableHeader = $words; }
                elseif (preg_match('/^(?:Zwischensumme|Summe|Nettosumme|Gesamt|Rechnungsbetrag|Wichtige Hinweise)\b/u', $text)) { $table = null; }
                if ($table !== null) {
                    $partial = $this->partialLine($table, $text, $words, $tableHeader, $page['number']);
                    if ($partial !== null) { $result['lineCandidates'][] = $partial; }
                }
                $rowPatterns = $patterns;
                if (preg_match('/^Rechnung$/iu', $previousText) && preg_match('/^Nummer\s*:/iu', $text)) {
                    $rowPatterns['invoice.number'] = '/^Nummer\s*:\s*((?=[A-Z0-9\/._-]*\d)[A-Z0-9\/._-]+)\s+vom\b/iu';
                    $rowPatterns['invoice.date'] = '/^Nummer\s*:\s*\S+\s+vom\s+(\d{2}\.\d{2}\.\d{4})/iu';
                } elseif ($words[0]['box'][1] < $page['height'] * 0.4 && preg_match('/\bRechnung/iu', implode(' ', array_column($page['words'], 'text')))) {
                    $rowPatterns['invoice.date'] = '/\b(?:Rechnungsdatum|Belegdatum|(?<![\pL])Datum)\s*:?\s*(\d{2}\.\d{2}\.\d{4})/iu';
                }
                foreach ($rowPatterns as $key => $pattern) {
                    if (!preg_match($pattern, $text, $m)) { continue; }
                    $value = $m[1];
                    if ($key === 'invoice.currency' && $value === '€') { $value = 'EUR'; }
                    if ($key === 'payment.iban') {
                        $value = preg_replace('/\s+/', '', $value);
                        if (!$this->validIban($value)) { $value = null; }
                    }
                    if (strpos($key, 'totals.') === 0) { $value = self::decimal($value); }
                    if (strpos($key, 'Date') !== false || $key === 'invoice.date') {
                        $date = \DateTimeImmutable::createFromFormat('!d.m.Y', $value);
                        $value = $date && $date->format('d.m.Y') === $value ? $date->format('Y-m-d') : null;
                    }
                    $candidate = $this->evidence($value, $m[1], $text, $page['number'], $words);
                    if ($value === null) { $candidate['status'] = 'invalid'; }
                    $result['fields'][$key]['candidates'][] = $candidate;
                }
                $previousText = $text;
                // Supported prototype row: position, description, quantity, unit, net price, explicit tax %, net amount.
                if (preg_match('/^(\d+)\s+(.+?)\s+(-?\d+(?:,\d+)?)\s+(Stk\.?|Std\.?|kg|m|Stück)\s+(-?[\d.]+,\d{2,4})\s+(\d+(?:,\d+)?)\s*%\s+(-?[\d.]+,\d{2})(?:\s+EUR)?$/u', $text, $m)) {
                    $line = [];
                    foreach (['id' => 1, 'description' => 2, 'quantity' => 3, 'unit' => 4, 'unitPrice' => 5, 'taxRate' => 6, 'netAmount' => 7] as $key => $n) {
                        $value = in_array($key, ['quantity', 'unitPrice', 'taxRate', 'netAmount'], true) ? self::decimal($m[$n]) : $m[$n];
                        $line[$key] = $this->evidence($value, $m[$n], $text, $page['number'], $words);
                    }
                    $result['lines'][] = $line;
                } elseif (preg_match('/^\d+\s+.+\d+,\d{2}/u', $text)) {
                    $result['warnings'][] = 'Unaufgelöste mögliche Position, Seite ' . $page['number'] . ': ' . $text;
                }
            }
        }
        foreach ($result['fields'] as $fieldKey => &$field) {
            $values = array_unique(array_column($field['candidates'], 'value'), SORT_REGULAR);
            // Tax rows may be additive, even when their amounts happen to be equal.
            $conflict = count($values) > 1 || ($fieldKey === 'totals.tax' && count($field['candidates']) > 1);
            $field['status'] = $conflict ? 'conflict' : ($field['candidates'][0]['value'] === null ? 'invalid' : 'needs_review');
            $field['value'] = !$conflict ? $field['candidates'][0]['value'] : null;
        }
        unset($field);
        if (isset($result['fields']['invoice.number']) && $result['fields']['invoice.number']['status'] === 'conflict') {
            $result['warnings'][] = 'Mehrere Rechnungsnummern: mögliche Anlagen oder mehrere Rechnungen in einer PDF. Keine automatische Zusammenführung.';
        }
        if ($result['lineCandidates']) {
            $result['warnings'][] = 'Unvollständige Positionskandidaten erkannt: Beschreibung kann Folgezeilen haben. Unbekannte Preisbasis bleibt offen; keine Übernahme in Netto-/Steuerprüfungen.';
        }
        foreach (['invoice.number', 'invoice.date', 'invoice.currency', 'seller.name', 'buyer.name', 'totals.net', 'totals.tax', 'totals.gross'] as $key) {
            if (!isset($result['fields'][$key])) { $result['fields'][$key] = ['value' => null, 'status' => 'missing', 'candidates' => []]; }
        }
        $result['documentStatus'] = $result['fields']['invoice.number']['status'] === 'conflict' ? 'ambiguous_invoice_identity' : (($result['fields']['invoice.number']['candidates'] || $result['fields']['totals.gross']['candidates']) ? 'invoice_candidate' : 'unclassified');
        if ($result['documentStatus'] === 'unclassified') {
            $result['warnings'][] = 'Keine hinreichenden Rechnungsmerkmale erkannt. Mögliches Anschreiben oder nicht unterstütztes Layout; Dokumenttyp manuell prüfen.';
        }
        $this->check($result, 'net_plus_tax', ['totals.net', 'totals.tax', 'totals.gross']);
        $net = '0'; $taxes = [];
        foreach ($result['lines'] as $i => $line) {
            $expected = $this->round(bcmul($line['quantity']['value'], $line['unitPrice']['value'], 8));
            $result['checks'][] = $this->comparison('line_' . ($i + 1), $expected, $line['netAmount']['value']);
            $net = bcadd($net, $line['netAmount']['value'], 2);
            $rate = $line['taxRate']['value'];
            $taxes[$rate] = bcadd($taxes[$rate] ?? '0', $line['netAmount']['value'], 2);
        }
        if ($result['lines']) {
            $reported = $result['fields']['totals.net']['value'];
            $result['checks'][] = $this->comparison('recognized_lines_vs_net', $net, $reported);
            $tax = '0';
            foreach ($taxes as $rate => $basis) { $tax = bcadd($tax, $this->round(bcdiv(bcmul($basis, (string) $rate, 8), '100', 8)), 2); }
            $result['checks'][] = $this->comparison('recognized_tax_groups_vs_tax', $tax, $result['fields']['totals.tax']['value']);
        }
        $result['warnings'][] = 'Positionsvollständigkeit, Rabatte, Zuschläge, Anzahlungen und Steuerbefreiungen müssen manuell geprüft werden.';
        return $result;
    }

    private function round(string $number): string
    {
        return bcadd($number, bccomp($number, '0', 8) < 0 ? '-0.005' : '0.005', 2);
    }

    private function validIban(string $iban): bool
    {
        if (!preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/D', $iban) || (substr($iban, 0, 2) === 'DE' && strlen($iban) !== 22)) { return false; }
        $rotated = substr($iban, 4) . substr($iban, 0, 4);
        $digits = preg_replace_callback('/[A-Z]/', function ($m) { return (string) (ord($m[0]) - 55); }, $rotated);
        return bcmod($digits, '97') === '1';
    }

    private function partialLine(string $table, string $text, array $words, array $header, int $page): ?array
    {
        $patterns = [
            'quantity_article' => '/^(\d+)\s+(\d+(?:,\d+)?)\s+(\S+)\s+(.+?)\s+(-?[\d.]+,\d{2,4})\s*€\s+(-?[\d.]+,\d{2})\s*€$/u',
            'ticket' => '/^(.+?)\s+(-?[\d.]+,\d{2})\s*€\s+(\d+(?:,\d+)?)\s+(-?[\d.]+,\d{2})\s*€$/u',
            'service_period' => '/^(\d+)\s+(.+?)\s+(\d{2}\.\d{2}\.\d{4}-\d{2}\.\d{2}\.\d{4})\s+(-?[\d.]+,\d{2})\s+(\d+(?:,\d+)?)\s*%$/u',
            'net_amount' => '/^(\d+)\.\s+(.+?)\s+(-?[\d.]+,\d{2})\s*€$/u',
            'article_amount' => '/^Artikel:\s+(.+?)\s+(-?[\d.]+,\d{2})\s*€$/u',
        ];
        if (!preg_match($patterns[$table], $text, $m)) { return null; }
        $maps = [
            'quantity_article' => ['id' => 1, 'quantity' => 2, 'articleNumber' => 3, 'description' => 4, 'unitPrice' => 5, 'amount' => 6],
            'ticket' => ['description' => 1, 'unitPrice' => 2, 'quantity' => 3, 'amount' => 4],
            'service_period' => ['contractNumber' => 1, 'description' => 2, 'servicePeriod' => 3, 'amount' => 4, 'taxRate' => 5],
            'net_amount' => ['id' => 1, 'description' => 2, 'amount' => 3],
            'article_amount' => ['description' => 1, 'amount' => 2],
        ];
        $fields = [];
        foreach ($maps[$table] as $key => $index) {
            $value = in_array($key, ['quantity', 'unitPrice', 'amount', 'taxRate'], true) ? self::decimal($m[$index]) : $m[$index];
            $fields[$key] = $this->evidence($value, $m[$index], $text, $page, $words);
        }
        // Only the explicit Netto heading establishes a net amount. Other totals
        // must not be converted or treated as net prices from arithmetic alone.
        return ['status' => 'incomplete', 'rule' => $table, 'amountBasis' => $table === 'net_amount' ? 'net' : 'unknown', 'headerEvidence' => $this->evidence(implode(' ', array_column($header, 'text')), implode(' ', array_column($header, 'text')), implode(' ', array_column($header, 'text')), $page, $header), 'fields' => $fields, 'missingFields' => array_values(array_diff(['description', 'quantity', 'unit', 'unitPrice', 'taxRate'], array_keys($fields)))];
    }

    private function comparison(string $name, string $expected, ?string $actual): array
    {
        return ['name' => $name, 'status' => $actual === null ? 'not_checkable' : (bccomp($expected, $actual, 2) === 0 ? 'passed' : 'failed'), 'expected' => $expected, 'actual' => $actual];
    }

    private function check(array &$result, string $name, array $keys): void
    {
        $v = array_map(function ($key) use ($result) { return $result['fields'][$key]['value']; }, $keys);
        $result['checks'][] = in_array(null, $v, true) ? ['name' => $name, 'status' => 'not_checkable'] : $this->comparison($name, bcadd($v[0], $v[1], 2), $v[2]);
    }

    private function evidence($value, string $raw, string $text, int $page, array $words): array
    {
        $conf = array_filter(array_column($words, 'confidence'), function ($v) { return $v !== null; });
        return ['value' => $value, 'originalText' => $raw, 'context' => $text, 'page' => $page, 'box' => [min(array_column(array_column($words, 'box'), 0)), min(array_column(array_column($words, 'box'), 1)), max(array_column(array_column($words, 'box'), 2)), max(array_column(array_column($words, 'box'), 3))], 'locationPrecision' => 'row', 'method' => $words[0]['method'], 'ocrConfidence' => $conf ? min($conf) : null, 'status' => 'needs_review'];
    }

    private function rows(array $words): array
    {
        usort($words, function ($a, $b) { return $a['box'][1] <=> $b['box'][1]; });
        $rows = [];
        foreach ($words as $word) {
            $found = false;
            foreach ($rows as &$row) {
                if (abs($row[0]['box'][1] - $word['box'][1]) < 4) { $row[] = $word; $found = true; break; }
            }
            unset($row);
            if (!$found) { $rows[] = [$word]; }
        }
        foreach ($rows as &$row) { usort($row, function ($a, $b) { return $a['box'][0] <=> $b['box'][0]; }); }
        unset($row);
        return $rows;
    }
}
