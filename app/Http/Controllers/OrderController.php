<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Cart;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Coupon;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class OrderController extends Controller
{
    /**
     * Display customer order history.
     */
    public function index()
    {
        $orders = Order::where('user_id', Auth::id())
            ->with(['items.product'])
            ->latest()
            ->paginate(10);
            
        if (request()->wantsJson() || request()->is('api/*')) {
            return \App\Http\Resources\OrderResource::collection($orders);
        }
            
        return view('orders.index', compact('orders'));
    }

    /**
     * Display specific order details.
     */
    public function show($id)
    {
        // Check if the order belongs to the user
        $order = Order::with(['items.product'])
            ->where('id', $id)
            ->where('user_id', Auth::id())
            ->first();

        // If not authorized, redirect to their most recent order instead of showing an error
        if (!$order) {
            $latestOrder = Order::where('user_id', Auth::id())->latest()->first();
            if ($latestOrder) {
                return redirect()->route('orders.show', $latestOrder->id)
                    ->with('warning', 'You do not have access to that acquisition. Showing your most recent one.');
            }
            return redirect()->route('orders.index')->with('error', 'Unauthorized access.');
        }
            
        if (request()->wantsJson() || request()->is('api/*')) {
            return new \App\Http\Resources\OrderResource($order);
        }
            
        return view('orders.show', compact('order'));
    }

    /**
     * Cancel a pending order (Collector-side).
     */
    public function cancel(Request $request, $id)
    {
        $order = Order::where('user_id', Auth::id())->findOrFail($id);
        
        if ($order->status !== Order::STATUS_PENDING) {
            return back()->with('error', 'Only pending orders can be cancelled from your garage.');
        }

        try {
            DB::statement('CALL sp_CancelOrder(?, ?, ?)', [
                $id, 
                Auth::id(), 
                $request->cancellation_reason ?? 'No reason provided'
            ]);
            return redirect()->route('orders.index')->with('success', 'Order cancelled successfully. Items returned to stock.');
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Show checkout page with collector options.
     */
    public function checkout(Request $request)
    {
        $cart = Cart::with('items.product')->where('user_id', Auth::id())->first();
        
        if (!$cart || $cart->items->isEmpty()) {
            return redirect()->route('products.index')->with('error', 'Your cart is empty.');
        }

        $selectedItemIds = $request->input('selected_items', session('selected_cart_items', []));
        $items = $cart->items->whereIn('id', $selectedItemIds);
        
        // If still empty (session/request lost), process whole cart
        if ($items->isEmpty()) {
            $items = $cart->items;
            $selectedItemIds = $items->pluck('id')->toArray();
        }

        session(['selected_cart_items' => $selectedItemIds]);

        $subtotal = $items->sum(fn($item) => ($item->price_at_time ?? $item->product->price) * $item->quantity);
        
        $discount = 0;
        $couponCode = session('coupon_code');

        if ($couponCode) {
            $coupon = Coupon::where('coupon_code', $couponCode)->first();
            if ($coupon && $coupon->isValid()) {
                if ($coupon->discount_type === Coupon::TYPE_PERCENTAGE) {
                    $discount = $subtotal * ($coupon->discount_value / 100);
                } elseif ($coupon->discount_type === Coupon::TYPE_FIXED) {
                    $discount = $coupon->discount_value;
                } else {
                    $discount = 0;
                }
            }
        }

        $regions = \App\Services\ShippingService::getRegions();
        $regionalCities = \App\Services\ShippingService::getRegionalCities();
        $mmDistances = \App\Services\ShippingService::getMetroManilaDistances();
        $shipping = 0; 
        $total = $subtotal - $discount;

        return view('checkout.index', compact('cart', 'items', 'subtotal', 'discount', 'shipping', 'total', 'couponCode', 'mmDistances', 'regions', 'regionalCities'));
    }

    /**
     * Start the order process and send OTP.
     */
    public function store(Request $request)
    {
        $user = Auth::user();

        // Prevent rapid re-processing / double OTP sending
        if (session()->has('pending_order') && $user->otp && $user->otp_expires_at && $user->otp_expires_at->isFuture()) {
            return redirect()->route('checkout.verify')->with('info', 'A verification code is already active for your acquisition.');
        }

        $request->validate([
            'shipping_address' => 'required|string',
            'payment_method' => 'required|in:Cash on Delivery',
            'shipping_region' => 'required|string',
            'shipping_city' => 'required|string',
            'customer_name' => 'required|string',
            'customer_email' => 'required|email',
            'customer_phone' => 'required|string',
        ]);
        $cart = \App\Models\Cart::with('items.product')->where('user_id', $user->id)->first();
        
        if (!$cart || $cart->items->isEmpty()) {
            return redirect()->route('products.index')->with('error', 'Your cart is empty.');
        }

        // Store pending order data in session
        session(['pending_order' => $request->all()]);

        // Send OTP
        $user->sendOrderOtp();

        return redirect()->route('checkout.verify')->with('success', 'FINAL LAP: A verification code has been sent to your email to confirm this acquisition.');
    }

    /**
     * Show the OTP verification page for the order.
     */
    public function showVerifyOtp()
    {
        if (!session('pending_order')) {
            return redirect()->route('checkout')->with('error', 'No pending order found. Start your engines again.');
        }
        return view('checkout.verify-otp');
    }

    /**
     * Verify the OTP and finally process the order.
     */
    public function verifyOtp(Request $request)
    {
        $user = Auth::user();
        
        // 1. Handle double-submissions / race conditions FIRST
        // If an order was JUST created (within 30s), they likely double-clicked and the first one succeeded.
        $recentOrder = \App\Models\Order::where('user_id', $user->id)
            ->where('created_at', '>=', now()->subSeconds(30))
            ->latest()
            ->first();
            
        if ($recentOrder) {
            return redirect()->route('orders.confirmation', $recentOrder->id)
                ->with('success', 'FINISH LINE! Your acquisition was already processed successfully.');
        }

        $request->validate([
            'otp' => 'required|string|size:6',
        ]);

        if (!$user->otp || $user->otp !== $request->otp || now()->gt($user->otp_expires_at)) {
            return back()->withErrors(['otp' => 'The provided code is invalid or has expired. The finish line is waiting.']);
        }

        $orderData = session('pending_order');
        if (!$orderData) {
            return redirect()->route('checkout')->with('error', 'Your order session expired. Refuel and try again.');
        }

        $cart = \App\Models\Cart::with('items.product')->where('user_id', $user->id)->first();
        
        if (!$cart || $cart->items->isEmpty()) {
            return redirect()->route('products.index')->with('error', 'Your cart is empty. Start your engines again.');
        }
        
        try {
            DB::beginTransaction();

            $regions = \App\Services\ShippingService::getRegions();
            $selectedItemIds = session('selected_cart_items', []);
            $items = $cart->items->whereIn('id', $selectedItemIds);
            
            if ($items->isEmpty()) {
                $items = $cart->items;
                $selectedItemIds = $items->pluck('id')->toArray();
            }

            $subtotal = $items->sum(fn($item) => ($item->price_at_time ?? $item->product->price) * $item->quantity);
            $discount = 0;
            $couponCode = session('coupon_code');

            if ($couponCode) {
                $coupon = \App\Models\Coupon::where('coupon_code', $couponCode)->first();
                if ($coupon && $coupon->isValid()) {
                    if ($coupon->discount_type === \App\Models\Coupon::TYPE_PERCENTAGE) {
                        $discount = $subtotal * ($coupon->discount_value / 100);
                    } elseif ($coupon->discount_type === \App\Models\Coupon::TYPE_FIXED) {
                        $discount = $coupon->discount_value;
                    }
                }
            }

            $region = $orderData['shipping_region'];
            $city = $orderData['shipping_city'];
            $distance = 0;
            
            if ($region === 'NCR') {
                $distances = \App\Services\ShippingService::getMetroManilaDistances();
                $distance = $distances[$city] ?? 0;
            }
            
            $shipping = \App\Services\ShippingService::calculate($region, (float)$distance);

            $fullAddress = $orderData['shipping_address'] . ", " . $city . ", " . ($regions[$region] ?? $region);

            // Temporarily hide unselected items
            $unselectedItems = [];
            if (!empty($selectedItemIds)) {
                $unselectedItems = DB::table('cart_items')
                    ->where('cart_id', $cart->id)
                    ->whereNotIn('id', $selectedItemIds)
                    ->get()
                    ->map(fn($item) => (array)$item)->toArray();

                DB::table('cart_items')
                    ->where('cart_id', $cart->id)
                    ->whereNotIn('id', $selectedItemIds)
                    ->delete();
            }

            try {
                DB::statement("SET @p_order_id = 0;");
                DB::statement("CALL sp_ProcessOrder(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, @p_order_id)", [
                    $user->id,
                    $fullAddress,
                    $orderData['payment_method'],
                    $orderData['customer_name'],
                    $orderData['customer_email'],
                    $orderData['customer_phone'],
                    $couponCode,
                    $discount,
                    $shipping,
                    $orderData['notes'] ?? null,
                    (int) isset($orderData['extra_packaging'])
                ]);

                $result = DB::selectOne("SELECT @p_order_id as id");
                $orderId = $result->id;
            } finally {
                if (!empty($unselectedItems)) {
                    foreach ($unselectedItems as $item) {
                        unset($item['id']); 
                        DB::table('cart_items')->insert($item);
                    }
                }
            }

            if (!$orderId) {
                throw new \Exception("Order processing failed at database level.");
            }

            // Clear OTP and Session
            $user->update(['otp' => null, 'otp_expires_at' => null]);
            session()->forget(['pending_order', 'coupon_code', 'selected_cart_items']);

            $order = \App\Models\Order::find($orderId);
            
            DB::commit();

            return redirect()->route('orders.confirmation', $orderId)->with('success', 'FINISH LINE! Order placed successfully. You can track your acquisition details below.');

        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()->route('checkout')->with('error', $e->getMessage());
        }
    }

    /**
     * Apply a promotional code to the session.
     */
    public function applyCoupon(Request $request)
    {
        $request->validate(['code' => 'required|string']);

        $coupon = Coupon::where('coupon_code', $request->code)->first();

        if (!$coupon || !$coupon->isValid()) {
            return back()->with('error', 'Invalid or expired promotional code.');
        }

        // Enforce 1-time use per user for specific coupons like WELCOME10
        if ($coupon->isUsedByUser(Auth::id())) {
            return back()->with('error', 'You have already used this promotional code on a previous acquisition.');
        }

        session(['coupon_code' => $coupon->coupon_code]);
        
        $subtotal = $request->input('subtotal', 0); // Optional: if passed from frontend
        $discount = 0;
        if ($coupon->discount_type === Coupon::TYPE_PERCENTAGE) {
            $discount = $subtotal * ($coupon->discount_value / 100);
        } else {
            $discount = $coupon->discount_value;
        }

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Promo code applied to your order!',
                'code' => $coupon->coupon_code,
                'discount' => $discount,
                'discount_value' => $coupon->discount_value,
                'discount_type' => $coupon->discount_type
            ]);
        }

        return back()->with('success', 'Promo code applied to your order!');
    }

    /**
     * Remove the promotional code from the session.
     */
    public function removeCoupon(Request $request)
    {
        session()->forget('coupon_code');

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Promo code removed.'
            ]);
        }

        return back()->with('success', 'Promo code removed.');
    }

    /**
     * Display order confirmation page.
     */
    public function confirmation($id)
    {
        // Check if the order belongs to the user
        $order = Order::with(['items.product'])
            ->where('id', $id)
            ->where('user_id', Auth::id())
            ->first();

        // If not authorized, redirect to their most recent confirmation instead of showing an error
        if (!$order) {
            $latestOrder = Order::where('user_id', Auth::id())->latest()->first();
            if ($latestOrder) {
                return redirect()->route('orders.confirmation', $latestOrder->id)
                    ->with('warning', 'You do not have access to that acquisition. Showing your most recent one.');
            }
            return redirect()->route('orders.index')->with('error', 'Unauthorized access.');
        }

        return view('orders.confirmation', compact('order'));
    }
}
