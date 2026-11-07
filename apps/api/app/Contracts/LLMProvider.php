<?php

namespace App\Contracts;

use App\Services\AI\ProviderEvent;

interface LLMProvider
{
    /** @param list<array{role:string,content:string}> $messages @return iterable<ProviderEvent> */
    public function stream(array $messages, string $model, int $maxOutputTokens): iterable;
}
