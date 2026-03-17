# Mega Flash Sale Backend

## About This App

This application is the **backend service for the Mega Flash Sale system**.

Mega Flash Sale is a simple application that simulates a flash sale purchasing process where multiple users may attempt to buy the same product at the same time. In this scenario, **race conditions** may occur when multiple requests attempt to update the same stock simultaneously.

This backend demonstrates how to handle these situations using:

- Redis caching
- PostgreSQL transactions
- Stored procedures
- RabbitMQ background workers
- Laravel queue system

---

# Setup

Create a new Laravel project using the following command:

```bash
composer create-project laravel/laravel backend
```

After the project is generated, navigate into the project directory and initialize git.

```bash
git init
```

You can then connect the repository to GitHub.

Laravel already includes many useful libraries by default. However, to integrate **Redis** and **RabbitMQ**, we need to install additional packages.

```bash
composer require vladimir-yuldashev/laravel-queue-rabbitmq predis/predis
```

---

## Laravel 11 API Setup

In Laravel 11, the configuration structure is slightly simpler compared to Laravel 10 and earlier versions.

Previously, API routes were usually registered manually through the `RouteServiceProvider`. In Laravel 11, you can enable API routing using:

```bash
php artisan install:api
```

This command generates the `api.php` route file.

---

# Infrastructure Setup

Next, create several containers using **Docker Compose**.

```
services:
  postgres:
    image: postgres:16-alpine
    container_name: fs_postgres
    ports:
      - "5432:5432"
    environment:
      POSTGRES_DB: fullstack_db
      POSTGRES_USER: user
      POSTGRES_PASSWORD: password
    volumes:
      - pgdata:/var/lib/postgresql/data
    networks:
      - app-network

  redis:
    image: redis:alpine
    container_name: fs_redis
    ports:
      - "6379:6379"
    networks:
      - app-network

  rabbitmq:
    image: rabbitmq:3-management
    container_name: fs_rabbitmq
    ports:
      - "5672:5672"
      - "15672:15672"
    environment:
      RABBITMQ_DEFAULT_USER: user
      RABBITMQ_DEFAULT_PASS: password
    networks:
      - app-network

networks:
  app-network:
    driver: bridge

volumes:
  pgdata:
```

Run the compose file to start the containers:

- PostgreSQL
- Redis
- RabbitMQ

---

# Environment Configuration

For the `.env` configuration, you can copy the example file:

```
.env.example
```

Then adjust the values according to the Docker Compose configuration.

---

# Database Migrations

Create migration files using:

```bash
php artisan make:migration {migration_name}
```

In this project, the following migrations are created:

1. `create_flash_sale_items_table`
2. `create_purchase_flash_sale_item_sp`

The first migration creates tables for:

- items
- orders

The second migration creates a **stored procedure** used for purchasing items and decreasing stock.

Run the migrations using:

```bash
php artisan migrate
```

---

# Database Seeder

To populate the database with dummy data, update `DatabaseSeeder.php`.

```php
class DatabaseSeeder extends Seeder
{
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
            ['name' => 'iPhone 15', 'price' => 1, 'stock' => 100],
            ['name' => 'Samsung S24', 'price' => 1, 'stock' => 80],
            ['name' => 'MacBook Air M3', 'price' => 5, 'stock' => 50],
            ['name' => 'AirPods Pro', 'price' => 1, 'stock' => 120],
            ['name' => 'iPad Pro', 'price' => 2, 'stock' => 70],
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
```

Run the seeder:

```bash
php artisan db:seed
```

---

# Service Layer

Next, implement the business logic inside the **Service layer**.

Services are typically placed inside:

```
app/Services
```

Example service:

```php
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

            $keys = Redis::keys('flash_sale_items*');

            foreach ($keys as $key) {
                Redis::del($key);
            }

            ProcessInvoiceEmail::dispatch($userId);

            return ['success' => true, 'message' => 'Order successful'];
        }

        Redis::set($redisKey, 0);
        return ['success' => false, 'message' => 'Out of stock'];
    }
}
```

This service contains two methods:

### `listItems()`

- Checks if the data already exists in Redis cache
- If not, it fetches data from the database
- The result is cached in Redis for 5 minutes

### `buyItem()`

1. Check stock in Redis
2. Call a **PostgreSQL stored procedure**
3. If successful:
   - decrease Redis stock
   - invalidate item cache
   - dispatch a RabbitMQ job

---

# Controller

Next, create the controller.

```php
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
```

The controller simply delegates the request to the service layer.

Laravel's `input()` method can be used for both:

- query parameters
- request body values

---

# Routes

Register the API routes in `api.php`.

```php
Route::get('/flash-sale/items', [FlashSaleController::class, 'list']);
Route::post('/flash-sale/buy', [FlashSaleController::class, 'buy']);
```

---

# Queue Worker

Create a job using:

```bash
php artisan make:job ProcessInvoiceEmail
```

Example implementation:

```php
class ProcessInvoiceEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $userId;

    public function __construct($userId)
    {
        $this->userId = $userId;
    }

    public function handle()
    {
        $user = \App\Models\User::find($this->userId);

        Mail::raw("Your flash sale invoice", function ($message) use ($user) {
            $message->to($user->email)->subject("Flash Sale Invoice");
        });
    }
}
```

When dispatched, this job sends an **invoice email** to the user.

---

# Running the Application

Run the Laravel server:

```bash
php artisan serve --host=0.0.0.0 --port=8000 --no-reload
```

Run the queue worker:

```bash
php artisan queue:work
```

---

# Load Testing

You can perform load testing using **Apache Benchmark**:

```bash
ab -n 1000 -c 100 -p post_data.json -T application/json http://127.0.0.1:8000/api/flash-sale/buy
```

Make sure to create a `post_data.json` file containing the request body for the test.