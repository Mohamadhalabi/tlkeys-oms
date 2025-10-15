<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Facades\Storage;
use niklasravnsborg\LaravelPdf\Facades\Pdf; // mPDF facade

class OrderPdfService
{
    /**
     * Generate the PDF and return a public URL to download.
     */
    public static function generate(Order $order): string
    {
        // Eager-load what's needed for the view
        $order->loadMissing(['items.product', 'customer', 'seller', 'branch']);

        // Locale & font selection
        $locale = app()->getLocale();
        $isAr   = ($locale === 'ar');

        // View data
        $viewData = [
            'order'          => $order,
            'companyName'    => config('app.name', 'Your Company'),
            'companyTagline' => config('app.tagline', ''), // optional
            'logoPath'       => public_path('logo.webp'),  // put your logo at public/logo.webp
        ];

        // Build the PDF (use Amiri only for Arabic; otherwise DejaVu Sans)
        $pdf = Pdf::loadView('pdf.order', $viewData, [], [
            'mode'             => 'utf-8',
            'format'           => 'A4',
            'orientation'      => 'P',
            'default_font'     => $isAr ? 'amiri' : 'dejavusans',
            'autoScriptToLang' => $isAr,      // enable shaping only for Arabic
            'autoLangToFont'   => $isAr,
            'useOTL'           => $isAr ? 0xFF : 0,
            'useKashida'       => $isAr ? 75   : 0,
            'directionality'   => $isAr ? 'rtl' : 'ltr',
            'margin_left'      => 14,
            'margin_right'     => 14,
            'margin_top'       => 16,
            'margin_bottom'    => 20,
        ]);

        // Ensure directory exists and save
        $dir = 'orders';
        Storage::disk('public')->makeDirectory($dir);

        $fileName = $dir . '/order-' . $order->id . '.pdf';
        Storage::disk('public')->put($fileName, $pdf->output());

        // Return a public URL
        return Storage::disk('public')->url($fileName);
    }
}
