<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Media — ImageGridMerger
 *
 * Against real ext-gd calls. The first section is the property the grid
 * keys were designed for: a sheet taken apart by ImageExtractor and put
 * back together here, with the same options, is the same sheet — every
 * pixel compared, not the size.
 *
 * Run: php src/Libs/Italix/Media/tests/ImageGridMergerTest.php
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
use Italix\Converters\ConvertedDocument;
use Italix\Media\ImageExtractor;
use Italix\Media\ImageGridMerger;

use function Italix\Testing\{suite, section, test, summary};

suite('Italix Media — ImageGridMerger');

$driver = new ImageGridMerger();

if (!$driver->is_available()) {
    echo "  SKIPPED — ext-gd is absent.\n";
    exit(summary());
}

/**
 * @param array{0: int, 1: int, 2: int, 3: int} $background r, g, b, GD alpha
 * @param list<array{0: int, 1: int, 2: int, 3: int, 4: array{0: int, 1: int, 2: int}}> $boxes
 */
function paint_png(int $width, int $height, array $background, array $boxes = []): string
{
    $image = imagecreatetruecolor($width, $height);
    imagealphablending($image, false);
    imagesavealpha($image, true);
    imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, imagecolorallocatealpha($image, ...$background));
    foreach ($boxes as [$x, $y, $w, $h, $rgb]) {
        imagefilledrectangle($image, $x, $y, $x + $w - 1, $y + $h - 1, imagecolorallocate($image, ...$rgb));
    }
    ob_start();
    imagepng($image);
    imagedestroy($image);

    return ob_get_clean();
}

/** Every pixel, alpha included. */
function same_pixels(string $a, string $b): bool
{
    $one = imagecreatefromstring($a);
    $two = imagecreatefromstring($b);
    $same = imagesx($one) === imagesx($two) && imagesy($one) === imagesy($two);
    for ($y = 0; $same && $y < imagesy($one); $y++) {
        for ($x = 0; $same && $x < imagesx($one); $x++) {
            $same = imagecolorat($one, $x, $y) === imagecolorat($two, $x, $y);
        }
    }
    imagedestroy($one);
    imagedestroy($two);

    return $same;
}

