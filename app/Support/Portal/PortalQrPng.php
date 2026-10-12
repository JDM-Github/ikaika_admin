<?php

namespace App\Support\Portal;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;

/**
 * A link's QR code as a PNG, for emails: mail clients drop SVG and data: images, so the code rides
 * along as an inline PNG. Neither GD nor Imagick is assumed on the servers, so the image is written
 * straight from bacon's module matrix with zlib: an 8-bit grayscale PNG, black modules on white,
 * with the quiet zone the standard asks for.
 */
final class PortalQrPng
{
    // Pixels per module and the white border in modules; about 300px for a share link.
    private const SCALE = 8;

    private const QUIET_ZONE = 4;

    public static function png(string $text): string
    {
        $matrix = Encoder::encode($text, ErrorCorrectionLevel::M())->getMatrix();
        $modules = $matrix->getWidth();
        $size = ($modules + self::QUIET_ZONE * 2) * self::SCALE;

        $rows = '';
        for ($y = 0; $y < $size; $y++) {
            $moduleY = intdiv($y, self::SCALE) - self::QUIET_ZONE;
            $row = "\0"; // filter type None
            for ($x = 0; $x < $size; $x++) {
                $moduleX = intdiv($x, self::SCALE) - self::QUIET_ZONE;
                $isDark = $moduleX >= 0 && $moduleY >= 0 && $moduleX < $modules && $moduleY < $modules
                    && $matrix->get($moduleX, $moduleY) === 1;
                $row .= $isDark ? "\x00" : "\xFF";
            }
            $rows .= $row;
        }

        return "\x89PNG\r\n\x1a\n"
            .self::chunk('IHDR', pack('NNCCCCC', $size, $size, 8, 0, 0, 0, 0))
            .self::chunk('IDAT', (string) gzcompress($rows, 9))
            .self::chunk('IEND', '');
    }

    private static function chunk(string $type, string $data): string
    {
        return pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    }
}
