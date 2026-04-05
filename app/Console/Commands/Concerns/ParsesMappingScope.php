<?php

namespace App\Console\Commands\Concerns;

trait ParsesMappingScope
{
    /**
     * @return array<string, mixed>
     */
    protected function parseMappingScope(?string $scope): array
    {
        if (! is_string($scope) || trim($scope) === '') {
            return [];
        }

        $scope = trim($scope);

        if (str_starts_with($scope, '{')) {
            $decoded = json_decode($scope, true);

            return is_array($decoded) ? $decoded : [];
        }

        return collect(explode(',', $scope))
            ->map(fn (string $pair) => array_map('trim', explode('=', $pair, 2)))
            ->filter(fn (array $pair) => count($pair) === 2 && $pair[0] !== '')
            ->mapWithKeys(function (array $pair): array {
                [$key, $value] = $pair;

                if (is_numeric($value)) {
                    $value = str_contains($value, '.') ? (float) $value : (int) $value;
                }

                return [$key => $value];
            })
            ->all();
    }
}
