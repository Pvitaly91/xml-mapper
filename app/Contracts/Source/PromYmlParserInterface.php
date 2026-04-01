<?php

namespace App\Contracts\Source;

use App\Data\Source\ParsedSourceFeedData;

interface PromYmlParserInterface
{
    public function parseFile(string $filePath): ParsedSourceFeedData;
}
