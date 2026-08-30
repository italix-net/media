# italix/media

[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.1-8892BF.svg)](https://php.net/)
[![License](https://img.shields.io/badge/license-MPL%202.0-blue.svg)](LICENSE)

Image, audio and video format-conversion drivers for [`italix/converters`](../Converters) — the
peer of `italix/documents` for everything that isn't a text document.

```php
use Italix\Converters\ConversionOptions;
use Italix\Converters\ConverterSet;
use Italix\Media\GdImageConverter;

$set = new ConverterSet([new GdImageConverter()]);

$set->convert($png_bytes, 'png', 'webp');
$set->convert($png_bytes, 'png', 'webp', ConversionOptions::none()->with_quality(95)->with_width(800));
```

> **Status: two drivers.** `GdImageConverter` — png/jpg/jpeg/gif/webp, pairwise, via `ext-gd`.
> `PdfPageToImageConverter` — the first page of a PDF to PNG, via Ghostscript. Audio and video are
> named in the description because that is this library's eventual scope, not because anything for
> them exists yet — nothing here should be read as a promise of when.

## The idea

This library doesn't own the conversion contract or the routing — `italix/converters` does, and
`italix/documents` depends on the exact same contract for its own formats. Neither library needs to
know the other exists: an application that wants Markdown rendered to a thumbnail image registers
both libraries' drivers into one shared `ConverterSet` and lets it route across the boundary. See
`italix/converters`' README, "Cooperating with other libraries" — and see
`tests/PdfPageToImageConverterTest.php`'s last section for that exact chain proven for real:
`md → html → pdf → png`, three drivers, two libraries that never import each other, one
`ConverterSet` built by whoever is wiring the application together.

## Installation

```bash
composer require italix/media
```

Requires PHP 8.1+ and `italix/converters`. Each driver brings its own optional dependency —
`GdImageConverter` needs `ext-gd`, listed under `suggest`, not `require`: this library still loads
without it, `is_available()` just reports image conversion as unreachable.

## `GdImageConverter`

`png` / `jpg` / `jpeg` / `gif` / `webp`, every directed pair, in-process via `ext-gd` — no subprocess,
no binary to install, the same reasoning `HtmlToPdfConverter` already applies by preferring dompdf
over shelling out to a browser.

Two decisions worth knowing about:

- **Decoding reads the bytes, not the label.** `imagecreatefromstring()` detects the real format
  from the file's own signature rather than trusting `$from_extension_c`. A mislabeled file still
  decodes correctly; only genuinely undecodable bytes fail. This is the opposite of how
  `Italix\Documents\Renderer` picks a renderer, and deliberately so — a raster format's header is
  strictly more trustworthy than a filename, where Markdown's plain text has no header to trust
  instead.
- **JPEG output always flattens onto white first**, whether or not the source actually has
  transparency. This isn't defensive over-caution: GD does not error when a transparent image is
  encoded straight to JPEG, it silently keeps whatever RGB value a transparent pixel happened to
  carry — verified directly, a pixel filled `rgba(0,0,0, alpha=127)` and encoded to JPEG without
  flattening decodes back as solid black. Flattening an already-opaque image onto white first is a
  no-op, so there's no reason to detect alpha before deciding whether to do it.

Reads four `ConversionOptions`: `QUALITY` (jpg/webp, overrides the built-in default), `LOSSLESS`
(webp only — GD's `IMG_WEBP_LOSSLESS`, verified directly to produce byte-exact round trips; requesting
it against a jpg/jpeg target throws `UnsupportedOptionException`, since JPEG has no lossless mode GD
can reach), and `WIDTH`/`HEIGHT` (resizes before encoding — one dimension alone preserves aspect
ratio, both together is an exact fit, not a fit-within). Does not read `COLORSPACE` or `ICC_PROFILE`:
`ext-gd` has no CMYK or profile support to act on.

## `PdfPageToImageConverter`

The first page of a PDF, rasterized to `png` at 150 dpi — via Ghostscript (the `gs` binary), shelled
out to directly. Only one pair: `pdf → png`. Reaching `jpg` or `webp` from a PDF isn't this driver's
job — register `GdImageConverter` alongside it in the same `ConverterSet`, and a two-hop route
(`pdf → png → jpg`) falls out of routing for free.

This is the one driver in the library that isn't in-process, and not by first choice.
`ext-imagick` is available and ships a PDF delegate that could do the same thing without a
subprocess — it was tried first, and it fails: ImageMagick's default security policy on Debian/Ubuntu
blocks the PDF coder outright (a hardening response to a history of Ghostscript RCEs reachable through
ImageMagick's PDF path), verified directly rather than assumed. That policy is common enough on real
deployments that depending on Imagick's PDF delegate would mean this driver silently reporting
"unavailable" on a large fraction of the machines it might actually run on. Calling Ghostscript
directly sidesteps the policy entirely, at the cost of being a subprocess.

Requires the `gs` binary on `$PATH` — a system package (`ghostscript` on Debian/Ubuntu), not
something Composer can install or declare. `is_available()` checks for it directly.

Defaults to the first page — the common preview/thumbnail case — but reads
`ConversionOptions::PAGE` (1-based) when given, passed straight through to Ghostscript's
`-dFirstPage`/`-dLastPage`. A page past the end of the document is not a separate case to detect:
verified directly, Ghostscript exits `0` for that (a warning on stderr) rather than failing loudly —
a real surprise — but it still writes no output file, which `convert()` already treats as failure
regardless of exit code.

## Options: how this library thinks about per-conversion knobs

Both drivers take a `ConversionOptions` on every `convert()` call — see `italix/converters`' README,
"Options," for the full mechanism. What's specific to this library:

- **`GdImageConverter`** reads `QUALITY`, `LOSSLESS`, `WIDTH`, `HEIGHT` — all real, load-bearing
  behavior, not plumbing waiting for a future driver. See the section above.
- **`PdfPageToImageConverter`** reads `PAGE` — this is *why* `ConversionOptions` exists at all: it
  was a hardcoded "first page only" limitation, named as such, until the contract had somewhere to
  put a page number.
- **Neither driver reads `COLORSPACE` or `ICC_PROFILE`.** `ext-gd` has no CMYK support at all — it's
  an RGB(A) library end to end — so `GdImageConverter` cannot honor a colorspace request no matter
  what the contract allows; there is nothing to check against, so it doesn't try. Real, correct CMYK
  needs Imagick with a genuine embedded ICC profile (naive RGB→CMYK math produces wrong colors for
  print, which is the entire reason ICC profiles exist) — a materially different toolchain from
  anything in this library today, and worth its own driver designed against a real print workflow's
  actual requirements, not built in the abstract ahead of one existing. Contrast with
  `Italix\Documents\HtmlToPdfConverter`, which *does* read `COLORSPACE`: dompdf can only ever produce
  RGB, so an explicit non-RGB request there throws `UnsupportedOptionException` rather than silently
  returning RGB anyway — the same principle, applied to a driver that has an opinion to enforce even
  though it still can't produce the thing that was asked for.

## Verification

Assertions in `tests/GdImageConverterTest.php` and `tests/PdfPageToImageConverterTest.php`, run with:

```bash
composer test
```

Against real `ext-gd` and real Ghostscript calls throughout, not fakes — including the
transparent-to-JPEG flattening behavior and WebP's alpha preservation, both reproduced and checked
pixel by pixel; `LOSSLESS`'s byte-exact round trip versus a lossy one that visibly is not; `WIDTH`/
`HEIGHT` resizing checked by decoding the actual output dimensions; `PAGE` proven against a genuine
2-page PDF (CSS `page-break-before`) where page 1 and page 2 render different, verified content, not
just "doesn't crash"; a real registered-in-a-`ConverterSet` round trip (`png → webp → png`); a
genuinely invalid PDF driving `PdfPageToImageConverter` into its real failure path,
`ConversionException` carrying Ghostscript's actual stderr; and the full cross-library
`md → html → pdf → png` chain.

## License

Mozilla Public License 2.0 — see `LICENSE`.