/** [r, g, b, alpha] at (x, y). */
function at(string $bytes, int $x, int $y): array
{
    $image = imagecreatefromstring($bytes);
    imagepalettetotruecolor($image);
    $rgb   = imagecolorat($image, $x, $y);
    imagedestroy($image);

    return [($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF, ($rgb >> 24) & 0x7F];
}

function size_of(string $bytes): array
{
    $image = imagecreatefromstring($bytes);
    $size  = [imagesx($image), imagesy($image)];
    imagedestroy($image);

    return $size;
}

function throws(callable $call): bool
{
    try {
        $call();
    } catch (ConversionException $e) {
        return true;
    }

    return false;
}

const WHITE = [255, 255, 255, 0];

// The extractor test's reference sheet: 1024x600, 3x2, gutter 8, margin 16.
$boxes = [];
foreach ([[16, 280], [304, 280]] as $r => [$y, $h]) {
    foreach ([[16, 326], [350, 325], [683, 325]] as $c => [$x, $w]) {
        $boxes[] = [$x, $y, $w, $h, [40 * $c + 70, 60 * $r + 60, 160 - 40 * $c]];
    }
}
$sheet = paint_png(1024, 600, WHITE, $boxes);

$options = ConversionOptions::none()
    ->with_grid_columns(3)
    ->with_grid_rows(2)
    ->with_grid_gutter_x(8)
    ->with_grid_gutter_y(8)
    ->with_grid_margin_left(16)
    ->with_grid_margin_right(16)
    ->with_grid_margin_top(16)
    ->with_grid_margin_bottom(16)
    ->with_background('#ffffff');

$bytes_of = static fn (array $pieces): array => array_map(static fn (ConvertedDocument $p): string => $p->bytes(), $pieces);

// -----------------------------------------------------------------------------
section('the round trip: taken apart and put back with the same options, the same sheet');

$extractor = new ImageExtractor();
$again     = $driver->merge($bytes_of($extractor->extract($sheet, 'png', null, $options)), 'png', 'png', $options);
test('1024x600 again', size_of($again->bytes()) === [1024, 600]);
test('...every pixel the same', same_pixels($again->bytes(), $sheet));

$flipped = $options->with_grid_order('tb-rl');
test("in 'tb-rl' order too", same_pixels($driver->merge($bytes_of($extractor->extract($sheet, 'png', null, $flipped)), 'png', 'png', $flipped)->bytes(), $sheet));

$webp = imagecreatefromstring($sheet);
ob_start();
imagewebp($webp, null, IMG_WEBP_LOSSLESS);
imagedestroy($webp);
$webp_sheet = ob_get_clean();
$lossless   = $options->with_lossless(true);
test('WebP with LOSSLESS: the round trip is exact too', same_pixels($driver->merge($bytes_of($extractor->extract($webp_sheet, 'webp', null, $lossless)), 'webp', 'webp', $lossless)->bytes(), $webp_sheet));
test('LOSSLESS asked of a JPEG is refused, not quietly lossy', throws(fn () => $driver->merge([$sheet], 'png', 'jpg', ConversionOptions::none()->with_lossless(true))));
test('without BACKGROUND the gutters come back transparent — the pieces carry nothing of them', !same_pixels($driver->merge($bytes_of($extractor->extract($sheet, 'png', null, $options)), 'png', 'png', ConversionOptions::from(array_diff_key($options->all(), [ConversionOptions::BACKGROUND => 1])))->bytes(), $sheet));

// -----------------------------------------------------------------------------
section('a sheet that grows to fit, never scales');

$red   = paint_png(100, 80, [200, 0, 0, 0]);
$wide  = paint_png(120, 60, [0, 200, 0, 0]);
$five  = [$red, $wide, $red, $red, $red];
$grown = $driver->merge($five, 'png', 'png', ConversionOptions::none()->with_grid_columns(3)->with_background('#ffffff'));
test('five pieces, 3 columns: 320x160 (the middle column as wide as its widest piece)', size_of($grown->bytes()) === [320, 160]);
test('the 120x60 piece sits centred in its 120x80 cell: 10px of background above it', at($grown->bytes(), 150, 5) === [255, 255, 255, 0] && at($grown->bytes(), 150, 10) === [0, 200, 0, 0]);
$top = $driver->merge($five, 'png', 'png', ConversionOptions::none()->with_grid_columns(3)->with_background('#ffffff')->with_align_y(0));
test('ALIGN_Y=0 puts it at the top of its cell instead', at($top->bytes(), 150, 0) === [0, 200, 0, 0] && at($top->bytes(), 150, 65) === [255, 255, 255, 0]);
test('the empty sixth cell is background', at($grown->bytes(), 300, 150) === [255, 255, 255, 0]);
$left = $driver->merge([$red, $wide, $red], 'png', 'png', ConversionOptions::none()->with_grid_rows(3)->with_background('#ffffff')->with_align_x(0));
test('ALIGN_X=0 puts a narrower piece at the left of its column', at($left->bytes(), 0, 0) === [200, 0, 0, 0] && at($left->bytes(), 110, 10) === [255, 255, 255, 0]);
$right = $driver->merge([$red, $wide, $red], 'png', 'png', ConversionOptions::none()->with_grid_rows(3)->with_background('#ffffff')->with_align_x(100));
test('ALIGN_X=100 at the right', at($right->bytes(), 0, 0) === [255, 255, 255, 0] && at($right->bytes(), 119, 0) === [200, 0, 0, 0]);
test('ALIGN outside 0-100 is refused — it would push a piece off its cell', throws(fn () => $driver->merge([$red, $wide], 'png', 'png', ConversionOptions::none()->with_grid_rows(2)->with_align_x(200))));
test('a gutter too large to allocate is a ConversionException, not a warning', throws(fn () => $driver->merge([$red, $red], 'png', 'png', ConversionOptions::none()->with_grid_gutter_x(1000000000))));
test('a gutter that overflows an integer too', throws(fn () => $driver->merge([$red, $red], 'png', 'png', ConversionOptions::none()->with_grid_gutter_x(PHP_INT_MAX))));
test('no grid keys: one row of all of them', size_of($driver->merge([$red, $wide], 'png', 'png', ConversionOptions::none())->bytes()) === [220, 80]);
test('a piece larger than its pixel track: an error, not a resize', throws(fn () => $driver->merge([$wide], 'png', 'png', ConversionOptions::none()->with_grid_columns('100px'))));

// -----------------------------------------------------------------------------
section('background and transparency');

$loose = $driver->merge([$red, $wide], 'png', 'png', ConversionOptions::none()->with_grid_columns(2)->with_grid_gutter_x(10));
test('PNG by default: the gutter is transparent', at($loose->bytes(), 105, 40)[3] === 127);
$as_jpg = $driver->merge([$red, $wide], 'png', 'jpg', ConversionOptions::none()->with_grid_columns(2)->with_grid_gutter_x(10));
test('JPEG by default: the gutter is white (within JPEG noise)', min(array_slice(at($as_jpg->bytes(), 105, 40), 0, 3)) > 240);
test('...and the result says it is a JPEG', $as_jpg->extension_code() === 'jpg' && $as_jpg->mime() === 'image/jpeg');
$as_gif = $driver->merge([$red, $wide], 'png', 'gif', ConversionOptions::none()->with_grid_columns(2)->with_grid_gutter_x(10));
test('GIF by default: the gutter is white', at($as_gif->bytes(), 105, 40) === [255, 255, 255, 0]);

$ghost = paint_png(50, 50, [0, 0, 255, 100]);
$kept  = $driver->merge([$ghost], 'png', 'png', ConversionOptions::none());
test("a piece's own translucency is copied, not blended onto the sheet", at($kept->bytes(), 25, 25) === [0, 0, 255, 100]);
test("an unreadable BACKGROUND throws", throws(fn () => $driver->merge([$red], 'png', 'png', ConversionOptions::none()->with_background('red'))));
test('no pieces throws', throws(fn () => $driver->merge([], 'png', 'png', ConversionOptions::none())));
test('pairs(): every GD format to every other, 25', count($driver->pairs()) === 25);

exit(summary());
