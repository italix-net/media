<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Media — the README's Cookbook, run
 *
 * Every example of the README's four Cookbook sections — GdImageConverter,
 * ImageExtractor, ImageGridMerger, ImageTrimmer — in the same order and with
 * the same options,
 * against images painted here — and
 * what each comment there says came back, asserted. When a README example
 * changes, this file changes with it; when this file fails, the README is
 * telling a lie.
 *
 * Run: php src/Libs/Italix/Media/tests/CookbookTest.php
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
use Italix\Converters\ConverterSet;
use Italix\Converters\UnsupportedOptionException;
use Italix\Documents\HtmlToPdfConverter;
use Italix\Media\GdImageConverter;
use Italix\Media\ImageExtractor;
use Italix\Media\ImageGridMerger;
use Italix\Media\ImageTrimmer;
use Italix\Media\PdfPageToImageConverter;

use function Italix\Testing\{suite, section, test, summary};

suite('Italix Media — the README Cookbook, run');

$extractor = new ImageExtractor();

if (!$extractor->is_available()) {
    echo "  SKIPPED — ext-gd is absent.\n";
    exit(summary());
}

/**
 * A <width> x <height> PNG of <background> (r, g, b, GD alpha), each [x, y, width, height, [r, g, b]] painted solid.
 *
 * @param array{0: int, 1: int, 2: int, 3: int} $background
 * @param list<array{0: int, 1: int, 2: int, 3: int, 4: array{0: int, 1: int, 2: int}}> $boxes
 */
function cookbook_paint(int $width, int $height, array $background, array $boxes = []): string
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

/**
 * "WxH" of every piece, in order.
 *
 * @param list<ConvertedDocument> $pieces
 * @return list<string>
 */
function cookbook_sizes(array $pieces): array
{
    return array_map(static function (ConvertedDocument $piece): string {
        $image = imagecreatefromstring($piece->bytes());
        $size  = imagesx($image) . 'x' . imagesy($image);
        imagedestroy($image);

        return $size;
    }, $pieces);
}

/**
 * "rNcM" of every piece, in order.
 *
 * @param list<ConvertedDocument> $pieces
 * @return list<string>
 */
function cookbook_cells(array $pieces): array
{
    return array_map(static fn (ConvertedDocument $p): string => "r{$p->locator()['row']}c{$p->locator()['column']}", $pieces);
}

