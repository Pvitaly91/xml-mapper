<?php

namespace App\Services\Ops;

class EnvironmentContextService
{
    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $class = $this->normalize((string) config('feed_mediator.environment.class', config('app.env', 'local')));
        $label = (string) (config('feed_mediator.environment.label') ?: ucfirst($class));
        $warnings = [];

        if ($class === 'staging') {
            $warnings[] = (string) config('feed_mediator.environment.staging_public_publish_note');
        }

        if ($class === 'local') {
            $warnings[] = 'Local mode is for development only. Published URLs here are not merchant-facing.';
        }

        return [
            'class' => $class,
            'label' => $label,
            'is_local' => $class === 'local',
            'is_staging' => $class === 'staging',
            'is_production' => $class === 'production',
            'badge_class' => match ($class) {
                'production' => 'ok',
                'staging' => 'warn',
                default => 'err',
            },
            'warnings' => $warnings,
        ];
    }

    private function normalize(string $environment): string
    {
        $environment = mb_strtolower(trim($environment));

        return match ($environment) {
            'prod' => 'production',
            'stage' => 'staging',
            'dev', 'development', 'testing', 'test' => 'local',
            'local', 'staging', 'production' => $environment,
            default => 'local',
        };
    }
}
