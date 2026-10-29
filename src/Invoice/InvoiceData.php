<?php

declare(strict_types=1);

namespace App\Invoice;

use App\Invoice\Exception\ValidationException;
use DateTimeImmutable;

/**
 * Normalisierte Rechnungsdaten. Wird aus JSON (API) oder Formulardaten (Web) erzeugt.
 *
 * Eingabe-Struktur (alle Beträge netto, Zahlen als float/int oder numerischer String):
 *
 * {
 *   "profile": "EN16931",                       // optional: MINIMUM|BASIC|EN16931|EXTENDED|XRECHNUNG
 *   "invoice": {
 *     "number": "RE-2026-0001",                 // Pflicht  (BT-1)
 *     "typeCode": "380",                        // optional (BT-3), 380=Rechnung, 381=Gutschrift
 *     "date": "2026-09-18",                     // Pflicht  (BT-2)
 *     "currency": "EUR",                        // optional (BT-5)
 *     "dueDate": "2026-10-18",                  // optional (BT-9)
 *     "deliveryDate": "2026-09-15",             // optional (BT-72)
 *     "buyerReference": "…",                    // optional (BT-10, Pflicht bei XRechnung)
 *     "orderNumber": "…",                       // optional (BT-13)
 *     "paymentTerms": "Zahlbar innerhalb 30 Tagen", // optional (BT-20)
 *     "paymentReference": "…",                  // optional (BT-83)
 *     "notes": ["…"]                            // optional (BT-22)
 *   },
 *   "seller": { … Party … , "vatId": "DE123456789", "taxNumber": "…", "iban": "…", "bic": "…", "accountHolder": "…" },
 *   "buyer":  { … Party … },
 *   "lines": [
 *     { "description": "…", "quantity": 1, "unit": "H87", "unitPrice": 100.0,
 *       "taxRate": 19, "taxCategory": "S", "articleNumber": "…", "note": "…" }
 *   ]
 * }
 *
 * Party: { "name", "street", "street2", "zip", "city", "country": "DE", "email", "phone", "contact", "id" }
 */
final class InvoiceData
{
    public const PROFILES = ['MINIMUM', 'BASIC', 'EN16931', 'EXTENDED', 'XRECHNUNG'];

    /** Steuerkategorien nach UNTDID 5305, die ohne Befreiungsgrund auskommen. */
    private const TAX_CATEGORIES_WITH_RATE = ['S'];
    /** Kategorien mit Satz 0 und Befreiungsgrund. */
    private const TAX_CATEGORIES_EXEMPT = ['Z', 'E', 'AE', 'K', 'G', 'O', 'L', 'M'];

    /** @var string */
    public $profile = 'EN16931';

    // invoice
    /** @var string */
    public $number;
    /** @var string */
    public $typeCode = '380';
    /** @var DateTimeImmutable */
    public $date;
    /** @var string */
    public $currency = 'EUR';
    /** @var DateTimeImmutable|null */
    public $dueDate = null;
    /** @var DateTimeImmutable|null */
    public $deliveryDate = null;
    /** @var string|null */
    public $buyerReference = null;
    /** @var string|null */
    public $orderNumber = null;
    /** @var string|null */
    public $paymentTerms = null;
    /** @var string|null */
    public $paymentReference = null;
    /** @var string[] */
    public $notes = [];

    /** @var Party */
    public $seller;
    /** @var Party */
    public $buyer;

    /** @var InvoiceLine[] */
    public $lines = [];

