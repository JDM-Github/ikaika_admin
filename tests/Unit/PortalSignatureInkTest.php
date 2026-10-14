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

        $ink = $this->ink($picture);

        // Cut to the stroke with a little room, the bar gone and the paper clear; the ink its own colour, darkened.
        $this->assertSame([204, 14], [imagesx($ink), imagesy($ink)]);
        $this->assertSame(127, (imagecolorat($ink, 0, 0) >> 24) & 0x7F);
        $this->assertSame([5, 5, 30, 0], $this->rgba($ink, 100, 7));
    }

    // The reported ask (2026-10-14): a signature of fine strokes printed faint.
    public function test_a_stroke_too_thin_to_print_is_widened(): void
    {
        // Hairlines a pixel wide, against a signature 201 pixels tall.
        $picture = $this->picture(300, 250, [255, 255, 255]);
        imageline($picture, 150, 20, 150, 220, imagecolorallocate($picture, 0, 0, 0));
        imageline($picture, 100, 120, 200, 120, imagecolorallocate($picture, 0, 0, 0));

        $ink = $this->ink($picture);

        // Widened three pixels a side, with the room for it around the box.
        $this->assertSame([111, 211], [imagesx($ink), imagesy($ink)]);
        $this->assertSame([0, 0, 127, 127], [
            $this->rgba($ink, 52, 85)[3], $this->rgba($ink, 58, 85)[3], $this->rgba($ink, 51, 85)[3], $this->rgba($ink, 59, 85)[3],
        ]);
    }

    public function test_a_long_stroke_inside_the_picture_is_writing_not_a_band(): void
    {
        // A signer's own underline nearly the picture's width, away from its edges.
        $picture = $this->picture(500, 120, [255, 255, 255]);
        imagefilledrectangle($picture, 20, 40, 60, 80, imagecolorallocate($picture, 0, 0, 0));
        imagefilledrectangle($picture, 10, 100, 489, 102, imagecolorallocate($picture, 0, 0, 0));

        $ink = $this->ink($picture);

        $this->assertSame([484, 67], [imagesx($ink), imagesy($ink)]);
    }

    // The reported ask (2026-10-14): a stock signature's watermark under it lifted the signature off its line.
    public function test_a_small_line_set_apart_under_the_signature_is_dropped(): void
    {
        $picture = $this->picture(500, 260, [255, 255, 255]);
        imagefilledrectangle($picture, 50, 40, 60, 180, imagecolorallocate($picture, 0, 0, 0));
        imagefilledrectangle($picture, 50, 170, 450, 176, imagecolorallocate($picture, 0, 0, 0));
        // A watermark's small letters, well under it.
        for ($letter = 0; $letter < 10; $letter++) {
            imagefilledrectangle($picture, 300 + $letter * 8, 236, 302 + $letter * 8, 241, imagecolorallocate($picture, 90, 90, 90));
        }

        $ink = $this->ink($picture);

        $this->assertSame([405, 145], [imagesx($ink), imagesy($ink)]);
    }

    public function test_a_large_signature_is_scaled_to_what_the_card_needs(): void
    {
        $picture = $this->picture(1000, 500, [255, 255, 255]);
        imagefilledrectangle($picture, 50, 50, 949, 449, imagecolorallocate($picture, 0, 0, 0));
        imagefilledrectangle($picture, 60, 60, 939, 439, imagecolorallocate($picture, 255, 255, 255));

        $ink = $this->ink($picture);

        $this->assertLessThanOrEqual(900, imagesx($ink));
        $this->assertSame(300, imagesy($ink));
    }

    // The reported ask (2026-10-14): a random picture uploaded as a signature printed shrunk onto the line.
    public function test_a_picture_that_is_no_signature_is_said_not_to_be_one(): void
    {
        // Many separate marks, as a page of text or a QR code.
        $page = $this->picture(400, 400, [255, 255, 255]);
        for ($mark = 0; $mark < 100; $mark++) {
            imagefilledrectangle($page, 20 + ($mark % 10) * 36, 20 + intdiv($mark, 10) * 36, 28 + ($mark % 10) * 36, 28 + intdiv($mark, 10) * 36, imagecolorallocate($page, 0, 0, 0));
        }
        // Mostly ink, as a photo.
        $photo = $this->picture(300, 200, [255, 255, 255]);
        imagefilledrectangle($photo, 40, 30, 259, 140, imagecolorallocate($photo, 60, 40, 30));
        // Nothing on it.
        $blank = $this->picture(50, 50, [250, 250, 250]);
        // No light paper at all, as a meme or a cartoon: its few darkest lines are not ink.
        $dark = $this->picture(300, 200, [200, 30, 30]);
        imagefilledrectangle($dark, 100, 90, 200, 96, imagecolorallocate($dark, 0, 0, 0));

        foreach ([$page, $photo, $blank, $dark] as $picture) {
            $this->assertSame(['ink' => null, 'isSignature' => false], PortalSignatureInk::clean($this->png($picture)));
        }
    }

    public function test_a_picture_that_cannot_be_read_is_left_to_the_card(): void
    {
        $this->assertSame(['ink' => null, 'isSignature' => true], PortalSignatureInk::clean('not a picture'));
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

    private function ink(GdImage $picture): GdImage
    {
        $read = PortalSignatureInk::clean($this->png($picture));
        $this->assertTrue($read['isSignature']);
        $this->assertNotNull($read['ink']);
        $ink = imagecreatefromstring($read['ink']);
        $this->assertInstanceOf(GdImage::class, $ink);

        return $ink;
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
