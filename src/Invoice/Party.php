<?php

declare(strict_types=1);

namespace App\Invoice;

final class Party
{
    /** @var string */
    public $name = '';
    /** @var string|null */
    public $id = null;
    /** @var string|null */
    public $street = null;
    /** @var string|null */
    public $street2 = null;
    /** @var string|null */
    public $zip = null;
    /** @var string|null */
    public $city = null;
    /** @var string */
    public $country = 'DE';
    /** @var string|null */
    public $email = null;
    /** @var string|null */
    public $phone = null;
    /** @var string|null */
    public $contact = null;
    /** @var string|null */
    public $vatId = null;
    /** @var string|null */
    public $taxNumber = null;
    /** @var string|null */
    public $iban = null;
    /** @var string|null */
    public $bic = null;
    /** @var string|null */
    public $accountHolder = null;

    /**
     * @param array<string,mixed>  $input
     * @param array<string,string> $errors
     */
    public static function fromArray(array $input, string $prefix, array &$errors, bool $isSeller): self
    {
        $p = new self();
        $p->name = trim((string) ($input['name'] ?? ''));
        if ($p->name === '') {
            $errors["$prefix.name"] = 'Name ist Pflicht.';
        }

        foreach (['id', 'street', 'street2', 'zip', 'city', 'email', 'phone', 'contact', 'taxNumber', 'bic', 'accountHolder'] as $f) {
            $p->$f = InvoiceData::optString($input[$f] ?? null);
        }

        $p->country = strtoupper((string) (InvoiceData::optString($input['country'] ?? null) ?? 'DE'));
        if (!preg_match('/^[A-Z]{2}$/', $p->country)) {
            $errors["$prefix.country"] = 'Land muss ein ISO-3166-1-alpha-2-Code sein (z. B. DE).';
        }

        $vat = InvoiceData::optString($input['vatId'] ?? null);
        if ($vat !== null) {
            $vat = strtoupper(preg_replace('/\s+/', '', $vat));
            if (!preg_match('/^[A-Z]{2}[A-Z0-9]{2,13}$/', $vat)) {
                $errors["$prefix.vatId"] = 'USt-IdNr. ist ungültig (z. B. DE123456789).';
            }
        }
        $p->vatId = $vat;

        $iban = InvoiceData::optString($input['iban'] ?? null);
        if ($iban !== null) {
            $iban = strtoupper(preg_replace('/\s+/', '', $iban));
            if (!preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $iban)) {
                $errors["$prefix.iban"] = 'IBAN ist ungültig.';
            }
        }
        $p->iban = $iban;

        if ($p->email !== null && !filter_var($p->email, FILTER_VALIDATE_EMAIL)) {
            $errors["$prefix.email"] = 'E-Mail-Adresse ist ungültig.';
        }

        // EN 16931: Postadresse mit Stadt, PLZ und Land ist für Verkäufer und Käufer Pflicht (BG-5 / BG-8)
        if ($p->city === null) {
            $errors["$prefix.city"] = 'Ort ist Pflicht.';
        }
        if ($p->zip === null) {
            $errors["$prefix.zip"] = 'Postleitzahl ist Pflicht.';
        }
        if ($isSeller && $p->street === null) {
            $errors["$prefix.street"] = 'Straße ist Pflicht.';
        }

        return $p;
    }
}
