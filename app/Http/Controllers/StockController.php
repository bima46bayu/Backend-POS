<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\StockLog;
use App\Models\Product;

class StockController extends Controller
{
    public function change(Request $request, Product $product)
    {
        $data = $request->validate([
            'change_type' => 'required|in:in,out,adjustment',
            'quantity'    => 'required|integer|min:1',
            'note'        => 'nullable|string',
        ]);

        if ($data['change_type'] === 'in') {
            $product->increment('stock', $data['quantity']);
        } elseif ($data['change_type'] === 'out') {
            if ($product->stock < $data['quantity']) {
                return response()->json(['message' => 'Stok tidak cukup'], 422);
            }
            $product->decrement('stock', $data['quantity']);
        } else { // adjustment → set absolute
            $product->update(['stock' => $data['quantity']]);
        }

        $log = StockLog::create([
            'product_id'  => $product->id,
            'user_id'     => $request->user()->id,
            'change_type' => $data['change_type'],
            'quantity'    => $data['quantity'],
            'note'        => $data['note'] ?? null,
        ]);

        return response()->json([
            'product' => $product->fresh(),
            'log'     => $log,
        ]);
    }


}
