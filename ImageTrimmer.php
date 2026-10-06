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
 * `null` for every crop-geometry option (the ConversionOptions default) is
 * CropGeometry's own identity case — the full, untouched frame — not this
 * driver's promised "adaptive content-detection" default from Trimmer's
 * own docblock. This driver does not implement adaptive trimming; a caller
 * that wants "figure out the real content bounds yourself" gets an
 * unchanged image back, not an error, but also not what the interface's
 * own docblock describes as the meaning of an omitted amount elsewhere —
 * worth calling out explicitly rather than leaving it to be discovered.
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
            $box     = CropGeometry::resolve(imagesx($decoded), imagesy($decoded), $options, static::class);
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
