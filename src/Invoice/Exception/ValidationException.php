<?php

declare(strict_types=1);

namespace App\Invoice\Exception;

use RuntimeException;

final class ValidationException extends RuntimeException
{
    /** @var array<string,string> */
    private $errors;

    /**
     * @param array<string,string> $errors Feldname => Fehlermeldung
     */
    public function __construct(array $errors, string $message = 'Validierung fehlgeschlagen')
    {
        parent::__construct($message, 422);
        $this->errors = $errors;
    }

    /** @return array<string,string> */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
