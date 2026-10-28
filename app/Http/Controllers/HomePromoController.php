<?php

namespace App\Http\Controllers;

use App\Models\Promotion;
use Illuminate\Http\Request;

/**
 * Home ads for the member app. Rows in `promotions` show on the customer
 * Home carousel while they are active and inside their dates.
 */
class HomePromoController extends Controller
{
    public function index()
    {
        return Promotion::query()->orderBy('sort_order')->orderByDesc('id')->get();
    }

    public function store(Request $request)
    {
        return response()->json(Promotion::create($this->validated($request)), 201);
    }

    public function update(Request $request, Promotion $promotion)
    {
        $promotion->update($this->validated($request));

        return $promotion->refresh();
    }

    public function destroy(Promotion $promotion)
    {
        $promotion->delete();

        return response()->noContent();
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:140'],
            'badge' => ['nullable', 'string', 'max:40'],
            'description' => ['nullable', 'string', 'max:500'],
            'image_url' => ['nullable', 'url', 'max:500'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'capacity' => ['nullable', 'integer', 'min:1'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }
}
