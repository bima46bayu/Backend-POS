<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // atau pakai logic auth kamu
    }

    public function rules(): array
    {
        // route model binding: /products/{product}
        // kalau route-mu pakai /products/{id}, ganti ke: $product = $this->route('id');
        $product = $this->route('product');
        $id = is_object($product) ? $product->id : $product;

        return [
            'name'            => ['required', 'string', 'max:150'],
            'price'           => ['required', 'numeric', 'min:0'],
            // Purchase/landed cost, separate from the sell price above.
            'cost_price'      => ['nullable', 'numeric', 'min:0'],
            // Pack purchasing: buy a Pack of 100, stock/sell per Pcs.
            'pack_size'       => ['nullable', 'numeric', 'min:0'],
            'pack_label'      => ['nullable', 'string', 'max:32'],
            'stock'           => ['nullable', 'integer', 'min:0'],
            'sku'             => [
                'required',
                'string',
                'max:50',
                Rule::unique('products', 'sku')->ignore($id),
            ],
            'description'     => ['nullable', 'string'],
            'category_id'     => ['nullable', 'integer', 'exists:categories,id'],
            'sub_category_id' => ['nullable', 'integer', 'exists:sub_categories,id'],

            // ✅ penting: unit_id ikut tervalidasi, biar nggak dibuang dari validated()
            'unit_id'         => ['nullable', 'integer', 'exists:units,id'],

            // image opsional
            'image'           => ['sometimes', 'file', 'mimes:jpg,jpeg,png,webp,svg,svg+xml', 'max:5120'],
            
            'inventory_type' => ['required', 'in:stock,non_stock'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $toNull = fn ($v) => ($v === '' || $v === 'null' || $v === null) ? null : $v;

        $this->merge([
            'description'     => $toNull($this->input('description')),
            'category_id'     => $toNull($this->input('category_id')),
            'sub_category_id' => $toNull($this->input('sub_category_id')),

            'unit_id'         => $this->input('unit_id') !== null && $this->input('unit_id') !== ''
                ? (int) $this->input('unit_id')
                : null,

            'price'           => $this->input('price') !== null && $this->input('price') !== ''
                ? (float) str_replace(',', '.', $this->input('price'))
                : null,

            'cost_price'      => $this->input('cost_price') !== null && $this->input('cost_price') !== ''
                ? (float) str_replace(',', '.', $this->input('cost_price'))
                : null,

            // pack_size <= 1 is a no-op divisor; normalise it away so nothing
            // downstream has to special-case it.
            'pack_size'       => $this->input('pack_size') !== null && $this->input('pack_size') !== ''
                ? ((float) str_replace(',', '.', $this->input('pack_size')) > 1
                    ? (float) str_replace(',', '.', $this->input('pack_size'))
                    : null)
                : null,

            'stock'           => $this->input('stock') !== null && $this->input('stock') !== ''
                ? (int) $this->input('stock')
                : null,
        ]);
    }
}
