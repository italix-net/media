<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Media — PdfPageToImageConverter, and the cross-library milestone
 *
 * The last section is the one this library's own README flagged as
 * missing: "no cross-library route from italix/documents' formats into
 * this library's formats exists yet". It does now — md -> html -> pdf ->
 * png, four hops, three drivers, two libraries that have never imported
 * each other, one shared ConverterSet built by whoever is wiring the
 * application together. This test file is the wiring.
 *
 * Run: php src/Libs/Italix/Media/tests/PdfPageToImageConverterTest.php
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
use Italix\Converters\ConverterSet;
use Italix\Documents\HtmlToPdfConverter;
use Italix\Documents\MarkdownToHtmlConverter;
use Italix\Media\GdImageConverter;
use Italix\Media\PdfPageToImageConverter;

use function Italix\Testing\{suite, section, test, summary};

suite('Italix Media — PdfPageToImageConverter');

$driver = new PdfPageToImageConverter();

if (!$driver->is_available()) {
    echo "  SKIPPED — Ghostscript (gs) is absent from PATH.\n";
    exit(summary());
}

if (!class_exists(\Dompdf\Dompdf::class)) {
    echo "  SKIPPED — dompdf/dompdf is absent, needed to build a real test PDF.\n";
    exit(summary());
}

$real_pdf = (new HtmlToPdfConverter())->convert(
    '<h1 style="color:#900">A real page</h1><p>Body text, so the render is not blank.</p>',
    'html',
    'pdf',
    ConversionOptions::none()
)->bytes();

/** A genuine 2-page PDF (CSS page-break-before), for the PAGE option tests. */
$two_page_pdf = (new HtmlToPdfConverter())->convert(
    '<div style="color:red">PAGE ONE</div>'
        . '<div style="page-break-before: always; color:blue">PAGE TWO</div>',
    'html',
    'pdf',
    ConversionOptions::none()
)->bytes();

// -----------------------------------------------------------------------------
section('the driver describes itself honestly');

test('pairs() is exactly pdf -> png — no other target is this driver\'s job', $driver->pairs() === [['pdf', 'png']]);
test('is_available() is true — gs is on PATH on this machine', $driver->is_available());
test('describe() names Ghostscript, not Imagick — the actual mechanism, not the one that was tried first', str_contains($driver->describe(), 'Ghostscript'));

// -----------------------------------------------------------------------------
section('convert() produces a real, correctly-sized PNG — checked by decoding it');

$result = $driver->convert($real_pdf, 'pdf', 'png', ConversionOptions::none());

test('the extension is png', $result->extension_code() === 'png');
test('the mime type says so too', $result->mime() === 'image/png');
test('the bytes really are a PNG', str_starts_with($result->bytes(), "\x89PNG"));

$decoded = imagecreatefromstring($result->bytes());
test('it decodes to a real image', $decoded !== false);
// A4 at 150 dpi: 8.27in x 11.69in x 150 ~= 1240 x 1754. Ghostscript's own
// rounding can land a pixel or two off either dimension.
test('...at roughly A4 proportions for the configured 150 dpi, not some placeholder size', abs(imagesx($decoded) - 1240) <= 3 && abs(imagesy($decoded) - 1754) <= 3);

// -----------------------------------------------------------------------------
section('a genuinely broken PDF fails loudly, with the real Ghostscript output attached');

$threw = false;
try {
    $driver->convert('this is not a pdf at all', 'pdf', 'png', ConversionOptions::none());
} catch (ConversionException $e) {
    $threw = true;
    test('the exception message says what failed', str_contains($e->getMessage(), 'Ghostscript'));
    test('...and carries Ghostscript\'s own stderr, not a generic message', $e->driver_stderr() !== null && $e->driver_stderr() !== '');
}
test('genuinely invalid input throws ConversionException rather than returning empty or garbage bytes', $threw);

// -----------------------------------------------------------------------------
section('registered alone in a ConverterSet');

$pdf_only = new ConverterSet([$driver]);
$route    = $pdf_only->route('pdf', 'png');

test('pdf -> png routes directly, one hop', count($route) === 1);
test('...naming this exact instance', $route[0][2] === $driver);

// -----------------------------------------------------------------------------
section('reaching jpg/webp from a pdf is not this driver\'s job — routing supplies it');

$with_gd = new ConverterSet([$driver, new GdImageConverter()]);

$route = $with_gd->route('pdf', 'jpg');
test('pdf -> jpg is not a direct pair on either driver, but a two-hop route is found', count($route) === 2);
test('...through png in the middle', $route[0][1] === 'png' && $route[1][0] === 'png');

$as_jpg = $with_gd->convert($real_pdf, 'pdf', 'jpg');
test('the routed conversion really is a JPEG', str_starts_with($as_jpg->bytes(), "\xFF\xD8"));

// -----------------------------------------------------------------------------
section('the milestone: md -> html -> pdf -> png, three drivers, two libraries, never imported each other');

if (!class_exists(\League\CommonMark\MarkdownConverter::class)) {
    // Genuinely absent, not a fluke: italix/documents lists league/commonmark
    // under require-dev only, so installing italix/documents as a dependency
    // of *this* library (rather than as the root package under test) never
    // pulls it in — verified directly by reproducing italix/media's own
    // isolated `composer install` (the same one `ix libs:ci --install`
    // runs), not assumed. MarkdownToHtmlConverter::is_available() correctly
    // reports false here, so the route below legitimately doesn't exist —
    // skip just this section, not the rest of the file, which needs none of
    // this.
    echo "  SKIPPED — league/commonmark is absent, so md -> html -> pdf -> png has no route.\n";
} else {
    $pipeline = new ConverterSet([
        new MarkdownToHtmlConverter(),
        new HtmlToPdfConverter(),
        $driver,
    ]);

    $route = $pipeline->route('md', 'png');
    test('a four-format, three-hop route is found automatically', count($route) === 3);
    test('...md -> html', [$route[0][0], $route[0][1]] === ['md', 'html']);
    test('...html -> pdf', [$route[1][0], $route[1][1]] === ['html', 'pdf']);
    test('...pdf -> png', [$route[2][0], $route[2][1]] === ['pdf', 'png']);

    $final = $pipeline->convert(
        "# The whole pipeline\n\nMarkdown in, a PNG image comes out the other end.",
        'md',
        'png'
    );

    test('the end-to-end result is a real, decodable PNG', imagecreatefromstring($final->bytes()) !== false);
    test('the reported format is the true final target', $final->extension_code() === 'png');
}

// -----------------------------------------------------------------------------
section('PAGE: this was a hardcoded "first page only" limitation before ConversionOptions existed');

$page1 = $driver->convert($two_page_pdf, 'pdf', 'png', ConversionOptions::none());
$page2 = $driver->convert($two_page_pdf, 'pdf', 'png', ConversionOptions::none()->with_page(2));

test('no PAGE option still defaults to page 1', $page1->bytes() !== '');
test('PAGE=2 renders genuinely different content, not the same page twice', $page1->bytes() !== $page2->bytes());
test('...and both are still real, correctly-decodable PNGs', imagecreatefromstring($page1->bytes()) !== false && imagecreatefromstring($page2->bytes()) !== false);

$out_of_range_threw = false;
try {
    $driver->convert($two_page_pdf, 'pdf', 'png', ConversionOptions::none()->with_page(99));
} catch (ConversionException $e) {
    $out_of_range_threw = true;
}
test(
    'a page number past the end of the document fails loudly — Ghostscript exits 0 for this (verified directly, a real surprise) but writes no output file, and that alone is still caught',
    $out_of_range_threw
);

exit(summary());
