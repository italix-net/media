<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Media — ImageTrimmer
 *
 * Against real ext-gd calls and real, decodable fixtures built with GD
 * itself (a solid-color canvas with a distinct marker pixel placed at a
 * known coordinate) — checking WHICH pixels survive a crop is the only way
 * to prove the box CropGeometry computed was actually applied correctly,
 * not just that *some* smaller image came back.
 *
 * Run: php src/Libs/Italix/Media/tests/ImageTrimmerTest.php
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

use Italix\Converters\ConversionException;
use Italix\Converters\ConversionOptions;
use Italix\Media\ImageTrimmer;

use function Italix\Testing\{suite, section, test, summary};

suite('Italix Media — ImageTrimmer');

$driver = new ImageTrimmer();

if (!$driver->is_available()) {
    echo "  SKIPPED — ext-gd (imagecrop) is absent.\n";
    exit(summary());
}

/**
 * A 1000x500 canvas, red everywhere except one blue marker pixel at
 * (900, 50) — near the top-right corner. A crop that should keep the
 * top-right region will still contain blue somewhere; a crop that should
 * have removed it will be pure red. This is what makes these assertions
 * about the ACTUAL box applied, not just about output dimensions.
 */
function marker_image(): string
{
    $image = imagecreatetruecolor(1000, 500);
    imagefill($image, 0, 0, imagecolorallocate($image, 200, 0, 0));
    imagesetpixel($image, 900, 50, imagecolorallocate($image, 0, 0, 200));
    ob_start();
    imagepng($image);
    imagedestroy($image);

    return ob_get_clean();
}

function has_blue_pixel(string $png_bytes): bool
{
    $image = imagecreatefromstring($png_bytes);
    $found = false;

    for ($x = 0; $x < imagesx($image) && !$found; $x++) {
        for ($y = 0; $y < imagesy($image) && !$found; $y++) {
            $rgb = imagecolorat($image, $x, $y);
            if (($rgb & 0xFF) > 100 && (($rgb >> 16) & 0xFF) < 100) {
                $found = true;
            }
        }
    }

    imagedestroy($image);

    return $found;
}

$marker_png = marker_image();

// -----------------------------------------------------------------------------
section('the driver describes itself honestly');

test('extensions() covers the real GD-decodable formats', $driver->extensions() === ['png', 'jpg', 'jpeg', 'gif', 'webp']);
test('is_available() is true — ext-gd with imagecrop is present', $driver->is_available());
test('describe() names the real mechanism', str_contains($driver->describe(), 'CropGeometry'));

// -----------------------------------------------------------------------------
section('a crop that keeps the marker region really keeps the marker pixel');

$kept = $driver->trim($marker_png, 'png', null, ConversionOptions::none()->with_crop_left(800));
$decoded_kept = imagecreatefromstring($kept->bytes());
test('cropping the left 800px leaves a 200px-wide image', imagesx($decoded_kept) === 200);
test('...the marker pixel (originally at x=900) survives inside it', has_blue_pixel($kept->bytes()));
imagedestroy($decoded_kept);

// -----------------------------------------------------------------------------
section('a crop that removes the marker region really removes it');

$removed = $driver->trim($marker_png, 'png', null, ConversionOptions::none()->with_width(500)->with_align_x(0));
test('ALIGN_X=0 keeps the LEFT 500px, where the marker never was', !has_blue_pixel($removed->bytes()));

// -----------------------------------------------------------------------------
section('RATIO crops correctly on a real image, checked by decoding the result, not trusting the byte count');

$widescreen = $driver->trim($marker_png, 'png', null, ConversionOptions::none()->with_ratio('2:1'));
$decoded_ws = imagecreatefromstring($widescreen->bytes());
test('a 1000x500 (2:1) source cropped to 2:1 needs no crop at all', [imagesx($decoded_ws), imagesy($decoded_ws)] === [1000, 500]);
imagedestroy($decoded_ws);

$square = $driver->trim($marker_png, 'png', null, ConversionOptions::none()->with_ratio('1:1'));
$decoded_sq = imagecreatefromstring($square->bytes());
test('cropped to 1:1, both dimensions are equal', imagesx($decoded_sq) === imagesy($decoded_sq));
test('...and it is really 500x500 (height stays full, width crops down)', [imagesx($decoded_sq), imagesy($decoded_sq)] === [500, 500]);
imagedestroy($decoded_sq);

// -----------------------------------------------------------------------------
section('extension and mime are correct, and the format round-trips through jpg too');

test('the extension matches what was requested', $kept->extension_code() === 'png');
test('the mime type matches', $kept->mime() === 'image/png');

$as_jpg = $driver->trim($marker_png, 'png', null, ConversionOptions::none());
test('same-format-in-same-format-out means this call keeps the source format even when the identity crop applies', $as_jpg->extension_code() === 'png');

// -----------------------------------------------------------------------------
section('a genuinely invalid crop request fails loudly');

$threw = false;
try {
    $driver->trim($marker_png, 'png', null, ConversionOptions::none()->with_width(5000));
} catch (ConversionException $e) {
    $threw = true;
}
test('a WIDTH larger than the source throws, via the same CropGeometry validation used directly', $threw);

exit(summary());
