<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\BankAccount;

class BankAccountController extends Controller
{
    public function index()
    {
        return response()->json(BankAccount::orderBy('bank_name')->get());
    }

    public function store(Request $r)
    {
        $data = $r->validate([
            'bank_name' => 'required|string',
            'account_number' => 'required|string',
            'account_name' => 'required|string',
            'account_type' => 'required|string',
            'currency' => 'required|string|max:10',
        ]);

        return response()->json(BankAccount::create($data), 201);
    }

    public function update(Request $r, $id)
    {
        $acc = BankAccount::findOrFail($id);

        $data = $r->validate([
            'bank_name' => 'required|string',
            'account_number' => 'required|string',
            'account_name' => 'required|string',
            'account_type' => 'required|string',
            'currency' => 'required|string|max:10',
        ]);

        $acc->update($data);

        return response()->json($acc);
    }

    public function destroy($id)
    {
        BankAccount::findOrFail($id)->delete();
        return response()->json(['success' => true]);
    }
}

