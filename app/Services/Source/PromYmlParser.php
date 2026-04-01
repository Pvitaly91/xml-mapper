<?php

namespace App\Services\Source;

use App\Contracts\Source\PromYmlParserInterface;
use App\Data\Source\ParsedSourceCategoryData;
use App\Data\Source\ParsedSourceFeedData;
use App\Data\Source\ParsedSourceOfferData;
use App\Support\Canonicalizer;
use DOMDocument;
use DOMElement;
use RuntimeException;
use XMLReader;

class PromYmlParser implements PromYmlParserInterface
{
    public function parseFile(string $filePath): ParsedSourceFeedData
    {
        $reader = new XMLReader();

        if (! $reader->open($filePath, null, LIBXML_NONET | LIBXML_COMPACT | LIBXML_NOCDATA)) {
            throw new RuntimeException(sprintf('Failed to open XML file [%s].', $filePath));
        }

        $categories = [];
        $offers = [];

        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT) {
                continue;
            }

            if ($reader->name === 'category') {
                $categories[] = $this->parseCategoryElement($this->expandElement($reader));

                continue;
            }

            if ($reader->name === 'offer') {
                $offers[] = $this->parseOfferElement($this->expandElement($reader));
            }
        }

        $reader->close();

        return new ParsedSourceFeedData($categories, $offers);
    }

    private function expandElement(XMLReader $reader): DOMElement
    {
        $node = $reader->expand();

        if (! $node instanceof DOMElement) {
            throw new RuntimeException(sprintf('Failed to parse XML element [%s].', $reader->name));
        }

        $document = new DOMDocument('1.0', 'UTF-8');

        /** @var DOMElement $element */
        $element = $document->appendChild($document->importNode($node, true));

        return $element;
    }

    private function parseCategoryElement(DOMElement $element): ParsedSourceCategoryData
    {
        $externalId = (string) $element->getAttribute('id');

        return new ParsedSourceCategoryData(
            externalId: $externalId,
            parentExternalId: Canonicalizer::normalizeText($element->getAttribute('parentId')),
            name: Canonicalizer::normalizeText($element->textContent) ?? $externalId,
            rzId: Canonicalizer::normalizeText($element->getAttribute('rz_id') ?: $element->getAttribute('rz-id')),
            rawPayload: [
                'id' => $externalId,
                'parentId' => Canonicalizer::normalizeText($element->getAttribute('parentId')),
                'name' => Canonicalizer::normalizeText($element->textContent),
            ],
        );
    }

    private function parseOfferElement(DOMElement $element): ParsedSourceOfferData
    {
        $params = [];
        $images = [];

        foreach ($element->childNodes as $childNode) {
            if (! $childNode instanceof DOMElement) {
                continue;
            }

            if ($childNode->tagName === 'param') {
                $name = Canonicalizer::normalizeText($childNode->getAttribute('name'));

                if ($name !== null) {
                    $params[$name] = Canonicalizer::normalizeText($childNode->textContent) ?? '';
                }

                continue;
            }

            if ($childNode->tagName === 'picture') {
                $picture = Canonicalizer::normalizeText($childNode->textContent);

                if ($picture !== null) {
                    $images[] = $picture;
                }
            }
        }

        $vendorCode = $this->childText($element, 'vendorCode') ?? $this->childText($element, 'article');
        $quantity = $this->childText($element, 'quantity_in_stock')
            ?? $this->childText($element, 'stock_quantity')
            ?? $this->childText($element, 'quantity');

        return new ParsedSourceOfferData(
            externalOfferId: Canonicalizer::normalizeText($element->getAttribute('id')),
            title: $this->childText($element, 'name') ?? 'Unnamed Offer',
            categoryExternalId: $this->childText($element, 'categoryId'),
            vendor: $this->childText($element, 'vendor'),
            article: $vendorCode,
            description: $this->childText($element, 'description'),
            price: $this->toFloat($this->childText($element, 'price')),
            oldPrice: $this->toFloat($this->childText($element, 'oldprice')),
            currency: $this->childText($element, 'currencyId') ?? 'UAH',
            quantity: $quantity !== null ? (int) $quantity : null,
            available: filter_var($element->getAttribute('available') ?: 'true', FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true,
            images: Canonicalizer::uniqueNonEmpty($images),
            params: $params,
            rawPayload: [
                'id' => Canonicalizer::normalizeText($element->getAttribute('id')),
                'name' => $this->childText($element, 'name'),
                'categoryId' => $this->childText($element, 'categoryId'),
                'vendor' => $this->childText($element, 'vendor'),
                'vendorCode' => $vendorCode,
                'currencyId' => $this->childText($element, 'currencyId'),
                'available' => $element->getAttribute('available'),
            ],
        );
    }

    private function childText(DOMElement $element, string $tagName): ?string
    {
        foreach ($element->childNodes as $childNode) {
            if ($childNode instanceof DOMElement && $childNode->tagName === $tagName) {
                return Canonicalizer::normalizeText($childNode->textContent);
            }
        }

        return null;
    }

    private function toFloat(?string $value): ?float
    {
        return $value !== null ? (float) $value : null;
    }
}
