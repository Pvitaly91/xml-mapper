<?php

namespace Tests\Unit\Source;

use App\Contracts\Source\PromYmlParserInterface;
use Tests\TestCase;

class PromYmlParserTest extends TestCase
{
    public function test_it_parses_categories_and_offers_from_prom_yml(): void
    {
        $parser = app(PromYmlParserInterface::class);
        $fixturePath = base_path('tests/Fixtures/prom_sample.yml');

        $feedData = $parser->parseFile($fixturePath);

        $this->assertCount(2, $feedData->categories);
        $this->assertCount(2, $feedData->offers);
        $this->assertSame('100', $feedData->categories[0]->externalId);
        $this->assertSame('SKU-1', $feedData->offers[0]->externalOfferId);
        $this->assertSame('Acme', $feedData->offers[0]->vendor);
        $this->assertSame('TSHIRT-001', $feedData->offers[0]->article);
        $this->assertSame('Black', $feedData->offers[0]->params['Color']);
    }
}
