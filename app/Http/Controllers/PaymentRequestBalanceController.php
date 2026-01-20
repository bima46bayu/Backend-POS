<?php

namespace App\Http\Controllers;

use App\Models\PaymentRequest;
use App\Models\PaymentRequestBalance;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PaymentRequestBalanceController extends Controller
{
    /* ===================== Helpers ===================== */

    private function authorizePR(PaymentRequest $pr, $user)
    {
        if ($user->role === 'kasir' && $pr->store_location_id !== $user->store_location_id) {
            abort(403, 'Forbidden');
        }
    }

    /* ===================== Store ===================== */

    public function store(Request $r, $prId)
    {
        $user = $r->user();

        $pr = PaymentRequest::findOrFail($prId);
        $this->authorizePR($pr, $user);

        $data = $r->validate([
            'bank_account_id' => 'required|exists:bank_accounts,id',
            'saldo' => 'nullable|numeric',
        ]);

        $exists = PaymentRequestBalance::where('payment_request_id', $prId)
            ->where('bank_account_id', $data['bank_account_id'])
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'bank_account_id' => 'Rekening sudah ada untuk payment request ini'
            ]);
        }

        $balance = PaymentRequestBalance::create([
            'payment_request_id' => $prId,
            'bank_account_id'    => $data['bank_account_id'],
            'saldo'              => $data['saldo'] ?? 0,
        ]);

        return response()->json($balance, 201);
    }

    /* ===================== Update ===================== */

    public function update(Request $r, $prId, $id)
    {
        $user = $r->user();

        $pr = PaymentRequest::findOrFail($prId);
        $this->authorizePR($pr, $user);

        $data = $r->validate([
            'saldo' => 'required|numeric|min:0',
        ]);

        $bal = PaymentRequestBalance::where('payment_request_id', $prId)
            ->where('id', $id)
            ->firstOrFail();

        $bal->update([
            'saldo' => $data['saldo'],
        ]);

        return response()->json($bal);
    }

    /* ===================== Destroy ===================== */

    public function destroy(Request $r, $prId, $id)
    {
        $user = $r->user();

        $pr = PaymentRequest::findOrFail($prId);
        $this->authorizePR($pr, $user);

        $bal = PaymentRequestBalance::where('payment_request_id', $prId)
            ->where('id', $id)
            ->firstOrFail();

        $bal->delete();

        return response()->json(['success' => true]);
    }
}
