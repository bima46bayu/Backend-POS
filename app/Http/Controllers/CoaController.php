<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Coa;

class CoaController extends Controller
{
    public function index()
    {
        return response()->json(Coa::orderBy('coa')->get());
    }

    public function store(Request $r)
    {
        $data = $r->validate([
            'sub_coa' => 'nullable|string',
            'coa' => 'nullable|string',
            'keterangan' => 'nullable|string'
        ]);

        return response()->json(Coa::create($data), 201);
    }

    public function update(Request $r, $id)
    {
        $coa = Coa::findOrFail($id);

        $data = $r->validate([
            'sub_coa' => 'nullable|string',
            'coa' => 'nullable|string',
            'keterangan' => 'nullable|string'
        ]);

        $coa->update($data);

        return response()->json($coa);
    }

    public function destroy($id)
    {
        Coa::findOrFail($id)->delete();
        return response()->json(['success' => true]);
    }
}

