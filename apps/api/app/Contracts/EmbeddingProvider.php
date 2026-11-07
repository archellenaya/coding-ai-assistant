<?php

namespace App\Contracts;

use App\Services\Memory\EmbeddingResult;

interface EmbeddingProvider
{
    public function embed(string $text): EmbeddingResult;
}
