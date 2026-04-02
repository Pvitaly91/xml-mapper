<?php

namespace App\Services\Feeds;

use Illuminate\Support\Facades\Storage;
use RuntimeException;
use SimpleXMLElement;
use XMLWriter;

class KastaExportXmlService
{
    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function writeOffer(XMLWriter $writer, array $snapshot): void
    {
        $writer->startElement('offer');
        $writer->writeAttribute('id', (string) ($snapshot['offer_id'] ?? ''));
        $writer->writeAttribute('available', ($snapshot['available'] ?? false) ? 'true' : 'false');
        $writer->writeElement('name', (string) ($snapshot['name'] ?? ''));
        $writer->writeElement('price', (string) ($snapshot['price'] ?? '0.00'));
        $writer->writeElement('currencyId', (string) ($snapshot['currency'] ?? 'UAH'));
        $writer->writeElement('categoryId', (string) ($snapshot['category_id'] ?? ''));

        if (! blank($snapshot['vendor'] ?? null)) {
            $writer->writeElement('vendor', (string) $snapshot['vendor']);
        }

        if (! blank($snapshot['vendor_code'] ?? null)) {
            $writer->writeElement('vendorCode', (string) $snapshot['vendor_code']);
        }

        foreach ($snapshot['pictures'] ?? [] as $picture) {
            $writer->writeElement('picture', (string) $picture);
        }

        if (! blank($snapshot['description'] ?? null)) {
            $writer->writeElement('description', (string) $snapshot['description']);
        }

        foreach (($snapshot['params'] ?? []) as $attributeCode => $value) {
            $writer->startElement('param');
            $writer->writeAttribute('name', (string) $attributeCode);
            $writer->text((string) $value);
            $writer->endElement();
        }

        $writer->endElement();
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function renderOfferFragment(array $snapshot): string
    {
        $writer = new XMLWriter();
        $writer->openMemory();
        $writer->setIndent(true);
        $this->writeOffer($writer, $snapshot);

        return trim($writer->outputMemory());
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function parseOfferSnapshots(string $relativePath): array
    {
        $disk = Storage::disk(config('feed_mediator.storage_disk'));

        if (! $disk->exists($relativePath)) {
            throw new RuntimeException(sprintf('Feed XML file [%s] does not exist.', $relativePath));
        }

        $xml = simplexml_load_string($disk->get($relativePath));

        if (! $xml instanceof SimpleXMLElement) {
            throw new RuntimeException(sprintf('Feed XML file [%s] is invalid.', $relativePath));
        }

        $offers = [];

        foreach ($xml->shop->offers->offer ?? [] as $offer) {
            $id = (string) ($offer['id'] ?? '');

            if ($id === '') {
                continue;
            }

            $offers[$id] = [
                'offer_id' => $id,
                'available' => ((string) ($offer['available'] ?? 'false')) === 'true',
                'name' => (string) ($offer->name ?? ''),
                'price' => (string) ($offer->price ?? ''),
                'currency' => (string) ($offer->currencyId ?? ''),
                'categoryId' => (string) ($offer->categoryId ?? ''),
                'vendor' => (string) ($offer->vendor ?? ''),
                'vendorCode' => (string) ($offer->vendorCode ?? ''),
            ];
        }

        ksort($offers);

        return $offers;
    }
}
