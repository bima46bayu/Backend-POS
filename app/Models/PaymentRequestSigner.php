<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentRequestSigner extends Model
{
    protected $fillable = [
        'name',
        'signature',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function toApiArray(): array
    {
        $signature = $this->signature;
        if (is_string($signature)) {
            $signature = trim($signature);
            $signature = $signature === '' ? null : ltrim(str_replace('\\', '/', $signature), '/');
        } else {
            $signature = null;
        }

        return [
            'id' => (int) $this->id,
            'name' => (string) $this->name,
            'signature' => $signature,
            'is_active' => (bool) $this->is_active,
            'created_at' => optional($this->created_at)?->toDateTimeString(),
            'updated_at' => optional($this->updated_at)?->toDateTimeString(),
        ];
    }
}
