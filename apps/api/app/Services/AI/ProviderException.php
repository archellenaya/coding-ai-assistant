<?php

namespace App\Services\AI;

use RuntimeException;

class ProviderException extends RuntimeException
{
    public function __construct(public readonly string $errorCode = 'provider_error')
    {
        parent::__construct('AI request failed.');
    }
}
