<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppServer extends Model
{
    protected $fillable = [
        'name',
        'base_url',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    /**
     * The address stored and shown to the mobile app: scheme included, no
     * trailing slash, so "192.168.1.10:8000/" and "http://192.168.1.10:8000"
     * are the same server.
     */
    public static function normalizeBaseUrl(string $raw): string
    {
        $value = trim($raw);
        if ($value === '') {
            return $value;
        }
        if (! preg_match('#^https?://#i', $value)) {
            $value = 'http://'.$value;
        }

        return rtrim($value, '/');
    }

    public static function isHttpUrl(string $value): bool
    {
        $parts = parse_url($value);
        if (! is_array($parts)) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');

        return in_array($scheme, ['http', 'https'], true) && $host !== '';
    }
}
