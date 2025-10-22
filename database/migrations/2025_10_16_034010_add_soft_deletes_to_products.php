<?php

// database/migrations/xxxx_add_soft_deletes_to_products.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  public function up(): void {
    Schema::table('products', function (Blueprint $t) {
      $t->softDeletes();
    });
  }
  public function down(): void {
    Schema::table('products', function (Blueprint $t) {
      $t->dropSoftDeletes();
    });
  }
};

