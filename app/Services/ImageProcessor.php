<?php
declare(strict_types=1);

namespace App\Services;

/**
 * ImageProcessor — small GD wrapper that produces resized image variants
 * (thumbnails). Deliberately dependency-free and defensive: if GD is missing or
 * the source isn't a supported raster image, methods return null so callers can
 * simply skip thumbnail generation rather than fail an upload.
 */
class ImageProcessor
{
    /** Never decoded above this, whatever the settings say (~400 MB in GD). */
    public const HARD_MAX_PIXELS = 100_000_000;

    /**
     * Whether GD can decode an image of $pixels pixels here (1.2.73, BH-027).
     *
     * A small file can describe a huge image: a 250 KB PNG of 16,000 × 16,000
     * pixels decodes to about 1 GB. Above HARD_MAX_PIXELS never; and where
     * GD's memory counts against memory_limit (PHP's bundled GD), only when
     * it fits in what is left, so a large photo is stored without
     * thumbnails instead of ending the request with a fatal error.
     */
    public static function safeToDecode(int $pixels): bool
    {
        if ($pixels <= 0) return false;
        if ($pixels > self::HARD_MAX_PIXELS) return false;
        if (!(defined('GD_BUNDLED') && GD_BUNDLED)) return true;
        $limit = self::bytes((string) ini_get('memory_limit'));
        if ($limit <= 0) return true;
        // Source and a resized copy, with headroom for the rest of the request.
        return memory_get_usage(true) + $pixels * 5 + 16 * 1024 * 1024 < $limit;
    }

    private static function bytes(string $v): int
    {
        $v = trim($v);
        if ($v === '' || $v === '-1') return -1;
        $n = (int) $v;
        return match (strtolower(substr($v, -1))) {
            'g' => $n * 1024 ** 3,
            'm' => $n * 1024 ** 2,
            'k' => $n * 1024,
            default => $n,
        };
    }

    /** Raster MIME types we can resize with GD. */
    public const SUPPORTED = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    public function available(): bool
    {
        return \extension_loaded('gd');
    }

    public function supports(string $mime): bool
    {
        return $this->available() && \in_array($mime, self::SUPPORTED, true);
    }

    /**
     * Generate one resized variant.
     *
     * @param bool   $crop  true = cover + centre-crop to exactly {w}x{h};
     *                       false = fit inside {w}x{h}, preserving aspect ratio
     *                       and never upscaling.
     * @param string|null $destMime force output type (e.g. 'image/webp'); null keeps source type.
     * @return array{width:int,height:int,mime:string}|null  null if it couldn't/shouldn't be made.
     */
    public function generate(
        string $srcAbs,
        string $destAbs,
        string $srcMime,
        int $w,
        int $h,
        bool $crop,
        int $quality = 82,
        ?string $destMime = null
    ): ?array {
        if (!$this->supports($srcMime)) return null;
        if ($w < 1 || $h < 1) return null;
        if (!is_file($srcAbs)) return null;
        // Never decode an image the server cannot hold (1.2.73, BH-027): GD
        // expands it to about 4 bytes a pixel, whatever the file size.
        $info = @getimagesize($srcAbs);
        if (!$info || !self::safeToDecode((int) $info[0] * (int) $info[1])) return null;

        $src = $this->load($srcAbs, $srcMime);
        if (!$src) return null;

        try {
            $sw = imagesx($src);
            $sh = imagesy($src);
            if ($sw < 1 || $sh < 1) return null;

            if ($crop) {
                // Cover the target box, then centre-crop to exactly w×h.
                $scale = max($w / $sw, $h / $sh);
                $tw = (int) max(1, round($sw * $scale));
                $th = (int) max(1, round($sh * $scale));
                $dstW = $w; $dstH = $h;
                $srcX = (int) max(0, round(($tw - $w) / 2 / $scale));
                $srcY = (int) max(0, round(($th - $h) / 2 / $scale));
                $copyW = (int) round($w / $scale);
                $copyH = (int) round($h / $scale);
                $copyW = min($copyW, $sw);
                $copyH = min($copyH, $sh);
            } else {
                // Fit inside the box; skip when the source already fits (no upscaling).
                if ($sw <= $w && $sh <= $h) return null;
                $scale = min($w / $sw, $h / $sh);
                $dstW = (int) max(1, round($sw * $scale));
                $dstH = (int) max(1, round($sh * $scale));
                $srcX = 0; $srcY = 0; $copyW = $sw; $copyH = $sh;
            }

            $dst = imagecreatetruecolor($dstW, $dstH);
            $outMime = $destMime ?: $srcMime;
            $this->preserveTransparency($dst, $outMime);

            imagecopyresampled($dst, $src, 0, 0, $srcX, $srcY, $dstW, $dstH, $copyW, $copyH);

            $ok = $this->save($dst, $destAbs, $outMime, $quality);
            imagedestroy($dst);
            if (!$ok) return null;
            @chmod($destAbs, 0644);

            return ['width' => $dstW, 'height' => $dstH, 'mime' => $outMime];
        } finally {
            imagedestroy($src);
        }
    }

    /** Extension for a MIME so callers can name variant files consistently. */
    public function extensionFor(string $mime): string
    {
        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/gif'  => 'gif',
            'image/webp' => 'webp',
            default      => 'img',
        };
    }

    private function load(string $path, string $mime): \GdImage|false
    {
        return match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png'  => @imagecreatefrompng($path),
            'image/gif'  => @imagecreatefromgif($path),
            'image/webp' => \function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            default      => false,
        };
    }

    private function preserveTransparency(\GdImage $img, string $mime): void
    {
        if (\in_array($mime, ['image/png', 'image/gif', 'image/webp'], true)) {
            imagealphablending($img, false);
            imagesavealpha($img, true);
            $transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);
            imagefilledrectangle($img, 0, 0, imagesx($img), imagesy($img), $transparent);
        }
    }

    private function save(\GdImage $img, string $path, string $mime, int $quality): bool
    {
        $quality = max(1, min(100, $quality));
        return match ($mime) {
            'image/jpeg' => imagejpeg($img, $path, $quality),
            'image/png'  => imagepng($img, $path, (int) round((100 - $quality) / 11.111)), // 0-9
            'image/gif'  => imagegif($img, $path),
            'image/webp' => \function_exists('imagewebp') ? imagewebp($img, $path, $quality) : false,
            default      => false,
        };
    }
}
