<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class StockController extends Controller
{
    public function change(Request $request, Product $product)
    {
        $data = $request->validate([
            'change_type' => 'required|in:in,out,adjustment',
            'quantity' => 'required|integer|min:1',
            'note' => 'nullable|string'
    ]);


    if ($data['change_type'] === 'in') {
        $product->increment('stock', $data['quantity']);
    } else {
        $product->decrement('stock', $data['quantity']);
    }


    $log = StockLog::create([
        'product_id' => $product->id,
        'user_id' => $request->user()->id,
        'change_type' => $data['change_type'],
        'quantity' => $data['quantity'],
        'note' => $data['note'] ?? null,
    ]);


    return response()->json([
        'product' => $product->fresh(),
        'log' => $log
        ]);
    }
}
