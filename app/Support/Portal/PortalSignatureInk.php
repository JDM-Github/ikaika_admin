<?php

namespace App\Support\Portal;

use GdImage;

/**
 * A signature as its ink alone (2026-10-14, user), for the ID card. It may be uploaded as a scan, a photo
 * of paper or a phone screenshot: the paper is made transparent, a band along the picture's own edge
 * (a phone's status bar, a scanner's shadow) is dropped, and what is left is cut to the ink, so on the
 * card it stands on its line rather than in a grey box. The paper's shade is read from the picture
 * itself, so off-white paper goes too, and ink keeps its own colour.
 */
final class PortalSignatureInk
{
    // How much darker than the paper a pixel must be to start to show, and to show fully, out of 255.
    private const FAINT = 28;

    private const SOLID = 110;

    // A row or column at the picture's edge this full of ink is a band, not writing.
    private const BAND = 0.6;

    // Ink at least this solid sets the picture's bounds, so specks of noise do not.
    private const BOUNDS_OPACITY = 0.25;

    private const PADDING = 2;

    private const MAX_WIDTH = 900;

    private const MAX_HEIGHT = 300;

    /**
     * PNG bytes of the ink alone, cut to it; null when the picture cannot be read or holds no ink.
     */
    public static function clean(string $bytes): ?string
    {
        if (! function_exists('imagecreatefromstring')) {
            return null;
        }
        $source = @imagecreatefromstring($bytes);
        if ($source === false) {
            return null;
        }
        imagepalettetotruecolor($source);
        $width = imagesx($source);
        $height = imagesy($source);

        // Each pixel's shade, one byte each, a clear one read as white paper; the paper's is the median shade.
        $shades = str_repeat("\0", $width * $height);
        $histogram = array_fill(0, 256, 0);
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $color = imagecolorat($source, $x, $y);
                $clear = (($color >> 24) & 0x7F) / 127;
                $shade = (int) round((0.299 * (($color >> 16) & 0xFF) + 0.587 * (($color >> 8) & 0xFF) + 0.114 * ($color & 0xFF)) * (1 - $clear) + 255 * $clear);
                $shades[$y * $width + $x] = chr($shade);
                $histogram[$shade]++;
            }
        }
        $paper = self::median($histogram, $width * $height);
        $opacity = static fn (int $index): float => max(0.0, min(1.0, ($paper - ord($shades[$index]) - self::FAINT) / (self::SOLID - self::FAINT)));

        [$top, $bottom, $left, $right] = self::withoutBands($opacity, $width, $height);

        $ink = imagecreatetruecolor($width, $height);
        imagealphablending($ink, false);
        imagesavealpha($ink, true);
        imagefill($ink, 0, 0, imagecolorallocatealpha($ink, 0, 0, 0, 127));
        $minX = $width;
        $minY = $height;
        $maxX = -1;
        $maxY = -1;
        for ($y = $top; $y <= $bottom; $y++) {
            for ($x = $left; $x <= $right; $x++) {
                $solid = $opacity($y * $width + $x);
                if ($solid <= 0.0) {
                    continue;
                }
                $color = imagecolorat($source, $x, $y);
                imagesetpixel($ink, $x, $y, imagecolorallocatealpha($ink, ($color >> 16) & 0xFF, ($color >> 8) & 0xFF, $color & 0xFF, 127 - (int) round($solid * 127)));
                if ($solid >= self::BOUNDS_OPACITY) {
                    $minX = min($minX, $x);
                    $minY = min($minY, $y);
                    $maxX = max($maxX, $x);
                    $maxY = max($maxY, $y);
                }
            }
        }
        if ($maxX < 0) {
            return null;
        }

        $minX = max(0, $minX - self::PADDING);
        $minY = max(0, $minY - self::PADDING);
        $cut = imagecrop($ink, [
            'x' => $minX,
            'y' => $minY,
            'width' => min($width - 1, $maxX + self::PADDING) - $minX + 1,
            'height' => min($height - 1, $maxY + self::PADDING) - $minY + 1,
        ]);
        if ($cut === false) {
            return null;
        }

        return self::png(self::fitted($cut));
    }

    /**
     * @param  array<int, int>  $histogram
     */
    private static function median(array $histogram, int $count): int
    {
        $seen = 0;
        foreach ($histogram as $shade => $pixels) {
            $seen += $pixels;
            if ($seen * 2 >= $count) {
                return $shade;
            }
        }

        return 255;
    }

    /**
     * The rows and columns left once each edge's bands are dropped: top, bottom, left, right.
     *
     * @param  callable(int): float  $opacity
     * @return array{int, int, int, int}
     */
    private static function withoutBands(callable $opacity, int $width, int $height): array
    {
        $rowIsBand = static function (int $y) use ($opacity, $width): bool {
            $inked = 0;
            for ($x = 0; $x < $width; $x++) {
                $inked += $opacity($y * $width + $x) >= 0.5 ? 1 : 0;
            }

            return $inked >= $width * self::BAND;
        };
        $columnIsBand = static function (int $x) use ($opacity, $width, $height): bool {
            $inked = 0;
            for ($y = 0; $y < $height; $y++) {
                $inked += $opacity($y * $width + $x) >= 0.5 ? 1 : 0;
            }

            return $inked >= $height * self::BAND;
        };

        $top = 0;
        while ($top < $height - 1 && $rowIsBand($top)) {
            $top++;
        }
        $bottom = $height - 1;
        while ($bottom > $top && $rowIsBand($bottom)) {
            $bottom--;
        }
        $left = 0;
        while ($left < $width - 1 && $columnIsBand($left)) {
            $left++;
        }
        $right = $width - 1;
        while ($right > $left && $columnIsBand($right)) {
            $right--;
        }

        return [$top, $bottom, $left, $right];
    }

    // No larger than the card needs: about three times what it prints at 300 dpi.
    private static function fitted(GdImage $image): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $scale = min(1.0, self::MAX_WIDTH / $width, self::MAX_HEIGHT / $height);
        if ($scale >= 1.0) {
            return $image;
        }
        $scaled = imagecreatetruecolor(max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)));
        imagealphablending($scaled, false);
        imagesavealpha($scaled, true);
        imagecopyresampled($scaled, $image, 0, 0, 0, 0, imagesx($scaled), imagesy($scaled), $width, $height);

        return $scaled;
    }

    private static function png(GdImage $image): string
    {
        imagesavealpha($image, true);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }
}
