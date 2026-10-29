<?php
/** @var array<string,mixed> $values */
/** @var array<string,string> $errors */
/** @var callable $e */
/** @var callable $v */

$field = static function (string $name, string $label, string $type = 'text', array $attrs = [], string $class = '') use ($values, $errors, $e, $v): string {
    $path = str_replace(['][', '[', ']'], ['.', '.', ''], $name);
    $err = $errors[$path] ?? null;
    $extra = '';
    foreach ($attrs as $k => $val) {
        $extra .= ' ' . $k . '="' . $e($val) . '"';
    }
    $html = '<div class="field ' . $class . ($err ? ' error' : '') . '"><label for="f-' . $e($path) . '">' . $e($label) . '</label>';
    $html .= '<input id="f-' . $e($path) . '" type="' . $e($type) . '" name="' . $e($name) . '" value="' . $v($values, $path) . '"' . $extra . '>';
    if ($err) {
        $html .= '<div class="msg">' . $e($err) . '</div>';
    }

    return $html . '</div>';
};

$lines = is_array($values['lines'] ?? null) && $values['lines'] !== [] ? array_values($values['lines']) : [[]];
$profile = (string) ($values['profile'] ?? 'EN16931');
$units = ['H87' => 'Stück', 'HUR' => 'Stunde', 'DAY' => 'Tag', 'C62' => 'Einheit', 'KGM' => 'kg', 'MTR' => 'm', 'MTK' => 'm²', 'LTR' => 'Liter', 'LS' => 'Pauschale', 'MON' => 'Monat'];
$categories = ['S' => 'S – Regelbesteuerung', 'Z' => 'Z – Nullsatz', 'E' => 'E – steuerbefreit', 'AE' => 'AE – Reverse Charge', 'K' => 'K – innergemeinschaftlich', 'G' => 'G – Ausfuhr', 'O' => 'O – nicht steuerbar'];
?>

<?php if ($errors !== []): ?>
<div class="errors">
  <strong>Die Rechnung konnte nicht erstellt werden. Bitte korrigieren:</strong>
  <ul>
    <?php foreach ($errors as $k => $msg): ?>
      <li><code><?= $e($k) ?></code> <?= $e($msg) ?></li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<form method="post" action="/convert" enctype="multipart/form-data" id="invoice-form">
<div class="card"><a href="/extract">PDF-Rechnung lokal auslesen und Erkennung prüfen</a></div>

<div class="card">
  <h2>1. Original-Rechnung (PDF) hochladen</h2>
  <div class="grid">
    <div class="field <?= isset($errors['pdf']) ? 'error' : '' ?>">
      <label for="f-pdf">PDF-Datei (wird als Sichtkomponente in die E-Rechnung eingebettet)</label>
      <input id="f-pdf" type="file" name="pdf" accept="application/pdf,.pdf">
      <?php if (isset($errors['pdf'])): ?><div class="msg"><?= $e($errors['pdf']) ?></div><?php endif; ?>
    </div>
    <div class="field">
      <label for="f-profile">ZUGFeRD-Profil</label>
      <select id="f-profile" name="profile">
        <?php foreach (['EN16931' => 'EN 16931 (COMFORT) – Standard', 'BASIC' => 'BASIC', 'EXTENDED' => 'EXTENDED', 'XRECHNUNG' => 'XRechnung 3.0 (öffentliche Auftraggeber)', 'MINIMUM' => 'MINIMUM (nicht als E-Rechnung gültig)'] as $val => $label): ?>
          <option value="<?= $val ?>" <?= $profile === $val ? 'selected' : '' ?>><?= $e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>
  <p class="hint">Alternativ können die Rechnungsdaten als JSON eingefügt werden – das Formular wird dann ausgefüllt.
    <details><summary>JSON einfügen</summary>
      <textarea id="json-import" rows="6" placeholder='{"invoice":{"number":"RE-1","date":"2026-09-18"},"seller":{…},"buyer":{…},"lines":[…]}'></textarea>
      <button type="button" class="btn secondary small" id="json-import-btn" style="margin-top:6px">Formular aus JSON füllen</button>
    </details>
  </p>
</div>

<div class="card">
  <h2>2. Rechnungskopf</h2>
  <div class="grid">
    <?= $field('invoice[number]', 'Rechnungsnummer *', 'text', ['required' => 'required']) ?>
    <?= $field('invoice[date]', 'Rechnungsdatum *', 'date', ['required' => 'required']) ?>
    <div class="field">
      <label for="f-invoice.typeCode">Dokumenttyp</label>
      <select id="f-invoice.typeCode" name="invoice[typeCode]">
        <?php foreach (['380' => '380 – Rechnung', '381' => '381 – Gutschrift', '384' => '384 – Rechnungskorrektur', '389' => '389 – Selbstfakturierte Rechnung'] as $val => $label): ?>
          <option value="<?= $val ?>" <?= ($values['invoice']['typeCode'] ?? '380') === $val ? 'selected' : '' ?>><?= $e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?= $field('invoice[currency]', 'Währung', 'text', ['maxlength' => '3']) ?>
    <?= $field('invoice[deliveryDate]', 'Leistungs-/Lieferdatum', 'date') ?>
    <?= $field('invoice[dueDate]', 'Fälligkeitsdatum', 'date') ?>
    <?= $field('invoice[orderNumber]', 'Bestellnummer des Käufers') ?>
    <?= $field('invoice[buyerReference]', 'Käuferreferenz / Leitweg-ID' . ($profile === 'XRECHNUNG' ? ' *' : '')) ?>
    <?= $field('invoice[paymentReference]', 'Verwendungszweck') ?>
    <?= $field('invoice[paymentTerms]', 'Zahlungsbedingungen', 'text', [], 'wide') ?>
    <?= $field('invoice[notes]', 'Bemerkung', 'text', [], 'wide') ?>
  </div>
