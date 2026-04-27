<?php

namespace App\Http\Controllers;

use App\Models\Cart;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Jobs\SendOrderConfirmationJob;
use App\Jobs\UpdateInventoryJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CheckoutController extends Controller
{
    /**
     * Show the checkout page with cart items and shipping form
     */
    public function index()
    {
        if (!Auth::check()) {
            return redirect()->route('login')->with('error', 'Please login to proceed with checkout.');
        }

        // Get cart items for the authenticated user
        $cartItems = Cart::with('product')
            ->where('user_id', Auth::id())
            ->get();

        if ($cartItems->isEmpty()) {
            return redirect()->route('products.index')->with('error', 'Your cart is empty. Add some products to proceed.');
        }

        // Calculate totals
        $subtotal = $cartItems->sum('total');
        $shippingCost = 0; // You can implement shipping calculation logic here
        $tax = 0; // You can implement tax calculation logic here
        $total = $subtotal + $shippingCost + $tax;

        // Get user data
        $user = Auth::user();

        return view('checkout', compact('cartItems', 'subtotal', 'shippingCost', 'tax', 'total', 'user'));
    }

    /**
     * Process the checkout and create order
     */
    public function process(Request $request)
    {
        if (!Auth::check()) {
            return redirect()->route('login')->with('error', 'Please login to proceed with checkout.');
        }

        // Validate the request
        $request->validate([
            'shipping_first_name' => 'required|string|max:255',
            'shipping_last_name' => 'required|string|max:255',
            'shipping_email' => 'required|email|max:255',
            'shipping_phone' => 'required|string|max:20',
            'shipping_address' => 'required|string|max:500',
            'shipping_city' => 'required|string|max:255',
            'shipping_postal_code' => 'required|string|max:10',
            'shipping_country' => 'required|string|max:255',
            'payment_method' => 'required|string|in:payhere,bank_transfer,cash_on_delivery',
        ]);

        try {
            DB::beginTransaction();

            // Get cart items
            $cartItems = Cart::with('product')
                ->where('user_id', Auth::id())
                ->get();

            if ($cartItems->isEmpty()) {
                DB::rollBack();
                return redirect()->route('products.index')->with('error', 'Your cart is empty.');
            }

            // Calculate totals
            $subtotal = $cartItems->sum('total');
            $shippingCost = 0;
            $tax = 0;
            $total = $subtotal + $shippingCost + $tax;

            // Create order
            $order = Order::create([
                'user_id' => Auth::id(),
                'status' => 'pending',
                'payment_status' => 'pending',
                'payment_method' => $request->payment_method,
                'shipping' => $shippingCost,
                'total' => $total,
                'shipping_address' => [
                    'first_name' => $request->shipping_first_name,
                    'last_name' => $request->shipping_last_name,
                    'email' => $request->shipping_email,
                    'phone' => $request->shipping_phone,
                    'address' => $request->shipping_address,
                    'city' => $request->shipping_city,
                    'postal_code' => $request->shipping_postal_code,
                    'country' => $request->shipping_country,
                ],
                'billing_address' => [
                    'first_name' => $request->shipping_first_name,
                    'last_name' => $request->shipping_last_name,
                    'email' => $request->shipping_email,
                    'phone' => $request->shipping_phone,
                    'address' => $request->shipping_address,
                    'city' => $request->shipping_city,
                    'postal_code' => $request->shipping_postal_code,
                    'country' => $request->shipping_country,
                ],
            ]);

            // Create order items
            foreach ($cartItems as $cartItem) {
                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $cartItem->product_id,
                    'quantity' => $cartItem->quantity,
                    'price' => $cartItem->price,
                    'total' => $cartItem->total,
                ]);

                // Update product stock (optional - uncomment if you want to reserve stock)
                // $cartItem->product->decrement('stock_quantity', $cartItem->quantity);
            }

            // Clear cart after successful order creation
            Cart::where('user_id', Auth::id())->delete();

            DB::commit();

            // ── Async Microservice Communication ───────────────────
            // These jobs are dispatched to the Redis queue and processed
            // by the Queue Worker service (separate container).
            // The SendOrderConfirmationJob also publishes to Redis pub/sub,
            // which the Notification Microservice (Node.js) consumes.
            SendOrderConfirmationJob::dispatch($order->id);
            UpdateInventoryJob::dispatch($order->id);

            // Redirect based on payment method
            if ($request->payment_method === 'payhere') {
                // Redirect to PayHere or return order confirmation with payment details
                return redirect()->route('checkout.success', $order->id)
                    ->with('success', 'Order placed successfully! You will be redirected to payment.');
            } else {
                return redirect()->route('checkout.success', $order->id)
                    ->with('success', 'Order placed successfully!');
            }

        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withInput()->with('error', 'Failed to process order. Please try again.');
        }
    }

    /**
     * Show order success page
     */
    public function success($orderId)
    {
        $order = Order::with('orderItems.product')
            ->where('user_id', Auth::id())
            ->where('id', $orderId)
            ->firstOrFail();

        return view('checkout.success', compact('order'));
    }
}
