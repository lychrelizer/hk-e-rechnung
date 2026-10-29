<?php

declare(strict_types=1);

namespace App\Invoice;

final class InvoiceLine
{
    /** @var string */
    public $id = '1';
    /** @var string */
    public $description = '';
    /** @var string|null */
    public $note = null;
    /** @var string|null */
    public $articleNumber = null;
    /** @var float */
    public $quantity = 1.0;
    /** @var string UN/ECE Rec. 20 Einheit, z. B. H87 = Stück, HUR = Stunde, DAY = Tag, KGM = Kilogramm, MTR = Meter, C62 = Einheit. */
    public $unit = 'H87';
    /** @var float */
    public $unitPrice = 0.0;
    /** @var float */
    public $taxRate = 19.0;
    /** @var string UNTDID 5305: S=Standard, Z=Nullsatz, E=steuerbefreit, AE=Reverse Charge, K=innergemeinschaftlich, G=Export, O=nicht steuerbar. */
    public $taxCategory = 'S';
    /** @var string|null */
    public $taxExemptionReason = null;

    /**
     * @param array<string,mixed>  $input
     * @param array<string,string> $errors
     */
    public static function fromArray(array $input, int $index, array &$errors): self
    {
        $prefix = "lines.$index";
        $l = new self();
        $l->id = (string) (InvoiceData::optString($input['id'] ?? null) ?? $index);

        $l->description = trim((string) ($input['description'] ?? ''));
        if ($l->description === '') {
            $errors["$prefix.description"] = 'Bezeichnung ist Pflicht.';
        }
        $l->note = InvoiceData::optString($input['note'] ?? null);
        $l->articleNumber = InvoiceData::optString($input['articleNumber'] ?? null);

        $qty = self::num($input['quantity'] ?? 1);
        if ($qty === null || $qty == 0.0) {
            $errors["$prefix.quantity"] = 'Menge muss eine Zahl ungleich 0 sein.';
        } else {
            $l->quantity = $qty;
        }

        $unit = strtoupper((string) (InvoiceData::optString($input['unit'] ?? null) ?? 'H87'));
        if (!preg_match('/^[A-Z0-9]{2,3}$/', $unit)) {
            $errors["$prefix.unit"] = 'Einheit muss ein UN/ECE-Rec.-20-Code sein (z. B. H87, HUR, DAY).';
        }
        $l->unit = $unit;

        $price = self::num($input['unitPrice'] ?? null);
        if ($price === null) {
            $errors["$prefix.unitPrice"] = 'Einzelpreis (netto) fehlt oder ist keine Zahl.';
        } else {
            $l->unitPrice = $price;
        }

        $category = strtoupper((string) (InvoiceData::optString($input['taxCategory'] ?? null) ?? 'S'));
        if (!InvoiceData::isRatedCategory($category) && !InvoiceData::isExemptCategory($category)) {
            $errors["$prefix.taxCategory"] = 'Unbekannte Steuerkategorie (S, Z, E, AE, K, G, O).';
        }
        $l->taxCategory = $category;

        $rate = self::num($input['taxRate'] ?? ($category === 'S' ? 19 : 0));
        if ($rate === null || $rate < 0 || $rate > 100) {
            $errors["$prefix.taxRate"] = 'Steuersatz muss zwischen 0 und 100 liegen.';
        } else {
            $l->taxRate = $rate;
        }

        $l->taxExemptionReason = InvoiceData::optString($input['taxExemptionReason'] ?? null);

        if (InvoiceData::isExemptCategory($category)) {
            if ($l->taxRate != 0.0) {
                $errors["$prefix.taxRate"] = "Steuerkategorie $category erfordert Steuersatz 0.";
            }
            if ($l->taxExemptionReason === null && $category !== 'Z') {
                $l->taxExemptionReason = self::defaultExemptionReason($category);
            }
        }

        return $l;
    }

    public function netAmount(): float
    {
        return round($this->quantity * $this->unitPrice, 2);
    }

    /** @param mixed $v */
    private static function num($v): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_int($v) || is_float($v)) {
            return (float) $v;
        }
        $s = str_replace([' ', "\u{a0}"], '', (string) $v);
        // deutsches Format "1.234,56" -> "1234.56"
        if (preg_match('/^-?\d{1,3}(\.\d{3})*(,\d+)?$/', $s) || (strpos($s, ',') !== false && strpos($s, '.') === false)) {
            $s = str_replace('.', '', $s);
            $s = str_replace(',', '.', $s);
        }

        return is_numeric($s) ? (float) $s : null;
    }

    private static function defaultExemptionReason(string $category): string
    {
        switch ($category) {
            case 'E':
                return 'Steuerbefreit gemäß § 4 UStG';
            case 'AE':
                return 'Steuerschuldnerschaft des Leistungsempfängers (Reverse Charge, § 13b UStG)';
            case 'K':
                return 'Steuerfreie innergemeinschaftliche Lieferung (§ 4 Nr. 1b UStG)';
            case 'G':
                return 'Steuerfreie Ausfuhrlieferung (§ 4 Nr. 1a UStG)';
            case 'O':
                return 'Nicht im Anwendungsbereich der Umsatzsteuer';
            default:
                return 'Steuerbefreit';
        }
    }
}