/** Every pixel, alpha included. */
function cookbook_same_pixels(string $a, string $b): bool
{
    $one  = imagecreatefromstring($a);
    $two  = imagecreatefromstring($b);
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
function cookbook_at(string $bytes, int $x, int $y): array
{
    $image = imagecreatefromstring($bytes);
    imagepalettetotruecolor($image);
    $rgb   = imagecolorat($image, $x, $y);
    imagedestroy($image);

    return [($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF, ($rgb >> 24) & 0x7F];
}

const COOKBOOK_WHITE = [255, 255, 255, 0];

// =============================================================================
// Cookbook: GdImageConverter
// =============================================================================

$converter = new GdImageConverter();

// -----------------------------------------------------------------------------
section('Formats');

$photo = cookbook_paint(1600, 900, [30, 90, 160, 0], [[200, 100, 400, 300, [250, 200, 0]]]);
$webp  = $converter->convert($photo, 'png', 'webp', ConversionOptions::none());
test('PNG to WebP: image/webp, the same 1600x900', $webp->mime() === 'image/webp' && cookbook_sizes([$webp]) === ['1600x900']);

$cutout = cookbook_paint(200, 100, [0, 0, 0, 127], [[50, 25, 100, 50, [0, 120, 0]]]);
$jpg    = $converter->convert($cutout, 'png', 'jpg', ConversionOptions::none());
test('a transparent PNG to JPEG: the transparency becomes white, not black', min(array_slice(cookbook_at($jpg->bytes(), 5, 5), 0, 3)) > 240);

$as_png = $converter->convert($webp->bytes(), 'webp', 'png', ConversionOptions::none());
test('and back from WebP to PNG', $as_png->extension_code() === 'png' && cookbook_sizes([$as_png]) === ['1600x900']);

// -----------------------------------------------------------------------------
section('Quality, and lossless');

$noise = imagecreatetruecolor(300, 300);
mt_srand(7);
for ($y = 0; $y < 300; $y++) {
    for ($x = 0; $x < 300; $x++) {
        imagesetpixel($noise, $x, $y, mt_rand(0, 0xFFFFFF));
    }
}
ob_start();
imagepng($noise);
imagedestroy($noise);
$busy = ob_get_clean();

$fine   = $converter->convert($busy, 'png', 'jpg', ConversionOptions::none()->with_quality(95));
$coarse = $converter->convert($busy, 'png', 'jpg', ConversionOptions::none()->with_quality(40));
test('QUALITY: 40 is a much smaller JPEG than 95', strlen($coarse->bytes()) * 2 < strlen($fine->bytes()));

$exact = $converter->convert($photo, 'png', 'webp', ConversionOptions::none()->with_lossless(true));
test('LOSSLESS WebP: back to PNG, every pixel the same', cookbook_same_pixels($converter->convert($exact->bytes(), 'webp', 'png', ConversionOptions::none())->bytes(), $photo));

// -----------------------------------------------------------------------------
section('Resizing');

test('WIDTH alone keeps the aspect ratio: 1600x900 -> 800x450', cookbook_sizes([$converter->convert($photo, 'png', 'png', ConversionOptions::none()->with_width(800))]) === ['800x450']);
test('HEIGHT alone too: -> 533x300', cookbook_sizes([$converter->convert($photo, 'png', 'png', ConversionOptions::none()->with_height(300))]) === ['533x300']);
test('both: exactly that size, stretched if the ratio differs — 400x400', cookbook_sizes([$converter->convert($photo, 'png', 'png', ConversionOptions::none()->with_width(400)->with_height(400))]) === ['400x400']);

$square    = (new ImageTrimmer())->trim($photo, 'png', null, ConversionOptions::none()->with_ratio('1:1'));
$thumbnail = $converter->convert($square->bytes(), 'png', 'webp', ConversionOptions::none()->with_width(256)->with_quality(80));
test('a square thumbnail without distortion: crop to 1:1 first, then resize — 256x256 WebP', cookbook_sizes([$thumbnail]) === ['256x256'] && $thumbnail->mime() === 'image/webp');

// -----------------------------------------------------------------------------
section('What a file really is');

$really_jpeg = $converter->convert($photo, 'png', 'jpg', ConversionOptions::none())->bytes();
$recovered   = $converter->convert($really_jpeg, 'png', 'webp', ConversionOptions::none());
test('a JPEG named .png still converts: the bytes are read, not the label', cookbook_sizes([$recovered]) === ['1600x900']);

// -----------------------------------------------------------------------------
section('Through a ConverterSet');

if (!(new PdfPageToImageConverter())->is_available() || !class_exists(HtmlToPdfConverter::class) || !(new HtmlToPdfConverter())->is_available()) {
    echo "  SKIPPED section — needs Ghostscript and italix/documents with dompdf, to build and render a real PDF.\n";
} else {
    $pdf = (new HtmlToPdfConverter())->convert('<h1>A page</h1><p>Some text.</p>', 'html', 'pdf', ConversionOptions::none())->bytes();

    $set     = new ConverterSet([new PdfPageToImageConverter(), new GdImageConverter()]);
    $preview = $set->convert($pdf, 'pdf', 'jpg');
    test('PDF to JPEG in two hops, pdf -> png -> jpg: image/jpeg', $preview->mime() === 'image/jpeg');

    $small = $set->convert($pdf, 'pdf', 'webp', ConversionOptions::none()->with_width(600));
    test('...and WIDTH reaches the GD hop: a 600px-wide WebP of the first page', explode('x', cookbook_sizes([$small])[0])[0] === '600');
}

// -----------------------------------------------------------------------------
section('When it cannot work, it says so — converting');

$said = '';
try {
    $converter->convert('not an image at all', 'png', 'webp', ConversionOptions::none());
} catch (ConversionException $e) {
    $said = $e->getMessage();
}
test('bytes that are no image: "GD could not decode this as an image (claimed .png)."', $said === 'GD could not decode this as an image (claimed .png).');

$refused = null;
try {
    $converter->convert($photo, 'png', 'jpg', ConversionOptions::none()->with_lossless(true));
} catch (UnsupportedOptionException $e) {
    $refused = $e;
}
test('LOSSLESS asked of a JPEG: UnsupportedOptionException, not a quietly lossy file', $refused !== null && $refused->option_key_code() === ConversionOptions::LOSSLESS);

// =============================================================================
// Cookbook: ImageExtractor
// =============================================================================

// -----------------------------------------------------------------------------
section('Declared grids — you know the sheet');

$sprites = $extractor->extract(cookbook_paint(256, 256, COOKBOOK_WHITE), 'png', null, ConversionOptions::none()
    ->with_grid_columns(4)
    ->with_grid_rows(4));
test('a sprite sheet: 16 pieces, 64x64 each', count($sprites) === 16 && array_unique(cookbook_sizes($sprites)) === ['64x64']);
test('...the last one says: row 4, column 4, x 192, y 192', $sprites[15]->locator() === ['row' => 4, 'column' => 4, 'x' => 192, 'y' => 192, 'width' => 64, 'height' => 64]);

$thumbs = $extractor->extract(cookbook_paint(1000, 700, COOKBOOK_WHITE), 'png', null, ConversionOptions::none()
    ->with_grid_columns(3)->with_grid_rows(2)
    ->with_grid_gutter_x(10)->with_grid_gutter_y(10)
    ->with_grid_margin_left(20)->with_grid_margin_right(20)
    ->with_grid_margin_top(20)->with_grid_margin_bottom(20));
test('a contact sheet on 1000x700: 314x325, 313x325, 313x325 — the spare pixel to the first', cookbook_sizes($thumbs) === ['314x325', '313x325', '313x325', '314x325', '313x325', '313x325']);

$slides = $extractor->extract(cookbook_paint(3240, 1080, [30, 90, 160, 0]), 'png', null, ConversionOptions::none()->with_grid_columns(3));
test('a carousel: 1080x1080, three times', cookbook_sizes($slides) === ['1080x1080', '1080x1080', '1080x1080']);

$columns = $extractor->extract(cookbook_paint(1200, 800, COOKBOOK_WHITE), 'png', null, ConversionOptions::none()
    ->with_grid_columns('300px 1fr 2fr'));
test("uneven columns on 1200x800: 300x800, 300x800, 600x800", cookbook_sizes($columns) === ['300x800', '300x800', '600x800']);

$hundreds = $extractor->extract(cookbook_paint(600, 10, COOKBOOK_WHITE), 'png', null, ConversionOptions::none()->with_grid_columns('300'));
$one      = $extractor->extract(cookbook_paint(600, 10, COOKBOOK_WHITE), 'png', null, ConversionOptions::none()->with_grid_columns('300px 1fr'));
test("pixels carry their unit: '300' is three hundred columns, '300px' one of 300 pixels", count($hundreds) === 300 && cookbook_sizes($one)[0] === '300x10');

// -----------------------------------------------------------------------------
section("Detected layouts — you don't");

$strip = cookbook_paint(400, 1000, COOKBOOK_WHITE, [[0, 0, 400, 300, [220, 220, 0]], [0, 320, 400, 180, [0, 180, 0]], [0, 520, 400, 480, [0, 0, 180]]]);
$shots = $extractor->extract($strip, 'png', null, ConversionOptions::none()
    ->with_grid_rows('auto')
    ->with_background('#ffffff'));
test('a strip of screenshots: 400x300, 400x180, 400x480', cookbook_sizes($shots) === ['400x300', '400x180', '400x480']);

$said = '';
try {
    $extractor->extract($strip, 'png', null, ConversionOptions::none()->with_grid_rows('auto'));
} catch (ConversionException $e) {
    $said = $e->getMessage();
}
test('...without BACKGROUND, refused rather than guessed, with the message the README quotes', $said === 'No colour holds even half of the border — the background is unclear; say it with BACKGROUND.');

$comic = cookbook_paint(900, 700, [128, 128, 128, 0], [
    [20, 20, 860, 200, [200, 0, 0]],
    [20, 240, 270, 200, [0, 200, 0]], [310, 240, 280, 200, [0, 0, 200]], [610, 240, 270, 200, [200, 200, 0]],
    [20, 460, 420, 220, [0, 200, 200]], [460, 460, 420, 220, [200, 0, 200]],
]);
$panels = $extractor->extract($comic, 'png', null, ConversionOptions::none()
    ->with_grid_columns('auto')->with_grid_rows('auto')
    ->with_grid_expect(6));
test('a comic page: r1c1  r2c1 r2c2 r2c3  r3c1 r3c2', cookbook_cells($panels) === ['r1c1', 'r2c1', 'r2c2', 'r2c3', 'r3c1', 'r3c2']);

$manga = $extractor->extract($comic, 'png', null, ConversionOptions::none()
    ->with_grid_columns('auto')->with_grid_rows('auto')
    ->with_grid_order('rl-tb'));
test('the same page as a manga: r1c1  r2c3 r2c2 r2c1  r3c2 r3c1', cookbook_cells($manga) === ['r1c1', 'r2c3', 'r2c2', 'r2c1', 'r3c2', 'r3c1']);

// -----------------------------------------------------------------------------
section('One rectangle, or several');

$photo  = cookbook_paint(1600, 1200, [90, 90, 90, 0], [[420, 310, 380, 260, [250, 200, 0]]]);
[$face] = $extractor->extract($photo, 'png', [[420, 310, 380, 260]], ConversionOptions::none());
test('one rectangle: 380x260, located by index and box', cookbook_sizes([$face]) === ['380x260'] && $face->locator() === ['index' => 1, 'x' => 420, 'y' => 310, 'width' => 380, 'height' => 260]);

$regions = $extractor->extract($photo, 'png', [
    [0, 0, 800, 600],
    [800, 600, 800, 600],
    [420, 310, 380, 260],
], ConversionOptions::none());
test('several, as a detector returns them: three pieces, in the order given', cookbook_sizes($regions) === ['800x600', '800x600', '380x260']);

$same = (new ImageTrimmer())->trim($photo, 'png', null, ConversionOptions::none()->with_crop_left(420)->with_crop_top(310)->with_width(380)->with_height(260));
test('...and ImageTrimmer with the crop keys gives the same rectangle, pixel for pixel', cookbook_same_pixels($same->bytes(), $face->bytes()));

// -----------------------------------------------------------------------------
section('Combinations');

$pages = $extractor->extract(cookbook_paint(1100, 800, COOKBOOK_WHITE), 'png', null, ConversionOptions::none()
    ->with_crop_left(50)->with_crop_right(50)->with_crop_top(50)->with_crop_bottom(50)
    ->with_grid_columns(2)->with_grid_rows(2));
test('crop, then a grid: 500x350 four times', cookbook_sizes($pages) === ['500x350', '500x350', '500x350', '500x350']);
test('...the first located at x 50, y 50 — in the source\'s own coordinates', $pages[0]->locator() === ['row' => 1, 'column' => 1, 'x' => 50, 'y' => 50, 'width' => 500, 'height' => 350]);

$atlas = cookbook_paint(300, 200, [0, 0, 0, 127], [[10, 10, 80, 80, [250, 0, 0]], [110, 10, 80, 80, [0, 250, 0]], [210, 10, 80, 80, [0, 0, 250]], [10, 110, 80, 80, [250, 250, 0]]]);
$icons = $extractor->extract($atlas, 'png', null, ConversionOptions::none()
    ->with_grid_columns(3)->with_grid_rows(2)
    ->with_grid_skip_empty(true));
test('a game atlas with empty slots: 4 pieces, r1c1 r1c2 r1c3 r2c1', cookbook_cells($icons) === ['r1c1', 'r1c2', 'r1c3', 'r2c1']);

$loose   = cookbook_paint(600, 300, COOKBOOK_WHITE, [[40, 50, 100, 120, [200, 0, 0]], [360, 20, 200, 80, [0, 0, 200]]]);
$halves  = $extractor->extract($loose, 'png', null, ConversionOptions::none()->with_grid_columns(2));
$trimmer = new ImageTrimmer();
$tight   = array_map(
    fn ($piece) => $trimmer->trim($piece->bytes(), 'png', null, ConversionOptions::none()),
    $halves
);
test('each piece trimmed tight: 300x300, 300x300 -> 100x120, 200x80', cookbook_sizes($halves) === ['300x300', '300x300'] && cookbook_sizes($tight) === ['100x120', '200x80']);

$names = [];
foreach ($thumbs as $piece) {
    ['row' => $r, 'column' => $c] = $piece->locator();
    $names[] = "thumb-r{$r}c{$c}.{$piece->extension_code()}";
}
test('files named after where they came from: thumb-r1c1.png, thumb-r1c2.png, ...', array_slice($names, 0, 3) === ['thumb-r1c1.png', 'thumb-r1c2.png', 'thumb-r1c3.png'] && $names[5] === 'thumb-r2c3.png');

// -----------------------------------------------------------------------------
section('Apart and back');

$png   = cookbook_paint(1016, 608, COOKBOOK_WHITE, [[0, 0, 100, 100, [200, 0, 0]], [690, 308, 326, 300, [0, 0, 200]]]);
$sheet = ConversionOptions::none()
    ->with_grid_columns(3)->with_grid_rows(2)
    ->with_grid_gutter_x(8)->with_grid_gutter_y(8)
    ->with_background('#ffffff');

$pieces = $extractor->extract($png, 'png', null, $sheet);
$again  = (new ImageGridMerger())->merge(array_map(fn ($p) => $p->bytes(), $pieces), 'png', 'png', $sheet);
test('the same pixels as $png', cookbook_same_pixels($again->bytes(), $png));

// -----------------------------------------------------------------------------
section('When it cannot work, it says so');

$touching = cookbook_paint(400, 400, COOKBOOK_WHITE, [[0, 0, 200, 200, [200, 0, 0]], [200, 0, 200, 200, [0, 200, 0]], [0, 200, 400, 200, [0, 0, 200]]]);
$said     = '';
try {
    $extractor->extract($touching, 'png', null, ConversionOptions::none()
        ->with_grid_columns('auto')->with_grid_rows('auto')
        ->with_grid_expect(3));
} catch (ConversionException $e) {
    $said = $e->getMessage();
}
test('pieces that touch: "Expected 3 piece(s), found 1."', $said === 'Expected 3 piece(s), found 1.');


// =============================================================================
// Cookbook: ImageGridMerger
// =============================================================================

$merger = new ImageGridMerger();

// -----------------------------------------------------------------------------
section('Side by side');

$before = cookbook_paint(400, 300, [200, 0, 0, 0]);
$after  = cookbook_paint(400, 300, [0, 0, 200, 0]);

$pair = $merger->merge([$before, $after], 'png', 'png', ConversionOptions::none());
test('before / after, no grid keys: one row, 800x300', cookbook_sizes([$pair]) === ['800x300']);

$framed = $merger->merge([$before, $after], 'png', 'png', ConversionOptions::none()
    ->with_grid_gutter_x(20)
    ->with_background('#ffffff'));
test('...with a 20px white gutter: 820x300, white at x 410', cookbook_sizes([$framed]) === ['820x300'] && cookbook_at($framed->bytes(), 410, 150) === [255, 255, 255, 0]);

// -----------------------------------------------------------------------------
section('A contact sheet from pieces of different sizes');

$photos = [
    cookbook_paint(120, 90, [200, 0, 0, 0]),
    cookbook_paint(90, 120, [0, 200, 0, 0]),
    cookbook_paint(100, 100, [0, 0, 200, 0]),
    cookbook_paint(120, 80, [200, 200, 0, 0]),
    cookbook_paint(80, 80, [0, 200, 200, 0]),
    cookbook_paint(100, 60, [200, 0, 200, 0]),
];
$contact = $merger->merge($photos, 'png', 'png', ConversionOptions::none()
    ->with_grid_columns(3)
    ->with_grid_gutter_x(10)->with_grid_gutter_y(10)
    ->with_grid_margin_left(20)->with_grid_margin_right(20)
    ->with_grid_margin_top(20)->with_grid_margin_bottom(20)
    ->with_background('#f0f0f0'));
test('3 columns: each as wide as its widest piece, each row as tall as its tallest — 370x250', cookbook_sizes([$contact]) === ['370x250']);
test('...the 120x90 photo centred in its 120x120 cell: background above it, the photo from y 35', cookbook_at($contact->bytes(), 80, 34) === [240, 240, 240, 0] && cookbook_at($contact->bytes(), 80, 35) === [200, 0, 0, 0]);

// -----------------------------------------------------------------------------
section('A vertical strip');

$screens = [cookbook_paint(400, 200, [200, 0, 0, 0]), cookbook_paint(380, 150, [0, 200, 0, 0]), cookbook_paint(400, 100, [0, 0, 200, 0])];
$strip   = $merger->merge($screens, 'png', 'png', ConversionOptions::none()
    ->with_grid_columns(1)
    ->with_grid_gutter_y(16)
    ->with_align_x(0)
    ->with_background('#ffffff'));
test('one column, 16px between: 400x482', cookbook_sizes([$strip]) === ['400x482']);
test('...the narrower screenshot flush left (ALIGN_X 0): its colour at x 0, white at x 390', cookbook_at($strip->bytes(), 0, 300) === [0, 200, 0, 0] && cookbook_at($strip->bytes(), 390, 300) === [255, 255, 255, 0]);

// -----------------------------------------------------------------------------
section('Fixed cells');

$tiles = $merger->merge([cookbook_paint(180, 120, [200, 0, 0, 0]), cookbook_paint(200, 150, [0, 0, 200, 0])], 'png', 'png', ConversionOptions::none()
    ->with_grid_columns('200px 200px')
    ->with_grid_rows('150px')
    ->with_background('#ffffff'));
test("'200px 200px' x '150px': 400x150, the 180x120 piece centred (from x 10, y 15)", cookbook_sizes([$tiles]) === ['400x150'] && cookbook_at($tiles->bytes(), 10, 15) === [200, 0, 0, 0] && cookbook_at($tiles->bytes(), 9, 15) === [255, 255, 255, 0]);

$said = '';
try {
    $merger->merge([cookbook_paint(220, 150, [200, 0, 0, 0])], 'png', 'png', ConversionOptions::none()->with_grid_columns('200px'));
} catch (ConversionException $e) {
    $said = $e->getMessage();
}
test('a piece larger than its cell: refused, never scaled', $said === 'A 220px piece does not fit track 1 (200px) — resize it first; a merge never scales.');

$converter = new GdImageConverter();
$fitted    = array_map(
    fn (string $png) => $converter->convert($png, 'png', 'png', ConversionOptions::none()->with_width(200))->bytes(),
    [cookbook_paint(400, 300, [200, 0, 0, 0]), cookbook_paint(800, 400, [0, 0, 200, 0])]
);
$gallery = $merger->merge($fitted, 'png', 'png', ConversionOptions::none()->with_grid_columns('200px 200px'));
test('resized first, with GdImageConverter: 200x150 and 200x100, a 400x150 sheet', cookbook_sizes([$gallery]) === ['400x150']);

// -----------------------------------------------------------------------------
section('Order, and a grid that is not full');

$four  = [cookbook_paint(100, 100, [200, 0, 0, 0]), cookbook_paint(100, 100, [0, 200, 0, 0]), cookbook_paint(100, 100, [0, 0, 200, 0]), cookbook_paint(100, 100, [200, 200, 0, 0])];
$paper = $merger->merge($four, 'png', 'png', ConversionOptions::none()
    ->with_grid_rows(2)
    ->with_grid_order('tb-lr'));
test("'tb-lr', 2 rows: down the first column, then the second — the second piece bottom left", cookbook_at($paper->bytes(), 50, 150) === [0, 200, 0, 0] && cookbook_at($paper->bytes(), 150, 50) === [0, 0, 200, 0]);

$seven = $merger->merge(array_fill(0, 7, cookbook_paint(100, 100, [0, 0, 200, 0])), 'png', 'png', ConversionOptions::none()
    ->with_grid_columns(3)
    ->with_background('#ffffff'));
test('7 pieces in 3 columns: 300x300, the two missing cells background of the same size', cookbook_sizes([$seven]) === ['300x300'] && cookbook_at($seven->bytes(), 250, 250) === [255, 255, 255, 0]);

// -----------------------------------------------------------------------------
section('Formats and background');

$loose = $merger->merge([$before, $after], 'png', 'png', ConversionOptions::none()->with_grid_gutter_x(20));
test('PNG, no BACKGROUND: the gutter is transparent', cookbook_at($loose->bytes(), 410, 150)[3] === 127);

$jpg = $merger->merge([$before, $after], 'png', 'jpg', ConversionOptions::none()->with_grid_gutter_x(20)->with_quality(85));
test('PNG pieces into a JPEG: white by default, image/jpeg', $jpg->mime() === 'image/jpeg' && min(array_slice(cookbook_at($jpg->bytes(), 410, 150), 0, 3)) > 240);

$webp = $merger->merge([$before, $after], 'png', 'webp', ConversionOptions::none()->with_lossless(true));
test('...or a lossless WebP: exact colours', cookbook_at($webp->bytes(), 200, 150) === [200, 0, 0, 0] && cookbook_at($webp->bytes(), 600, 150) === [0, 0, 200, 0]);


// =============================================================================
// Cookbook: ImageTrimmer
// =============================================================================

$trimmer = new ImageTrimmer();

// -----------------------------------------------------------------------------
section('Cut to the content — adaptive');

$scan    = cookbook_paint(1000, 800, COOKBOOK_WHITE, [[100, 80, 700, 600, [40, 40, 40]]]);
$content = $trimmer->trim($scan, 'png', null, ConversionOptions::none());
test('a scan with a white border: 700x600, the border gone', cookbook_sizes([$content]) === ['700x600'] && cookbook_at($content->bytes(), 0, 0) === [40, 40, 40, 0]);

$logo  = cookbook_paint(500, 300, [0, 0, 0, 127], [[150, 100, 200, 100, [0, 90, 200]]]);
$tight = $trimmer->trim($logo, 'png', null, ConversionOptions::none());
test('a logo with transparent padding: 200x100', cookbook_sizes([$tight]) === ['200x100']);

$product = cookbook_paint(800, 600, [245, 245, 245, 0], [[210, 160, 400, 300, [235, 235, 235]], [200, 150, 400, 300, [100, 50, 20]]]);
$packed  = $trimmer->trim($product, 'png', null, ConversionOptions::none()->with_background('#f5f5f5'));
test('a product on light grey, with a faint shadow: at the default GAP_TOLERANCE (10) the shadow is background — 400x300', cookbook_sizes([$packed]) === ['400x300']);
$shadowed = $trimmer->trim($product, 'png', null, ConversionOptions::none()->with_background('#f5f5f5')->with_gap_tolerance(2));
test('...at 2 the shadow is content, and stays: 410x310', cookbook_sizes([$shadowed]) === ['410x310']);

// -----------------------------------------------------------------------------
section('Declared crops');

$page = cookbook_paint(1000, 800, COOKBOOK_WHITE);

$inner = $trimmer->trim($page, 'png', null, ConversionOptions::none()
    ->with_crop_left('10%')->with_crop_right('10%')
    ->with_crop_top('10%')->with_crop_bottom('10%'));
test('10% off every side of 1000x800: 800x640', cookbook_sizes([$inner]) === ['800x640']);

$captioned = $trimmer->trim($page, 'png', null, ConversionOptions::none()->with_crop_bottom(60));
test('one side only — a caption bar along the bottom: 1000x740', cookbook_sizes([$captioned]) === ['1000x740']);

$photo = cookbook_paint(1600, 1200, [90, 90, 90, 0], [[420, 310, 380, 260, [250, 200, 0]]]);
$box   = $trimmer->trim($photo, 'png', null, ConversionOptions::none()
    ->with_crop_left(420)->with_crop_top(310)
    ->with_width(380)->with_height(260));
test('a rectangle by coordinates: x and y as the left and top cut, then the size — 380x260, all of it the box', cookbook_sizes([$box]) === ['380x260'] && cookbook_at($box->bytes(), 0, 0) === [250, 200, 0, 0] && cookbook_at($box->bytes(), 379, 259) === [250, 200, 0, 0]);

// -----------------------------------------------------------------------------
section('Sizes and ratios');

$wide   = cookbook_paint(1600, 900, [90, 90, 90, 0], [[350, 0, 1, 900, [250, 0, 0]]]);
$square = $trimmer->trim($wide, 'png', null, ConversionOptions::none()->with_ratio('1:1'));
test('a square thumbnail of a 1600x900 photo: 900x900, centred — the cut starts at x 350', cookbook_sizes([$square]) === ['900x900'] && cookbook_at($square->bytes(), 0, 0) === [250, 0, 0, 0]);

$tall   = cookbook_paint(1200, 1200, [90, 90, 90, 0], [[0, 0, 1200, 1, [250, 0, 0]]]);
$banner = $trimmer->trim($tall, 'png', null, ConversionOptions::none()->with_ratio('16:9')->with_align_y(0));
test('a 16:9 banner from the top of a square: 1200x675, the first row kept', cookbook_sizes([$banner]) === ['1200x675'] && cookbook_at($banner->bytes(), 0, 0) === [250, 0, 0, 0]);

$big    = cookbook_paint(1600, 1200, [90, 90, 90, 0], [[400, 300, 1, 1, [250, 0, 0]]]);
$centre = $trimmer->trim($big, 'png', null, ConversionOptions::none()->with_width(800)->with_height(600));
test('800x600 out of the middle of 1600x1200: the cut starts at 400, 300', cookbook_sizes([$centre]) === ['800x600'] && cookbook_at($centre->bytes(), 0, 0) === [250, 0, 0, 0]);
$left = $trimmer->trim($big, 'png', null, ConversionOptions::none()->with_width(800)->with_height(600)->with_align_x(0));
test('...ALIGN_X 0: from the left edge instead', cookbook_at($left->bytes(), 400, 0) === [250, 0, 0, 0]);

// -----------------------------------------------------------------------------
section('When it cannot work, it says so — trimming');

$said = '';
try {
    $trimmer->trim(cookbook_paint(300, 200, COOKBOOK_WHITE), 'png', null, ConversionOptions::none());
} catch (ConversionException $e) {
    $said = $e->getMessage();
}
test('nothing but background: "Nothing but background: there is no content to trim to."', $said === 'Nothing but background: there is no content to trim to.');

$said = '';
try {
    $trimmer->trim($page, 'png', null, ConversionOptions::none()->with_width(5000));
} catch (ConversionException $e) {
    $said = $e->getMessage();
}
test('a crop larger than the image: refused, never padded', str_starts_with($said, 'This crop removes more than the source has (1000px)'));

exit(summary());
