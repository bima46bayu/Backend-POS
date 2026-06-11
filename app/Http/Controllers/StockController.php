<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Product;

class StockController extends Controller
{
    /** @deprecated Use inventory layers / stock opname per cabang. */
    public function change(Request $request, Product $product)
    {
        return response()->json([
            'message' => 'Endpoint deprecated. Use inventory layers / stock opname per cabang.',
        ], 410);
    }
}
