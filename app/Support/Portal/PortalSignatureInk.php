<?php

namespace App\Support\Portal;

use GdImage;

/**
 * A signature as its ink alone (2026-10-14, user), for the ID card. It may be uploaded as a scan, a photo
 * of paper or a phone screenshot: the paper is made transparent, a band along the picture's own edge
 * (a phone's status bar, a scanner's shadow) is dropped, and what is left is cut to the ink, so on the
 * card it stands on its line rather than in a grey box. The paper's shade is read from the picture
 * itself, so off-white paper goes too. The ink comes out in one colour, its own darkened, and a stroke
 * too thin to print clearly once the card shrinks it is widened (2026-10-14, user), so every signature
 * reads as strongly.
 *
 * A small line of ink set apart from the signature by a clear gap -- a stock picture's watermark, a
 * caption -- is dropped too. A picture that is no signature at all is said to be one: a signature is a
 * few dark strokes on light paper, so a picture with no light paper (a meme, a cartoon), of many
 * separate marks (a page of text, a QR code) or mostly ink (a photo) is not printed as one.
 */
final class PortalSignatureInk
{
    // A signature is dark ink on light paper: a picture whose usual shade is darker than this has no paper.
    private const LIGHTEST_PAPER = 150;

    // How much darker than the paper a pixel must be to start to show, and to show fully, out of 255.
    private const FAINT = 28;

    private const SOLID = 80;

    // The ink's own colour is darkened to this share, so blue ink stays blue but prints strong.
    private const DARKEN = 0.5;

    // A stroke thinner than this share of the signature's height is widened to it, by this many pixels a side at most.
    private const THINNEST = 0.03;

    private const MOST_GROWTH = 6;

    // Ink strength, out of 255, that sets the picture's bounds, so specks of noise do not; and that counts as a stroke.
    private const BOUNDS = 64;

    private const STROKE = 128;

    // A row or column at the picture's edge this full of ink is a band, not writing.
    private const BAND = 0.6;

    // A line of ink apart from the signature is dropped when it is this small beside it and this far from it.
    private const STRAY_HEIGHT = 0.4;

    private const STRAY_MASS = 0.35;

    private const STRAY_GAP = 0.08;

    // Past this many separate marks, or this much of its box in ink, a picture is not a signature; a box
    // must span this share of the picture each way to count as mostly ink, so a straight stroke never does.
    private const MOST_MARKS = 60;

    private const MOST_COVERAGE = 0.4;

    private const BROAD = 0.25;

    // A mark smaller than this share of the box, and never under 6 pixels, is a speck.
    private const SPECK = 0.00005;

    private const PADDING = 2;

    private const MAX_WIDTH = 900;

    private const MAX_HEIGHT = 300;

    /**
     * The signature's ink alone, cut to it, as PNG bytes. No ink and still a signature where the picture
     * cannot be read here; no ink and not a signature where it holds none, or holds something else.
     *
     * @return array{ink: ?string, isSignature: bool}
     */
    public static function clean(string $bytes): array
    {
        $unread = ['ink' => null, 'isSignature' => true];
        if (! function_exists('imagecreatefromstring')) {
            return $unread;
        }
        $source = @imagecreatefromstring($bytes);
        if ($source === false) {
            return $unread;
        }
        imagepalettetotruecolor($source);
        $width = imagesx($source);
        $height = imagesy($source);
        $strength = self::strength($source, $width, $height);
        if ($strength === null) {
            return ['ink' => null, 'isSignature' => false];
        }

        [$top, $bottom, $left, $right] = self::withoutBands($strength, $width, $height);
        $rows = self::signatureRows($strength, $width, $top, $bottom, $left, $right);
        $bounds = self::bounds($strength, $width, $rows, $left, $right);
        if ($bounds === null || ! self::looksLikeSignature($strength, $width, $height, $rows, $bounds)) {
            return ['ink' => null, 'isSignature' => false];
        }

        return ['ink' => self::png(self::fitted(self::ink($source, $strength, $width, $rows, $bounds))), 'isSignature' => true];
    }

