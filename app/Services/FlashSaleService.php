<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;
use App\Jobs\ProcessInvoiceEmail;

class FlashSaleService
{
    public function listItems(string $sort = 'id', string $order = 'asc')
    {
        $allowedSort = ['id', 'price', 'stock', 'name'];
        if (!in_array($sort, $allowedSort)) {
            $sort = 'id';
        }

        $cacheKey = "flash_sale_items:{$sort}:{$order}";
        $items = Redis::get($cacheKey);

        if (!$items) {
            $items = DB::table('flash_sale_items')
                ->select('id', 'name', 'price', 'stock')
                ->orderBy($sort, $order)
                ->get();

            Redis::setex($cacheKey, 300, json_encode($items));
        } else {
            $items = json_decode($items);
        }

        return $items;
    }

    public function buyItem(int $userId, int $itemId): array
    {
        $redisKey = "flash_sale_item_stock:$itemId";
        $stock = Redis::get($redisKey);

        if ($stock !== null && (int)$stock <= 0) {
            return ['success' => false, 'message' => 'Out of stock'];
        }

        $result = DB::select(
            "SELECT purchase_flash_sale_item(:user_id, :item_id) AS result",
            ['user_id' => $userId, 'item_id' => $itemId]
        );

        $purchaseResult = $result[0]->result ?? 0;

        if ($purchaseResult === 1) {

            if ($stock !== null) {
                Redis::decr($redisKey);
            }

            $prefix = config('database.redis.options.prefix');
            $keys = Redis::keys('flash_sale_items*');

            Log::info('Flash sale cache keys', ['keys' => $keys]);

            foreach ($keys as $key) {
                $cleanKey = str_replace($prefix, '', $key);
                Redis::del($cleanKey);
            }

            ProcessInvoiceEmail::dispatch($userId);

            return ['success' => true, 'message' => 'Order successful'];
        }

        Redis::set($redisKey, 0);
        return ['success' => false, 'message' => 'Out of stock'];
    }
}