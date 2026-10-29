<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';
if ($argc < 2) { fwrite(STDERR, "php bin/extract.php rechnung.pdf [--ocr]\n"); exit(2); }
try {
    $pages = (new App\Extraction\PdfTextReader())->read($argv[1], in_array('--ocr', $argv, true));
    $report = (new App\Extraction\InvoiceExtractor())->extract($pages);
    $report['sourceSha256'] = hash_file('sha256', $argv[1]);
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
} catch (Throwable $e) { fwrite(STDERR, $e->getMessage() . "\n"); exit(1); }
