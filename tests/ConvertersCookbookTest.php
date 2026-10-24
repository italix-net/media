<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Media — italix/converters' README Cookbook, run
 *
 * The Cookbook in italix/converters' README shows ConverterSet, Pipeline
 * and the grid geometry with real drivers — this library's and
 * italix/documents' — which italix/converters itself does not know (it
 * depends on nothing). So its examples run here, where the real drivers
 * are: in the same order and with the same options, what each comment
 * there says came back, asserted.
 *
 * Run: php src/Libs/Italix/Media/tests/ConvertersCookbookTest.php
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

use Italix\Converters\AxisLayout;
use Italix\Converters\ConversionException;
use Italix\Converters\ConversionOptions;
use Italix\Converters\ConverterSet;
use Italix\Converters\GridGeometry;
use Italix\Converters\NoRouteException;
use Italix\Converters\Pipeline;
use Italix\Converters\Spans;
use Italix\Documents\HtmlToPdfConverter;
use Italix\Documents\MarkdownToHtmlConverter;
use Italix\Media\GdImageConverter;
use Italix\Media\PdfPageToImageConverter;

use function Italix\Testing\{suite, section, test, summary};

suite('Italix Media — italix/converters\' README Cookbook, run');

$gd = new GdImageConverter();

if (!$gd->is_available()) {
    echo "  SKIPPED — ext-gd is absent.\n";
    exit(summary());
}

/** "WxH" of an image. */
function converters_size(string $bytes): string
{
    $image = imagecreatefromstring($bytes);
    $size  = imagesx($image) . 'x' . imagesy($image);
    imagedestroy($image);

    return $size;
}

/**
 * A route as "from->to Driver" hops, for comparing.
 *
 * @param array<mixed> $route
 * @return list<string>
 */
function converters_hops(array $route): array
{
    return array_map(static fn (array $hop): string => "{$hop[0]}->{$hop[1]} " . (new ReflectionClass($hop[2]))->getShortName(), $route);
}

$canvas = imagecreatetruecolor(1600, 900);
ob_start();
imagepng($canvas);
imagedestroy($canvas);
$png = ob_get_clean();

$documents = class_exists(MarkdownToHtmlConverter::class)
    && (new MarkdownToHtmlConverter())->is_available()
    && (new HtmlToPdfConverter())->is_available()
    && (new PdfPageToImageConverter())->is_available();

// -----------------------------------------------------------------------------
section('One driver, directly');

$smaller = $gd->convert($png, 'png', 'png', ConversionOptions::none()->with_width(800));
test('a resize is one call to one driver: 1600x900 -> 800x450', converters_size($smaller->bytes()) === '800x450');
$as_webp = $gd->convert($png, 'png', 'webp', ConversionOptions::none()->with_width(800)->with_quality(80));
test('...a resize and a format change, still one call: an 800x450 WebP', $as_webp->mime() === 'image/webp' && converters_size($as_webp->bytes()) === '800x450');

