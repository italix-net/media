<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Media - ImageExtractor
 *
 * @package Italix\Media
 */

declare(strict_types=1);

namespace Italix\Media;

use Italix\Converters\AxisLayout;
use Italix\Converters\ConversionException;
use Italix\Converters\ConversionOptions;
use Italix\Converters\ConvertedDocument;
use Italix\Converters\CropGeometry;
use Italix\Converters\Extractor;
use Italix\Converters\GridGeometry;
use Italix\Converters\Spans;

/**
 * Takes regions out of an image — one rectangle, several, or a whole
 * composite: a sprite sheet, a contact sheet, a strip of screenshots, a page
 * of panels — via ext-gd, in-process. A single rectangle is one explicit
 * boundary (or, when it is easier said as margins, a ratio or an alignment,
 * ImageTrimmer's crop keys). In order:
 *
 * 1. The crop keys (CROP_*, WIDTH, HEIGHT, RATIO), if any, cut the source
 *    first — CropGeometry, exactly as ImageTrimmer applies them.
 * 2. Explicit $boundaries: those boxes, each [x, y, width, height] inside
 *    what step 1 left (what a person or a model picked). Grid keys alongside
 *    them are an error: two answers to one question.
 * 3. Otherwise the grid keys, through GridGeometry::cells().
 * 4. GRID_COLUMNS or GRID_ROWS = 'auto': detected (below).
 *
 * Then GRID_SKIP_EMPTY drops the cells that are all background, and
 * GRID_EXPECT checks the count. Every piece carries its locator: row,
 * column (or index, for explicit boxes) and its box in the source's own
 * coordinates — so a detected cut, or a skipped cell, is never invisible.
 *
 * **Detection** reads the background (BACKGROUND, or the commonest colour
 * along the border) and, through Spans, the runs of background at least
 * GAP_MIN long (default 3px) with no pixel farther from it than
 * GAP_TOLERANCE (default 10, of 100). It is recursive, rows first: the
 * content's bands, then the columns inside each band — so a page whose rows
 * hold different numbers of panels comes apart, and a regular grid is just
 * the case where every band splits alike. A detected piece is labelled by
 * its band and its place in the band, so 'tb-lr'/'tb-rl' on detected rows
 * read those labels — on an irregular page, "column 2" of one band and of
 * the next need not line up. The detected region is the whole image (or
 * what the crop keys left): GRID_MARGIN_* alongside 'auto' is refused, the
 * margins are found, not declared. 'auto' on one axis and a declared
 * spec on the other detects the one and lays the other out over the
 * content's extent (GRID_GUTTER_* between its tracks). A piece is its band
 * by its span: a small image in a tall band keeps the band's height —
 * trimming each piece tight is ImageTrimmer's job, afterwards.
 *
 * What detection cannot do: pieces that touch, a gradient or photographic
 * background, a shadow down the middle, pieces that are not rectangles or
 * not aligned, content running into the edge, a frame drawn round the
 * sheet (crop it off with CROP_* first). Declare the tracks or give the
 * boxes then — and GRID_EXPECT turns "found 1 instead of 4" into an error
 * rather than a wrong result. A border with no colour covering half of it
 * is refused outright: BACKGROUND must say what the background is.
 *
 * Every pixel of a detected region is read twice through imagecolorat() —
 * the whole region, then each band — and a third time for GRID_SKIP_EMPTY.
 * Measured 2026-09-26 on a calm 8-core box at nice 15: 3.0s for a
 * 2000x2000 sheet of 16, 8.4s for 4000x3000 of 24; a declared grid of the
 * same sheets, 0.3s and 0.8s.
 */
final class ImageExtractor implements Extractor
{
    private const GAP_MIN_N = 3;

    public function extensions(): array
    {
        return GdImages::FORMATS;
    }

    public function is_available(): bool
    {
        return extension_loaded('gd') && function_exists('imagecrop');
    }

