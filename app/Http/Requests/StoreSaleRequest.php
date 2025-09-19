<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSaleRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    protected function prepareForValidation(): void
    {
        // Normalisasi payment method 'qris' -> 'QRIS'
        $payments = $this->input('payments');
        if (is_array($payments)) {
            foreach ($payments as &$p) {
                if (isset($p['method']) && strtolower($p['method']) === 'qris') {
                    $p['method'] = 'QRIS';
                }
            }
            $this->merge(['payments' => $payments]);
        }
    }

    public function rules(): array
    {
        return [
            'customer_name'           => 'nullable|string|max:100',

            // Items
            'items'                   => 'required|array|min:1',
            'items.*.product_id'      => 'required|integer|exists:products,id',
            'items.*.qty'             => 'required|integer|min:1',
            // jika ingin selalu ambil harga dari DB, ganti ke 'nullable|numeric|min:0'
            'items.*.unit_price'      => 'required|numeric|min:0',
            'items.*.discount_nominal'=> 'nullable|numeric|min:0', // diskon per unit (Rp)

            // Header adjustments
            'discount'                => 'nullable|numeric|min:0', // diskon header (Rp)
            'service_charge'          => 'nullable|numeric|min:0', // biaya layanan (Rp)

            // Pajak: salah satu boleh diisi
            'tax_percent'             => 'nullable|numeric|min:0|max:100',
            'tax'                     => 'nullable|numeric|min:0',

            // Payments
            'payments'                => 'required|array|min:1',
            'payments.*.method'       => 'required|in:cash,card,ewallet,transfer,QRIS',
            'payments.*.amount'       => 'required|numeric|min:0.01',
            'payments.*.reference'    => 'nullable|string|max:100',
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($v) {
            $taxPercent = $this->input('tax_percent');
            $taxNominal = $this->input('tax');

            // Boleh isi salah satu atau keduanya kosong; tidak boleh keduanya terisi bersamaan
            if ($taxPercent !== null && $taxNominal !== null) {
                $v->errors()->add('tax', 'Gunakan salah satu: tax_percent atau tax (nominal), bukan keduanya.');
            }
        });
    }

    public function messages(): array
    {
        return [
            'items.required'      => 'Items tidak boleh kosong.',
            'payments.required'   => 'Payments minimal 1 data.',
            'payments.*.method.in'=> 'Metode pembayaran harus salah satu dari: cash, card, ewallet, transfer, QRIS.',
        ];
    }
}
