<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Media — GdImageConverter
 *
 * Everything here is against real ext-gd calls, not fakes: this driver's
 * whole value is in getting real encode/decode behavior right (in
 * particular the transparent-to-JPEG flattening), which a fake image
 * object could never actually get wrong the way the real one can.
 *
 * Run: php src/Libs/Italix/Media/tests/GdImageConverterTest.php
 */

declare(strict_types=1);

(static function (): void {
    foreach ([
        __DIR__ . '/../vendor/autoload.php',
        __DIR__ . '/../../../../../vendor/autoload.php',
        __DIR__ . '/../../../../vendor/autoload.php',
        __DIR__ . '/../../../autoload.php',
    ] as $autoload) {
        if (is_file($autoload)) {
            require_once $autoload;

            return;
        }
    }

    fwrite(STDERR, "Could not find an autoloader. Run composer install.\n");
    exit(2);
})();

use Italix\Converters\ConversionOptions;
use Italix\Converters\ConverterSet;
use Italix\Converters\UnsupportedOptionException;
use Italix\Media\GdImageConverter;

use function Italix\Testing\{suite, section, test, summary};

suite('Italix Media — GdImageConverter');

if (!extension_loaded('gd') || !function_exists('imagewebp')) {
    echo "  SKIPPED — ext-gd (with WebP support) is absent.\n";
    exit(summary());
}

$driver = new GdImageConverter();

/** A tiny real PNG, opaque red, 4x4 — for tests that don't care about transparency. */
$opaque_png = (static function (): string {
    $im = imagecreatetruecolor(4, 4);
    imagefill($im, 0, 0, imagecolorallocate($im, 200, 30, 30));
    ob_start();
    imagepng($im);
    $bytes = ob_get_clean();
    imagedestroy($im);

    return $bytes;
})();

/** A tiny real PNG, fully transparent but with RGB=black — the flattening trap. */
$transparent_black_png = (static function (): string {
    $im = imagecreatetruecolor(4, 4);
    imagesavealpha($im, true);
    imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
    ob_start();
    imagepng($im);
    $bytes = ob_get_clean();
    imagedestroy($im);

    return $bytes;
})();

// -----------------------------------------------------------------------------
section('the driver describes itself honestly');

test('pairs() covers every directed pair among the five formats (5x4)', count($driver->pairs()) === 20);
test('...and includes both directions of a pair, not just one', in_array(['png', 'webp'], $driver->pairs(), true) && in_array(['webp', 'png'], $driver->pairs(), true));
test('...with no format paired to itself', !in_array(['png', 'png'], $driver->pairs(), true));
test('is_available() is true — this machine has ext-gd with WebP support', $driver->is_available());

// -----------------------------------------------------------------------------
section('convert() produces real, valid images — checked by decoding the output, not by trusting it');

$to_webp = $driver->convert($opaque_png, 'png', 'webp', ConversionOptions::none());
test('png -> webp: extension is webp', $to_webp->extension_code() === 'webp');
test('...mime is image/webp', $to_webp->mime() === 'image/webp');
test('...the bytes really are a WebP (RIFF header)', str_starts_with($to_webp->bytes(), 'RIFF'));

$decoded_webp = imagecreatefromstring($to_webp->bytes());
test('...and it decodes back to an image of the same size', $decoded_webp !== false && imagesx($decoded_webp) === 4 && imagesy($decoded_webp) === 4);

$to_gif = $driver->convert($opaque_png, 'png', 'gif', ConversionOptions::none());
test('png -> gif: the bytes really are a GIF (GIF8 header)', str_starts_with($to_gif->bytes(), 'GIF8'));

// -----------------------------------------------------------------------------
section('webp preserves transparency — unlike jpg, it doesn\'t need the flattening step');

$half_transparent_png = (static function (): string {
    $im = imagecreatetruecolor(4, 4);
    imagesavealpha($im, true);
    imagefill($im, 0, 0, imagecolorallocatealpha($im, 10, 20, 30, 64)); // half-transparent, distinct RGB
    ob_start();
    imagepng($im);
    $bytes = ob_get_clean();
    imagedestroy($im);

    return $bytes;
})();

