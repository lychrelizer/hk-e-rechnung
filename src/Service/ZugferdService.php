<?php

declare(strict_types=1);

namespace App\Service;

use App\Invoice\InvoiceData;
use App\Invoice\Exception\ValidationException;
use horstoeko\zugferd\codelists\ZugferdPaymentMeans;
use horstoeko\zugferd\ZugferdDocumentBuilder;
use horstoeko\zugferd\ZugferdDocumentPdfBuilder;
use horstoeko\zugferd\ZugferdProfiles;
use horstoeko\zugferd\ZugferdXsdValidator;
use RuntimeException;

/**
 * Erzeugt aus InvoiceData das ZUGFeRD-XML (CII) und bettet es in eine PDF/A-3-Datei ein.
 */
final class ZugferdService
{
    private const PROFILE_MAP = [
        'MINIMUM' => ZugferdProfiles::PROFILE_MINIMUM,
        'BASIC' => ZugferdProfiles::PROFILE_BASIC,
        'EN16931' => ZugferdProfiles::PROFILE_EN16931,
        'EXTENDED' => ZugferdProfiles::PROFILE_EXTENDED,
        'XRECHNUNG' => ZugferdProfiles::PROFILE_XRECHNUNG_3,
    ];

    /** @var string */
    private $creatorTool;

    public function __construct(string $creatorTool = 'E-Rechnung Converter')
    {
        $this->creatorTool = $creatorTool;
    }

    /**
     * Baut das CII-Dokument.
     */
    public function buildDocument(InvoiceData $data): ZugferdDocumentBuilder
    {
        $totals = $data->totals();
        $doc = ZugferdDocumentBuilder::createNew(self::PROFILE_MAP[$data->profile]);

        // --- Kopf ---
        $doc->setDocumentInformation($data->number, $data->typeCode, $data->date, $data->currency);

        foreach ($data->notes as $note) {
            $doc->addDocumentNote($note);
        }
        if ($data->buyerReference !== null) {
            $doc->setDocumentBuyerReference($data->buyerReference);
        }
        if ($data->orderNumber !== null) {
            $doc->setDocumentBuyerOrderReferencedDocument($data->orderNumber);
        }
        if ($data->deliveryDate !== null) {
            $doc->setDocumentSupplyChainEvent($data->deliveryDate);
        }

        // --- Verkäufer ---
        $s = $data->seller;
        $doc->setDocumentSeller($s->name, $s->id);
        $doc->setDocumentSellerAddress($s->street, $s->street2, null, $s->zip, $s->city, $s->country);
        if ($s->vatId !== null) {
            $doc->addDocumentSellerVATRegistrationNumber($s->vatId);
        }
        if ($s->taxNumber !== null) {
            $doc->addDocumentSellerTaxNumber($s->taxNumber);
        }
        if ($s->contact !== null || $s->phone !== null || $s->email !== null) {
            $doc->setDocumentSellerContact($s->contact ?? $s->name, null, $s->phone, null, $s->email);
        }
        if ($s->email !== null) {
            $doc->setDocumentSellerCommunication('EM', $s->email);
        }

        // --- Käufer ---
        $b = $data->buyer;
        $doc->setDocumentBuyer($b->name, $b->id);
        $doc->setDocumentBuyerAddress($b->street, $b->street2, null, $b->zip, $b->city, $b->country);
        if ($b->vatId !== null) {
            $doc->addDocumentBuyerVATRegistrationNumber($b->vatId);
        }
        if ($b->contact !== null || $b->phone !== null || $b->email !== null) {
            $doc->setDocumentBuyerContact($b->contact ?? $b->name, null, $b->phone, null, $b->email);
        }
        if ($b->email !== null) {
            $doc->setDocumentBuyerCommunication('EM', $b->email);
        }

        // --- Zahlung ---
        if ($s->iban !== null) {
            $doc->addDocumentPaymentMean(
                ZugferdPaymentMeans::UNTDID_4461_58,
                $data->paymentReference,
                null,
                null,
                null,
                null,
                $s->iban,
                $s->accountHolder ?? $s->name,
                null,
                $s->bic
            );
        } elseif ($data->profile === 'XRECHNUNG') {
            // XRechnung verlangt ein Zahlungsmittel
            $doc->addDocumentPaymentMean(ZugferdPaymentMeans::UNTDID_4461_1, $data->paymentReference);
        }
        if ($data->paymentTerms !== null || $data->dueDate !== null) {
            $doc->addDocumentPaymentTerm($data->paymentTerms, $data->dueDate);
        }

        // --- Positionen ---
        foreach ($data->lines as $line) {
            $doc->addNewPosition($line->id);
            $doc->setDocumentPositionProductDetails($line->description, $line->note, $line->articleNumber);
            $doc->setDocumentPositionNetPrice($line->unitPrice);
            $doc->setDocumentPositionQuantity($line->quantity, $line->unit);
            $doc->addDocumentPositionTax(
                $line->taxCategory,
                'VAT',
                $line->taxRate,
                null,
                InvoiceData::isExemptCategory($line->taxCategory) ? $line->taxExemptionReason : null
            );
            $doc->setDocumentPositionLineSummation($line->netAmount());
        }

        // --- Steueraufschlüsselung & Summen ---
        foreach ($totals['taxes'] as $t) {
            $doc->addDocumentTax(
                $t['category'],
                'VAT',
                $t['basis'],
                $t['amount'],
                $t['rate'],
                InvoiceData::isExemptCategory($t['category']) ? $t['exemptionReason'] : null
            );
        }
        $doc->setDocumentSummation(
            $totals['grandTotal'],
            $totals['grandTotal'],
            $totals['lineTotal'],
            0.0,
            0.0,
            $totals['lineTotal'],
            $totals['taxTotal']
        );

        return $doc;
    }

