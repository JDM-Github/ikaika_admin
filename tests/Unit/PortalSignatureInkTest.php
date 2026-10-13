<?php

namespace Tests\Unit;

use App\Support\Portal\PortalSignatureInk;
use GdImage;
use Tests\TestCase;

class PortalSignatureInkTest extends TestCase
{
    // The reported ask (2026-10-14): a signature uploaded as a phone screenshot printed as a grey box with a line over it.
    public function test_a_screenshot_signature_comes_out_as_its_ink_alone(): void
    {
        // Off-white paper, a dark status bar along the top, and a stroke of ink across the middle.
        $picture = $this->picture(400, 300, [242, 242, 240]);
        imagefilledrectangle($picture, 0, 0, 399, 11, imagecolorallocate($picture, 20, 20, 20));
        imagefilledrectangle($picture, 100, 140, 299, 149, imagecolorallocate($picture, 10, 10, 60));

        $ink = $this->read(PortalSignatureInk::clean($this->png($picture)));

        // Cut to the stroke with a little room, the bar gone and the paper clear; the ink keeps its colour.
        $this->assertSame([204, 14], [imagesx($ink), imagesy($ink)]);
        $this->assertSame(127, (imagecolorat($ink, 0, 0) >> 24) & 0x7F);
        $this->assertSame([10, 10, 60, 0], $this->rgba($ink, 100, 7));
    }

    public function test_a_long_stroke_inside_the_picture_is_writing_not_a_band(): void
    {
        // A signer's own underline nearly the picture's width, away from its edges.
        $picture = $this->picture(500, 120, [255, 255, 255]);
        imagefilledrectangle($picture, 20, 40, 60, 80, imagecolorallocate($picture, 0, 0, 0));
        imagefilledrectangle($picture, 10, 100, 489, 102, imagecolorallocate($picture, 0, 0, 0));

        $ink = $this->read(PortalSignatureInk::clean($this->png($picture)));

        $this->assertSame([484, 67], [imagesx($ink), imagesy($ink)]);
    }

    public function test_a_large_signature_is_scaled_to_what_the_card_needs(): void
    {
        $picture = $this->picture(1000, 500, [255, 255, 255]);
        imagefilledrectangle($picture, 50, 50, 949, 449, imagecolorallocate($picture, 0, 0, 0));
        imagefilledrectangle($picture, 60, 60, 939, 439, imagecolorallocate($picture, 255, 255, 255));

        $ink = $this->read(PortalSignatureInk::clean($this->png($picture)));

        $this->assertLessThanOrEqual(900, imagesx($ink));
        $this->assertSame(300, imagesy($ink));
    }

    public function test_a_picture_without_ink_or_that_cannot_be_read_is_left_alone(): void
    {
        $this->assertNull(PortalSignatureInk::clean($this->png($this->picture(50, 50, [250, 250, 250]))));
        $this->assertNull(PortalSignatureInk::clean('not a picture'));
    }

    /**
     * @param  array{int, int, int}  $paper
     */
    private function picture(int $width, int $height, array $paper): GdImage
    {
        $picture = imagecreatetruecolor($width, $height);
        imagefill($picture, 0, 0, imagecolorallocate($picture, ...$paper));

        return $picture;
    }

    private function png(GdImage $picture): string
    {
        ob_start();
        imagepng($picture);

        return (string) ob_get_clean();
    }

    private function read(?string $png): GdImage
    {
        $this->assertNotNull($png);
        $picture = imagecreatefromstring($png);
        $this->assertInstanceOf(GdImage::class, $picture);

        return $picture;
    }

    /**
     * @return array{int, int, int, int}
     */
    private function rgba(GdImage $picture, int $x, int $y): array
    {
        $color = imagecolorat($picture, $x, $y);

        return [($color >> 16) & 0xFF, ($color >> 8) & 0xFF, $color & 0xFF, ($color >> 24) & 0x7F];
    }
}
