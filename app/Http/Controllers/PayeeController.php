<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Payee;

class PayeeController extends Controller
{
    public function index()
    {
        return response()->json(Payee::orderBy('payee')->get());
    }

    public function store(Request $r)
    {
        $data = $r->validate([
            'payee' => 'required|string',
            'bank_name' => 'nullable|string',
            'account_number' => 'nullable|string',
            'account_name' => 'nullable|string'
        ]);

        return response()->json(Payee::create($data), 201);
    }

    public function update(Request $r, $id)
    {
        $payee = Payee::findOrFail($id);

        $data = $r->validate([
            'payee' => 'required|string',
            'bank_name' => 'nullable|string',
            'account_number' => 'nullable|string',
            'account_name' => 'nullable|string'
        ]);

        $payee->update($data);

        return response()->json($payee);
    }

    public function destroy($id)
    {
        Payee::findOrFail($id)->delete();
        return response()->json(['success' => true]);
    }
}

