<?php

namespace App\Services\AI;

final readonly class ProviderEvent
{
    public function __construct(public string $type, public string $text = '', public array $usage = [], public ?string $responseId = null) {}
}
