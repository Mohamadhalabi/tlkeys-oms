<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use niklasravnsborg\LaravelPdf\Facades\Pdf;

class OrderPdfController extends Controller
{
    public function show(Order $order)
    {
        @set_time_limit(120);

        // Locale for the PDF
        $locale = request('lang')
            ?? session('locale')
            ?? app()->getLocale()
            ?? config('app.locale');
        app()->setLocale($locale);

        // ✅ FIXED: Removed 'city', 'country', 'postal_code' if they don't exist
        $order->load([
            'customer:id,name,email,phone,address', 
            'seller:id,name',
            'branch:id,name,code',
            // We explicitly select sku for custom items handling
            'items.product:id,sku,title,image', 
        ]);

        // ===== Resolve logo
        $logoPath = $this->resolveLocalLogoPath();

        // ===== Build product image map
        $imgMap = [];
        foreach ($order->items as $row) {
            if ($row->product_id && $row->product?->image) {
                $imgMap[$row->product_id] = $this->resolveImageSrc($row->product->image);
            }
        }

        $data = [
            'order'    => $order,
            'logoPath' => $logoPath,
            'company'  => config('app.name', 'Techno Lock Keys'),
            'imgMap'   => $imgMap,
        ];

        // mPDF options
        $pdf = Pdf::loadView('pdf.order', $data, [], [
            'mode'             => 'utf-8',
            'format'           => 'A4',
            'orientation'      => 'P',
            'default_font'     => ($locale === 'ar') ? 'amiri' : 'dejavusans',
            'autoScriptToLang' => ($locale === 'ar'),
            'autoLangToFont'   => ($locale === 'ar'),
            'useOTL'           => ($locale === 'ar') ? 0xFF : 0,
            'useKashida'       => ($locale === 'ar') ? 75 : 0,
            'directionality'   => ($locale === 'ar') ? 'rtl' : 'ltr',
            'margin_left'      => 0,
            'margin_right'     => 0,
            'margin_top'       => 0,
            'margin_bottom'    => 20,
            'enable_remote'    => false,
        ]);

        $downloadName = ($order->code ?? ('Order-' . $order->id)) . '.pdf';
        return $pdf->stream($downloadName);
    }

    private function resolveLocalLogoPath(): ?string
    {
        try {
            if (Storage::disk('public')->exists('logo.png')) {
                $abs = Storage::disk('public')->path('logo.png');
                if (is_file($abs)) return 'file://' . $abs;
            }
        } catch (\Throwable $e) { }

        foreach (['logo.webp', 'logo.png', 'logo.jpg', 'logo.jpeg', 'logo.svg'] as $name) {
            $abs = public_path('images/' . $name);
            if (is_file($abs)) return 'file://' . $abs;
        }
        return null;
    }

    private function resolveImageSrc(?string $path): ?string
    {
        if (!$path) return null;
        $path = trim($path);

        if (preg_match('~^https?://~i', $path)) {
            return $this->cacheRemoteThumb($path) ?? $this->remoteToThumbDataUri($path);
        }

        try {
            $abs = Storage::disk('public')->path(ltrim($path, '/'));
            if (is_file($abs)) return 'file://' . $abs;
        } catch (\Throwable $e) { }

        $abs2 = public_path(ltrim($path, '/'));
        if (is_file($abs2)) return 'file://' . $abs2;

        if (Str::startsWith($path, 'storage/')) {
            $abs3 = public_path($path);
            if (is_file($abs3)) return 'file://' . $abs3;
        }

        return null;
    }

    private function cacheRemoteThumb(string $url, int $max = 88): ?string
    {
        try {
            $dir = 'pdf_img_cache';
            Storage::disk('public')->makeDirectory($dir);
            $key  = md5($url) . "_{$max}.jpg";
            $path = $dir . '/' . $key;
            $abs  = Storage::disk('public')->path($path);

            if (is_file($abs) && filesize($abs) > 0) return 'file://' . $abs;

            $resp = Http::timeout(5)->get($url);
            if ($resp->failed()) return null;

            $img = @imagecreatefromstring($resp->body());
            if (!$img) return null;

            $w = imagesx($img); $h = imagesy($img);
            $scale = min($max / $w, $max / $h, 1);
            $nw = (int)max(1, $w * $scale); $nh = (int)max(1, $h * $scale);

            $thumb = imagecreatetruecolor($nw, $nh);
            imagefill($thumb, 0, 0, imagecolorallocate($thumb, 255, 255, 255));
            imagecopyresampled($thumb, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
            imagejpeg($thumb, $abs, 80);
            imagedestroy($thumb); imagedestroy($img);

            return is_file($abs) ? 'file://' . $abs : null;
        } catch (\Throwable $e) { }
        return null;
    }

    private function remoteToThumbDataUri(string $url): ?string
    {
        return null; 
    }
}