<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\TransactionIndexRequest;
use App\Http\Resources\Api\V1\TransactionResource;
use App\Services\PointsService;

class TransactionController extends Controller
{
    public function index(TransactionIndexRequest $request, PointsService $points)
    {
        $page = $points->ledger(
            $request->user()->member,
            $request->validated('type'),
            $request->validated('from'),
            $request->validated('to'),
            $request->perPage(),
        );

        return TransactionResource::collection($page->withQueryString());
    }
}
