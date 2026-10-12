<?php

namespace Tests\Unit;

use App\Support\Portal\PortalQrPng;
use PHPUnit\Framework\TestCase;

class PortalQrPngTest extends TestCase
{
    public function test_a_link_becomes_a_square_grayscale_png_with_a_white_quiet_zone(): void
    {
        $png = PortalQrPng::png('https://portal.example/share/kelly');

        $this->assertStringStartsWith("\x89PNG\r\n\x1a\n", $png);
        $header = unpack('Nwidth/Nheight/Cdepth/Ccolor', substr($png, 16, 10));
        $this->assertSame($header['width'], $header['height']);
        $this->assertSame([8, 0], [$header['depth'], $header['color']]);

        // One filter byte plus one byte a pixel per row; the corner pixel is the white quiet zone.
        $length = unpack('N', substr($png, 33, 4))[1];
        $rows = (string) gzuncompress(substr($png, 41, $length));
        $this->assertSame($header['height'] * ($header['width'] + 1), strlen($rows));
        $this->assertSame("\xFF", $rows[1]);
        $this->assertStringContainsString("\x00\x00", substr($rows, 0, strlen($rows)));
    }
}
