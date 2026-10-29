<?php
declare(strict_types=1);
namespace App\Extraction;

use Symfony\Component\Process\Process;

/** All coordinates are points, relative to the top left of the page. */
final class PdfTextReader
{
    public static function command(array $args): string
    {
        $process = new Process($args);
        $process->setTimeout(90);
        $process->mustRun();
        return $process->getOutput();
    }

    public function read(string $pdf, bool $forceOcr = false): array
    {
        if (!is_file($pdf) || filesize($pdf) > 20 * 1024 * 1024 || file_get_contents($pdf, false, null, 0, 4) !== '%PDF') {
            throw new \RuntimeException('PDF fehlt, ist ungültig oder größer als 20 MiB.');
        }
        $dir = sys_get_temp_dir() . '/invoice-' . bin2hex(random_bytes(12));
        if (!mkdir($dir, 0700)) { throw new \RuntimeException('Temporäres Verzeichnis nicht verfügbar.'); }
        try {
            // A fixed local filename also prevents Poppler interpreting an input as an option.
            copy($pdf, $dir . '/input.pdf');
            self::command([getenv('PDFTOTEXT_BIN') ?: 'pdftotext', '-bbox-layout', '-enc', 'UTF-8', $dir . '/input.pdf', $dir . '/text.html']);
            $doc = new \DOMDocument();
            if (!$doc->loadXML(file_get_contents($dir . '/text.html'), LIBXML_NONET)) {
                throw new \RuntimeException('Poppler-Ausgabe ist ungültig.');
            }
            $nodes = $doc->getElementsByTagName('page');
            if ($nodes->length > 30) { throw new \RuntimeException('Prototyp: höchstens 30 Seiten.'); }
            $pages = [];
            foreach ($nodes as $i => $node) {
                $page = ['number' => $i + 1, 'width' => (float) $node->getAttribute('width'), 'height' => (float) $node->getAttribute('height'), 'words' => [], 'warnings' => []];
                foreach ($node->getElementsByTagName('word') as $word) {
                    $page['words'][] = ['text' => $word->textContent, 'box' => array_map(function ($key) use ($word) { return (float) $word->getAttribute($key); }, ['xMin', 'yMin', 'xMax', 'yMax']), 'method' => 'pdf_text', 'confidence' => null];
                }
                $text = implode(' ', array_column($page['words'], 'text'));
                $bad = substr_count($text, "\xEF\xBF\xBD");
                $ocr = $forceOcr || mb_strlen(preg_replace('/\s/u', '', $text), 'UTF-8') < 40 || $bad > 2;
                $page['nativeWords'] = $page['words'];
                if ($ocr) {
                    try {
                        $prefix = $dir . '/page';
                        self::command([getenv('PDFTOPPM_BIN') ?: 'pdftoppm', '-f', (string) ($i + 1), '-l', (string) ($i + 1), '-scale-to', '3500', '-singlefile', '-png', $dir . '/input.pdf', $prefix]);
                        $size = getimagesize($prefix . '.png');
                        $tsv = self::command([getenv('TESSERACT_BIN') ?: 'tesseract', $prefix . '.png', 'stdout', '-l', 'deu', '--psm', '3', '-c', 'tessedit_create_tsv=1']);
                        $page['words'] = [];
                        foreach (explode("\n", trim($tsv)) as $row) {
                            $c = explode("\t", $row, 12);
                            if (count($c) !== 12 || $c[0] !== '5' || trim($c[11]) === '') { continue; }
                            $sx = $page['width'] / $size[0]; $sy = $page['height'] / $size[1];
                            $page['words'][] = ['text' => trim($c[11]), 'box' => [(float) $c[6] * $sx, (float) $c[7] * $sy, ((float) $c[6] + (float) $c[8]) * $sx, ((float) $c[7] + (float) $c[9]) * $sy], 'method' => 'ocr', 'confidence' => (float) $c[10]];
                        }
                        $page['warnings'][] = 'OCR verwendet; alle Zuordnungen müssen geprüft werden.';
                    } catch (\Throwable $e) {
                        $page['words'] = $page['nativeWords'];
                        $page['warnings'][] = 'OCR fehlgeschlagen: ' . $e->getMessage();
                    }
                }
                if (!$page['words']) { $page['warnings'][] = 'Keine lesbaren Wörter.'; }
                $pages[] = $page;
            }
            return $pages;
        } finally {
            foreach (glob($dir . '/*') as $file) { unlink($file); }
            rmdir($dir);
        }
    }
}
