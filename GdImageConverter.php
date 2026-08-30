<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Media - GdImageConverter
 *
 * @package Italix\Media
 */

declare(strict_types=1);

namespace Italix\Media;

use Italix\Converters\ConversionException;
use Italix\Converters\ConversionOptions;
use Italix\Converters\ConvertedDocument;
use Italix\Converters\Converter;
use Italix\Converters\UnsupportedOptionException;

/**
 * Raster image format conversion — png/jpg/jpeg/gif/webp, pairwise — via
 * ext-gd. In-process, no subprocess, no binary to install: the same reason
 * Italix\Documents\HtmlToPdfConverter prefers dompdf over shelling out to a
 * browser.
 *
 * Decoding is format-agnostic on purpose: imagecreatefromstring() reads by
 * the bytes' own signature, not by trusting $from_extension_c's label. A
 * mislabeled file (a `.png` that is actually a JPEG) still decodes
 * correctly; only genuinely undecodable bytes fail. This is the opposite
 * stance from Italix\Documents\Renderer, which deliberately picks by
 * extension — there, the extension IS the author's declared intent for a
 * text format with no reliable magic bytes of its own; here, a raster
 * format's header is strictly more trustworthy than a filename, so there is
 * nothing to prefer the label over.
 *
 * Encoding to JPEG always flattens onto an opaque white background first,
 * unconditionally, whether or not the source actually has an alpha
 * channel. This is not a hypothetical: GD does not error when a
 * transparent image is encoded straight to JPEG — it silently keeps
 * whatever RGB value a transparent pixel happened to carry, which reads
 * back as solid black far more often than not (verified directly: a
 * 1x1 image filled with rgba(0, 0, 0, alpha=127), encoded to JPEG without
 * flattening, decodes back as solid black; flattened onto white first, it
 * decodes back as white). Flattening an already-opaque source onto white is
 * a no-op, so there is no reason to detect alpha before deciding whether to
 * do it.
 *
 * ConversionOptions read here: QUALITY (jpg/webp, overrides QUALITY_N),
 * LOSSLESS (webp only — GD's `IMG_WEBP_LOSSLESS`, verified directly to
 * produce byte-exact round trips, unlike any quality value), WIDTH/HEIGHT
 * (resizes the decoded image before encoding; one dimension alone preserves
 * aspect ratio via GD's own imagescale(), both together is an exact fit,
 * not a fit-within). COLORSPACE and ICC_PROFILE are not read: ext-gd has no
 * CMYK or profile support at all, so there is nothing here to check against
 * — a driver built on Imagick would be the one to read those.
 *
 * LOSSLESS=true against a jpg/jpeg target throws UnsupportedOptionException
 * rather than silently encoding lossy: JPEG has no lossless mode GD can
 * reach, so honoring the request silently would mean returning something
 * the caller explicitly said they did not want.
 */
final class GdImageConverter implements Converter
{
    private const FORMATS = ['png', 'jpg', 'jpeg', 'gif', 'webp'];

    private const MIME = [
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
    ];

    /** JPEG and WebP quality. PNG and GIF have no equivalent knob worth exposing here. */
    private const QUALITY_N = 90;

    public function pairs(): array
    {
        $pairs = [];

        foreach (self::FORMATS as $from_c) {
            foreach (self::FORMATS as $to_c) {
                if ($from_c !== $to_c) {
                    $pairs[] = [$from_c, $to_c];
                }
            }
        }

        return $pairs;
    }

    public function is_available(): bool
    {
        return extension_loaded('gd')
            && function_exists('imagewebp')
            && function_exists('imagecreatefromwebp');
    }

