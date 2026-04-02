<?php

namespace App\Services\Dictionaries\Readers;

use App\Contracts\Dictionaries\DictionaryReaderInterface;
use RuntimeException;

class JsonDictionaryReader implements DictionaryReaderInterface
{
    public function format(): string
    {
        return 'json';
    }

    public function read(string $path): iterable
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException(sprintf('Unable to open JSON dictionary file [%s].', $path));
        }

        try {
            $opening = $this->nextSignificantChar($handle);

            if ($opening !== '[') {
                throw new RuntimeException('JSON dictionary file must contain a top-level array.');
            }

            $next = $this->nextSignificantChar($handle);

            if ($next === ']') {
                return;
            }

            while ($next !== null) {
                if ($next !== '{') {
                    throw new RuntimeException('JSON dictionary file must contain an array of objects.');
                }

                $buffer = '{';
                $depth = 1;
                $inString = false;
                $escaped = false;

                while (($char = fgetc($handle)) !== false) {
                    $buffer .= $char;

                    if ($inString) {
                        if ($escaped) {
                            $escaped = false;

                            continue;
                        }

                        if ($char === '\\') {
                            $escaped = true;

                            continue;
                        }

                        if ($char === '"') {
                            $inString = false;
                        }

                        continue;
                    }

                    if ($char === '"') {
                        $inString = true;

                        continue;
                    }

                    if ($char === '{') {
                        $depth++;

                        continue;
                    }

                    if ($char === '}') {
                        $depth--;

                        if ($depth === 0) {
                            break;
                        }
                    }
                }

                if ($depth !== 0) {
                    throw new RuntimeException('JSON dictionary file is truncated or malformed.');
                }

                $decoded = json_decode($buffer, true, 512, JSON_THROW_ON_ERROR);

                if (! is_array($decoded)) {
                    throw new RuntimeException('Each JSON dictionary row must decode into an object.');
                }

                yield $decoded;

                $separator = $this->nextSignificantChar($handle);

                if ($separator === ']') {
                    break;
                }

                if ($separator !== ',') {
                    throw new RuntimeException('JSON dictionary file must separate rows with commas.');
                }

                $next = $this->nextSignificantChar($handle);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  resource  $handle
     */
    private function nextSignificantChar($handle): ?string
    {
        while (($char = fgetc($handle)) !== false) {
            if (! ctype_space($char)) {
                return $char;
            }
        }

        return null;
    }
}
