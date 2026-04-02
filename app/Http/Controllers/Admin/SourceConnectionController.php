<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\SourceConnections\UpsertSourceConnectionAction;
use App\Http\Requests\Admin\SourceConnections\SourceConnectionRequest;
use App\Models\SourceConnection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SourceConnectionController extends AdminController
{
    public function index(Request $request): View
    {
        $shop = $this->adminShop($request);

        $connections = SourceConnection::query()
            ->with(['latestImport', 'feedProfiles'])
            ->where('shop_id', $shop->id)
            ->when($request->string('status')->toString(), fn ($query, $status) => $query->where('status', $status))
            ->when($request->string('driver')->toString(), fn ($query, $driver) => $query->where('driver', $driver))
            ->when($request->string('search')->toString(), function ($query, $search): void {
                $query->where(function ($innerQuery) use ($search): void {
                    $innerQuery->where('name', 'like', '%'.$search.'%')
                        ->orWhere('code', 'like', '%'.$search.'%')
                        ->orWhere('source_url', 'like', '%'.$search.'%')
                        ->orWhere('api_base_url', 'like', '%'.$search.'%');
                });
            })
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        return view('admin.source-connections.index', [
            'connections' => $connections,
            'filters' => $request->only(['status', 'driver', 'search']),
        ]);
    }

    public function create(Request $request): View
    {
        return view('admin.source-connections.form', [
            'connection' => new SourceConnection([
                'driver' => $request->string('driver')->toString() ?: SourceConnection::DRIVER_PROM_YML,
            ]),
            'pageTitle' => 'Create Source Connection',
            'driverOptions' => SourceConnection::driverOptions(),
            'redirectToOnboarding' => $request->boolean('redirect_to_onboarding'),
        ]);
    }

    public function store(SourceConnectionRequest $request, UpsertSourceConnectionAction $action): RedirectResponse
    {
        $connection = $action->handle($request->user(), $request->validated());

        if ($request->boolean('redirect_to_onboarding')) {
            return redirect()
                ->route('admin.onboarding.show')
                ->with('status', 'Source connection created.');
        }

        return redirect()
            ->route('admin.source-connections.show', $connection)
            ->with('status', 'Source connection created.');
    }

    public function show(Request $request, SourceConnection $sourceConnection): View
    {
        $this->ensureShopOwned($request, $sourceConnection);
        $sourceConnection->load(['latestImport', 'feedProfiles' => fn ($query) => $query->latest('id')->limit(10)]);

        return view('admin.source-connections.show', [
            'connection' => $sourceConnection,
            'imports' => $sourceConnection->imports()->latest('id')->paginate(10, ['*'], 'imports_page'),
        ]);
    }

    public function edit(Request $request, SourceConnection $sourceConnection): View
    {
        $this->ensureShopOwned($request, $sourceConnection);

        return view('admin.source-connections.form', [
            'connection' => $sourceConnection,
            'pageTitle' => 'Edit Source Connection',
            'driverOptions' => SourceConnection::driverOptions(),
            'redirectToOnboarding' => $request->boolean('redirect_to_onboarding'),
        ]);
    }

    public function update(SourceConnectionRequest $request, SourceConnection $sourceConnection, UpsertSourceConnectionAction $action): RedirectResponse
    {
        $this->ensureShopOwned($request, $sourceConnection);
        $connection = $action->handle($request->user(), $request->validated(), $sourceConnection);

        if ($request->boolean('redirect_to_onboarding')) {
            return redirect()
                ->route('admin.onboarding.show')
                ->with('status', 'Source connection updated.');
        }

        return redirect()
            ->route('admin.source-connections.show', $connection)
            ->with('status', 'Source connection updated.');
    }
}
