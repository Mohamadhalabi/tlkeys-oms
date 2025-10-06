<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use niklasravnsborg\LaravelPdf\Facades\Pdf;

class OrderPdfController extends Controller
{
    public function show(Order $order)
    {
        @set_time_limit(120);

        $order->load([
            'customer:id,name,email,phone,address', // removed ,uuid
            'seller:id,name',
            'branch:id,name,code',
            'items.product:id,sku,title,image',
        ]);


        // Company logo (local only)
        $logoPath = null;
        try {
            $candidate = Storage::disk('public')->path('logo.png'); // storage/app/public/logo.png
            if (is_file($candidate)) $logoPath = $candidate;
        } catch (\Throwable $e) {}

        // Build a map of product_id => src (either file://path or data:image/..;base64,..)
        $imgMap = [];
        foreach ($order->items as $row) {
            $imgMap[$row->product_id] = $this->resolveImageSrc($row->product?->image);
        }

        $data = [
            'order'    => $order,
            'logoPath' => $logoPath,
            'company'  => config('app.name', 'Your Company'),
            'imgMap'   => $imgMap,
        ];

        // mPDF config (keep remote off; we embed base64 for remote)
        $pdf = Pdf::loadView(
            'pdf.order',
            $data,
            [],
            [
                'format'         => 'A4',
                'orientation'    => 'P',
                'default_font'   => 'amiri',
                'margin_left'    => 14,
                'margin_right'   => 14,
                'margin_top'     => 16,
                'margin_bottom'  => 20,
                'enable_remote'  => false,
            ]
        );

        $downloadName = ($order->uuid ?? ('TLO' . str_pad($order->id, 6, '0', STR_PAD_LEFT))) . '.pdf';

        return $pdf->stream($downloadName);
    }

    /**
     * Return a PDF-safe <img src>:
     * - local file:  file:///absolute/path.jpg
     * - remote URL:  data:image/jpeg;base64,...
     * - unknown:     null
     */
    private function resolveImageSrc(?string $path): ?string
    {
        if (!$path) return null;
        $path = trim($path);

        // Remote? Convert to BASE64 thumbnail for reliability + speed.
        if (preg_match('~^https?://~i', $path)) {
            return $this->remoteToThumbDataUri($path);
        }

        // Try storage disk
        try {
            $abs = Storage::disk('public')->path(ltrim($path, '/'));
            if (is_file($abs)) return 'file://' . $abs;
        } catch (\Throwable $e) {}

        // Try public/…
        $abs2 = public_path(ltrim($path, '/'));
        if (is_file($abs2)) return 'file://' . $abs2;

        // Try public/storage/… (symlink)
        if (str_starts_with($path, 'storage/')) {
            $abs3 = public_path($path);
            if (is_file($abs3)) return 'file://' . $abs3;
        }

        return null;
    }

    /**
     * Download a remote image quickly and turn it into a tiny JPEG data URI (thumb).
     * Uses GD; no extra packages.
     */
    private function remoteToThumbDataUri(string $url, int $max = 88): ?string
    {
        try {
            // Fast HEAD first (optional). If it fails, skip.
            $head = Http::timeout(4)->withHeaders(['Accept' => 'image/*'])->head($url);
            if ($head->failed()) return null;

            // Download with small timeout
            $resp = Http::timeout(8)->withHeaders(['Accept' => 'image/*'])->get($url);
            if ($resp->failed()) return null;

            $bin = $resp->body();
            if (!$bin) return null;

            $img = @imagecreatefromstring($bin);
            if (!$img) return null;

            $w = imagesx($img);
            $h = imagesy($img);
            if ($w <= 0 || $h <= 0) return null;

            // Keep aspect, fit inside max x max
            $scale = min($max / $w, $max / $h, 1);
            $nw = (int) max(1, floor($w * $scale));
            $nh = (int) max(1, floor($h * $scale));

            $thumb = imagecreatetruecolor($nw, $nh);
            imagealphablending($thumb, true);
            imagesavealpha($thumb, true);
            // Fill with white to avoid black background when JPEG
            $white = imagecolorallocate($thumb, 255, 255, 255);
            imagefilledrectangle($thumb, 0, 0, $nw, $nh, $white);

            imagecopyresampled($thumb, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);

            ob_start();
            imagejpeg($thumb, null, 80);
            $jpeg = ob_get_clean();

            imagedestroy($thumb);
            imagedestroy($img);

            return 'data:image/jpeg;base64,' . base64_encode($jpeg);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
