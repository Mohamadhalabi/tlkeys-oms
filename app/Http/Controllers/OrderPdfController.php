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

        $order->load([
            'customer:id,name,email,phone,address,city,country,postal_code',
            'seller:id,name',
            'branch:id,name,code',
            'items.product:id,sku,title,image',
        ]);

        // ===== Resolve logo (public/storage/logo.png or public/images/logo.(png|webp|jpg))
        $logoPath = $this->resolveLocalLogoPath();

        // ===== Build product image map (all LOCAL or data: URIs)
        $imgMap = [];
        foreach ($order->items as $row) {
            $imgMap[$row->product_id] = $this->resolveImageSrc($row->product?->image);
        }

        $data = [
            'order'    => $order,
            'logoPath' => $logoPath, // absolute path prefixed with file:// or null
            'company'  => config('app.name', 'Your Company'),
            'imgMap'   => $imgMap,   // product_id => local path (file://...) or data: URI
        ];

        // mPDF options tuned for speed
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
            'margin_left'      => 14,
            'margin_right'     => 14,
            'margin_top'       => 16,
            'margin_bottom'    => 20,
            'enable_remote'    => false, // we give mPDF only local paths or data URIs
        ]);

        $downloadName = ($order->uuid ?? ('TLO' . str_pad($order->id, 6, '0', STR_PAD_LEFT))) . '.pdf';
        return $pdf->stream($downloadName);
    }

    /**
     * Resolve the company logo to a local, mPDF-safe src.
     * Returns: "file:///abs/path.ext" or null.
     */
    private function resolveLocalLogoPath(): ?string
    {
        // Try public storage first: public/storage/logo.png
        try {
            if (Storage::disk('public')->exists('logo.png')) {
                $abs = Storage::disk('public')->path('logo.png');
                if (is_file($abs)) return 'file://' . $abs;
            }
        } catch (\Throwable $e) { /* ignore */ }

        // Fallbacks under /public/images
        foreach (['logo.webp', 'logo.png', 'logo.jpg', 'logo.jpeg', 'logo.svg'] as $name) {
            $abs = public_path('images/' . $name);
            if (is_file($abs)) {
                // SVG works too; mPDF rasterizes internally
                return 'file://' . $abs;
            }
        }

        return null;
    }

    /**
     * Return a PDF-safe <img src>:
     * - local file:  file:///absolute/path.jpg
     * - remote URL:  file:///cached/thumb.jpg (downloaded once & cached), fallback to data:image/jpeg;base64,...
     * - unknown:     null
     */
    private function resolveImageSrc(?string $path): ?string
    {
        if (!$path) return null;
        $path = trim($path);

        // Remote? Use cached local thumb to avoid slow HTTP on every PDF render
        if (preg_match('~^https?://~i', $path)) {
            return $this->cacheRemoteThumb($path) ?? $this->remoteToThumbDataUri($path);
        }

        // Try storage disk (public)
        try {
            $abs = Storage::disk('public')->path(ltrim($path, '/'));
            if (is_file($abs)) return 'file://' . $abs;
        } catch (\Throwable $e) { /* ignore */ }

        // Try public/…
        $abs2 = public_path(ltrim($path, '/'));
        if (is_file($abs2)) return 'file://' . $abs2;

        // Try public/storage/… (symlink)
        if (Str::startsWith($path, 'storage/')) {
            $abs3 = public_path($path);
            if (is_file($abs3)) return 'file://' . $abs3;
        }

        return null;
    }

    /**
     * Cache a small JPEG thumb for a remote image under storage/app/public/pdf_img_cache.
     * Returns "file:///abs/path.jpg" or null on failure.
     */
    private function cacheRemoteThumb(string $url, int $max = 88): ?string
    {
        try {
            $dir = 'pdf_img_cache';
            Storage::disk('public')->makeDirectory($dir);

            $key  = md5($url) . "_{$max}.jpg";
            $path = $dir . '/' . $key;
            $abs  = Storage::disk('public')->path($path);

            // If cached, use it
            if (is_file($abs) && filesize($abs) > 0) {
                return 'file://' . $abs;
            }

            // Download once (small timeout)
            $resp = Http::timeout(6)->withHeaders(['Accept' => 'image/*'])->get($url);
            if ($resp->failed()) return null;

            $bin = $resp->body();
            if (!$bin) return null;

            $img = @imagecreatefromstring($bin);
            if (!$img) return null;

            $w = imagesx($img);
            $h = imagesy($img);
            if ($w <= 0 || $h <= 0) return null;

            $scale = min($max / $w, $max / $h, 1);
            $nw = (int) max(1, floor($w * $scale));
            $nh = (int) max(1, floor($h * $scale));

            $thumb = imagecreatetruecolor($nw, $nh);
            $white = imagecolorallocate($thumb, 255, 255, 255);
            imagefilledrectangle($thumb, 0, 0, $nw, $nh, $white);
            imagecopyresampled($thumb, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);

            // Save to disk once
            imagejpeg($thumb, $abs, 80);
            imagedestroy($thumb);
            imagedestroy($img);

            if (is_file($abs)) {
                return 'file://' . $abs;
            }
        } catch (\Throwable $e) {
            // ignore and fallback to data URI
        }
        return null;
    }

    /**
     * Fallback: make a tiny data URI for a remote image (no cache).
     */
    private function remoteToThumbDataUri(string $url, int $max = 88): ?string
    {
        try {
            $resp = Http::timeout(6)->withHeaders(['Accept' => 'image/*'])->get($url);
            if ($resp->failed()) return null;

            $bin = $resp->body();
            if (!$bin) return null;

            $img = @imagecreatefromstring($bin);
            if (!$img) return null;

            $w = imagesx($img);
            $h = imagesy($img);
            if ($w <= 0 || $h <= 0) return null;

            $scale = min($max / $w, $max / $h, 1);
            $nw = (int) max(1, floor($w * $scale));
            $nh = (int) max(1, floor($h * $scale));

            $thumb = imagecreatetruecolor($nw, $nh);
            $white = imagecolorallocate($thumb, 255, 255, 255);
            imagefilledrectangle($thumb, 0, 0, $nw, $nh, $white);
            imagecopyresampled($thumb, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);

            ob_start();
            imagejpeg($thumb, null, 80);
            $jpeg = ob_get_clean();

            imagedestroy($thumb);
            imagedestroy($img);

            return $jpeg ? 'data:image/jpeg;base64,' . base64_encode($jpeg) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