    /**
     * @param array<string,mixed> $input
     *
     * @throws ValidationException
     */
    public static function fromArray(array $input): self
    {
        $errors = [];
        $self = new self();

        $profile = strtoupper(trim((string) ($input['profile'] ?? 'EN16931')));
        if (!in_array($profile, self::PROFILES, true)) {
            $errors['profile'] = 'Unbekanntes Profil. Erlaubt: ' . implode(', ', self::PROFILES);
        }
        $self->profile = $profile;

        $inv = is_array($input['invoice'] ?? null) ? $input['invoice'] : [];

        $self->number = trim((string) ($inv['number'] ?? ''));
        if ($self->number === '') {
            $errors['invoice.number'] = 'Rechnungsnummer ist Pflicht.';
        }

        $self->typeCode = trim((string) ($inv['typeCode'] ?? '380')) ?: '380';
        if (!preg_match('/^\d{3}$/', $self->typeCode)) {
            $errors['invoice.typeCode'] = 'Rechnungstyp muss ein dreistelliger UNTDID-1001-Code sein (z. B. 380).';
        }

        $date = self::parseDate($inv['date'] ?? null);
        if ($date === null) {
            $errors['invoice.date'] = 'Rechnungsdatum fehlt oder ist ungültig (YYYY-MM-DD).';
        } else {
            $self->date = $date;
        }

        $self->currency = strtoupper(trim((string) ($inv['currency'] ?? 'EUR'))) ?: 'EUR';
        if (!preg_match('/^[A-Z]{3}$/', $self->currency)) {
            $errors['invoice.currency'] = 'Währung muss ein ISO-4217-Code sein (z. B. EUR).';
        }

        foreach (['dueDate', 'deliveryDate'] as $field) {
            $raw = $inv[$field] ?? null;
            if ($raw !== null && $raw !== '') {
                $parsed = self::parseDate($raw);
                if ($parsed === null) {
                    $errors["invoice.$field"] = 'Ungültiges Datum (YYYY-MM-DD).';
                } else {
                    $self->$field = $parsed;
                }
            }
        }

        $self->buyerReference = self::optString($inv['buyerReference'] ?? null);
        $self->orderNumber = self::optString($inv['orderNumber'] ?? null);
        $self->paymentTerms = self::optString($inv['paymentTerms'] ?? null);
        $self->paymentReference = self::optString($inv['paymentReference'] ?? null);

        $notes = $inv['notes'] ?? [];
        if (is_string($notes)) {
            $notes = [$notes];
        }
        if (is_array($notes)) {
            $self->notes = array_values(array_filter(array_map(function ($n) {
                return trim((string) $n);
            }, $notes), function ($n) {
                return $n !== '';
            }));
        }

        if ($profile === 'XRECHNUNG' && $self->buyerReference === null) {
            $errors['invoice.buyerReference'] = 'Bei XRechnung ist die Leitweg-ID / Käuferreferenz Pflicht.';
        }

        $self->seller = Party::fromArray(is_array($input['seller'] ?? null) ? $input['seller'] : [], 'seller', $errors, true);
        $self->buyer = Party::fromArray(is_array($input['buyer'] ?? null) ? $input['buyer'] : [], 'buyer', $errors, false);

        if ($self->seller->vatId === null && $self->seller->taxNumber === null) {
            $errors['seller.vatId'] = 'Verkäufer benötigt USt-IdNr. oder Steuernummer.';
        }

        $lines = $input['lines'] ?? [];
        if (!is_array($lines) || $lines === []) {
            $errors['lines'] = 'Mindestens eine Rechnungsposition ist erforderlich.';
        } else {
            $i = 0;
            foreach ($lines as $raw) {
                ++$i;
                if (!is_array($raw)) {
                    $errors["lines.$i"] = 'Position muss ein Objekt sein.';
                    continue;
                }
                $self->lines[] = InvoiceLine::fromArray($raw, $i, $errors);
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return $self;
    }

    public static function isExemptCategory(string $category): bool
    {
        return in_array($category, self::TAX_CATEGORIES_EXEMPT, true);
    }

    public static function isRatedCategory(string $category): bool
    {
        return in_array($category, self::TAX_CATEGORIES_WITH_RATE, true);
    }

    /** @param mixed $raw */
    public static function parseDate($raw): ?DateTimeImmutable
    {
        if ($raw instanceof DateTimeImmutable) {
            return $raw;
        }
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }
        foreach (['Y-m-d', 'd.m.Y', 'Ymd', DATE_ATOM] as $format) {
            $d = DateTimeImmutable::createFromFormat('!' . $format, $raw) ?: DateTimeImmutable::createFromFormat($format, $raw);
            if ($d instanceof DateTimeImmutable && $d->format($format === DATE_ATOM ? DATE_ATOM : $format) === $raw) {
                return $d->setTime(0, 0);
            }
        }

        return null;
    }

    /** @param mixed $value */
    public static function optString($value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * Summen aus den Positionen berechnen (kaufmännisch auf 2 Stellen gerundet).
     *
     * @return array{lineTotal: float, taxTotal: float, grandTotal: float, taxes: array<string, array{category: string, rate: float, basis: float, amount: float, exemptionReason: ?string}>}
     */
    public function totals(): array
    {
        $lineTotal = 0.0;
        $taxes = [];

        foreach ($this->lines as $line) {
            $net = $line->netAmount();
            $lineTotal += $net;

            $key = $line->taxCategory . '|' . number_format($line->taxRate, 2, '.', '');
            if (!isset($taxes[$key])) {
                $taxes[$key] = [
                    'category' => $line->taxCategory,
                    'rate' => $line->taxRate,
                    'basis' => 0.0,
                    'amount' => 0.0,
                    'exemptionReason' => $line->taxExemptionReason,
                ];
            }
            $taxes[$key]['basis'] += $net;
        }

        $taxTotal = 0.0;
        foreach ($taxes as &$t) {
            $t['basis'] = round($t['basis'], 2);
            $t['amount'] = round($t['basis'] * $t['rate'] / 100, 2);
            $taxTotal += $t['amount'];
        }
        unset($t);

        $lineTotal = round($lineTotal, 2);
        $taxTotal = round($taxTotal, 2);

        return [
            'lineTotal' => $lineTotal,
            'taxTotal' => $taxTotal,
            'grandTotal' => round($lineTotal + $taxTotal, 2),
            'taxes' => $taxes,
        ];
    }
}
