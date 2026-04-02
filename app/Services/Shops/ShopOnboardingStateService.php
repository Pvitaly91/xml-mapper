<?php

namespace App\Services\Shops;

use App\Models\Shop;
use App\Models\User;

class ShopOnboardingStateService
{
    /**
     * @return array<string, mixed>
     */
    public function state(User $user): array
    {
        $shop = $user->shop;

        if ($shop !== null) {
            $shopSettings = is_array($shop->settings) ? $shop->settings : [];
            $shopState = $shopSettings['onboarding'] ?? [];
            $userSettings = is_array($user->settings) ? $user->settings : [];
            $userState = $userSettings['onboarding'] ?? [];

            if ($userState !== []) {
                $merged = array_replace_recursive($userState, $shopState);
                $this->persistToShop($shop, $merged);
                $this->persistToUser($user, []);

                return $merged;
            }

            return is_array($shopState) ? $shopState : [];
        }

        $settings = is_array($user->settings) ? $user->settings : [];
        $state = $settings['onboarding'] ?? [];

        return is_array($state) ? $state : [];
    }

    /**
     * @param  array<string, mixed>  $state
     */
    public function put(User $user, array $state): array
    {
        if ($user->shop !== null) {
            $this->persistToShop($user->shop, $state);

            return $state;
        }

        $this->persistToUser($user, $state);

        return $state;
    }

    /**
     * @param  array<string, mixed>  $patch
     * @return array<string, mixed>
     */
    public function merge(User $user, array $patch): array
    {
        $state = array_replace_recursive($this->state($user), $patch);

        return $this->put($user, $state);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public function markStepCompleted(User $user, string $step, array $extra = []): array
    {
        $state = $this->state($user);
        $completed = $state['completed_steps'] ?? [];
        $completed[$step] = now()->toIso8601String();
        $state['completed_steps'] = $completed;
        $state['last_completed_step'] = $step;
        $state['last_action_at'] = now()->toIso8601String();

        if ($extra !== []) {
            $state = array_replace_recursive($state, $extra);
        }

        return $this->put($user, $state);
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function persistToShop(Shop $shop, array $state): void
    {
        $settings = is_array($shop->settings) ? $shop->settings : [];
        $settings['onboarding'] = $state;
        $shop->forceFill(['settings' => $settings])->save();
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function persistToUser(User $user, array $state): void
    {
        $settings = is_array($user->settings) ? $user->settings : [];

        if ($state === []) {
            unset($settings['onboarding']);
        } else {
            $settings['onboarding'] = $state;
        }

        $user->forceFill(['settings' => $settings])->save();
    }
}