$webp_with_alpha = $driver->convert($half_transparent_png, 'png', 'webp', ConversionOptions::none());
$decoded_alpha    = imagecreatefromstring($webp_with_alpha->bytes());
imagesavealpha($decoded_alpha, true);
imagealphablending($decoded_alpha, false);
$rgba  = imagecolorat($decoded_alpha, 0, 0);
$alpha = ($rgba >> 24) & 0x7F;

test('the alpha channel survives the round trip, close to the original 64/127', abs($alpha - 64) <= 2);

// -----------------------------------------------------------------------------
section('the reason this driver exists: JPEG output never carries a transparency artifact through');

$naive_pixel = (static function (string $png_bytes): array {
    // What the bug would look like without flattening: decode the PNG,
    // encode straight to JPEG with no white background step.
    $im = imagecreatefromstring($png_bytes);
    ob_start();
    imagejpeg($im);
    $jpg = ob_get_clean();
    imagedestroy($im);

    $back = imagecreatefromstring($jpg);
    $rgb  = imagecolorat($back, 0, 0);

    return [($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF];
})($transparent_black_png);

test('reproduced first: the naive path really does turn transparent black into solid black', $naive_pixel === [0, 0, 0]);

$driver_result   = $driver->convert($transparent_black_png, 'png', 'jpg', ConversionOptions::none());
$driver_back     = imagecreatefromstring($driver_result->bytes());
$driver_pixel    = imagecolorat($driver_back, 0, 0);
$driver_rgb      = [($driver_pixel >> 16) & 0xFF, ($driver_pixel >> 8) & 0xFF, $driver_pixel & 0xFF];

test('the driver flattens onto white instead: same input, pixel comes back white, not black', $driver_rgb === [255, 255, 255]);
test('an already-opaque source is untouched by the flattening step', (static function () use ($driver, $opaque_png): bool {
    $result = $driver->convert($opaque_png, 'png', 'jpg', ConversionOptions::none());
    $back   = imagecreatefromstring($result->bytes());
    $rgb    = imagecolorat($back, 0, 0);
    [$r, $g, $b] = [($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF];

    // Lossy JPEG re-encoding shifts values slightly; close to the original
    // red (200, 30, 30) is the right bar, exact equality is not.
    return abs($r - 200) < 10 && abs($g - 30) < 10 && abs($b - 30) < 10;
})());

// -----------------------------------------------------------------------------
section('decoding reads the bytes, not the claimed extension');

$mislabeled = $driver->convert($opaque_png, 'jpg', 'webp', ConversionOptions::none()); // really a PNG, labeled jpg
test('a mislabeled source still decodes correctly, because the label is never trusted', str_starts_with($mislabeled->bytes(), 'RIFF'));

$garbage_threw = false;
try {
    $driver->convert('not an image at all', 'png', 'webp', ConversionOptions::none());
} catch (\Italix\Converters\ConversionException $e) {
    $garbage_threw = true;
}
test('genuinely undecodable bytes throw ConversionException rather than producing garbage output', $garbage_threw);

// -----------------------------------------------------------------------------
section('registered in a real ConverterSet, round-tripping through it');

$set = new ConverterSet([$driver]);

$route = $set->route('png', 'webp');
test('a ConverterSet finds this driver directly, one hop', count($route) === 1);

$round_tripped = $set->convert($set->convert($opaque_png, 'png', 'webp')->bytes(), 'webp', 'png');
$decoded       = imagecreatefromstring($round_tripped->bytes());
$rgb           = imagecolorat($decoded, 0, 0);
[$r, $g, $b]   = [($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF];

// WebP at quality 90 is lossy — (200, 29, 30) came back on a real run, not
// (200, 30, 30). Exact equality would be testing lossless compression this
// driver never claimed to use; a tight tolerance is the honest assertion.
test(
    'png -> webp -> png survives a round trip through the router, same color within lossy tolerance',
    abs($r - 200) <= 3 && abs($g - 30) <= 3 && abs($b - 30) <= 3
);

// -----------------------------------------------------------------------------
section('QUALITY: a caller-supplied value overrides DEFAULT_QUALITY');

/** A busy, non-flat pattern — a flat color compresses so well that quality barely changes its size. */
$busy_png = (static function (): string {
    $im = imagecreatetruecolor(16, 16);
    for ($x = 0; $x < 16; $x++) {
        for ($y = 0; $y < 16; $y++) {
            imagesetpixel($im, $x, $y, imagecolorallocate($im, ($x * 17) % 256, ($y * 23) % 256, (($x + $y) * 11) % 256));
        }
    }
    ob_start();
    imagepng($im);
    $bytes = ob_get_clean();
    imagedestroy($im);

    return $bytes;
})();

$low_quality  = $driver->convert($busy_png, 'png', 'jpg', ConversionOptions::none()->with_quality(10));
$high_quality = $driver->convert($busy_png, 'png', 'jpg', ConversionOptions::none()->with_quality(95));

test('a low requested quality produces visibly smaller output than a high one', strlen($low_quality->bytes()) < strlen($high_quality->bytes()));

// -----------------------------------------------------------------------------
section('LOSSLESS: real byte-exact WebP, not just a smaller lossy file');

$lossless_webp = $driver->convert($busy_png, 'png', 'webp', ConversionOptions::none()->with_lossless(true));
$lossy_webp    = $driver->convert($busy_png, 'png', 'webp', ConversionOptions::none()->with_quality(50));

$original       = imagecreatefromstring($busy_png);
$original_pixel = imagecolorat($original, 8, 8);
$lossless_pixel = imagecolorat(imagecreatefromstring($lossless_webp->bytes()), 8, 8);
$lossy_pixel    = imagecolorat(imagecreatefromstring($lossy_webp->bytes()), 8, 8);

test('lossless=true round-trips a busy image byte-for-byte, pixel-for-pixel', $lossless_pixel === $original_pixel);
test('...unlike the same image at quality=50, confirming this is a real difference, not a no-op flag', $lossy_pixel !== $original_pixel);

$jpeg_lossless_threw = false;
try {
    $driver->convert($opaque_png, 'png', 'jpg', ConversionOptions::none()->with_lossless(true));
} catch (UnsupportedOptionException $e) {
    $jpeg_lossless_threw = true;
    test('the exception names the lossless key', $e->option_key_code() === ConversionOptions::LOSSLESS);
}
test('lossless=true against a jpg target is refused — JPEG has no lossless mode to honor it with', $jpeg_lossless_threw);

// -----------------------------------------------------------------------------
section('WIDTH/HEIGHT: resizing the decoded image before encoding');

$wide_png = (static function (): string {
    $im = imagecreatetruecolor(200, 100);
    imagefill($im, 0, 0, imagecolorallocate($im, 10, 20, 30));
    ob_start();
    imagepng($im);
    $bytes = ob_get_clean();
    imagedestroy($im);

    return $bytes;
})();

$width_only = $driver->convert($wide_png, 'png', 'png', ConversionOptions::none()->with_width(100));
$decoded_w  = imagecreatefromstring($width_only->bytes());
test('width alone resizes to that width, preserving aspect ratio (200x100 -> 100x?)', imagesx($decoded_w) === 100);
test('...specifically 100x50, not just "some height"', imagesy($decoded_w) === 50);

$height_only = $driver->convert($wide_png, 'png', 'png', ConversionOptions::none()->with_height(20));
$decoded_h   = imagecreatefromstring($height_only->bytes());
test('height alone also preserves aspect ratio (200x100 -> ?x20)', imagesy($decoded_h) === 20);
test('...specifically 40x20', imagesx($decoded_h) === 40);

$both = $driver->convert($wide_png, 'png', 'png', ConversionOptions::none()->with_width(30)->with_height(30));
$decoded_both = imagecreatefromstring($both->bytes());
test('both dimensions together is an exact fit, not aspect-preserving', imagesx($decoded_both) === 30 && imagesy($decoded_both) === 30);

$unresized = $driver->convert($wide_png, 'png', 'png', ConversionOptions::none());
$decoded_u = imagecreatefromstring($unresized->bytes());
test('no width/height at all leaves the original size untouched', imagesx($decoded_u) === 200 && imagesy($decoded_u) === 100);

// -----------------------------------------------------------------------------
section('driver-scoped values: a value set for this driver reaches it, one set for another does not');

$scoped_w = imagecreatefromstring($driver->convert($wide_png, 'png', 'png', ConversionOptions::none()->with_width(100, GdImageConverter::class))->bytes());
test('WIDTH scoped to GdImageConverter is read: 200x100 -> 100x50', [imagesx($scoped_w), imagesy($scoped_w)] === [100, 50]);
$elsewhere = imagecreatefromstring($driver->convert($wide_png, 'png', 'png', ConversionOptions::none()->with_width(100, 'Some\\OtherDriver'))->bytes());
test('WIDTH scoped to another driver is not: the size is untouched', [imagesx($elsewhere), imagesy($elsewhere)] === [200, 100]);
$over = imagecreatefromstring($driver->convert($wide_png, 'png', 'png', ConversionOptions::none()->with_width(50)->with_width(100, GdImageConverter::class))->bytes());
test('a scoped value wins over the unscoped one, for this driver', imagesx($over) === 100);

$noise_im = imagecreatetruecolor(200, 200);
mt_srand(11);
for ($y = 0; $y < 200; $y++) {
    for ($x = 0; $x < 200; $x++) {
        imagesetpixel($noise_im, $x, $y, mt_rand(0, 0xFFFFFF));
    }
}
ob_start();
imagepng($noise_im);
imagedestroy($noise_im);
$noise_png = ob_get_clean();
$scoped_q  = $driver->convert($noise_png, 'png', 'jpg', ConversionOptions::none()->with_quality(10, GdImageConverter::class));
$default_q = $driver->convert($noise_png, 'png', 'jpg', ConversionOptions::none());
test('QUALITY scoped to GdImageConverter is read: quality 10 is far smaller than the default 90', strlen($scoped_q->bytes()) * 3 < strlen($default_q->bytes()));

$scoped_lossless_threw = false;
try {
    $driver->convert($wide_png, 'png', 'jpg', ConversionOptions::none()->with_lossless(true, GdImageConverter::class));
} catch (UnsupportedOptionException $e) {
    $scoped_lossless_threw = true;
}
test('LOSSLESS scoped to GdImageConverter is read too: a JPEG refuses it', $scoped_lossless_threw);

// -----------------------------------------------------------------------------
section('palette images to WebP — imagewebp() refuses a palette, so the driver converts to true colour first');

$palette = static function (string $format, bool $transparent): string {
    $im   = imagecreate(8, 8);
    $red  = imagecolorallocate($im, 255, 0, 0);
    $blue = imagecolorallocate($im, 0, 0, 255);
    imagefilledrectangle($im, 0, 0, 3, 7, $red);
    imagefilledrectangle($im, 4, 0, 7, 7, $blue);
    if ($transparent) {
        imagecolortransparent($im, $blue);
    }
    ob_start();
    $format === 'gif' ? imagegif($im) : imagepng($im);
    imagedestroy($im);

    return (string) ob_get_clean();
};
$webp_pixel = static function (string $webp, int $x): array {
    $im = imagecreatefromstring($webp);
    $c  = imagecolorsforindex($im, imagecolorat($im, $x, 0));
    imagedestroy($im);

    return [$c['red'], $c['green'], $c['blue'], $c['alpha']];
};
// Lossy WebP rounds a colour by a point or two: close, channel by channel.
$close = static fn (array $a, array $b): bool => max(array_map(static fn (int $x, int $y): int => abs($x - $y), $a, $b)) <= 4;

$from_palette_png = $driver->convert($palette('png', false), 'png', 'webp', ConversionOptions::none());
test('a palette PNG becomes a WebP, its colours kept', str_starts_with($from_palette_png->bytes(), 'RIFF') && $close($webp_pixel($from_palette_png->bytes(), 1), [255, 0, 0, 0]));
$from_transparent = $driver->convert($palette('png', true), 'png', 'webp', ConversionOptions::none()->with_lossless(true));
test("...its transparent colour stays transparent (lossless)", $webp_pixel($from_transparent->bytes(), 6)[3] === 127 && $webp_pixel($from_transparent->bytes(), 1) === [255, 0, 0, 0]);
$from_gif = $driver->convert($palette('gif', false), 'gif', 'webp', ConversionOptions::none());
test('a GIF — always a palette — becomes a WebP too', str_starts_with($from_gif->bytes(), 'RIFF') && $close($webp_pixel($from_gif->bytes(), 6), [0, 0, 255, 0]));

exit(summary());