    /**
     * XML erzeugen und gegen das XSD des Profils prüfen.
     */
    public function buildXml(InvoiceData $data): string
    {
        $doc = $this->buildDocument($data);
        $this->assertValid($doc);

        return $doc->getContent();
    }

    /**
     * Fertige ZUGFeRD-PDF (PDF/A-3 mit eingebettetem XML) als Binärstring.
     *
     * @param string $pdfBinary Inhalt der Original-PDF (Sichtkomponente)
     */
    public function buildPdf(InvoiceData $data, string $pdfBinary): string
    {
        if (strpos($pdfBinary, '%PDF') !== 0) {
            throw new ValidationException(['pdf' => 'Die hochgeladene Datei ist keine PDF-Datei.']);
        }

        $doc = $this->buildDocument($data);
        $this->assertValid($doc);

        try {
            $pdfBuilder = new ZugferdDocumentPdfBuilder($doc, $pdfBinary);
            $pdfBuilder->setAdditionalCreatorTool($this->creatorTool);
            $pdfBuilder->generateDocument();

            return $pdfBuilder->downloadString();
        } catch (\Throwable $e) {
            throw new RuntimeException(
                'PDF konnte nicht verarbeitet werden: ' . $e->getMessage()
                . ' (Hinweis: verschlüsselte PDFs oder PDF-Versionen > 1.7 mit Objekt-Streams werden von FPDI nicht unterstützt.)',
                500,
                $e
            );
        }
    }

    public function xmlFilename(InvoiceData $data): string
    {
        return $data->profile === 'XRECHNUNG' ? 'xrechnung.xml' : 'factur-x.xml';
    }

    private function assertValid(ZugferdDocumentBuilder $doc): void
    {
        $xsd = new ZugferdXsdValidator($doc);
        $xsd->validate();
        if ($xsd->hasValidationErrors()) {
            $errors = [];
            foreach ($xsd->validationErrors() as $i => $err) {
                $errors["xsd.$i"] = is_string($err) ? $err : (string) json_encode($err);
            }
            throw new ValidationException($errors, 'Das erzeugte XML ist nicht schemakonform');
        }
    }
}
