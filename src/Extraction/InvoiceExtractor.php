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
        $result = ['schemaVersion' => 1, 'pages' => $pages, 'fields' => [], 'lines' => [], 'checks' => [], 'warnings' => ['Automatische Vorschläge sind nicht bestätigt. Verkäufer und Käufer werden ohne eindeutige Rollenbezeichnung nicht zugeordnet.']];
        $patterns = [
            'invoice.number' => '/\bRechnungs(?:nummer|nr\.?|[- ]Nr\.?)\s*:?\s*([A-Z0-9][A-Z0-9\/._-]*)/iu',
            'invoice.date' => '/\bRechnungsdatum\s*:?\s*(\d{2}\.\d{2}\.\d{4})/iu',
            'invoice.dueDate' => '/\b(?:Fällig am|Fälligkeitsdatum)\s*:?\s*(\d{2}\.\d{2}\.\d{4})/iu',
            'invoice.deliveryDate' => '/\b(?:Lieferdatum|Leistungsdatum)\s*:?\s*(\d{2}\.\d{2}\.\d{4})/iu',
            'invoice.currency' => '/\b(EUR|USD|CHF|GBP)\b/u',
            'payment.iban' => '/\bIBAN\s*:?\s*([A-Z]{2}\d{2}(?:\s?[A-Z0-9]){11,30})\b/u',
            'totals.net' => '/^(?:Nettosumme|Gesamt netto|Nettobetrag|Summe netto)\s*:?\s*(-?[\d.]+,\d{2})\b/iu',
            'totals.tax' => '/^(?:Umsatzsteuer|MwSt\.?|USt\.?)\s+(?:\d+(?:,\d+)?\s*%\s*:?\s*)(-?[\d.]+,\d{2})\b/iu',
            'totals.gross' => '/^(?:Bruttobetrag|Gesamtbetrag|Rechnungsbetrag|Gesamt brutto)\s*:?\s*(-?[\d.]+,\d{2})\b/iu',
        ];
        foreach ($pages as $page) {
            foreach ($page['warnings'] as $warning) { $result['warnings'][] = 'Seite ' . $page['number'] . ': ' . $warning; }
            foreach ($this->rows($page['words']) as $words) {
                $text = implode(' ', array_column($words, 'text'));
                foreach ($patterns as $key => $pattern) {
                    if (!preg_match($pattern, $text, $m)) { continue; }
                    $value = $m[1];
                    if (strpos($key, 'totals.') === 0) { $value = self::decimal($value); }
                    if (strpos($key, 'Date') !== false || $key === 'invoice.date') {
                        $date = \DateTimeImmutable::createFromFormat('!d.m.Y', $value);
                        $value = $date && $date->format('d.m.Y') === $value ? $date->format('Y-m-d') : null;
                    }
                    $candidate = $this->evidence($value, $m[1], $text, $page['number'], $words);
                    if ($value === null) { $candidate['status'] = 'invalid'; }
                    $result['fields'][$key]['candidates'][] = $candidate;
                }
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
        foreach (['invoice.number', 'invoice.date', 'invoice.currency', 'seller.name', 'buyer.name', 'totals.net', 'totals.tax', 'totals.gross'] as $key) {
            if (!isset($result['fields'][$key])) { $result['fields'][$key] = ['value' => null, 'status' => 'missing', 'candidates' => []]; }
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
