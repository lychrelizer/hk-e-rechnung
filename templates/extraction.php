<div class="card">
  <h2>PDF-Datenextraktion – Prototyp</h2>
  <p>Lokale Verarbeitung mit Poppler und Tesseract. Fehlende Werte bleiben leer. Vorschläge werden erst nach fachlicher Prüfung zu Rechnungsdaten.</p>
  <form method="post" enctype="multipart/form-data" action="/extract">
    <label for="pdf">Originalrechnung (PDF, maximal 20 MiB und 30 Seiten)</label>
    <input id="pdf" name="pdf" type="file" accept="application/pdf" required>
    <p><label><input style="width:auto" type="checkbox" name="forceOcr"> OCR für alle Seiten erzwingen (auch bei fehlerhafter Textebene)</label></p>
    <button class="btn">Auslesen und prüfen</button>
    <button class="btn secondary" name="download" value="1">Prüfbericht als JSON speichern</button>
  </form>
  <p><a href="/">Zum Rechnungsformular</a></p>
</div>
<?php if ($error): ?><div class="errors"><?= $e($error) ?></div><?php endif; ?>
<?php if ($report): ?>
<div class="card"><h2>Hinweise</h2><ul><?php foreach ($report['warnings'] as $warning): ?><li><?= $e($warning) ?></li><?php endforeach; ?></ul></div>
<div class="card" style="overflow:auto"><h2>Felder und Fundstellen</h2>
<p>needs_review = zu prüfen · missing = fehlt · conflict = widersprüchlich · invalid = ungültig. Fundstellen sind Zeilenrechtecke in PDF-Punkten ab links oben.</p>
<table><thead><tr><th>Feld</th><th>Wert / Status</th><th>Originaltext und Fundstelle</th></tr></thead><tbody>
<?php foreach ($report['fields'] as $key => $field): ?>
<tr><td><?= $e($key) ?></td><td><?= $e($field['value']) ?><br><strong><?= $e($field['status']) ?></strong></td><td>
<?php foreach ($field['candidates'] as $candidate): ?>
<p><?= $e($candidate['originalText']) ?><br>Seite <?= $e($candidate['page']) ?> · <?= $e($candidate['method']) ?> · <?= $e($candidate['status']) ?> · <?= $e(json_encode($candidate['box'])) ?><br><?= $e($candidate['context']) ?></p>
<?php endforeach; ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<div class="card"><h2>Rechnerische Prüfungen</h2><p>passed = rechnerisch stimmig, failed = Abweichung, not_checkable = nicht prüfbar. Eine passende Summe bestätigt keine vollständige Erkennung.</p>
<?php foreach ($report['checks'] as $check): ?><p><code><?= $e($check['name']) ?></code> <strong><?= $e($check['status']) ?></strong> Soll <?= $e($check['expected'] ?? '') ?> / Ist <?= $e($check['actual'] ?? '') ?></p><?php endforeach; ?></div>
<div class="card"><h2>Erkannte Positionen</h2>
<?php if (!$report['lines']): ?><p>Keine Positionen sicher im unterstützten Tabellenformat zugeordnet.</p><?php endif; ?>
<?php foreach ($report['lines'] as $line): ?><pre style="white-space:pre-wrap"><?= $e(json_encode($line, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre><?php endforeach; ?></div>
<div class="card"><details><summary>Vollständiger Prüfbericht mit Wortkoordinaten</summary><pre style="white-space:pre-wrap"><?= $e(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre></details></div>
<?php endif; ?>
