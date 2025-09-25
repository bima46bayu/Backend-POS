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
        // Ambil ID dari route model binding: /products/{product}
        // Jika kamu pakai /products/{id}, ubah ke: $id = $this->route('id');
        $product = $this->route('product'); 
        $id = is_object($product) ? $product->id : $product;

        return [
            'name'            => ['required','string','max:150'],
            'price'           => ['required','numeric','min:0'],
            'stock'           => ['nullable','integer','min:0'],
            'sku'             => [
                'required','string','max:50',
                Rule::unique('products','sku')->ignore($id),
            ],
            'description'     => ['nullable','string'],
            'category_id'     => ['nullable','integer','exists:categories,id'],
            'sub_category_id' => ['nullable','integer','exists:sub_categories,id'],
            // kalau update bisa kirim file langsung
            'image'           => ['sometimes','file','mimes:jpg,jpeg,png,svg','max:10240'], // 10MB
        ];
    }

    /**
     * Normalisasi input agar "" -> null untuk field nullable,
     * dan cast angka supaya konsisten.
     */
    protected function prepareForValidation(): void
    {
        $toNull = fn($v) => ($v === '' || $v === 'null' || $v === null) ? null : $v;

        $this->merge([
            'description'     => $toNull($this->input('description')),
            'category_id'     => $toNull($this->input('category_id')),
            'sub_category_id' => $toNull($this->input('sub_category_id')),
            'price'           => $this->input('price') !== null ? (float) $this->input('price') : null,
            'stock'           => $this->input('stock') !== null && $this->input('stock') !== '' ? (int) $this->input('stock') : null,
        ]);
    }
}