</div>

<div class="card">
  <h2>3. Verkäufer (Rechnungssteller)</h2>
  <div class="grid">
    <?= $field('seller[name]', 'Firma / Name *', 'text', ['required' => 'required'], 'wide') ?>
    <?= $field('seller[street]', 'Straße und Hausnummer *') ?>
    <?= $field('seller[street2]', 'Adresszusatz') ?>
    <?= $field('seller[zip]', 'PLZ *') ?>
    <?= $field('seller[city]', 'Ort *') ?>
    <?= $field('seller[country]', 'Land (ISO) *', 'text', ['maxlength' => '2']) ?>
    <?= $field('seller[vatId]', 'USt-IdNr.') ?>
    <?= $field('seller[taxNumber]', 'Steuernummer') ?>
    <?= $field('seller[contact]', 'Ansprechpartner') ?>
    <?= $field('seller[email]', 'E-Mail', 'email') ?>
    <?= $field('seller[phone]', 'Telefon') ?>
    <?= $field('seller[iban]', 'IBAN') ?>
    <?= $field('seller[bic]', 'BIC') ?>
    <?= $field('seller[accountHolder]', 'Kontoinhaber') ?>
  </div>
</div>

<div class="card">
  <h2>4. Käufer (Rechnungsempfänger)</h2>
  <div class="grid">
    <?= $field('buyer[name]', 'Firma / Name *', 'text', ['required' => 'required'], 'wide') ?>
    <?= $field('buyer[street]', 'Straße und Hausnummer') ?>
    <?= $field('buyer[street2]', 'Adresszusatz') ?>
    <?= $field('buyer[zip]', 'PLZ *') ?>
    <?= $field('buyer[city]', 'Ort *') ?>
    <?= $field('buyer[country]', 'Land (ISO) *', 'text', ['maxlength' => '2']) ?>
    <?= $field('buyer[vatId]', 'USt-IdNr.') ?>
    <?= $field('buyer[id]', 'Kundennummer') ?>
    <?= $field('buyer[contact]', 'Ansprechpartner') ?>
    <?= $field('buyer[email]', 'E-Mail', 'email') ?>
  </div>
</div>

<div class="card">
  <h2>5. Positionen</h2>
  <table id="lines">
    <thead>
      <tr>
        <th style="width:26%">Bezeichnung *</th>
        <th>Artikel-Nr.</th>
        <th style="width:9%">Menge *</th>
        <th style="width:11%">Einheit</th>
        <th style="width:12%">Einzelpreis netto *</th>
        <th style="width:8%">USt %</th>
        <th style="width:15%">Kategorie</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($lines as $i => $line): ?>
      <tr class="line">
        <td><input name="lines[<?= $i ?>][description]" value="<?= $e($line['description'] ?? '') ?>" placeholder="Leistung / Artikel"></td>
        <td><input name="lines[<?= $i ?>][articleNumber]" value="<?= $e($line['articleNumber'] ?? '') ?>"></td>
        <td><input name="lines[<?= $i ?>][quantity]" value="<?= $e($line['quantity'] ?? '1') ?>" inputmode="decimal" class="qty"></td>
        <td><select name="lines[<?= $i ?>][unit]">
          <?php foreach ($units as $code => $label): ?>
            <option value="<?= $code ?>" <?= ($line['unit'] ?? 'H87') === $code ? 'selected' : '' ?>><?= $e($label) ?></option>
          <?php endforeach; ?>
        </select></td>
        <td><input name="lines[<?= $i ?>][unitPrice]" value="<?= $e($line['unitPrice'] ?? '') ?>" inputmode="decimal" class="price"></td>
        <td><input name="lines[<?= $i ?>][taxRate]" value="<?= $e($line['taxRate'] ?? '19') ?>" inputmode="decimal" class="rate"></td>
        <td><select name="lines[<?= $i ?>][taxCategory]" class="cat">
          <?php foreach ($categories as $code => $label): ?>
            <option value="<?= $code ?>" <?= ($line['taxCategory'] ?? 'S') === $code ? 'selected' : '' ?>><?= $e($label) ?></option>
          <?php endforeach; ?>
        </select></td>
        <td><button type="button" class="btn secondary small remove" title="Position entfernen">✕</button></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <div class="actions" style="margin-top:10px">
    <button type="button" class="btn secondary small" id="add-line">+ Position</button>
    <span class="hint">Fehler in Positionen werden oben als <code>lines.N.feld</code> gemeldet.</span>
  </div>
  <div class="totals" id="totals"></div>
