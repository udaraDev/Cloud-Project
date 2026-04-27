<?php

use App\Jobs\SendOrderConfirmationJob;
use App\Jobs\UpdateInventoryJob;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

/**
 * Tests for the asynchronous order processing pipeline.
 * 
 * These tests verify:
 *   1. Jobs are dispatched to the queue when an order is placed
 *   2. SendOrderConfirmationJob correctly loads order data
 *   3. UpdateInventoryJob correctly decrements stock
 */

test('checkout dispatches async jobs to queue', function () {
    Queue::fake();

    $user = User::factory()->create();
    $product = Product::factory()->create(['stock_quantity' => 50, 'price' => 500]);

    $this->actingAs($user)
        ->post('/cart/add', ['product_id' => $product->id, 'quantity' => 1]);

    $this->actingAs($user)
        ->post('/checkout', [
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => $user->email,
            'phone' => '0771234567',
            'address' => '123 Test Street',
            'city' => 'Kandy',
            'postal_code' => '20000',
            'country' => 'Sri Lanka',
            'payment_method' => 'cash_on_delivery',
        ]);

    Queue::assertPushed(SendOrderConfirmationJob::class);
    Queue::assertPushed(UpdateInventoryJob::class);
});

test('update inventory job decrements product stock', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create(['stock_quantity' => 100, 'price' => 850]);

    $order = Order::create([
        'user_id' => $user->id,
        'customer_name' => 'Test User',
        'total' => 850,
        'status' => 'pending',
        'payment_status' => 'pending',
        'payment_method' => 'cash_on_delivery',
        'shipping_address' => json_encode([
            'address' => '123 Test St',
            'city' => 'Kandy',
            'postal_code' => '20000',
            'country' => 'Sri Lanka',
        ]),
    ]);

    $order->orderItems()->create([
        'product_id' => $product->id,
        'quantity' => 3,
        'price' => 850,
        'total' => 2550,
    ]);

    $job = new UpdateInventoryJob($order->id);
    $job->handle();

    $product->refresh();
    expect($product->stock_quantity)->toBe(97);
});

test('send order confirmation job does not fail for valid order', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create(['stock_quantity' => 50, 'price' => 500]);

    $order = Order::create([
        'user_id' => $user->id,
        'customer_name' => 'Test User',
        'total' => 500,
        'status' => 'pending',
        'payment_status' => 'pending',
        'payment_method' => 'cash_on_delivery',
        'shipping_address' => json_encode([
            'email' => $user->email,
            'address' => '123 Test St',
            'city' => 'Kandy',
        ]),
    ]);

    $order->orderItems()->create([
        'product_id' => $product->id,
        'quantity' => 1,
        'price' => 500,
        'total' => 500,
    ]);

    $job = new SendOrderConfirmationJob($order->id);

    // Should not throw — Redis::publish fallback to logging
    expect(fn() => $job->handle())->not->toThrow(Exception::class);
});

test('send order confirmation job handles missing order gracefully', function () {
    $job = new SendOrderConfirmationJob(99999);

    // Should not throw for missing order — just logs warning
    expect(fn() => $job->handle())->not->toThrow(Exception::class);
});
