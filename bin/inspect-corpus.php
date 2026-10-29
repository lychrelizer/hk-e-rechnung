<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';

use App\Extraction\PdfTextReader;
use App\Extraction\InvoiceExtractor;

$directory = $argv[1] ?? dirname(__DIR__) . '/Beispielrechnungen';
$output = $directory . '/extraction-reports';
if (!is_dir($output) && !mkdir($output, 0700, true)) { throw new RuntimeException('Ausgabeverzeichnis fehlt.'); }
$summary = [];
foreach (glob($directory . '/*.pdf') as $path) {
    $name = basename($path);
    try {
        $pages = (new PdfTextReader())->read($path);
        $report = (new InvoiceExtractor())->extract($pages);
        $report['sourceSha256'] = hash_file('sha256', $path);
        file_put_contents($output . '/' . $name . '.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $text = '';
        foreach ($pages as $page) {
            $text .= "\n=== PAGE " . $page['number'] . " ===\n";
            // Keep geometric rows for diagnosis, rather than flattening the whole page.
            $words = $page['words'];
            usort($words, function ($a, $b) { return $a['box'][1] <=> $b['box'][1]; });
            $rows = [];
            foreach ($words as $word) {
                $key = (int) round($word['box'][1] / 4);
                $rows[$key][] = $word;
            }
            foreach ($rows as $row) {
                usort($row, function ($a, $b) { return $a['box'][0] <=> $b['box'][0]; });
                $text .= implode(' ', array_column($row, 'text')) . "\n";
            }
        }
        file_put_contents($output . '/' . $name . '.txt', $text);
        $fields = [];
        foreach ($report['fields'] as $key => $field) { $fields[$key] = ['value' => $field['value'], 'status' => $field['status']]; }
        $entry = ['file' => $name, 'sha256' => $report['sourceSha256'], 'pages' => count($pages), 'ocrPages' => count(array_filter($pages, function ($p) { return isset($p['words'][0]) && $p['words'][0]['method'] === 'ocr'; })), 'fields' => $fields, 'lines' => count($report['lines']), 'lineCandidates' => count($report['lineCandidates']), 'checks' => $report['checks']];
        $summary[] = $entry;
        echo $name . ': ' . count($pages) . ' pages, ' . count($report['lines']) . ' complete lines, ' . count($report['lineCandidates']) . " partial candidates\n";
    } catch (Throwable $e) {
        $summary[] = ['file' => $name, 'error' => $e->getMessage()];
        fwrite(STDERR, $name . ': ' . $e->getMessage() . "\n");
    }
}
file_put_contents($output . '/summary.json', json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
$markdown = "# Lokale Auswertung der Beispielrechnungen\n\nAutomatische Vorschläge, keine fachliche Freigabe. Positionskandidaten sind unvollständig und werden nicht als vollständige Positionen gerechnet. Originale bleiben unverändert.\n\n| PDF | Seiten / OCR | Nummer | Datum | Netto | Steuer | Brutto | Prüfung | Positionskandidaten |\n|---|---|---|---|---|---|---|---|---|\n";
foreach ($summary as $entry) {
    if (isset($entry['error'])) { $markdown .= '| ' . $entry['file'] . ' | Fehler: ' . str_replace(["\n", '|'], ' ', $entry['error']) . " | | | | | | | |\n"; continue; }
    $cells = ['[' . $entry['file'] . '](' . rawurlencode($entry['file'] . '.json') . ')', $entry['pages'] . ' / ' . $entry['ocrPages']];
    foreach (['invoice.number', 'invoice.date', 'totals.net', 'totals.tax', 'totals.gross'] as $key) { $cells[] = $entry['fields'][$key]['value'] ?? $entry['fields'][$key]['status']; }
    $cells[] = $entry['checks'][0]['status']; $cells[] = $entry['lineCandidates'];
    $markdown .= '| ' . implode(' | ', array_map(function ($cell) { return str_replace('|', '\\|', (string) $cell); }, $cells)) . " |\n";
}
file_put_contents($output . '/summary.md', $markdown);
