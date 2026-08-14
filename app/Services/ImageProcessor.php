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
