<?php

namespace App\Services;

use App\Models\Unit;

class UnitConversionService
{
    /** @return array<string, string> canonical key => base unit in family */
    private static function aliases(): array
    {
        return [
            'kg'     => 'kg',
            'kilogram' => 'kg',
            'kilograms' => 'kg',
            'g'      => 'g',
            'gr'     => 'g',
            'gram'   => 'g',
            'grams'  => 'g',
            'l'      => 'l',
            'liter'  => 'l',
            'litre'  => 'l',
            'ltr'    => 'l',
            'ml'     => 'ml',
            'milliliter' => 'ml',
            'millilitre' => 'ml',
        ];
    }

    public static function normalize(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }

        $key = strtolower(trim($name));

        return self::aliases()[$key] ?? $key;
    }

    public static function toBaseAmount(float $qty, ?string $unitName): float
    {
        $unit = self::normalize($unitName);

        return match ($unit) {
            'g'  => $qty / 1000,
            'ml' => $qty / 1000,
            default => $qty,
        };
    }

    public static function fromBaseAmount(float $baseQty, ?string $unitName): float
    {
        $unit = self::normalize($unitName);

        return match ($unit) {
            'g'  => $baseQty * 1000,
            'ml' => $baseQty * 1000,
            default => $baseQty,
        };
    }

    /**
     * Convert qty from one unit to another (e.g. 150 Gram → 0.15 Kg).
     */
    public static function convert(float $qty, Unit|string|null $from, Unit|string|null $to): float
    {
        $fromName = $from instanceof Unit ? $from->name : $from;
        $toName = $to instanceof Unit ? $to->name : $to;

        $fromKey = self::normalize($fromName);
        $toKey = self::normalize($toName);

        if ($fromKey === null || $toKey === null) {
            throw new \InvalidArgumentException('Satuan asal dan tujuan wajib diisi.');
        }

        if ($fromKey === $toKey) {
            return $qty;
        }

        $families = [
            'mass'   => ['kg', 'g'],
            'volume' => ['l', 'ml'],
        ];

        foreach ($families as $members) {
            if (in_array($fromKey, $members, true) && in_array($toKey, $members, true)) {
                $base = self::toBaseAmount($qty, $fromKey);

                return self::fromBaseAmount($base, $toKey);
            }
        }

        throw new \InvalidArgumentException(
            "Konversi satuan \"{$fromName}\" ke \"{$toName}\" tidak didukung. "
            .'Gunakan pasangan Kg/Gram atau L/Ml, atau satuan yang sama.'
        );
    }
}