</div>

<div class="card">
  <h2>6. E-Rechnung erzeugen</h2>
  <div class="actions">
    <button class="btn" type="submit" name="output" value="pdf">ZUGFeRD-PDF herunterladen</button>
    <button class="btn secondary" type="submit" name="output" value="xml">Nur XML herunterladen</button>
    <span class="hint">Beträge werden aus den Positionen berechnet (netto × Menge, USt je Steuersatz).</span>
  </div>
</div>

</form>

<script>
(function () {
  const tbody = document.querySelector('#lines tbody');
  const totalsEl = document.getElementById('totals');
  const fmt = new Intl.NumberFormat('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const num = s => { s = String(s ?? '').trim(); if (!s) return 0; if (/^-?\d{1,3}(\.\d{3})*(,\d+)?$/.test(s) || (s.includes(',') && !s.includes('.'))) s = s.replace(/\./g, '').replace(',', '.'); const n = parseFloat(s); return isNaN(n) ? 0 : n; };

  function renumber() {
    tbody.querySelectorAll('tr.line').forEach((tr, i) => {
      tr.querySelectorAll('[name]').forEach(el => el.name = el.name.replace(/lines\[\d+\]/, 'lines[' + i + ']'));
    });
  }
  function totals() {
    let net = 0; const tax = {};
    tbody.querySelectorAll('tr.line').forEach(tr => {
      const n = Math.round(num(tr.querySelector('.qty').value) * num(tr.querySelector('.price').value) * 100) / 100;
      const r = num(tr.querySelector('.rate').value);
      net += n; tax[r] = (tax[r] || 0) + n;
    });
    let taxSum = 0, html = 'Netto: ' + fmt.format(net);
    Object.keys(tax).sort((a, b) => b - a).forEach(r => { const t = Math.round(tax[r] * r) / 100; taxSum += t; html += ' &nbsp;·&nbsp; USt ' + r + ' %: ' + fmt.format(t); });
    html += ' &nbsp;·&nbsp; <b>Brutto: ' + fmt.format(net + taxSum) + '</b>';
    totalsEl.innerHTML = html;
  }
  function addLine(data) {
    const tpl = tbody.querySelector('tr.line');
    const tr = tpl.cloneNode(true);
    tr.querySelectorAll('input').forEach(el => el.value = '');
    tr.querySelector('.qty').value = '1'; tr.querySelector('.rate').value = '19';
    tr.querySelectorAll('select').forEach(el => el.selectedIndex = 0);
    tbody.appendChild(tr);
    if (data) fill(tr, data);
    renumber(); totals();
    return tr;
  }
  function fill(tr, d) {
    const map = { description: 'description', articleNumber: 'articleNumber', quantity: 'quantity', unit: 'unit', unitPrice: 'unitPrice', taxRate: 'taxRate', taxCategory: 'taxCategory' };
    for (const k in map) { const el = tr.querySelector('[name$="[' + map[k] + ']"]'); if (el && d[k] !== undefined) el.value = d[k]; }
  }
  tbody.addEventListener('click', e => {
    if (e.target.classList.contains('remove')) {
      if (tbody.querySelectorAll('tr.line').length > 1) e.target.closest('tr').remove();
      else e.target.closest('tr').querySelectorAll('input').forEach(el => el.value = '');
      renumber(); totals();
    }
  });
  tbody.addEventListener('input', totals);
  tbody.addEventListener('change', e => {
    if (e.target.classList.contains('cat')) {
      const rate = e.target.closest('tr').querySelector('.rate');
      if (e.target.value !== 'S') rate.value = '0'; else if (num(rate.value) === 0) rate.value = '19';
      totals();
    }
  });
  document.getElementById('add-line').addEventListener('click', () => addLine());

  document.getElementById('json-import-btn').addEventListener('click', () => {
    let data;
    try { data = JSON.parse(document.getElementById('json-import').value); } catch (err) { alert('Ungültiges JSON: ' + err.message); return; }
    const set = (name, val) => { const el = document.querySelector('[name="' + name + '"]'); if (el && val !== undefined && val !== null) el.value = Array.isArray(val) ? val.join(' ') : val; };
    if (data.profile) set('profile', String(data.profile).toUpperCase());
    for (const grp of ['invoice', 'seller', 'buyer']) if (data[grp]) for (const k in data[grp]) set(grp + '[' + k + ']', data[grp][k]);
    if (Array.isArray(data.lines) && data.lines.length) {
      tbody.querySelectorAll('tr.line').forEach((tr, i) => i > 0 && tr.remove());
      fill(tbody.querySelector('tr.line'), data.lines[0]);
      data.lines.slice(1).forEach(l => addLine(l));
      renumber();
    }
    totals();
  });

  totals();
})();
</script>
