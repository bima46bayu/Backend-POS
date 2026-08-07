<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\AppSetting;
use App\Models\PaymentRequest;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\URL;

class PaymentRequestController extends Controller
{
    use Concerns\AuthorizesStoreAccess;

    /* ===================== INDEX ===================== */
    public function index(Request $request)
    {
        $user = $request->user();

        $q = PaymentRequest::query()
            ->with(['bankAccount', 'storeLocation'])
            ->withSum('items as total_bill', 'amount')
            ->withSum('items as total_discount', 'deduction')
            ->withSum('items as total_transfer', 'transfer_amount');

        // Prefer explicit store picker (store_id / store_location_id).
        // Do NOT fall back to "all stores" for HQ — require a store like other ops.
        $storeId = null;
        if ($request->filled('store_id') || $request->filled('store_location_id')) {
            $storeId = $this->resolveStoreIdFromRequest($request);
        } elseif ($user->store_location_id) {
            $storeId = (int) $user->store_location_id;
            $this->authorizeStoreAccess($user, $storeId);
        }

        if (! $storeId) {
            $q->whereRaw('1 = 0');
        } else {
            $q->where('store_location_id', $storeId);
        }

        $perPage = (int) $request->get('per_page', 10);
        $prs = $q->latest()->paginate($perPage);

        return response()->json($prs);
    }

    /* ===================== STORE ===================== */

    public function store(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'main_bank_account_id' => ['required', 'exists:bank_accounts,id'],
            'currency' => ['required', 'string', 'max:10'],
            'store_location_id' => ['nullable', 'integer', 'exists:store_locations,id'],
            'store_id' => ['nullable', 'integer', 'exists:store_locations,id'],
        ]);

        $storeId = $this->resolveStoreIdFromRequest(
            $request,
            isset($data['store_location_id'])
                ? (int) $data['store_location_id']
                : (isset($data['store_id']) ? (int) $data['store_id'] : null)
        );

        if (! $storeId) {
            return response()->json([
                'message' => 'store_location_id wajib. Pilih cabang terlebih dahulu.',
            ], 422);
        }

        $pr = PaymentRequest::create([
            'main_bank_account_id' => $data['main_bank_account_id'],
            'currency' => $data['currency'],
            'store_location_id' => $storeId,
        ]);

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

        $this->authorizeStoreAccess($user, (int) $pr->store_location_id);

        return response()->json($pr);
    }

    /* ===================== DESTROY ===================== */

    public function destroy($id, Request $request)
    {
        $user = $request->user();

        $pr = PaymentRequest::findOrFail($id);

        $this->authorizeStoreAccess($user, (int) $pr->store_location_id);

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
        $signatories   = AppSetting::paymentRequestSignatories();

        $pdf = Pdf::loadView('pdf.payment-request', compact(
            'pr',
            'totalTagihan',
            'totalPotongan',
            'totalTransfer',
            'totalSaldo',
            'signatories'
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