    public function convert(
        string $source,
        string $from_extension_c,
        string $to_extension_c,
        ConversionOptions $options
    ): ConvertedDocument {
        $decoded = @imagecreatefromstring($source);

        if ($decoded === false) {
            throw new ConversionException("GD could not decode this as an image (claimed .{$from_extension_c}).");
        }

        $image = self::resize($decoded, $options->width_n(), $options->height_n());

        if ($image !== $decoded) {
            imagedestroy($decoded);
        }

        try {
            $bytes = $this->encode($image, $to_extension_c, $options);
        } finally {
            imagedestroy($image);
        }

        return new ConvertedDocument($bytes, $to_extension_c, self::MIME[$to_extension_c]);
    }

    public function describe(): string
    {
        return implode('/', self::FORMATS)
            . ' <-> each other (ext-gd, JPEG flattened onto white, WebP lossless supported, RGB only)';
    }

    /** @param \GdImage $image */
    private function encode($image, string $to_extension_c, ConversionOptions $options): string
    {
        $quality_n     = $options->quality_n(self::QUALITY_N);
        $lossless_flag = $options->lossless(false);

        ob_start();
        $flattened = null;

        switch ($to_extension_c) {
            case 'png':
                imagesavealpha($image, true);
                $written = imagepng($image);
                break;

            case 'webp':
                imagesavealpha($image, true);
                $written = imagewebp($image, null, $lossless_flag ? IMG_WEBP_LOSSLESS : $quality_n);
                break;

            case 'jpg':
            case 'jpeg':
                if ($lossless_flag) {
                    ob_end_clean();

                    throw new UnsupportedOptionException(ConversionOptions::LOSSLESS, true, $this->describe());
                }

                $flattened = self::flatten_to_white($image);
                $written   = imagejpeg($flattened, null, $quality_n);
                break;

            case 'gif':
                $written = imagegif($image);
                break;

            default:
                ob_end_clean();

                throw new ConversionException("No encoder for .{$to_extension_c}.");
        }

        $bytes = ob_get_clean();

        if ($flattened !== null) {
            imagedestroy($flattened);
        }

        if ($written === false || $bytes === false || $bytes === '') {
            throw new ConversionException("GD failed to encode as .{$to_extension_c}.");
        }

        return $bytes;
    }

    /**
     * Resizes when either dimension was requested; returns $image unchanged
     * otherwise, including the identity case (no allocation for the common
     * "no resize requested" path).
     *
     * One dimension alone preserves aspect ratio — GD's own imagescale()
     * does this natively from a width, and there is no equivalent shortcut
     * from a height alone, so that case computes the proportional width by
     * hand. Both dimensions together is an exact fit, not a fit-within: the
     * caller asked for a specific size and gets exactly that, distortion
     * included if the aspect ratio does not match.
     *
     * @param \GdImage $image
     * @return \GdImage the resized image, or $image itself when untouched
     */
    private static function resize($image, ?int $width_n, ?int $height_n)
    {
        if ($width_n === null && $height_n === null) {
            return $image;
        }

        if ($width_n !== null && $height_n !== null) {
            $resized = imagescale($image, $width_n, $height_n);
        } elseif ($width_n !== null) {
            $resized = imagescale($image, $width_n);
        } else {
            $aspect_width_n = (int) round(imagesx($image) * ($height_n / imagesy($image)));
            $resized        = imagescale($image, $aspect_width_n, $height_n);
        }

        if ($resized === false) {
            throw new ConversionException("GD failed to resize to width={$width_n}, height={$height_n}.");
        }

        return $resized;
    }

    /**
     * @param \GdImage $image
     * @return \GdImage a new, opaque, white-backed copy — the caller destroys it
     */
    private static function flatten_to_white($image)
    {
        $width  = imagesx($image);
        $height = imagesy($image);
        $flat   = imagecreatetruecolor($width, $height);

        imagefilledrectangle($flat, 0, 0, $width, $height, imagecolorallocate($flat, 255, 255, 255));
        imagealphablending($flat, true);
        imagecopy($flat, $image, 0, 0, 0, 0, $width, $height);

        return $flat;
    }
}
