<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\SubCategory;

class SubCategoryController extends Controller {
    public function index() {
        return SubCategory::with('category')->paginate(20);
    }

    public function store(Request $r) {
        $data = $r->validate([
            'category_id'=>'required|exists:categories,id',
            'name'=>'required|string'
        ]);
        return SubCategory::create($data);
    }

    public function show(SubCategory $subCategory) {
        return $subCategory->load('category');
    }

    public function update(Request $r, SubCategory $subCategory) {
        $data = $r->validate([
            'category_id'=>'required|exists:categories,id',
            'name'=>'required|string'
        ]);
        $subCategory->update($data);
        return $subCategory;
    }

    public function destroy(SubCategory $subCategory) {
        $subCategory->delete();
        return response()->json(['message'=>'Deleted']);
    }
}

