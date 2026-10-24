<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Media - ImageGridMerger
 *
 * @package Italix\Media
 */

declare(strict_types=1);

namespace Italix\Media;

use Italix\Converters\ConversionException;
use Italix\Converters\ConversionOptions;
use Italix\Converters\ConvertedDocument;
use Italix\Converters\GridGeometry;
use Italix\Converters\Merger;

/**
 * Puts images together on one sheet — a contact sheet, a strip, "before /
 * after" side by side — via ext-gd, in-process. The inverse of
 * ImageExtractor: the same grid keys describe the same sheet, so the
 * options that took one apart put it back together, pixel for pixel on a
 * lossless format.
 *
 * The layout is GridGeometry::canvas(): the pieces go into the cells in
 * GRID_ORDER; a column is as wide as its widest piece and a row as tall as
 * its tallest, unless the spec gives it in pixels; with no grid keys, one
 * row. **Never scales a piece**: one larger than its pixel track is an
 * error — resizing is a Converter step before this one (GdImageConverter
 * with WIDTH/HEIGHT, in a Pipeline), said, not implied. A piece smaller
 * than its cell sits where ALIGN_X/ALIGN_Y put it (0 start, 50 centre — the
 * default — 100 end; anything outside 0-100 is refused, it would push the
 * piece off its cell); BACKGROUND fills the rest: by default transparent,
 * or white for JPEG and GIF. Pixels are copied, not blended, so a piece's
 * own transparency survives onto a transparent sheet; JPEG is flattened
 * onto white at the end, whatever BACKGROUND says, as GdImageConverter does.
 *
 * The round trip holds with the options that took the sheet apart **and**
 * its BACKGROUND: the pieces carry nothing of the gutters and margins
 * between them, so what fills those comes from BACKGROUND, not from the
 * old sheet. A sheet GD cannot allocate is a ConversionException, not a
 * warning; one that fits in GD but not in PHP's memory_limit is still PHP's
 * fatal error — keep sheets to what memory holds (4 bytes a pixel).
 */
final class ImageGridMerger implements Merger
{
    public function pairs(): array
    {
        $pairs = [];
        foreach (GdImages::FORMATS as $from_c) {
            foreach (GdImages::FORMATS as $to_c) {
                $pairs[] = [$from_c, $to_c];
            }
        }

        return $pairs;
    }

    public function is_available(): bool
    {
        return extension_loaded('gd') && function_exists('imagecopy');
    }

    public function merge(array $sources, string $from_extension_c, string $to_extension_c, ConversionOptions $options): ConvertedDocument
    {
        if (!in_array($from_extension_c, GdImages::FORMATS, true) || !in_array($to_extension_c, GdImages::FORMATS, true)) {
            throw new ConversionException("Not a pair this driver merges: .{$from_extension_c} -> .{$to_extension_c}.");
        }

        $images = [];

        try {
            foreach (array_values($sources) as $bytes) {
                $images[] = GdImages::decode($bytes, $from_extension_c);
            }

            $sheet = GridGeometry::canvas(
                array_map(static fn (\GdImage $image): array => [imagesx($image), imagesy($image)], $images),
                $options,
                static::class
            );

            $default_c = in_array($to_extension_c, ['jpg', 'jpeg', 'gif'], true) ? '#ffffff' : 'transparent';
            [$red, $green, $blue, $alpha] = GdImages::color($options->background_code(static::class) ?? $default_c);

            $canvas = @imagecreatetruecolor($sheet['width'], $sheet['height']);
            if ($canvas === false) {
                throw new ConversionException("GD cannot allocate a {$sheet['width']}x{$sheet['height']} sheet.");
            }

            try {
                imagealphablending($canvas, false);
                imagesavealpha($canvas, true);
                imagefilledrectangle($canvas, 0, 0, $sheet['width'] - 1, $sheet['height'] - 1, imagecolorallocatealpha($canvas, $red, $green, $blue, $alpha));

                $align_x_perc = $options->align_x_perc(50, static::class);
                $align_y_perc = $options->align_y_perc(50, static::class);
                if ($align_x_perc < 0 || $align_x_perc > 100 || $align_y_perc < 0 || $align_y_perc > 100) {
                    throw new ConversionException("ALIGN_X/ALIGN_Y are 0-100: {$align_x_perc}, {$align_y_perc}.");
                }

                foreach ($images as $i => $image) {
                    $cell = $sheet['cells'][$i];
                    $x    = $cell['x'] + (int) round(($cell['width'] - imagesx($image)) * $align_x_perc / 100);
                    $y    = $cell['y'] + (int) round(($cell['height'] - imagesy($image)) * $align_y_perc / 100);
                    imagecopy($canvas, $image, $x, $y, 0, 0, imagesx($image), imagesy($image));
                }

                return new ConvertedDocument(GdImages::encode($canvas, $to_extension_c, $options, static::class, $this->describe()), $to_extension_c, GdImages::MIME[$to_extension_c]);
            } finally {
                imagedestroy($canvas);
            }
        } finally {
            foreach ($images as $image) {
                imagedestroy($image);
            }
        }
    }

    public function describe(): string
    {
        return implode('/', GdImages::FORMATS) . ' (N) -> one sheet, any of them (ext-gd, layout via ' . GridGeometry::class . ', never scaled)';
    }
}
