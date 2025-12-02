<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use App\Models\StoreLocation;
use Database\Seeders\UnitSeeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Buat store location utama
        $mainStore = StoreLocation::firstOrCreate([
            'code' => 'MAIN',
        ], [
            'name'      => 'Instafacory',
            'address'   => 'Taman Tekno BSD City, Sektor XI No.56 Blok A2, Setu, Kec. Setu, Kota Tangerang Selatan, Banten 15314',
            'phone'     => '081234567890',
        ]);

        // User dummy pakai factory
        User::factory()->create([
            'name'               => 'Test User',
            'email'              => 'test@example.com',
            'store_location_id'  => $mainStore->id,
        ]);

        // Admin default
        User::create([
            'name'               => 'Admin Firman',
            'email'              => 'admin@example.com',
            'password'           => Hash::make('password'),
            'role'               => 'admin',
            'store_location_id'  => $mainStore->id,
        ]);

        User::create([
            'name'               => 'Bima',
            'email'              => 'kasir@example.com',
            'password'           => Hash::make('password'),
            'role'               => 'kasir',
            'store_location_id'  => $mainStore->id,
        ]);

        $this->call(UnitSeeder::class);
    }
}
