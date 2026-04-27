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

    $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);

    \App\Models\Cart::create([
        'user_id' => $user->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'price' => $product->price,
        'total' => $product->price
    ]);

    $response2 = $this->actingAs($user)
        ->post('/checkout/process', [
            'shipping_first_name' => 'Test',
            'shipping_last_name' => 'User',
            'shipping_email' => $user->email,
            'shipping_phone' => '0771234567',
            'shipping_address' => '123 Test Street',
            'shipping_city' => 'Kandy',
            'shipping_postal_code' => '20000',
            'shipping_country' => 'Sri Lanka',
            'payment_method' => 'cash_on_delivery',
        ]);
        
    $response2->assertSessionHasNoErrors();
    $response2->assertRedirect(); // Usually redirects to success page
    
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
        'billing_address' => json_encode([
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
        'billing_address' => json_encode([
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
