# Lokaler Rechnungs-Extraktionsprototyp

## Nutzung

PHP 7.2.34 mit DOM, mbstring und BCMath; vorhandenes `vendor/` inklusive
Symfony Process und horstoeko/zugferd. Poppler (`pdftotext`, `pdftoppm`) und
Tesseract mit `deu` müssen für den PHP-Prozess verfügbar sein. Optional können
`PDFTOTEXT_BIN`, `PDFTOPPM_BIN`, `TESSERACT_BIN` als Prozess-Umgebungsvariablen
absolute Programmpfade enthalten. Es werden keine KI-Dienste angesprochen.

Webserver mit DocumentRoot `public/` betreiben. `/extract` öffnet den separaten
Upload mit Prüfbericht. Vorhandener Web-Zugriffsschutz gilt auch hier.
Der Bericht kann über den zweiten Upload-Button als JSON heruntergeladen werden.
Es gibt keine serverseitige Archivierung; temporäre PDFs und Bilder werden nach
der Verarbeitung entfernt. Für nachvollziehbare Aufbewahrung Original-PDF und
JSON zusammen speichern; `sourceSha256` verbindet den Bericht mit dem Original.

```powershell
php bin/extract.php rechnung.pdf
php bin/extract.php rechnung.pdf --ocr
php tests/extraction.php
php tests/extraction-layouts.php
php bin/inspect-corpus.php
php tests/corpus.php
```

CLI-Berichte gehen auf stdout, Fehler auf stderr. Bei Umleitung unter Windows
auf UTF-8 achten. Upload-Limits von PHP (`upload_max_filesize`, `post_max_size`)
müssen passend gesetzt sein. Prototyp-Limits: 20 MiB, 30 Seiten, 90 Sekunden pro
Werkzeugaufruf, Rasterbilder bis 3500 Pixel Kantenlänge.

## Codeprüfung und Aufbau

