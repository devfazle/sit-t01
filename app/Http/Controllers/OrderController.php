<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class OrderController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'items'       => 'required|array',
        ]);

        $result = DB::transaction(function () use ($request) {
            $totalAmount = 0;
            $failedItems = [];

            $order = Order::create([
                'customer_id'  => $request->customer_id,
                'total_amount' => 0,
                'status'       => 'pending',
            ]);

            foreach ($request->items as $item) {
                $product = Product::lockForUpdate()->find($item['product_id']);

                if (!$product || $product->stock < $item['quantity']) {
                    $failedItems[] = $item['product_id'];
                    continue;
                }

                OrderItem::create([
                    'order_id'   => $order->id,
                    'product_id' => $item['product_id'],
                    'quantity'   => $item['quantity'],
                    'unit_price' => $product->price,
                ]);

                $product->decrement('stock', $item['quantity']);
                $totalAmount += $product->price * $item['quantity'];
            }

            $order->update(['total_amount' => $totalAmount]);

            return ['order' => $order, 'failed_items' => $failedItems];
        });

        Cache::forget('products.dashboard');

        return response()->json([
            'order'        => $result['order'],
            'failed_items' => $result['failed_items'],
        ], 201);
    }

    public function index()
    {
        $orders = Order::with('customer')->get();

        $data = [];
        foreach ($orders as $order) {
            $data[] = [
                'id'          => $order->id,
                'customer'    => $order->customer->name,
                'total'       => $order->total_amount,
                'status'      => $order->status,
                'items_count' => $order->items->count(),
                'created_at'  => $order->created_at,
            ];
        }

        return response()->json($data);
    }

    public function filterByStatus(Request $request)
    {
        $request->validate([
            'status' => 'required|in:pending,completed,cancelled'
        ]);

        $orders = Order::where('status', $request->status)->get();

        return response()->json($orders);
    }
}
