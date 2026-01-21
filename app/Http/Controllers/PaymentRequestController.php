<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\PaymentRequest;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\URL;

class PaymentRequestController extends Controller
{
    /* ===================== INDEX ===================== */
    public function index(Request $request)
    {
        $user = $request->user();

        $q = PaymentRequest::query()
            ->with(['bankAccount', 'storeLocation'])
            ->withSum('items as total_bill', 'amount')
            ->withSum('items as total_discount', 'deduction')
            ->withSum('items as total_transfer', 'transfer_amount');

        // ===============================
        // WAJIB FILTER STORE (SEMUA ROLE)
        // ===============================

        $storeId = $request->input('store_id') 
            ?? $user->store_location_id;

        if (!$storeId) {
            // bisa pilih salah satu:

            // return kosong
            $q->whereRaw('1 = 0');

            // atau kalau mau strict:
            // abort(403, 'Store location not specified');
        } else {
            $q->where('store_location_id', $storeId);
        }

        // ===============================
        // PAGINATION
        // ===============================
        $perPage = (int) $request->get('per_page', 10);

        $prs = $q->latest()->paginate($perPage);

        return response()->json($prs);
    }

    /* ===================== STORE ===================== */

    public function store(Request $request)
    {
        $user = $request->user();

        if (!$user->store_location_id) {
            return response()->json([
                'message' => 'User belum memiliki store location'
            ], 422);
        }

        $data = $request->validate([
            'main_bank_account_id' => ['required', 'exists:bank_accounts,id'],
            'currency' => ['required', 'string', 'max:10'],
        ]);

        // otomatis dari user login
        $data['store_location_id'] = $user->store_location_id;

        $pr = PaymentRequest::create($data);

        return response()->json($pr, 201);
    }

    /* ===================== SHOW ===================== */

    public function show($id, Request $request)
    {
        $user = $request->user();

        $pr = PaymentRequest::with([
                'storeLocation',
                'bankAccount',
                'items.payee',
                'items.coa',
                'balances.bankAccount',
            ])
            ->findOrFail($id);

        if ($pr->store_location_id !== $user->store_location_id) {
            abort(403);
        }

        return response()->json($pr);
    }

    /* ===================== DESTROY ===================== */

    public function destroy($id, Request $request)
    {
        $user = $request->user();

        $pr = PaymentRequest::findOrFail($id);

        if ($pr->store_location_id !== $user->store_location_id) {
            abort(403);
        }

        $pr->delete();

        return response()->noContent();
    }

    public function getPdfLink($id)
    {
        $url = URL::signedRoute('payment.pdf', ['id' => $id]);

        return response()->json([
            'pdf_url' => $url
        ]);
    }

    public function pdf($id)
    {
        $pr = PaymentRequest::with([
            'items.payee',
            'items.coa',
            'balances.bankAccount',
            'storeLocation',
            'bankAccount'
        ])->findOrFail($id);

        // Authorization check
        if ($pr->user_id !== auth()->id()) {
            abort(403, 'Unauthorized');
        }

        $totalTagihan  = $pr->items->sum('amount');
        $totalPotongan = $pr->items->sum('deduction');
        $totalTransfer = $pr->items->sum('transfer_amount');
        $totalSaldo    = $pr->balances->sum('saldo');

        $pdf = Pdf::loadView('pdf.payment-request', compact(
            'pr',
            'totalTagihan',
            'totalPotongan',
            'totalTransfer',
            'totalSaldo'
        ))
        ->setPaper('A4', 'portrait')
        ->setOptions([
            'isPhpEnabled' => true,
            'isRemoteEnabled' => true,
            'chroot' => public_path(),
        ]);

        return $pdf->stream("Payment Request {$pr->id}.pdf");
    }
}
