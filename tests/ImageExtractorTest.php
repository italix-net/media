<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Media — ImageExtractor
 *
 * Against real ext-gd calls on sheets painted with GD itself: every cell a
 * solid colour of its own, at boxes computed by hand — so a piece's size,
 * its colour and its locator together prove WHICH region came back, not
 * just that some image did.
 *
 * Run: php src/Libs/Italix/Media/tests/ImageExtractorTest.php
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
use Italix\Media\ImageExtractor;

use function Italix\Testing\{suite, section, test, summary};

suite('Italix Media — ImageExtractor');

$driver = new ImageExtractor();

if (!$driver->is_available()) {
    echo "  SKIPPED — ext-gd is absent.\n";
    exit(summary());
}

/**
 * A <width> x <height> sheet of <background>, with each [x, y, width, height, [r, g, b]] painted solid.
 *
 * @param array{0: int, 1: int, 2: int, 3: int} $background r, g, b, GD alpha
 * @param list<array{0: int, 1: int, 2: int, 3: int, 4: array{0: int, 1: int, 2: int}}> $boxes
 */
function paint(int $width, int $height, array $background, array $boxes, string $format = 'png', int $quality = 90): string
{
    $image = imagecreatetruecolor($width, $height);
    imagealphablending($image, false);
    imagesavealpha($image, true);
    imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, imagecolorallocatealpha($image, ...$background));
    foreach ($boxes as [$x, $y, $w, $h, $rgb]) {
        imagefilledrectangle($image, $x, $y, $x + $w - 1, $y + $h - 1, imagecolorallocate($image, ...$rgb));
    }
    ob_start();
    $format === 'jpg' ? imagejpeg($image, null, $quality) : imagepng($image);
    imagedestroy($image);

    return ob_get_clean();
}

/** The colour of cell (row, column) — distinct, and far from white. */
function hue(int $row, int $column): array
{
    return [40 * $column + 30, 60 * $row, 200 - 40 * $column];
}

