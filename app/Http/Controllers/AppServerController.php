<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAppServerRequest;
use App\Http\Requests\UpdateAppServerRequest;
use App\Models\AppServer;

class AppServerController extends Controller
{
    /**
     * Addresses the mobile login screen can offer. Public, because the device
     * has not signed in yet.
     */
    public function index()
    {
        $servers = AppServer::query()
            ->where('is_active', true)
            ->orderByDesc('id')
            ->get(['id', 'name', 'base_url']);

        return response()->json(['data' => $servers]);
    }

    public function manage()
    {
        $servers = AppServer::query()
            ->orderByDesc('id')
            ->get(['id', 'name', 'base_url', 'is_active', 'created_at']);

        return response()->json(['data' => $servers]);
    }

    public function store(StoreAppServerRequest $request)
    {
        $data = $request->validated();
        $server = AppServer::create([
            'name' => $data['name'],
            'base_url' => $data['base_url'],
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);

        return response()->json(['data' => $server], 201);
    }

    public function update(UpdateAppServerRequest $request, int $id)
    {
        $server = AppServer::query()->findOrFail($id);
        $data = $request->validated();

        if (
            $server->is_active
            && array_key_exists('is_active', $data)
            && ! $data['is_active']
            && $this->isLastActive($server)
        ) {
            abort(422, 'Minimal satu alamat server harus tetap aktif.');
        }

        $server->fill([
            'name' => $data['name'],
            'base_url' => $data['base_url'],
            'is_active' => array_key_exists('is_active', $data)
                ? (bool) $data['is_active']
                : $server->is_active,
        ]);
        $server->save();

        return response()->json(['data' => $server]);
    }

    public function destroy(int $id)
    {
        $server = AppServer::query()->findOrFail($id);

        if ($server->is_active && $this->isLastActive($server)) {
            abort(422, 'Minimal satu alamat server harus tetap aktif.');
        }

        $server->delete();

        return response()->noContent();
    }

    private function isLastActive(AppServer $server): bool
    {
        return ! AppServer::query()
            ->where('is_active', true)
            ->where('id', '!=', $server->id)
            ->exists();
    }
}
