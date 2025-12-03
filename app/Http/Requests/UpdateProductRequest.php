<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // atau logic auth kamu
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

            // ✅ ini yang tadi belum ada → bikin unit_id ikut tervalidasi & tidak dibuang
            'unit_id'         => ['nullable', 'integer', 'exists:units,id'],

            // kalau update bisa kirim file langsung
            'image'           => ['sometimes', 'file', 'mimes:jpg,jpeg,png,svg', 'max:10240'], // 10MB
        ];
    }

    /**
     * Normalisasi input:
     * - "" → null untuk field nullable
     * - cast angka supaya konsisten
     */
    protected function prepareForValidation(): void
    {
        $toNull = fn ($v) => ($v === '' || $v === 'null' || $v === null) ? null : $v;

        $this->merge([
            'description'     => $toNull($this->input('description')),
            'category_id'     => $toNull($this->input('category_id')),
            'sub_category_id' => $toNull($this->input('sub_category_id')),

            // ✅ normalisasi unit_id (boleh kosong → null)
            'unit_id'         => $this->input('unit_id') !== null && $this->input('unit_id') !== ''
                ? (int) $this->input('unit_id')
                : null,

            'price'           => $this->input('price') !== null && $this->input('price') !== ''
                ? (float) $this->input('price')
                : null,

            'stock'           => $this->input('stock') !== null && $this->input('stock') !== ''
                ? (int) $this->input('stock')
                : null,
        ]);
    }
}