if (!$documents) {
    echo "  SKIPPED the ConverterSet and cross-library Pipeline sections — they need italix/documents (commonmark, dompdf) and Ghostscript.\n";
} else {
    // -------------------------------------------------------------------------
    section('A ConverterSet: the route is found');

    $set = new ConverterSet([
        new MarkdownToHtmlConverter(),                 // italix/documents
        new HtmlToPdfConverter(),                      // italix/documents
        new PdfPageToImageConverter(),                 // italix/media
        new GdImageConverter(),                        // italix/media
    ]);

    $markdown = "# A title\n\nSome *text*.\n";
    $preview  = $set->convert($markdown, 'md', 'jpg', ConversionOptions::none()->with_width(400));
    test('Markdown to a 400px-wide JPEG, four drivers, two libraries: image/jpeg 400x565', $preview->mime() === 'image/jpeg' && converters_size($preview->bytes()) === '400x565');
    test('route(): md->html, html->pdf, pdf->png, png->jpg', converters_hops($set->route('md', 'jpg')) === ['md->html MarkdownToHtmlConverter', 'html->pdf HtmlToPdfConverter', 'pdf->png PdfPageToImageConverter', 'png->jpg GdImageConverter']);

    // -------------------------------------------------------------------------
    section('Waypoints: a format the route must pass through');

    test("png->jpg is direct", converters_hops($set->route('png', 'jpg')) === ['png->jpg GdImageConverter']);
    test("via ['gif']: png->gif->jpg", converters_hops($set->route('png', 'jpg', ['gif'])) === ['png->gif GdImageConverter', 'gif->jpg GdImageConverter']);
    test("md->jpg via ['webp']: the webp hop inserted before the jpg one", array_slice(converters_hops($set->route('md', 'jpg', ['webp'])), -2) === ['png->webp GdImageConverter', 'webp->jpg GdImageConverter']);
    $through_webp = $set->convert($markdown, 'md', 'jpg', ConversionOptions::none()->with_width(400), ['webp']);
    test('...and converting along it: the same 400x565 JPEG', converters_size($through_webp->bytes()) === '400x565');
    test('...really through WebP: its lossy hop leaves other bytes than the direct route', $through_webp->bytes() !== $preview->bytes());
    $alternatives = array_map('converters_hops', $set->all_routes('png', 'jpg', [], 1));
    test('all_routes(png, jpg, slack 1): the direct one and three one hop longer (jpeg, gif, webp)', count($alternatives) === 4 && $alternatives[0] === ['png->jpg GdImageConverter']);

    // -------------------------------------------------------------------------
    section('Driver preference: which driver, when several can');

    test('a class name in via picks among drivers for the same hop, and never adds a hop: with one candidate, nothing changes', converters_hops($set->route('md', 'jpg', [GdImageConverter::class])) === converters_hops($set->route('md', 'jpg')));

    // -------------------------------------------------------------------------
    section('One options bag through every hop — scoped when it must reach one driver only');

    $scoped = $set->convert($markdown, 'md', 'jpg', ConversionOptions::none()
        ->with_width(300, GdImageConverter::class)
        ->with_quality(70, GdImageConverter::class));
    test('WIDTH and QUALITY scoped to GdImageConverter: a 300px-wide JPEG', converters_size($scoped->bytes()) === '300x424');

    // -------------------------------------------------------------------------
    section('A Pipeline across libraries: exactly these steps');

    $two_pages = (new HtmlToPdfConverter())->convert('<p>one</p><p style="page-break-before: always">two</p>', 'html', 'pdf', ConversionOptions::none())->bytes();
    $second    = (new Pipeline([
        ['driver' => new PdfPageToImageConverter(), 'to' => 'png', 'options' => ConversionOptions::none()->with_page(2)],
        ['driver' => $gd, 'to' => 'webp', 'options' => ConversionOptions::none()->with_width(600)],
    ]))->run($two_pages, 'pdf');
    test('page 2 of a PDF as a 600px-wide WebP: each step its own options', $second->mime() === 'image/webp' && explode('x', converters_size($second->bytes()))[0] === '600');
}

// -----------------------------------------------------------------------------
section('A Pipeline: the same format twice, each step its own options');

$pipeline = new Pipeline([
    ['driver' => $gd, 'options' => ConversionOptions::none()->with_width(800)],
    ['driver' => $gd, 'to' => 'webp', 'options' => ConversionOptions::none()->with_quality(70)],
    ['driver' => $gd, 'to' => 'png', 'options' => ConversionOptions::none()->with_height(200)],
]);
$result = $pipeline->run($png, 'png');
test('png -(WIDTH 800)-> png -(QUALITY 70)-> webp -(HEIGHT 200)-> png: a 356x200 PNG', $result->mime() === 'image/png' && converters_size($result->bytes()) === '356x200');
test('describe(): one line per step', count($pipeline->describe()) === 3);

// -----------------------------------------------------------------------------
section('Geometry without pixels');

test('AxisLayout::resolve(1024, 3, …): 342, 341, 341', AxisLayout::resolve(1024, 3, 0, 0, 0) === [[0, 342], [342, 341], [683, 341]]);
test('GridGeometry::cells(300, 200, 3x2)[4]: row 2, column 2 at 100,100', GridGeometry::cells(300, 200, ConversionOptions::none()->with_grid_columns(3)->with_grid_rows(2))[4] === ['row' => 2, 'column' => 2, 'x' => 100, 'y' => 100, 'width' => 100, 'height' => 100]);
$sheet = GridGeometry::canvas([[100, 80], [120, 60], [100, 80]], ConversionOptions::none()->with_grid_columns(2));
test('GridGeometry::canvas(three pieces, 2 columns): 220x160', [$sheet['width'], $sheet['height']] === [220, 160]);
test('Spans::split(profile, min gap 2): [[2, 3], [7, 8]]', Spans::split([0.0, 0.0, 0.4, 0.9, 0.0, 0.0, 0.0, 0.7, 0.8, 0.0], 2) === [[2, 3], [7, 8]]);

// -----------------------------------------------------------------------------
section('When it cannot work, it says so');

$said = '';
try {
    (new ConverterSet([new GdImageConverter()]))->convert($png, 'png', 'docx');
} catch (NoRouteException $e) {
    $said = $e->getMessage();
}
test('no route: NoRouteException, naming what IS reachable', $said === 'No route from .png to .docx. Reachable from .png: .jpg, .jpeg, .gif, .webp.');

$said = '';
try {
    (new Pipeline([['to' => 'webp']]))->run($png, 'png');
} catch (ConversionException $e) {
    $said = $e->getMessage();
}
test("a Pipeline step without a driver: \"Pipeline step 0 needs a 'driver' key holding a Converter instance.\"", $said === "Pipeline step 0 needs a 'driver' key holding a Converter instance.");

exit(summary());
