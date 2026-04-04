<?php

namespace App\Services\Governance\Handlers;

use App\Actions\Admin\SourceConnections\UpsertSourceConnectionAction;
use App\Contracts\Governance\GovernedActionHandler;
use App\Models\ApprovalRequest;
use App\Models\Shop;
use App\Models\SourceConnection;
use App\Models\User;

class SecretRebindActionHandler implements GovernedActionHandler
{
    public function __construct(
        private readonly UpsertSourceConnectionAction $upsertSourceConnectionAction,
    ) {}

    public function execute(array $payload, ?User $actor = null, ?ApprovalRequest $approvalRequest = null): array
    {
        $sourceConnection = SourceConnection::query()->findOrFail((int) $payload['source_connection_id']);
        $shop = Shop::query()->findOrFail((int) $sourceConnection->shop_id);
        $connection = $this->upsertSourceConnectionAction->handle(
            $actor,
            (array) ($payload['connection_payload'] ?? []),
            $sourceConnection,
            $shop
        );

        return [
            'source_connection_id' => $connection->id,
            'secret_state' => $connection->promotionSecretState(),
        ];
    }
}
