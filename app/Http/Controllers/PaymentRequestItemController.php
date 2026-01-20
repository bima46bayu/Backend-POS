<?php

namespace App\Http\Controllers;

use App\Models\PaymentRequest;
use App\Models\PaymentRequestDetail;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PaymentRequestItemController extends Controller
{
    /* ================= Helpers ================= */

    private function authorizePR(PaymentRequest $pr, $user)
    {
        if ($user->role === 'kasir' && $pr->store_location_id !== $user->store_location_id) {
            abort(403, 'Forbidden');
        }
    }

    private function computeTransfer($amount, $deduction)
    {
        if ($deduction > $amount) {
            throw ValidationException::withMessages([
                'deduction' => 'Potongan tidak boleh lebih besar dari jumlah tagihan'
            ]);
        }

        return $amount - $deduction;
    }

    /* ================= Store ================= */

    public function store(Request $r, $prId)
    {
        $user = $r->user();

        $pr = PaymentRequest::findOrFail($prId);
        $this->authorizePR($pr, $user);

        $data = $r->validate([
            'payee_id'     => 'required|exists:payees,id',
            'coa_id'       => 'required|exists:coas,id',
            'description'  => 'nullable|string|max:255',
            'amount'       => 'required|numeric|min:0',
            'deduction'    => 'nullable|numeric|min:0',
            'remark'       => 'nullable|string|max:255',
        ]);

        $deduction = $data['deduction'] ?? 0;
        $transfer = $this->computeTransfer($data['amount'], $deduction);

        $item = PaymentRequestDetail::create([
            'payment_request_id' => $prId,
            'payee_id'           => $data['payee_id'],
            'coa_id'             => $data['coa_id'],
            'description'        => $data['description'] ?? null,
            'amount'             => $data['amount'],
            'deduction'          => $deduction,
            'transfer_amount'    => $transfer,
            'remark'             => $data['remark'] ?? null,
        ]);

        return response()->json($item, 201);
    }

    /* ================= Update ================= */

    public function update(Request $r, $prId, $id)
    {
        $user = $r->user();

        $pr = PaymentRequest::findOrFail($prId);
        $this->authorizePR($pr, $user);

        $item = PaymentRequestDetail::where('payment_request_id', $prId)
            ->where('id', $id)
            ->firstOrFail();

        $data = $r->validate([
            'payee_id'     => 'required|exists:payees,id',
            'coa_id'       => 'required|exists:coas,id',
            'description'  => 'nullable|string|max:255',
            'amount'       => 'required|numeric|min:0',
            'deduction'    => 'nullable|numeric|min:0',
            'remark'       => 'nullable|string|max:255',
        ]);

        $deduction = $data['deduction'] ?? 0;
        $transfer = $this->computeTransfer($data['amount'], $deduction);

        $item->update([
            'payee_id'        => $data['payee_id'],
            'coa_id'          => $data['coa_id'],
            'description'     => $data['description'] ?? null,
            'amount'          => $data['amount'],
            'deduction'       => $deduction,
            'transfer_amount' => $transfer,
            'remark'          => $data['remark'] ?? null,
        ]);

        return response()->json($item);
    }

    /* ================= Destroy ================= */

    public function destroy(Request $r, $prId, $id)
    {
        $user = $r->user();

        $pr = PaymentRequest::findOrFail($prId);
        $this->authorizePR($pr, $user);

        $item = PaymentRequestDetail::where('payment_request_id', $prId)
            ->where('id', $id)
            ->firstOrFail();

        $item->delete();

        return response()->json(['success' => true]);
    }
}