    /**
     * @param  array<int, mixed>|null $boundaries [x, y, width, height] each, whole pixels, inside the cropped region; null for the grid keys
     * @return list<ConvertedDocument>
     */
    public function extract(string $source, string $extension_c, ?array $boundaries, ConversionOptions $options): array
    {
        if (!in_array($extension_c, GdImages::FORMATS, true)) {
            throw new ConversionException("Not an image format this driver reads: .{$extension_c}.");
        }

        $image = GdImages::decode($source, $extension_c);

        try {
            $region  = CropGeometry::resolve(imagesx($image), imagesy($image), $options, static::class);
            $columns = $options->grid_columns(static::class);
            $rows    = $options->grid_rows(static::class);

            if ($boundaries !== null) {
                if ($columns !== null || $rows !== null) {
                    throw new ConversionException('Explicit boundaries and GRID_COLUMNS/GRID_ROWS together: two answers to one question.');
                }
                $cells = self::explicit($boundaries, $region);
            } elseif ($columns === 'auto' || $rows === 'auto') {
                $cells = $this->detect($image, $region, $options);
            } else {
                $cells = array_map(
                    static fn (array $c): array => ['row' => $c['row'], 'column' => $c['column'], 'x' => $c['x'] + $region['x'], 'y' => $c['y'] + $region['y'], 'width' => $c['width'], 'height' => $c['height']],
                    GridGeometry::cells($region['width'], $region['height'], $options, static::class)
                );
            }

            if ($options->grid_skip_empty(false, static::class)) {
                $background = GdImages::background($image, $region, $options, static::class);
                $tolerance_perc = $options->gap_tolerance_perc(10, static::class);
                $cells = array_values(array_filter(
                    $cells,
                    static fn (array $c): bool => Spans::content(GdImages::profiles($image, $c, $background, $tolerance_perc)['rows']) !== null
                ));
            }

            $expect_n = $options->grid_expect_n(static::class);
            if ($expect_n !== null && count($cells) !== $expect_n) {
                throw new ConversionException(sprintf('Expected %d piece(s), found %d.', $expect_n, count($cells)));
            }

            $pieces = [];
            foreach ($cells as $cell) {
                $box   = ['x' => $cell['x'], 'y' => $cell['y'], 'width' => $cell['width'], 'height' => $cell['height']];
                $piece = imagecrop($image, $box);
                if ($piece === false) {
                    throw new ConversionException('GD refused this box: ' . json_encode($box) . '.');
                }
                try {
                    imagesavealpha($piece, true);
                    $pieces[] = new ConvertedDocument(GdImages::encode($piece, $extension_c, $options, static::class, $this->describe()), $extension_c, GdImages::MIME[$extension_c], $cell);
                } finally {
                    imagedestroy($piece);
                }
            }

            return $pieces;
        } finally {
            imagedestroy($image);
        }
    }

    public function describe(): string
    {
        return implode('/', GdImages::FORMATS) . ' -> pieces of a grid: declared, explicit or detected (ext-gd, geometry via ' . GridGeometry::class . ')';
    }

    /**
     * @param  array<mixed>                                     $boundaries
     * @param  array{x: int, y: int, width: int, height: int}   $region
     * @return list<array{index: int, x: int, y: int, width: int, height: int}>
     */
    private static function explicit(array $boundaries, array $region): array
    {
        $cells = [];
        foreach (array_values($boundaries) as $i => $box) {
            if (!is_array($box) || !array_is_list($box) || count($box) !== 4 || array_filter($box, 'is_int') !== $box) {
                throw new ConversionException('Boundary ' . ($i + 1) . ' is not a list [x, y, width, height] of whole pixels.');
            }
            [$x, $y, $width_n, $height_n] = $box;
            if ($x < 0 || $y < 0 || $width_n < 1 || $height_n < 1 || $x + $width_n > $region['width'] || $y + $height_n > $region['height']) {
                throw new ConversionException('Boundary ' . ($i + 1) . " [{$x}, {$y}, {$width_n}, {$height_n}] is not inside the {$region['width']}x{$region['height']} region.");
            }
            $cells[] = ['index' => $i + 1, 'x' => $region['x'] + $x, 'y' => $region['y'] + $y, 'width' => $width_n, 'height' => $height_n];
        }

        return $cells;
    }

