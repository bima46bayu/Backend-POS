<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Unit;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UnitController extends Controller
{
    public function index(Request $request)
    {
        $search   = $request->input('search');
        $perPage  = (int) $request->input('per_page', 20);

        $query = Unit::query()->orderBy('id');

        if ($search) {
            $query->where('name', 'like', '%' . $search . '%');
        }

        $units = $query->paginate($perPage);

        return response()->json([
            'items' => $units->items(),
            'pagination' => [
                'current_page' => $units->currentPage(),
                'per_page'     => $units->perPage(),
                'total'        => $units->total(),
                'last_page'    => $units->lastPage(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => [
                'required',
                'string',
                'max:100',
                'unique:units,name',
            ],
        ]);

        $unit = Unit::create([
            'name'      => $data['name'],
            'is_system' => false, // yang dibuat user
        ]);

        return response()->json([
            'message' => 'Unit created',
            'data'    => $unit,
        ], 201);
    }

    public function update(Request $request, Unit $unit)
    {
        // kalau kamu mau: larang edit default system
        if ($unit->is_system) {
            return response()->json([
                'message' => 'Unit bawaan sistem tidak boleh diubah.',
            ], 422);
        }

        $data = $request->validate([
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('units', 'name')->ignore($unit->id),
            ],
        ]);

        $unit->update($data);

        return response()->json([
            'message' => 'Unit updated',
            'data'    => $unit,
        ]);
    }

    public function destroy(Unit $unit)
    {
        // 1) Cegah hapus unit bawaan sistem (opsional)
        if ($unit->is_system) {
            return response()->json([
                'message' => 'Unit bawaan sistem tidak boleh dihapus.',
            ], 422);
        }

        // 2) Cek masih dipakai product atau tidak
        $usedCount = $unit->products()->count();
        if ($usedCount > 0) {
            return response()->json([
                'message' => 'Unit tidak dapat dihapus karena masih dipakai di ' . $usedCount . ' product.',
            ], 422);
        }

        $unit->delete();

        return response()->json([
            'message' => 'Unit deleted',
        ]);
    }
}

