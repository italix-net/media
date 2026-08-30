<?php
/*
 * This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/.
 */
/**
 * Italix Media - PdfPageToImageConverter
 *
 * @package Italix\Media
 */

declare(strict_types=1);

namespace Italix\Media;

use Italix\Converters\ConversionException;
use Italix\Converters\ConversionOptions;
use Italix\Converters\ConvertedDocument;
use Italix\Converters\Converter;

/**
 * The first page of a PDF, rasterized to PNG — via Ghostscript, shelled out
 * to directly. This is the one driver in this library that isn't
 * in-process, and not by initial preference.
 *
 * ext-imagick is installed on this machine and ships a PDF delegate that
 * could do the same thing without a subprocess — it was the first thing
 * tried here. It fails: ImageMagick's default security policy on
 * Debian/Ubuntu blocks the PDF coder outright
 * (`<policy domain="coder" rights="none" pattern="PDF" />` in
 * `/etc/ImageMagick-6/policy.xml`, a hardening response to a history of
 * Ghostscript RCEs reachable through ImageMagick's PDF path). Verified
 * directly, not assumed from documentation: even a trivial PDF blob throws
 * `ImagickException: ... not allowed by the security policy 'PDF'` before
 * Ghostscript is ever invoked. That policy is exactly the kind of decision
 * this codebase has already argued a driver must not silently loosen from
 * its own constructor (see HtmlToPdfConverter's docblock on dompdf's
 * defaults) — and it is common enough on real Debian/Ubuntu deployments
 * that depending on Imagick's PDF delegate would mean this driver silently
 * reporting "unavailable" on a large fraction of machines it might run on,
 * for a reason with nothing to do with Ghostscript being absent.
 *
 * Calling Ghostscript directly sidesteps that coder policy entirely — the
 * restriction lives in ImageMagick's delegate path, not in Ghostscript
 * itself — at the cost of being a subprocess rather than an in-process
 * call. `-dSAFER` is passed explicitly (Ghostscript's own sandboxing,
 * restricting file operations a malicious PDF's content could otherwise
 * trigger) even though modern Ghostscript defaults to it: the same
 * "explicit, not inherited" stance HtmlToPdfConverter already takes with
 * its paper size.
 *
 * Only one pair: pdf -> png. Reaching jpg or webp from a PDF is not this
 * driver's job — register GdImageConverter alongside it in the same
 * ConverterSet, and a two-hop route (pdf -> png -> jpg) falls out of
 * ConverterSet's own routing for free, with no second copy of
 * GdImageConverter's encoding logic needed here. (Ghostscript's `png16m`
 * device produces fully opaque 24-bit output with no alpha channel, so
 * that particular route never actually exercises GdImageConverter's
 * JPEG-flattening branch — the reuse is real, that specific behavior just
 * isn't what gets proven by it.) See
 * tests/PdfPageToImageConverterTest.php, which proves the full
 * md -> html -> pdf -> png chain across both italix/documents and this
 * library.
 *
 * Defaults to the first page — the common "preview" or "thumbnail" case —
 * but reads ConversionOptions::PAGE (1-based) when given, passed straight
 * through to Ghostscript's -dFirstPage/-dLastPage. This was a hardcoded,
 * named limitation before ConversionOptions existed; it is the reason that
 * option exists at all.
 *
 * A page past the end of the document is not a separate case to detect:
 * verified directly, Ghostscript exits 0 for it (a warning on stderr,
 * "Requested FirstPage is greater than the number of pages") rather than a
 * nonzero exit — surprising enough to be worth naming so nobody "fixes" the
 * exit-code check later. What it reliably does NOT do is write an output
 * file, and convert() already checks for that regardless of exit code, so
 * this still surfaces as the same ConversionException every other
 * Ghostscript failure does, just without stderr saying much.
 */
final class PdfPageToImageConverter implements Converter
{
    private const RESOLUTION_DPI = 150;

    /** Kill a Ghostscript invocation that has not finished within this many seconds. */
    private const TIMEOUT_S = 30;

    public function pairs(): array
    {
        return [['pdf', 'png']];
    }

    public function is_available(): bool
    {
        // `command -v`, not `which`: a POSIX shell builtin everywhere that
        // matters here, with no dependency on a separate `which` package
        // being installed.
        $found = shell_exec('command -v gs 2>/dev/null');

        return is_string($found) && trim($found) !== '';
    }

    public function convert(
        string $source,
        string $from_extension_c,
        string $to_extension_c,
        ConversionOptions $options
    ): ConvertedDocument {
        $page_n = $options->page_n(1);

        $input_path  = tempnam(sys_get_temp_dir(), 'ix-media-pdf-');
        $output_path = tempnam(sys_get_temp_dir(), 'ix-media-png-');

        try {
            file_put_contents($input_path, $source);

            $command = sprintf(
                'timeout %d gs -q -dNOPAUSE -dBATCH -dSAFER -sDEVICE=png16m -r%d'
                    . ' -dFirstPage=%d -dLastPage=%d -sOutputFile=%s %s 2>&1',
                self::TIMEOUT_S,
                self::RESOLUTION_DPI,
                $page_n,
                $page_n,
                escapeshellarg($output_path),
                escapeshellarg($input_path)
            );

            exec($command, $output_lines, $exit_code);

            if ($exit_code !== 0 || !is_file($output_path) || filesize($output_path) === 0) {
                throw new ConversionException(
                    "Ghostscript could not rasterize this PDF (exit {$exit_code}).",
                    implode("\n", $output_lines)
                );
            }

            $bytes = file_get_contents($output_path);
        } finally {
            @unlink($input_path);
            @unlink($output_path);
        }

        if ($bytes === false || $bytes === '') {
            throw new ConversionException('Ghostscript produced no readable output.');
        }

        return new ConvertedDocument($bytes, 'png', 'image/png');
    }

    public function describe(): string
    {
        return 'pdf -> png (Ghostscript, ' . self::RESOLUTION_DPI . ' dpi, subprocess, page 1 unless PAGE is set)';
    }
}
