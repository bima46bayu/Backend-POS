<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\SubCategory;
use App\Models\Product;

class Category extends Model
{
    use HasFactory;
    protected $fillable = ['name','description'];

    public function subCategories()
    {
        return $this->hasMany(SubCategory::class);
    }
    
    public function products() {
        return $this->hasMany(Product::class);
    }
}