    /**
     * The ink's colour: the middle colour of its solid strokes, darkened.
     *
     * @param  list<array{int, int}>  $rows
     * @param  array{int, int, int, int}  $bounds
     * @return array{int, int, int}
     */
    private static function inkColor(GdImage $source, string $strength, int $width, array $rows, array $bounds): array
    {
        [$minX, , $maxX] = $bounds;
        $channels = [array_fill(0, 256, 0), array_fill(0, 256, 0), array_fill(0, 256, 0)];
        $count = 0;
        foreach ($rows as [$first, $last]) {
            for ($y = $first; $y <= $last; $y++) {
                for ($x = $minX; $x <= $maxX; $x++) {
                    if (ord($strength[$y * $width + $x]) < self::STROKE) {
                        continue;
                    }
                    $color = imagecolorat($source, $x, $y);
                    $channels[0][($color >> 16) & 0xFF]++;
                    $channels[1][($color >> 8) & 0xFF]++;
                    $channels[2][$color & 0xFF]++;
                    $count++;
                }
            }
        }

        return array_map(static function (array $histogram) use ($count): int {
            $seen = 0;
            foreach ($histogram as $value => $pixels) {
                $seen += $pixels;
                if ($seen * 2 >= $count) {
                    return (int) round($value * self::DARKEN);
                }
            }

            return 0;
        }, $channels);
    }

    /**
     * How many pixels a side to widen the strokes by: their width, read as twice their area over their
     * edge, against the thinnest that prints clearly at the signature's height.
     *
     * @param  list<array{int, int}>  $rows
     * @param  array{int, int, int, int}  $bounds
     */
    private static function growth(string $strength, int $width, array $rows, array $bounds): int
    {
        [$minX, $minY, $maxX, $maxY] = $bounds;
        $isStroke = static fn (int $index): bool => ord($strength[$index]) >= self::STROKE;
        $area = 0;
        $edge = 0;
        foreach ($rows as [$first, $last]) {
            for ($y = $first; $y <= $last; $y++) {
                for ($x = $minX; $x <= $maxX; $x++) {
                    $index = $y * $width + $x;
                    if (! $isStroke($index)) {
                        continue;
                    }
                    $area++;
                    $isInside = $x > $minX && $x < $maxX && $y > $first && $y < $last
                        && $isStroke($index - 1) && $isStroke($index + 1) && $isStroke($index - $width) && $isStroke($index + $width);
                    $edge += $isInside ? 0 : 1;
                }
            }
        }
        $thinnest = self::THINNEST * ($maxY - $minY + 1);
        $stroke = $edge === 0 ? $thinnest : 2 * $area / $edge;

        return $stroke >= $thinnest ? 0 : min(self::MOST_GROWTH, (int) ceil(($thinnest - $stroke) / 2));
    }

