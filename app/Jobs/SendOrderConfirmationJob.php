<?php

namespace App\Jobs;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * SendOrderConfirmationJob
 * 
 * This job is dispatched asynchronously after an order is placed.
 * It runs in the Queue Worker service (separate container) and:
 *   1. Loads the order details from the shared database
 *   2. Publishes an event to Redis pub/sub channel "order:confirmed"
 *   3. The Notification Microservice (Node.js) picks up the event and sends the email
 * 
 * Communication flow:
 *   Laravel App → Redis Queue (async) → Queue Worker → Redis Pub/Sub → Notification Service → Email
 */
class SendOrderConfirmationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * Number of seconds to wait before retrying.
     */
    public int $backoff = 30;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $orderId
    ) {}

    /**
     * Execute the job.
     * 
     * Loads the order, prepares the notification payload,
     * and publishes to the Redis pub/sub channel for the
     * Notification Microservice to consume.
     */
    public function handle(): void
    {
        $order = Order::with('orderItems.product', 'user')->find($this->orderId);

        if (!$order) {
            Log::warning("SendOrderConfirmationJob: Order #{$this->orderId} not found.");
            return;
        }

        // Prepare notification payload for the Notification Microservice
        $payload = [
            'order_id' => $order->id,
            'email' => $order->shipping_address['email'] ?? $order->user->email,
            'customer_name' => $order->customer_name,
            'total' => $order->total,
            'payment_method' => $order->payment_method,
            'items' => $order->orderItems->map(function ($item) {
                return [
                    'name' => $item->product->name ?? 'Product',
                    'quantity' => $item->quantity,
                    'price' => $item->price,
                    'total' => $item->total,
                ];
            })->toArray(),
        ];

        // Publish to Redis pub/sub — the Notification Service (Node.js) subscribes to this channel
        Redis::publish('order:confirmed', json_encode($payload));

        Log::info("SendOrderConfirmationJob: Published order #{$order->id} confirmation to notification service.", [
            'order_id' => $order->id,
            'email' => $payload['email'],
        ]);
    }

    /**
     * Handle a job failure.
     */
    public function failed(?\Throwable $exception): void
    {
        Log::error("SendOrderConfirmationJob: Failed for order #{$this->orderId}", [
            'error' => $exception?->getMessage(),
        ]);
    }
}
