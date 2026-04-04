<?php

namespace App\Services\Ops;

use App\Support\SensitiveDataRedactor;
use Illuminate\Contracts\Container\Container;
use Throwable;

class OpsErrorTrackingService
{
    public function __construct(
        private readonly Container $container,
        private readonly SensitiveDataRedactor $redactor,
    ) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public function capture(Throwable $exception, array $context = []): void
    {
        if (blank(config('feed_mediator.observability.error_tracking.dsn'))) {
            return;
        }

        if (! class_exists(\Sentry\State\HubInterface::class) || ! $this->container->bound('sentry')) {
            return;
        }

        /** @var \Sentry\State\HubInterface $hub */
        $hub = $this->container->make('sentry');
        $scope = $hub->getScope();

        if ($scope !== null) {
            foreach ($this->redactor->redactArray($context) as $key => $value) {
                $scope->setExtra((string) $key, $value);
            }
        }

        $hub->captureException($exception);
    }
}
