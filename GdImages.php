<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Media - GdImages
 *
 * @package Italix\Media
 */

declare(strict_types=1);

namespace Italix\Media;

use Italix\Converters\ConversionException;
use Italix\Converters\ConversionOptions;
use Italix\Converters\UnsupportedOptionException;

/**
 * What the grid drivers and the adaptive trim share on top of ext-gd:
 * decoding, encoding, colours, and the content profile Spans reads.
 *
 * @internal not part of this library's public surface — its shape follows
 *           what the drivers need, and may change with them
 */
final class GdImages
{
    public const FORMATS = ['png', 'jpg', 'jpeg', 'gif', 'webp'];

    public const MIME = [
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
    ];

    /** JPEG and WebP quality when QUALITY is not given — GdImageConverter's own default. */
    private const DEFAULT_QUALITY = 90;

    private function __construct()
    {
    }

    /**
     * Decoded by the bytes' own signature, as GdImageConverter does; always
     * truecolour, alpha kept — a palette GIF's pixels read like any other.
     *
     * @throws ConversionException bytes GD cannot decode
     */
    public static function decode(string $bytes, string $extension_c): \GdImage
    {
        $image = @imagecreatefromstring($bytes);

        if ($image === false) {
            throw new ConversionException("GD could not decode this as an image (claimed .{$extension_c}).");
        }

        imagepalettetotruecolor($image);
        imagesavealpha($image, true);

        return $image;
    }

    /**
     * QUALITY and LOSSLESS read as GdImageConverter reads them: WebP lossless
     * on request, JPEG refusing it rather than quietly encoding lossy. JPEG is
     * flattened onto white first, unconditionally, for the reason
     * GdImageConverter gives: GD keeps a transparent pixel's leftover RGB,
     * which reads back as black.
     *
     * @throws UnsupportedOptionException LOSSLESS asked of a JPEG
     * @throws ConversionException        an unknown format, or GD failing to encode
     */
    public static function encode(\GdImage $image, string $extension_c, ConversionOptions $options, string $reader_c, string $description_c): string
    {
        $quality       = $options->quality(self::DEFAULT_QUALITY, $reader_c) ?? self::DEFAULT_QUALITY;
        $lossless_flag = $options->lossless(false, $reader_c);

        if ($lossless_flag && in_array($extension_c, ['jpg', 'jpeg'], true)) {
            throw new UnsupportedOptionException(ConversionOptions::LOSSLESS, true, $description_c);
        }

        ob_start();

        switch ($extension_c) {
            case 'png':
                imagesavealpha($image, true);
                $written = imagepng($image);
                break;

            case 'webp':
                imagesavealpha($image, true);
                $written = imagewebp($image, null, $lossless_flag ? IMG_WEBP_LOSSLESS : $quality);
                break;

            case 'jpg':
            case 'jpeg':
                $flat = imagecreatetruecolor(imagesx($image), imagesy($image));
                imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
                imagealphablending($flat, true);
                imagecopy($flat, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));
                $written = imagejpeg($flat, null, $quality);
                imagedestroy($flat);
                break;

            case 'gif':
                // imagegif() quantizes a truecolour image on its own, and some libgd builds (2.3.3 here)
                // drift a flat colour by a few units — white comes out 252,254,252. Quantize explicitly,
                // then imagecolormatch() sets each palette entry to the average of the pixels it stands for.
                if (imageistruecolor($image)) {
                    $palette = imagecreatetruecolor(imagesx($image), imagesy($image));
                    imagecopy($palette, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));
                    imagetruecolortopalette($palette, false, 256);
                    imagecolormatch($image, $palette);
                    $written = imagegif($palette);
                    imagedestroy($palette);
                } else {
                    $written = imagegif($image);
                }
                break;

            default:
                ob_end_clean();