/** [width, height, [r, g, b] at the centre] of a piece. */
function look(string $bytes): array
{
    $image = imagecreatefromstring($bytes);
    $rgb   = imagecolorat($image, intdiv(imagesx($image), 2), intdiv(imagesy($image), 2));
    $seen  = [imagesx($image), imagesy($image), [($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF]];
    imagedestroy($image);

    return $seen;
}

function close_to(array $a, array $b, int $slack): bool
{
    foreach ($a as $i => $v) {
        if (abs($v - $b[$i]) > $slack) {
            return false;
        }
    }

    return true;
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

// The reference sheet, 1024x600: 3 columns x 2 rows, gutter 8, margin 16, white.
// Worked by hand — columns 326, 325, 325 at x 16, 350, 683; rows 280, 280 at y 16, 304.
const COLUMNS = [[16, 326], [350, 325], [683, 325]];
const ROWS    = [[16, 280], [304, 280]];
const WHITE   = [255, 255, 255, 0];

$boxes = [];
foreach (ROWS as $r => [$y, $h]) {
    foreach (COLUMNS as $c => [$x, $w]) {
        $boxes[] = [$x, $y, $w, $h, hue($r + 1, $c + 1)];
    }
}
$sheet = paint(1024, 600, WHITE, $boxes);

$declared = ConversionOptions::none()
    ->with_grid_columns(3)
    ->with_grid_rows(2)
    ->with_grid_gutter_x(8)
    ->with_grid_gutter_y(8)
    ->with_grid_margin_left(16)
    ->with_grid_margin_right(16)
    ->with_grid_margin_top(16)
    ->with_grid_margin_bottom(16);

// -----------------------------------------------------------------------------
section('the driver describes itself honestly');

test('extensions() covers the GD-decodable formats', $driver->extensions() === ['png', 'jpg', 'jpeg', 'gif', 'webp']);
test('describe() names the geometry it uses', str_contains($driver->describe(), 'GridGeometry'));

// -----------------------------------------------------------------------------
section('a declared grid: every piece is its cell, by size, colour and locator');

$pieces = $driver->extract($sheet, 'png', null, $declared);
test('six pieces', count($pieces) === 6);
test('the first is cell (1,1): 326x280, its own colour', look($pieces[0]->bytes()) === [326, 280, hue(1, 1)]);
test('the sixth is cell (2,3): 325x280, its own colour', look($pieces[5]->bytes()) === [325, 280, hue(2, 3)]);
test('the fifth says where it came from', $pieces[4]->locator() === ['row' => 2, 'column' => 2, 'x' => 350, 'y' => 304, 'width' => 325, 'height' => 280]);
test('same format out', $pieces[0]->extension_code() === 'png' && $pieces[0]->mime() === 'image/png');

$rtl = $driver->extract($sheet, 'png', null, $declared->with_grid_order('rl-tb'));
test("'rl-tb': the first piece is the top-right cell", look($rtl[0]->bytes())[2] === hue(1, 3));

// -----------------------------------------------------------------------------
section('explicit boxes: what a person or a model picked');

$two = $driver->extract($sheet, 'png', [[16, 16, 326, 280], [683, 304, 325, 280]], ConversionOptions::none());
test('two boxes, two pieces, the right colours', look($two[0]->bytes())[2] === hue(1, 1) && look($two[1]->bytes())[2] === hue(2, 3));
test('an explicit piece is located by its index and box', $two[1]->locator() === ['index' => 2, 'x' => 683, 'y' => 304, 'width' => 325, 'height' => 280]);

$cropped = $driver->extract($sheet, 'png', [[0, 0, 326, 280]], ConversionOptions::none()->with_crop_left(16)->with_crop_top(16));
test('the crop keys cut first, and a box is inside what they left — its locator in the source', $cropped[0]->locator() === ['index' => 1, 'x' => 16, 'y' => 16, 'width' => 326, 'height' => 280]);
test('...and it really is cell (1,1)', look($cropped[0]->bytes())[2] === hue(1, 1));

test('a box outside the image throws', throws(fn () => $driver->extract($sheet, 'png', [[1000, 0, 100, 100]], ConversionOptions::none())));
test('a box that is not four whole numbers throws', throws(fn () => $driver->extract($sheet, 'png', [[0, 0, 10]], ConversionOptions::none())));
test('boxes and grid keys together throw: two answers to one question', throws(fn () => $driver->extract($sheet, 'png', [[0, 0, 10, 10]], $declared)));

// -----------------------------------------------------------------------------
section('detection: the same sheet, nothing declared');

$auto     = ConversionOptions::none()->with_grid_columns('auto')->with_grid_rows('auto');
$detected = $driver->extract($sheet, 'png', null, $auto);
test('six pieces found', count($detected) === 6);
test('...each exactly the declared cell', array_map(static fn ($p) => $p->locator(), $detected) === array_map(static fn ($p) => $p->locator(), $pieces));

$mixed = $driver->extract($sheet, 'png', null, ConversionOptions::none()->with_grid_rows('auto')->with_grid_columns(3)->with_grid_gutter_x(8));
test("rows 'auto', 3 columns declared (gutter 8): the same six cells", array_map(static fn ($p) => $p->locator(), $mixed) === array_map(static fn ($p) => $p->locator(), $pieces));

// A page whose rows hold 1, 3 and 2 panels, on a grey background.
$grey  = [128, 128, 128, 0];
$page  = paint(900, 700, $grey, [
    [20, 20, 860, 200, [200, 0, 0]],
    [20, 240, 270, 200, [0, 200, 0]], [310, 240, 280, 200, [0, 0, 200]], [610, 240, 270, 200, [200, 200, 0]],
    [20, 460, 420, 220, [0, 200, 200]], [460, 460, 420, 220, [200, 0, 200]],
]);
$panels = $driver->extract($page, 'png', null, $auto);
test('an irregular page: six panels', count($panels) === 6);
test('...labelled by band and place in it', array_map(static fn ($p) => [$p->locator()['row'], $p->locator()['column']], $panels) === [[1, 1], [2, 1], [2, 2], [2, 3], [3, 1], [3, 2]]);
test('...the middle one of the second band is the blue 280x200', look($panels[2]->bytes()) === [280, 200, [0, 0, 200]]);

$noisy = $driver->extract(paint(1024, 600, WHITE, $boxes, 'jpg', 70), 'jpg', null, $auto->with_grid_expect(6));
test('the same sheet as a quality-70 JPEG: still six pieces (GRID_EXPECT holds)', count($noisy) === 6);
test('...the last one close to its colour', close_to(look($noisy[5]->bytes())[2], hue(2, 3), 12));

$touching = paint(400, 400, WHITE, [[0, 0, 200, 200, [200, 0, 0]], [200, 0, 200, 200, [0, 200, 0]], [0, 200, 200, 200, [0, 0, 200]], [200, 200, 200, 200, [200, 200, 0]]]);
test('pieces that touch cannot be told apart — GRID_EXPECT turns that into an error', throws(fn () => $driver->extract($touching, 'png', null, $auto->with_grid_expect(4))));
test('a sheet with nothing but background: an error, not an empty list', throws(fn () => $driver->extract(paint(100, 100, WHITE, []), 'png', null, $auto)));

$sprites = paint(300, 100, [0, 0, 0, 127], [[10, 10, 80, 80, [250, 0, 0]], [110, 10, 80, 80, [0, 250, 0]], [210, 10, 80, 80, [0, 0, 250]]]);
test('sprites on a transparent sheet: the transparent border is the background, three sprites', count($driver->extract($sprites, 'png', null, ConversionOptions::none()->with_grid_columns('auto'))) === 3);

// -----------------------------------------------------------------------------
section('the crop keys, the gap and the background, pinned');

$inner = $driver->extract($sheet, 'png', null, ConversionOptions::none()
    ->with_crop_left(16)->with_crop_right(16)->with_crop_top(16)->with_crop_bottom(16)
    ->with_grid_columns(3)->with_grid_rows(2)->with_grid_gutter_x(8)->with_grid_gutter_y(8));
test('crop keys first, then a declared grid inside: the same cells, located in the source', array_map(static fn ($p) => $p->locator(), $inner) === array_map(static fn ($p) => $p->locator(), $pieces));

$croppedauto = $driver->extract($sheet, 'png', null, $auto->with_crop_left(350));
test("crop keys, then 'auto': the two right-hand columns only, located in the source", array_map(static fn ($p) => $p->locator()['x'], $croppedauto) === [350, 683, 350, 683]);

$pair = paint(200, 100, WHITE, [[10, 10, 80, 80, [200, 0, 0]], [100, 10, 90, 80, [0, 200, 0]]]);
test('a 10px gap splits at GAP_MIN 10', count($driver->extract($pair, 'png', null, ConversionOptions::none()->with_grid_columns('auto')->with_gap_min(10))) === 2);
test('...and does not at GAP_MIN 11', count($driver->extract($pair, 'png', null, ConversionOptions::none()->with_grid_columns('auto')->with_gap_min(11))) === 1);
$hairline = paint(200, 100, WHITE, [[10, 10, 80, 80, [200, 0, 0]], [92, 10, 98, 80, [0, 200, 0]]]);
test('a 2px gap is under the default GAP_MIN (3): one piece', count($driver->extract($hairline, 'png', null, ConversionOptions::none()->with_grid_columns('auto'))) === 1);

$faint = paint(200, 100, [250, 250, 250, 0], [[10, 10, 80, 80, [235, 235, 235]], [110, 10, 80, 80, [0, 0, 0]]]);
test('GAP_TOLERANCE: a faint grey box is background at 10', count($driver->extract($faint, 'png', null, ConversionOptions::none()->with_grid_columns('auto'))) === 1);
test('...and content at 5', count($driver->extract($faint, 'png', null, ConversionOptions::none()->with_grid_columns('auto')->with_gap_tolerance(5))) === 2);
test('GAP_TOLERANCE outside 0-100 throws', throws(fn () => $driver->extract($faint, 'png', null, ConversionOptions::none()->with_grid_columns('auto')->with_gap_tolerance(101))));

$speckled = imagecreatetruecolor(200, 100);
imagefill($speckled, 0, 0, imagecolorallocate($speckled, 255, 255, 255));
for ($x = 0; $x < 200; $x += 7) {
    imagesetpixel($speckled, $x, 0, imagecolorallocate($speckled, $x, 0, 0));   // many colours on the border, each rare
}
imagefilledrectangle($speckled, 20, 20, 80, 80, imagecolorallocate($speckled, 0, 0, 200));
imagefilledrectangle($speckled, 120, 20, 180, 80, imagecolorallocate($speckled, 0, 0, 200));
ob_start();
imagepng($speckled);
imagedestroy($speckled);
test('the background is the COMMONEST border colour, not any colour on it: two pieces', count($driver->extract(ob_get_clean(), 'png', null, ConversionOptions::none()->with_grid_columns('auto')->with_grid_rows('auto')->with_crop_top(1))) === 2);

$striped = paint(99, 99, [0, 0, 200, 0], [[0, 0, 33, 99, [200, 0, 0]], [66, 0, 33, 99, [0, 200, 0]]]);
test('a border in three colours, none holding half of it: the background is unclear, refused', throws(fn () => $driver->extract($striped, 'png', null, $auto)));

$ghosts = imagecreatetruecolor(300, 100);
imagealphablending($ghosts, false);
imagesavealpha($ghosts, true);
for ($x = 0; $x < 300; $x++) {
    imageline($ghosts, $x, 0, $x, 99, imagecolorallocatealpha($ghosts, $x % 256, 0, 0, 127));   // transparent, with a different RGB in every column
}
imagefilledrectangle($ghosts, 10, 10, 90, 90, imagecolorallocatealpha($ghosts, 0, 200, 0, 0));
imagefilledrectangle($ghosts, 210, 10, 290, 90, imagecolorallocatealpha($ghosts, 0, 200, 0, 0));
ob_start();
imagepng($ghosts);
imagedestroy($ghosts);
test('transparent pixels are background whatever RGB they carry: two sprites', count($driver->extract(ob_get_clean(), 'png', null, ConversionOptions::none()->with_grid_columns('auto')->with_background('transparent'))) === 2);

$said = '';
try {
    $driver->extract($sheet, 'png', [['width' => 20, 'height' => 20, 'x' => 10, 'y' => 10]], ConversionOptions::none());
} catch (ConversionException $e) {
    $said = $e->getMessage();
}
test('a boundary as an associative array is refused as not a list — not read by its key order, not half-read with warnings', str_contains($said, 'not a list'));
test("GRID_MARGIN_* alongside 'auto' is refused", throws(fn () => $driver->extract($sheet, 'png', null, $auto->with_grid_margin_left(50))));

$gif_sheet = paint(1024, 600, WHITE, $boxes);
$gif = imagecreatefromstring($gif_sheet);
ob_start();
imagegif($gif);
imagedestroy($gif);
$gif_pieces = $driver->extract(ob_get_clean(), 'gif', null, $auto);
test('a palette GIF is detected like any other: six pieces', count($gif_pieces) === 6 && $gif_pieces[0]->extension_code() === 'gif');

// -----------------------------------------------------------------------------
section('empty cells');

$five = paint(1024, 600, WHITE, array_slice($boxes, 0, 5));
$kept = $driver->extract($five, 'png', null, $declared->with_grid_skip_empty(true));
test('GRID_SKIP_EMPTY: the blank sixth cell is not returned', count($kept) === 5);
test('...and the others keep their own place', $kept[4]->locator()['row'] === 2 && $kept[4]->locator()['column'] === 2);
test('without it, the blank cell comes back too', count($driver->extract($five, 'png', null, $declared)) === 6);
test('GRID_EXPECT on a declared grid checks the count too', throws(fn () => $driver->extract($five, 'png', null, $declared->with_grid_skip_empty(true)->with_grid_expect(6))));

exit(summary());
