<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\FlashSaleService;

class FlashSaleController extends Controller
{
    protected FlashSaleService $flashSaleService;

    public function __construct(FlashSaleService $flashSaleService)
    {
        $this->flashSaleService = $flashSaleService;
    }

    public function list(Request $request)
    {
        $sort = $request->input('sort', 'id');
        $order = $request->input('order', 'asc');

        $items = $this->flashSaleService->listItems($sort, $order);

        return response()->json($items);
    }

    public function buy(Request $request)
    {
        $userId = $request->user()->id ?? $request->input('user_id');
        $itemId = $request->input('item_id');

        $result = $this->flashSaleService->buyItem($userId, $itemId);

        return response()->json([
            'message' => $result['message']
        ], $result['success'] ? 200 : 400);
    }
}