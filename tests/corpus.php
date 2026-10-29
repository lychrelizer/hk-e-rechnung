<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';

// Private PDFs and reviewed expectations intentionally live outside version control.
$directory = $argv[1] ?? dirname(__DIR__) . '/Beispielrechnungen';
$manifest = $directory . '/expected.json';
if (!is_file($manifest)) { fwrite(STDERR, "Lokales Testkorpus fehlt: expected.json erforderlich.\n"); exit(2); }
$cases = json_decode(file_get_contents($manifest), true);
if (!is_array($cases) || !$cases) { throw new RuntimeException('Leeres oder ungültiges Testmanifest.'); }
$assertions = 0;
foreach ($cases as $case) {
    $path = $directory . '/' . $case['file'];
    if (!is_file($path) || hash_file('sha256', $path) !== $case['sourceSha256']) { throw new RuntimeException('Testquelle geändert: ' . $case['file']); }
    $report = (new App\Extraction\InvoiceExtractor())->extract((new App\Extraction\PdfTextReader())->read($path));
    foreach ($case['fields'] as $field => $expected) {
        foreach ($expected as $key => $value) {
            ++$assertions;
            if (!isset($report['fields'][$field]) || !array_key_exists($key, $report['fields'][$field]) || $report['fields'][$field][$key] !== $value) { throw new RuntimeException($case['file'] . ': ' . $field . '.' . $key . ' weicht ab.'); }
        }
    }
    foreach (['documentStatus', 'lineCandidates', 'arithmeticStatus'] as $key) {
        if (!array_key_exists($key, $case)) { continue; }
        $actual = $key === 'lineCandidates' ? count($report['lineCandidates']) : ($key === 'arithmeticStatus' ? $report['checks'][0]['status'] : $report[$key]);
        ++$assertions;
        if ($actual !== $case[$key]) { throw new RuntimeException($case['file'] . ': ' . $key . ' weicht ab.'); }
    }
}
echo 'OK: ' . count($cases) . ' private PDFs, ' . $assertions . " assertions\n";
