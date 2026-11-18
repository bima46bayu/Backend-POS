<?php

namespace App\Http\Requests;

use App\Models\AppServer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAppServerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name')),
            'base_url' => AppServer::normalizeBaseUrl((string) $this->input('base_url')),
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            'base_url' => [
                'required',
                'string',
                'max:255',
                Rule::unique('app_servers', 'base_url'),
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value) || ! AppServer::isHttpUrl($value)) {
                        $fail('Alamat server harus berupa URL http atau https.');
                    }
                },
            ],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Nama wajib diisi.',
            'base_url.required' => 'Alamat server wajib diisi.',
            'base_url.unique' => 'Alamat server ini sudah ada.',
        ];
    }
}
