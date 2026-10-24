<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Media - ImageTrimmer
 *
 * @package Italix\Media
 */

declare(strict_types=1);

namespace Italix\Media;

use Italix\Converters\ConversionException;
use Italix\Converters\ConversionOptions;
use Italix\Converters\ConvertedDocument;
use Italix\Converters\CropGeometry;
use Italix\Converters\Spans;
use Italix\Converters\Trimmer;

/**
 * Crops a raster image — via ext-gd, in-process, same reasoning as
 * GdImageConverter for staying off a subprocess. All the actual geometry
 * (which pixels to keep, from WIDTH/HEIGHT/RATIO/ALIGN_X/ALIGN_Y/CROP_LEFT/
 * CROP_RIGHT/CROP_TOP/CROP_BOTTOM) is `Italix\Converters\CropGeometry`'s
 * job, shared with any future video driver that crops per-frame geometry
 * the same way — this class only ever decodes, hands the real pixel
 * dimensions to CropGeometry, and calls imagecrop() with the box it
 * returns.
 *
 * `$amount` (Trimmer's own parameter) is not used by this driver: all
 * configuration comes through `ConversionOptions`, read by CropGeometry,
 * because the crop-geometry keys are shared across formats and belong on
 * the shared options bag, not on a driver-specific `$amount` shape. Passed
 * anyway, ignored, per Converter/Trimmer's own contract that an
 * unrecognized parameter is not an error.
 *
 * **Adaptive** when `$amount` is null and no crop-geometry key is given at
 * all (WIDTH, HEIGHT, RATIO, CROP_*): what Trimmer's docblock promises for
 * an omitted amount — the image cut to its real content. The background is
 * BACKGROUND, or the commonest colour along the border; content is any pixel
 * farther from it than GAP_TOLERANCE (default 10, of 100), found through
 * Italix\Converters\Spans, the same profile a grid is detected with. An
 * image that is nothing but background is an error: there is no content to
 * cut to. (Up to 2.1.0 this case returned the image unchanged — a documented
 * gap, now closed.) Any crop key given keeps the CropGeometry path, exactly
 * as before.
 */
final class ImageTrimmer implements Trimmer
{
    private const FORMATS = ['png', 'jpg', 'jpeg', 'gif', 'webp'];

    private const MIME = [
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
    ];

    public function extensions(): array
    {
        return self::FORMATS;
    }

    public function is_available(): bool
    {
        return extension_loaded('gd') && function_exists('imagecrop');
    }

    public function trim(string $source, string $extension_c, mixed $amount, ConversionOptions $options): ConvertedDocument
    {
        $decoded = @imagecreatefromstring($source);

        if ($decoded === false) {
            throw new ConversionException("GD could not decode this as an image (claimed .{$extension_c}).");
        }

        try {
            $box     = $amount === null && !self::has_crop_keys($options)
                ? self::content_box($decoded, $options)
                : CropGeometry::resolve(imagesx($decoded), imagesy($decoded), $options, static::class);
            $cropped = imagecrop($decoded, $box);

            if ($cropped === false) {
                throw new ConversionException('GD refused this crop box: ' . json_encode($box) . '.');
            }

            try {
                $bytes = $this->encode($cropped, $extension_c);
            } finally {
                imagedestroy($cropped);
            }
        } finally {
            imagedestroy($decoded);
        }

        return new ConvertedDocument($bytes, $extension_c, self::MIME[$extension_c]);
    }

    public function describe(): string
    {
        return implode('/', self::FORMATS) . ' -> same format, cropped (ext-gd, geometry via ' . CropGeometry::class . ')';
    }

    /** Whether any key CropGeometry reads is given — then the crop is declared, not detected. ALIGN_* alone only places a declared cut. */
    private static function has_crop_keys(ConversionOptions $options): bool
    {
        foreach ([
            ConversionOptions::WIDTH,
            ConversionOptions::HEIGHT,
            ConversionOptions::RATIO,
            ConversionOptions::CROP_LEFT,
            ConversionOptions::CROP_RIGHT,
            ConversionOptions::CROP_TOP,
            ConversionOptions::CROP_BOTTOM,
        ] as $key_c) {
            if ($options->has($key_c, static::class)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The box of the image's real content.
     *
     * @return array{x: int, y: int, width: int, height: int}
     * @throws ConversionException an image that is nothing but background
     */
    private static function content_box(\GdImage $image, ConversionOptions $options): array
    {
        imagepalettetotruecolor($image);   // a palette image (GIF, PNG-8) answers imagecolorat() with indices, not colours
        $whole    = ['x' => 0, 'y' => 0, 'width' => imagesx($image), 'height' => imagesy($image)];
        $profiles = GdImages::profiles(
            $image,
            $whole,
            GdImages::background($image, $whole, $options, static::class),
            $options->gap_tolerance_perc(10, static::class)
        );
        $rows    = Spans::content($profiles['rows']);
        $columns = Spans::content($profiles['columns']);

        if ($rows === null || $columns === null) {
            throw new ConversionException('Nothing but background: there is no content to trim to.');
        }

        return ['x' => $columns[0], 'y' => $rows[0], 'width' => $columns[1] - $columns[0] + 1, 'height' => $rows[1] - $rows[0] + 1];
    }

    /** @param \GdImage $image */
    private function encode($image, string $extension_c): string
    {
        ob_start();

        switch ($extension_c) {
            case 'png':
                imagesavealpha($image, true);
                $written = imagepng($image);
                break;

            case 'webp':
                imagesavealpha($image, true);
                $written = imagewebp($image);
                break;

            case 'jpg':
            case 'jpeg':
                $written = imagejpeg($image);
                break;

            case 'gif':
                $written = imagegif($image);
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
}