Vorher: `WebController::convert()` nimmt manuelle Formulardaten und PDF entgegen;
`InvoiceData::fromArray()` verlangt bereits vollständige Daten. Formularvorgaben
setzen u.a. Datum, EUR, Menge 1 und Steuer 19 %. Diese Vorgaben sind keine
Extraktionsergebnisse. `InvoiceData::totals()` rechnet aus den eingegebenen
Positionen, vergleicht aber nicht mit Originalbeträgen. Eine Extraktionsschicht,
Beispielrechnungen und Tests waren nicht vorhanden; auch eine Projekt-
`composer.json` fehlt, das vorhandene vendor-Autoloading enthält bereits `App\`.

Die Extraktion bleibt von `InvoiceData` getrennt, damit fehlende Werte nicht
durch dessen Standardwerte ersetzt werden und keine unbestätigten Daten direkt
in die ZUGFeRD-Ausgabe gelangen.

- `src/Extraction/PdfTextReader.php`: Poppler `-bbox-layout`, Wortkoordinaten,
  OCR pro Seite bei weniger als 40 Nicht-Leerzeichen oder mehreren Unicode-
  Ersatzzeichen; alternativ erzwungene OCR. Native Wörter bleiben erhalten.
- `src/Extraction/InvoiceExtractor.php`: räumliche Zeilengruppierung, konservative
  deutsche Feldmuster und ein erstes Positionsmuster, Kandidaten und Prüfungen.
- `templates/extraction.php`: sichtbare Kandidaten, Konflikte, Fundstellen,
  Positionen und Rechenprüfungen.

Koordinaten: PDF-Punkte, Ursprung links oben. Fundstellen von Werten umfassen
derzeit die gesamte Zeile (`locationPrecision: row`); die Wörter stehen separat
mit exakten Rechtecken im Bericht. Jeder Kandidat enthält normalisierten Wert,
Originaltext, Zeilenkontext, Seite, Rechteck, Methode, ggf. OCR-Konfidenz und
Prüfstatus. OCR-Konfidenz ist keine Wahrscheinlichkeit einer korrekten Zuordnung.
`needs_review` wird niemals automatisch zu einer fachlichen Bestätigung.
Widersprüche behalten alle Kandidaten; der eindeutige Wert bleibt dann `null`.

Geldberechnungen verwenden BCMath-Dezimalstrings und kaufmännische Rundung.
Geprüft werden Menge × Einzelpreis, erkannte Positionssumme gegen Netto,
Steuergruppen aus erkannten Positionen gegen ausgewiesene Steuer sowie
Netto + Steuer gegen Brutto. Fehlende oder widersprüchliche Operanden ergeben
`not_checkable`. Ein `passed` bestätigt ausschließlich diese Gleichung.

## Testumfang und Grenzen

`php tests/extraction.php` erzeugt synthetische PDFs in einem temporären Verzeichnis und
prüft digitale Erkennung, Raster-Scan mit tatsächlicher deutscher OCR,
Feldprovenienz, fehlende Werte, deutsche Zahlen, widersprüchliche Nummern,
gemischte Steuerzeilen und falsche Bruttosummen. Die Scanvorlage wurde visuell
geprüft. `tests/extraction-layouts.php` enthält anonymisierte Regressionstests
für beobachtete Layoutmuster, ohne echte Rechnungsinhalte im Quellcode.

## Lokales Beispielkorpus

Zehn echte PDFs (zwölf Seiten, davon vier OCR-Seiten) wurden lokal ausgewertet:
neun Rechnungsdokumente und ein Anschreiben. Die Originale bleiben unverändert
im bereits ignorierten Verzeichnis `Beispielrechnungen/`. Das gilt auch für
abgeleitete Texte, Berichte, Bilder und das lokale Testmanifest `expected.json`.
Die Änderung an `.gitignore` stammt vom Benutzer.

`php bin/inspect-corpus.php` verarbeitet alle PDFs in diesem Verzeichnis und
schreibt `extraction-reports/summary.md`, `summary.json` und je PDF einen
vollständigen JSON-Bericht sowie einen diagnostischen Textauszug. Es werden
auch personenbezogene Rechnungsinhalte ausgegeben; die Dateien bleiben lokal.
Ein abweichendes Korpusverzeichnis kann als erstes Argument übergeben werden.

`php tests/corpus.php` prüft die aktuellen PDFs gegen das lokale, separat
festgelegte `expected.json`. SHA-256 verhindert die versehentliche Anwendung
alter Erwartungen auf geänderte Originale. Ohne privates Korpus endet dieser
optionale Test mit Exitcode 2; die anonymisierten Tests sind unabhängig davon.
Neue PDF-Dateien werden im Analysewerkzeug erfasst, sind aber erst nach
Ergänzung der Erwartungen Teil dieses Regressionstests. Erwartete Werte nicht
ungeprüft aus der Extraktion übernehmen.

Aktueller Stand dieses kleinen Korpus: acht eindeutige Nummernvorschläge bei
neun Rechnungsdokumenten; eine PDF mit Hauptrechnung und gesonderter Laboranlage
bleibt wegen zweier Rechnungsnummern widersprüchlich. Alle neun Rechnungsdaten
werden vorgeschlagen; das Anschreiben bleibt ohne Nummer/Datum als
`unclassified` gekennzeichnet. Bei fünf Dokumenten ist Netto + Steuer = Brutto
anhand erkannter Originalwerte prüfbar und stimmig. Dies ist keine Aussage zur
allgemeinen Erkennungsquote. Zwölf unvollständige Positionskandidaten werden
zusätzlich angezeigt; vollständige Positionen im ursprünglichen Format werden
bei diesen PDFs noch nicht erkannt.

Berichtsschema Version 2 ergänzt `documentStatus` und `lineCandidates`.
Positionskandidaten werden nur bei passenden Tabellenüberschriften erkannt:
Menge/Artikel/Text/Einzelpreis, Ticketpreis/Menge, Leistung/Zeitraum/Steuersatz,
nummerierte Nettoleistungen sowie Artikel/Betrag. Sie enthalten ausschließlich
belegte Teilfelder, fehlende Angaben und die Tabellenüberschrift als Fundstelle.
Nur eine explizite Nettoüberschrift setzt `amountBasis: net`; ansonsten bleibt
die Preisbasis `unknown`. Folgezeilen können noch zur Beschreibung gehören.
Diese Kandidaten fließen nicht in die Prüfungen vollständiger Positionen ein.

Unterstützte Positionszeile:
`1 Beratung 2 Std 100,00 19 % 200,00`.
Mehrzeilige Beschreibungen, andere Spaltenreihenfolgen, Artikelnummern,
Rabatte, Zuschläge, Anzahlungen, Steuerbefreiungen und Adressrollen werden noch
nicht verlässlich erkannt. Verkäufer-/Käuferrollen werden nicht geraten.
Datumsfelder benötigen `TT.MM.JJJJ`. Neben Rechnungs-/Belegdatum wird ein
eigenständiges `Datum` im oberen Seitenbereich mit Rechnungsmerkmalen als
Vorschlag berücksichtigt; ein Geburtstag oder freistehendes Anschreibendatum
wird nicht übernommen. `Nummer: ... vom ...` wird nur direkt nach der
Überschrift `Rechnung` verwendet. Rechnungsnummern müssen mindestens eine
Ziffer enthalten; rein alphabetische Nummern werden aktuell nicht erkannt.
Nummerngruppen mit Leerzeichen bleiben erhalten. `€` wird als EUR normalisiert.
IBAN-Kandidaten werden normalisiert und auf MOD-97, Format und bei deutschen
IBANs auf Länge geprüft. Maskierte Konten bleiben ungültig; Kontoinhaber und
Zahlungsrichtung werden dadurch nicht festgestellt.
Zahlenmuster sind auf deutsche Komma-Dezimalzahlen ausgelegt.
Mehrere unterschiedliche ausgewiesene Steuerbeträge werden derzeit als Konflikt
behandelt; eine vollständige beleggestützte Steueraufstellung ist noch offen.
Zeilenrechtecke können bei mehrspaltigen Layouts fremde Wörter zusammenführen.

Die OCR-Heuristik erkennt keine beliebig beschädigten Textebenen und keine
eingescannten Teilbereiche auf ansonsten textreichen Seiten. Dafür gibt es
`--ocr` bzw. die Web-Checkbox. OCR-Fehler, auch bei Zahlen, bleiben möglich.
Kein interaktives Korrigieren/Bestätigen mit Audit-Historie und keine automatische
Formularübernahme in dieser ersten Version.

Nächster fachlicher Schritt: anhand des Korpus mehrzeilige Tabellenpositionen,
Brutto-/Nettopreisbasis, Rabatte und Dokumentanlagen zuverlässig auflösen;
anschließend Steueraufstellungen und Rollen-/Adressblöcke ausbauen.