                throw new ConversionException("No encoder for .{$extension_c}.");
        }

        $bytes = ob_get_clean();

        if ($written === false || $bytes === false || $bytes === '') {
            throw new ConversionException("GD failed to encode as .{$extension_c}.");
        }

        return $bytes;
    }

    /**
     * A BACKGROUND value as GD's [red, green, blue, alpha] — alpha 0 opaque to
     * 127 transparent, GD's own scale.
     *
     * @return array{0: int, 1: int, 2: int, 3: int}
     * @throws ConversionException anything but '#rrggbb', '#rrggbbaa' or 'transparent'
     */
    public static function color(string $color_c): array
    {
        if ($color_c === 'transparent') {
            return [0, 0, 0, 127];
        }
        if (preg_match('/^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})?$/i', $color_c, $m) !== 1) {
            throw new ConversionException("Not a colour: \"{$color_c}\" — expected '#rrggbb', '#rrggbbaa' or 'transparent'.");
        }

        $opacity_n = isset($m[4]) ? hexdec($m[4]) : 255;

        return [(int) hexdec($m[1]), (int) hexdec($m[2]), (int) hexdec($m[3]), 127 - (int) round($opacity_n * 127 / 255)];
    }

    /**
     * The background of <box>: BACKGROUND when given, otherwise the commonest
     * colour along the box's border — where a margin, if there is one, is.
     * When no colour holds at least half the border (content running into
     * the edge, a frame of several colours) the guess is refused rather than
     * made: BACKGROUND says it then.
     *
     * @param  array{x: int, y: int, width: int, height: int} $box
     * @return array{0: int, 1: int, 2: int, 3: int}
     * @throws ConversionException an unreadable BACKGROUND, or a border with no clear background
     */
    public static function background(\GdImage $image, array $box, ConversionOptions $options, string $reader_c): array
    {
        $given = $options->background_code($reader_c);
        if ($given !== null) {
            return self::color($given);
        }

        $right  = $box['x'] + $box['width'] - 1;
        $bottom = $box['y'] + $box['height'] - 1;
        $counts = [];
        for ($x = $box['x']; $x <= $right; $x++) {
            $counts[imagecolorat($image, $x, $box['y'])] = ($counts[imagecolorat($image, $x, $box['y'])] ?? 0) + 1;
            $counts[imagecolorat($image, $x, $bottom)]   = ($counts[imagecolorat($image, $x, $bottom)] ?? 0) + 1;
        }
        for ($y = $box['y']; $y <= $bottom; $y++) {
            $counts[imagecolorat($image, $box['x'], $y)] = ($counts[imagecolorat($image, $box['x'], $y)] ?? 0) + 1;
            $counts[imagecolorat($image, $right, $y)]    = ($counts[imagecolorat($image, $right, $y)] ?? 0) + 1;
        }
        arsort($counts);
        $pixel = (int) array_key_first($counts);

        if ($counts[$pixel] * 2 < array_sum($counts)) {
            throw new ConversionException('No colour holds even half of the border — the background is unclear; say it with BACKGROUND.');
        }

        return [($pixel >> 16) & 0xFF, ($pixel >> 8) & 0xFF, $pixel & 0xFF, ($pixel >> 24) & 0x7F];
    }

    /**
     * For each row and each column of <box>, the share of its pixels that
     * differ from <background> by more than <tolerance_perc> (0-100, on the
     * largest of the red, green, blue and alpha differences) — the profile
     * Spans reads. One pass over the box. A fully transparent pixel on a
     * fully transparent background is background, whatever RGB it carries.
     *
     * @param  array{x: int, y: int, width: int, height: int} $box
     * @param  array{0: int, 1: int, 2: int, 3: int}          $background
     * @return array{rows: list<float>, columns: list<float>}
     * @throws ConversionException a tolerance outside 0-100
     */
    public static function profiles(\GdImage $image, array $box, array $background, int $tolerance_perc): array
    {
        if ($tolerance_perc < 0 || $tolerance_perc > 100) {
            throw new ConversionException("GAP_TOLERANCE is 0-100: {$tolerance_perc}.");
        }

        [$red, $green, $blue, $alpha] = $background;
        $rows    = array_fill(0, $box['height'], 0);
        $columns = array_fill(0, $box['width'], 0);

        for ($j = 0; $j < $box['height']; $j++) {
            for ($i = 0; $i < $box['width']; $i++) {
                $pixel = imagecolorat($image, $box['x'] + $i, $box['y'] + $j);
                if ($alpha === 127 && (($pixel >> 24) & 0x7F) === 127) {
                    continue;   // fully transparent on fully transparent: whatever RGB it carries is invisible
                }
                $distance_n = max(
                    abs((($pixel >> 16) & 0xFF) - $red) * 100 / 255,
                    abs((($pixel >> 8) & 0xFF) - $green) * 100 / 255,
                    abs(($pixel & 0xFF) - $blue) * 100 / 255,
                    abs((($pixel >> 24) & 0x7F) - $alpha) * 100 / 127
                );
                if ($distance_n > $tolerance_perc) {
                    $rows[$j]++;
                    $columns[$i]++;
                }
            }
        }

        return [
            'rows'    => array_map(static fn (int $n): float => $n / $box['width'], $rows),
            'columns' => array_map(static fn (int $n): float => $n / $box['height'], $columns),
        ];
    }
}