    /**
     * @param  array{x: int, y: int, width: int, height: int} $region
     * @return list<array{row: int, column: int, x: int, y: int, width: int, height: int}>
     */
    private function detect(\GdImage $image, array $region, ConversionOptions $options): array
    {
        $background  = GdImages::background($image, $region, $options, static::class);
        $tolerance_perc = $options->gap_tolerance_perc(10, static::class);
        $whole       = GdImages::profiles($image, $region, $background, $tolerance_perc);
        $content_y   = Spans::content($whole['rows']);
        $content_x   = Spans::content($whole['columns']);

        if ($content_y === null || $content_x === null) {
            throw new ConversionException('Nothing but background: there is no piece to find.');
        }
        foreach ([
            $options->grid_margin_left(static::class),
            $options->grid_margin_right(static::class),
            $options->grid_margin_top(static::class),
            $options->grid_margin_bottom(static::class),
        ] as $margin) {
            if ($margin !== null) {
                throw new ConversionException("GRID_MARGIN_* alongside 'auto': the margins are detected here — crop keys cut a region first, if that is what is meant.");
            }
        }

        $gap = $options->gap_min(static::class);
        $gap_x_n = $gap === null ? self::GAP_MIN_N : max(1, AxisLayout::pixels($gap, $region['width']));
        $gap_y_n = $gap === null ? self::GAP_MIN_N : max(1, AxisLayout::pixels($gap, $region['height']));

        $columns = $options->grid_columns(static::class);
        $rows    = $options->grid_rows(static::class);
        $cells   = [];

        if ($rows === 'auto') {
            // Rows first: the bands, then the columns inside each.
            foreach (Spans::split($whole['rows'], $gap_y_n) as $r => [$top, $bottom]) {
                $band = ['x' => $region['x'], 'y' => $region['y'] + $top, 'width' => $region['width'], 'height' => $bottom - $top + 1];
                $spans = $columns === 'auto'
                    ? Spans::split(GdImages::profiles($image, $band, $background, $tolerance_perc)['columns'], $gap_x_n)
                    : self::declared($content_x, $columns, AxisLayout::pixels($options->grid_gutter_x(static::class), $region['width']));
                foreach ($spans as $c => [$left, $right]) {
                    $cells[] = ['row' => $r + 1, 'column' => $c + 1, 'x' => $region['x'] + $left, 'y' => $band['y'], 'width' => $right - $left + 1, 'height' => $band['height']];
                }
            }
        } else {
            // Columns detected, rows declared (or none): the mirror image.
            foreach (Spans::split($whole['columns'], $gap_x_n) as $c => [$left, $right]) {
                $spans = self::declared($content_y, $rows, AxisLayout::pixels($options->grid_gutter_y(static::class), $region['height']));
                foreach ($spans as $r => [$top, $bottom]) {
                    $cells[] = ['row' => $r + 1, 'column' => $c + 1, 'x' => $region['x'] + $left, 'y' => $region['y'] + $top, 'width' => $right - $left + 1, 'height' => $bottom - $top + 1];
                }
            }
        }

        return GridGeometry::sort($cells, $options->grid_order_code('lr-tb', static::class));
    }

    /**
     * A declared spec laid over the content's extent on one axis: [first, last] of each track, as Spans gives them.
     *
     * @param  array{0: int, 1: int} $content
     * @return list<array{0: int, 1: int}>
     */
    private static function declared(array $content, int|string|null $spec, int $gutter_n): array
    {
        return array_map(
            static fn (array $track): array => [$content[0] + $track[0], $content[0] + $track[0] + $track[1] - 1],
            AxisLayout::resolve($content[1] - $content[0] + 1, $spec, $gutter_n, 0, 0)
        );
    }
}
