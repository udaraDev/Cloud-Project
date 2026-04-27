<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\Product;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * UpdateInventoryJob
 * 
 * This job runs asynchronously in the Queue Worker service to update
 * product stock quantities after an order is placed.
 * 
 * By decoupling inventory updates from the checkout flow:
 *   - Checkout responds faster (better UX)
 *   - Inventory updates can be retried on failure
 *   - Stock reconciliation happens independently
 * 
 * Communication flow:
 *   Laravel App → Redis Queue (async) → Queue Worker → Database (stock update)
 */
class UpdateInventoryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * Number of seconds to wait before retrying.
     */
    public int $backoff = 15;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $orderId
    ) {}

    /**
     * Execute the job — decrement stock for each item in the order.
     */
    public function handle(): void
    {
        $order = Order::with('orderItems')->find($this->orderId);

        if (!$order) {
            Log::warning("UpdateInventoryJob: Order #{$this->orderId} not found.");
            return;
        }

        DB::beginTransaction();

        try {
            foreach ($order->orderItems as $item) {
                $product = Product::find($item->product_id);

                if (!$product) {
                    Log::warning("UpdateInventoryJob: Product #{$item->product_id} not found, skipping.");
                    continue;
                }

                // Decrement stock (with floor at 0)
                $newStock = max(0, $product->stock_quantity - $item->quantity);
                $product->update([
                    'stock_quantity' => $newStock,
                    'in_stock' => $newStock > 0,
                ]);

                Log::info("UpdateInventoryJob: Product #{$product->id} stock updated.", [
                    'product' => $product->name,
                    'previous_stock' => $product->stock_quantity + $item->quantity,
                    'ordered_quantity' => $item->quantity,
                    'new_stock' => $newStock,
                ]);
            }

            DB::commit();

            Log::info("UpdateInventoryJob: Inventory updated successfully for order #{$order->id}.", [
                'items_count' => $order->orderItems->count(),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("UpdateInventoryJob: Failed for order #{$this->orderId}", [
                'error' => $e->getMessage(),
            ]);
            throw $e; // Re-throw so the queue worker can retry
        }
    }

    /**
     * Handle a job failure after all retries exhausted.
     */
    public function failed(?\Throwable $exception): void
    {
        Log::error("UpdateInventoryJob: Permanently failed for order #{$this->orderId}", [
            'error' => $exception?->getMessage(),
        ]);

        // Optionally: notify admin about failed inventory update
    }
}
