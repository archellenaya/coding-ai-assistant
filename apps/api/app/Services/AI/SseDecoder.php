<?php

namespace App\Services\AI;

use Psr\Http\Message\StreamInterface;

class SseDecoder
{
    /** Decode bounded SSE frames, including multiline data and arbitrary network chunk boundaries. */
    public function decode(StreamInterface $stream): iterable
    {
        $buffer = '';
        $data = [];
        $size = 0;
        while (! $stream->eof()) {
            $chunk = $stream->read(1024);
            if ($chunk === '' && ! $stream->eof()) {
                throw new ProviderException('provider_timeout');
            }
            $buffer .= $chunk;
            if (strlen($buffer) > 1048576) {
                throw new ProviderException('provider_protocol_error');
            }
            while (($position = strpos($buffer, "\n")) !== false) {
                $line = rtrim(substr($buffer, 0, $position), "\r");
                $buffer = substr($buffer, $position + 1);
                if ($line === '') {
                    if ($data !== []) {
                        yield implode("\n", $data);
                    }
                    $data = [];
                    $size = 0;
                } elseif (str_starts_with($line, 'data:')) {
                    $value = substr($line, 5);
                    if (str_starts_with($value, ' ')) {
                        $value = substr($value, 1);
                    }
                    $size += strlen($value);
                    if ($size > 1048576) {
                        throw new ProviderException('provider_protocol_error');
                    }
                    $data[] = $value;
                }
            }
        }
        // A valid SSE event ends with a blank line. Incomplete tails are not completion.
    }
}