    // Each pixel's ink strength, one byte each: how much darker than the paper, its median shade, it is; null when that is no paper.
    private static function strength(GdImage $source, int $width, int $height): ?string
    {
        $shades = str_repeat("\0", $width * $height);
        $histogram = array_fill(0, 256, 0);
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $color = imagecolorat($source, $x, $y);
                // A clear pixel is read as white paper.
                $clear = (($color >> 24) & 0x7F) / 127;
                $shade = (int) round((0.299 * (($color >> 16) & 0xFF) + 0.587 * (($color >> 8) & 0xFF) + 0.114 * ($color & 0xFF)) * (1 - $clear) + 255 * $clear);
                $shades[$y * $width + $x] = chr($shade);
                $histogram[$shade]++;
            }
        }
        $paper = 255;
        $seen = 0;
        foreach ($histogram as $shade => $pixels) {
            $seen += $pixels;
            if ($seen * 2 >= $width * $height) {
                $paper = $shade;
                break;
            }
        }
        if ($paper < self::LIGHTEST_PAPER) {
            return null;
        }

        // Each shade's strength, translated byte for byte.
        $shadeBytes = '';
        $levelBytes = '';
        for ($shade = 0; $shade < 256; $shade++) {
            $shadeBytes .= chr($shade);
            $levelBytes .= chr((int) max(0, min(255, round(($paper - $shade - self::FAINT) * 255 / (self::SOLID - self::FAINT)))));
        }

        return strtr($shades, $shadeBytes, $levelBytes);
    }

    /**
     * The rows and columns left once each edge's bands are dropped: top, bottom, left, right.
     *
     * @return array{int, int, int, int}
     */
    private static function withoutBands(string $strength, int $width, int $height): array
    {
        $rowIsBand = static function (int $y) use ($strength, $width): bool {
            $inked = 0;
            for ($x = 0; $x < $width; $x++) {
                $inked += ord($strength[$y * $width + $x]) >= self::STROKE ? 1 : 0;
            }

            return $inked >= $width * self::BAND;
        };
        $columnIsBand = static function (int $x) use ($strength, $width, $height): bool {
            $inked = 0;
            for ($y = 0; $y < $height; $y++) {
                $inked += ord($strength[$y * $width + $x]) >= self::STROKE ? 1 : 0;
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

    /**
     * The rows the signature is written on: each run of inked rows, less a small one a clear gap sets apart
     * from the heaviest, as [first, last] pairs.
     *
     * @return list<array{int, int}>
     */
    private static function signatureRows(string $strength, int $width, int $top, int $bottom, int $left, int $right): array
    {
        $runs = [];
        $run = null;
        for ($y = $top; $y <= $bottom + 1; $y++) {
            $mass = 0;
            if ($y <= $bottom) {
                for ($x = $left; $x <= $right; $x++) {
                    $ink = ord($strength[$y * $width + $x]);
                    $mass += $ink >= self::BOUNDS ? $ink : 0;
                }
            }
            if ($mass > 0) {
                $run = $run === null ? [$y, $y, $mass] : [$run[0], $y, $run[2] + $mass];
            } elseif ($run !== null) {
                $runs[] = $run;
                $run = null;
            }
        }
        if ($runs === []) {
            return [];
        }

        $main = array_reduce($runs, static fn (?array $heaviest, array $candidate): array => $heaviest === null || $candidate[2] > $heaviest[2] ? $candidate : $heaviest);
        $mainHeight = $main[1] - $main[0] + 1;
        $kept = [];
        foreach ($runs as [$first, $last, $mass]) {
            $gap = $last < $main[0] ? $main[0] - $last - 1 : $first - $main[1] - 1;
            $isStray = $first !== $main[0]
                && $last - $first + 1 <= $mainHeight * self::STRAY_HEIGHT
                && $mass <= $main[2] * self::STRAY_MASS
                && $gap >= max(2, $mainHeight * self::STRAY_GAP);
            if (! $isStray) {
                $kept[] = [$first, $last];
            }
        }

        return $kept;
    }

    /**
     * The box the ink fills on the signature's rows: left, top, right, bottom; null when there is none.
     *
     * @param  list<array{int, int}>  $rows
     * @return array{int, int, int, int}|null
     */
    private static function bounds(string $strength, int $width, array $rows, int $left, int $right): ?array
    {
        $minX = PHP_INT_MAX;
        $maxX = -1;
        foreach ($rows as [$first, $last]) {
            for ($y = $first; $y <= $last; $y++) {
                for ($x = $left; $x <= $right; $x++) {
                    if (ord($strength[$y * $width + $x]) >= self::BOUNDS) {
                        $minX = min($minX, $x);
                        $maxX = max($maxX, $x);
                    }
                }
            }
        }

        return $maxX < 0 ? null : [$minX, $rows[0][0], $maxX, $rows[array_key_last($rows)][1]];
    }

    /**
     * A few strokes rather than many separate marks, and not mostly ink.
     *
     * @param  list<array{int, int}>  $rows
     * @param  array{int, int, int, int}  $bounds
     */
    private static function looksLikeSignature(string $strength, int $width, int $height, array $rows, array $bounds): bool
    {
        [$minX, $minY, $maxX, $maxY] = $bounds;
        $boxWidth = $maxX - $minX + 1;
        $boxHeight = $maxY - $minY + 1;
        $box = $boxWidth * $boxHeight;
        $isBroad = $boxWidth >= $width * self::BROAD && $boxHeight >= $height * self::BROAD;

        // The strokes on the signature's rows, one byte each over the box: 1 unvisited, 0 paper or visited.
        $mask = str_repeat("\0", $box);
        $inked = 0;
        foreach ($rows as [$first, $last]) {
            for ($y = $first; $y <= $last; $y++) {
                for ($x = $minX; $x <= $maxX; $x++) {
                    if (ord($strength[$y * $width + $x]) >= self::STROKE) {
                        $mask[($y - $minY) * $boxWidth + ($x - $minX)] = "\1";
                        $inked++;
                    }
                }
            }
        }
        if ($isBroad && $inked > $box * self::MOST_COVERAGE) {
            return false;
        }

        $speck = max(6, (int) round($box * self::SPECK));
        $marks = 0;
        for ($start = 0; $start < $box; $start++) {
            if ($mask[$start] !== "\1") {
                continue;
            }
            $mask[$start] = "\0";
            $stack = [$start];
            $size = 0;
            while ($stack !== []) {
                $index = array_pop($stack);
                $size++;
                $x = $index % $boxWidth;
                $y = intdiv($index, $boxWidth);
                for ($dy = -1; $dy <= 1; $dy++) {
                    for ($dx = -1; $dx <= 1; $dx++) {
                        $nx = $x + $dx;
                        $neighbour = ($y + $dy) * $boxWidth + $nx;
                        if ($nx >= 0 && $nx < $boxWidth && $neighbour >= 0 && $neighbour < $box && $mask[$neighbour] === "\1") {
                            $mask[$neighbour] = "\0";
                            $stack[] = $neighbour;
                        }
                    }
                }
            }
            if ($size >= $speck && ++$marks > self::MOST_MARKS) {
                return false;
            }
        }

        return true;
    }

    /**
     * The ink on the signature's rows in its one colour over clear paper, its thin strokes widened, cut to
     * the box with a little room.
     *
     * @param  list<array{int, int}>  $rows
     * @param  array{int, int, int, int}  $bounds
     */
    private static function ink(GdImage $source, string $strength, int $width, array $rows, array $bounds): GdImage
    {
        [$minX, $minY, $maxX, $maxY] = $bounds;
        [$red, $green, $blue] = self::inkColor($source, $strength, $width, $rows, $bounds);
        $growth = self::growth($strength, $width, $rows, $bounds);
        $room = self::PADDING + $growth;
        $originX = $minX - $room;
        $originY = $minY - $room;
        $inkWidth = $maxX - $minX + 1 + 2 * $room;
        $inkHeight = $maxY - $minY + 1 + 2 * $room;

        // The strokes widened as discs on a mask of their own, so a widened stroke is solid.
        $widened = null;
        if ($growth > 0) {
            $widened = imagecreatetruecolor($inkWidth, $inkHeight);
            $solid = imagecolorallocate($widened, 255, 255, 255);
            foreach ($rows as [$first, $last]) {
                for ($y = $first; $y <= $last; $y++) {
                    for ($x = $minX; $x <= $maxX; $x++) {
                        if (ord($strength[$y * $width + $x]) >= self::STROKE) {
                            imagefilledellipse($widened, $x - $originX, $y - $originY, 2 * $growth + 1, 2 * $growth + 1, $solid);
                        }
                    }
                }
            }
        }

        $levels = str_repeat("\0", $inkWidth * $inkHeight);
        foreach ($rows as [$first, $last]) {
            for ($y = $first; $y <= $last; $y++) {
                for ($x = $minX; $x <= $maxX; $x++) {
                    $levels[($y - $originY) * $inkWidth + ($x - $originX)] = $strength[$y * $width + $x];
                }
            }
        }

        $ink = imagecreatetruecolor($inkWidth, $inkHeight);
        imagealphablending($ink, false);
        imagesavealpha($ink, true);
        imagefill($ink, 0, 0, imagecolorallocatealpha($ink, 0, 0, 0, 127));
        for ($y = 0; $y < $inkHeight; $y++) {
            for ($x = 0; $x < $inkWidth; $x++) {
                $level = $widened !== null && (imagecolorat($widened, $x, $y) & 0xFF) > 0 ? 255 : ord($levels[$y * $inkWidth + $x]);
                if ($level > 0) {
                    imagesetpixel($ink, $x, $y, imagecolorallocatealpha($ink, $red, $green, $blue, 127 - intdiv($level * 127, 255)));
                }
            }
        }

        return $ink;
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
