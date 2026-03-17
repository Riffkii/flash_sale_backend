<?php

namespace Database\Seeders;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run()
    {
        DB::table('users')->insert([
            'name' => 'Test User',
            'email' => 'riffkikris13@gmail.com',
            'password' => Hash::make('password'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $items = [
            [
                'name' => 'iPhone 15',
                'price' => 1,
                'stock' => 100,
            ],
            [
                'name' => 'Samsung S24',
                'price' => 1,
                'stock' => 80,
            ],
            [
                'name' => 'MacBook Air M3',
                'price' => 5,
                'stock' => 50,
            ],
            [
                'name' => 'AirPods Pro',
                'price' => 1,
                'stock' => 120,
            ],
            [
                'name' => 'iPad Pro',
                'price' => 2,
                'stock' => 70,
            ],
        ];

        foreach ($items as $item) {
            $itemId = DB::table('flash_sale_items')->insertGetId([
                'name' => $item['name'],
                'price' => $item['price'],
                'stock' => $item['stock'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $redisKey = "flash_sale_item_stock:$itemId";
            Redis::set($redisKey, $item['stock']);
        }
    }
}
