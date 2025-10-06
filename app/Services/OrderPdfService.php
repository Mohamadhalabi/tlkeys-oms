<?php

namespace App\Services;

use App\Models\Order;
use PDF; // niklasravnsborg/laravel-pdf facade
use Illuminate\Support\Facades\Storage;

class OrderPdfService
{
    /**
     * Generate the PDF and return a public URL to download.
     */
    public static function generate(Order $order): string
    {
        $order->loadMissing(['items.product', 'customer', 'seller', 'branch']);

        $viewData = [
            'order'        => $order,
            'companyName'  => config('app.name', 'Your Company'),
            'companyTagline' => config('app.tagline', ''), // optional
            'logoPath'     => public_path('logo.webp'), // put your logo at public/logo.webp
        ];

        $pdf = PDF::loadView('pdf.order', $viewData)
            ->setPaper('a4')
            ->setOption('margin-top', 16);

        // Save into storage (public)
        $fileName = 'orders/order-' . $order->id . '.pdf';
        // Ensure dir exists
        Storage::disk('public')->makeDirectory('orders');

        // mPDF returns raw string; store it:
        Storage::disk('public')->put($fileName, $pdf->output());

        return Storage::disk('public')->url($fileName);
    }
}
