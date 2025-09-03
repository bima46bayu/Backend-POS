<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class StockLogController extends Controller
{
    public function index(Request $request)
    {
    $query = StockLog::with(['product','user'])->latest();


    if ($request->filled('product_id')) {
        $query->where('product_id', $request->get('product_id'));
    }


    return $query->paginate(20);
    }


    public function show($id)
    {
        return StockLog::with(['product','user'])->findOrFail($id);
    }
}
