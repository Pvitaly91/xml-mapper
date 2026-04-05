<?php

namespace App\Http\Controllers\E2e;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

class E2eSupportController extends Controller
{
    public function expireStepUp(Request $request): JsonResponse
    {
        abort_unless(app()->environment(['local', 'testing', 'e2e']), 404);
        abort_unless($request->user() !== null, 403);

        $request->session()->put('admin_auth.password_confirmed_at', now()->subHours(2)->toIso8601String());
        $request->session()->put('admin_auth.mfa_verified_at', now()->subHours(2)->toIso8601String());

        return response()->json([
            'status' => 'ok',
            'expired_at' => now()->toIso8601String(),
        ]);
    }

    public function mockWebhook(Request $request, string $outcome = 'success'): JsonResponse
    {
        abort_unless(app()->environment(['local', 'testing', 'e2e']), 404);

        $record = [
            'recorded_at' => now()->toIso8601String(),
            'outcome' => $outcome,
            'ip' => $request->ip(),
            'payload' => $request->json()->all() ?: $request->all(),
        ];

        File::append(
            storage_path('app/e2e/mock-webhook.jsonl'),
            json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL
        );

        if ($outcome === 'fail') {
            return response()->json([
                'status' => 'failed',
            ], 503);
        }

        return response()->json([
            'status' => 'ok',
        ]);
    }
}
