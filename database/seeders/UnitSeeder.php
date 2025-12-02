<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Unit;

class UnitSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            'Pcs',
            'Box',
            'Pack',
            'Kg',
            'Gram',
            'L',
            'Ml',
        ];

        foreach ($defaults as $name) {
            Unit::updateOrCreate(
                ['name' => $name],
                ['is_system' => true]
            );
        }
    }
}

