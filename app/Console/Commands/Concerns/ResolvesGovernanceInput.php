<?php

namespace App\Console\Commands\Concerns;

use App\Models\Shop;
use App\Models\User;
use RuntimeException;

trait ResolvesGovernanceInput
{
    protected function resolveUserIdentifier(string|int $identifier): User
    {
        return User::query()
            ->when(
                is_numeric((string) $identifier),
                fn ($query) => $query->whereKey((int) $identifier),
                fn ($query) => $query->where('email', (string) $identifier)
            )
            ->firstOrFail();
    }

    protected function resolveOptionalActor(?string $identifier): ?User
    {
        if ($identifier === null || trim($identifier) === '') {
            return null;
        }

        return $this->resolveUserIdentifier($identifier);
    }

    protected function resolveShopOption(mixed $shopId): ?Shop
    {
        if ($shopId === null || $shopId === '') {
            return null;
        }

        return Shop::query()->findOrFail((int) $shopId);
    }

    protected function requireActorOption(?string $identifier, string $optionName = 'by'): User
    {
        $actor = $this->resolveOptionalActor($identifier);

        if (! $actor instanceof User) {
            throw new RuntimeException('Reviewer identity is required. Pass --'.$optionName.'=<email|id>.');
        }

        return $actor;
    }
}
