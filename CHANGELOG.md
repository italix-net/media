# Changelog — italix/media

Format: [Keep a Changelog](https://keepachangelog.com/). Versioning policy: `VERSIONING.md` at the
project root.

## [2.3.1] — 2026-09-27

### Changed (development only)

- `require-dev`: `italix/documents` `^0.16` (was `^0.7 || ^0.8`) — the published release the cookbook suites
  now run and pass against.

### Fixed

**`GdImageConverter` encodes a palette image as WebP.** `imagewebp()` refuses a palette image — every
GIF, and any 8-bit PNG — and the driver threw "GD failed to encode as .webp". It now converts to true
colour first, a transparent index becoming alpha. Found importing saved web pages into
`italix/documents` (0.13.0's `SingleFileImporter`), where such images were kept rather than
re-encoded. `tests/GdImageConverterTest.php` (+3): a palette PNG, the same with a transparent colour
(lossless, exact), a GIF; all three fail against 2.3.0.

**`ImageGridMerger` writes a GIF's flat colours exactly.** `imagegif()` quantizes a truecolour canvas
by itself, and libgd 2.3.3 drifts a flat colour by a few units: the white gutter came out 252,254,252
and `ImageGridMergerTest`'s "GIF by default: the gutter is white" failed on that host. `GdImages::encode()`
now quantizes explicitly and `imagecolormatch()` resets each palette entry to the average of the pixels
it stands for. `GdImageConverter`'s own GIF path is unchanged.

## [2.3.0] — 2026-09-26

### Changed

Reads `ConversionOptions` by the names `italix/converters` 0.7.0 gives them — `quality()`,
`page_i()`, `align_x_perc()`, `align_y_perc()`, `gap_tolerance_perc()` — and names its own values the
same way (`$page_i`, `$align_x_perc`, `$tolerance_perc`). No behaviour changes. Requires
`italix/converters` `^0.7`.



### Fixed

**`GdImageConverter` and `PdfPageToImageConverter` read driver-scoped values.** Both read their
options unscoped (`quality_n(self::QUALITY_N)`, `page_n(1)`), so a value set for them alone —
`with_quality(10, GdImageConverter::class)`, the very example `italix/converters` 0.4.0 gives for
scoping — was never seen, and the driver silently used its default. Every read now names the driver
(`static::class`), as the three drivers of 2.2.0 already did: a value scoped to this driver wins over
the unscoped one, a value scoped to another driver stays invisible. Found writing the README's
Cookbook. `tests/GdImageConverterTest.php` (+5) and `tests/PdfPageToImageConverterTest.php` (+2)
cover WIDTH, QUALITY, LOSSLESS and PAGE scoped both ways; all five new checks fail against 2.2.0.

## [2.2.0] — 2026-09-26

### Added

**`ImageExtractor`** (`Italix\Converters\Extractor`) — regions taken out of an image, via `ext-gd`:
one rectangle (one explicit boundary), several (`[x, y, width, height]` each, after any crop keys), a
declared grid (`GridGeometry::cells()`), or a detected one (`GRID_COLUMNS`/`GRID_ROWS` = `'auto'`):
runs of background at least `GAP_MIN` long (default 3px), rows first and then the columns in each
row, so an irregular page of panels comes apart too. `GRID_SKIP_EMPTY`, `GRID_EXPECT`, and a locator
on every piece. The background is `BACKGROUND`, or the commonest colour along the border — refused
when no colour holds half of it. Measured 2026-09-26 at nice 15: detection 3.0s on a 2000x2000
sheet, 8.4s on 4000x3000; a declared grid 0.3s and 0.8s.

**`ImageGridMerger`** (`Italix\Converters\Merger`) — images put together on one sheet,
`GridGeometry::canvas()`: never scales (a piece larger than its pixel track is an error), `ALIGN_X/Y`
(0-100, refused outside) inside a cell, `BACKGROUND` elsewhere — transparent by default, white for
JPEG and GIF. Pixels copied, not blended. With the options that took a sheet apart and its
`BACKGROUND`, the same sheet back, pixel for pixel on PNG and on lossless WebP.

`GdImages` — what the three drivers share on top of `ext-gd`; `@internal`.

### Changed

**`ImageTrimmer` is adaptive** when `$amount` is null and no crop key is given: the image cut to its
real content, through `Italix\Converters\Spans` — what `Trimmer`'s docblock promised for that case.
Up to 2.1.0 the same call returned the image unchanged, a gap documented on the class; a caller that
relied on it gets the content box now, and an image that is nothing but background throws. Any crop
key keeps the declared path exactly as before. The one existing test that called it with no options
(to check the format is kept) now passes `CROP_LEFT = 0`, the declared identity it meant.

Requires `italix/converters` `^0.6`.

### Verification

`tests/ImageExtractorTest.php` (44), `tests/ImageGridMergerTest.php` (25), `tests/ImageTrimmerTest.php`
(25, from 13) — real `ext-gd` calls on sheets painted with GD itself, every cell its own colour at
boxes computed by hand. An adversarial review (mutation testing, fuzzing, edge cases) found five
bugs before release — palette images read as indices by the adaptive trim, WebP never lossless,
unguarded canvas allocation, huge track counts exhausting memory, `ALIGN` outside 0-100 losing
pieces — and gaps in what the tests pinned; each fixed, and each pinned by a test proven to fail
without its fix.

## [2.1.0] — 2026-09-03

### Added

**`ImageTrimmer`** (`Italix\Converters\Trimmer`) — the first real `Trimmer` driver, and the first
consumer of `italix/converters` 0.5.0's `CropGeometry`. In-process via `ext-gd`'s `imagecrop()`, same
reasoning as `GdImageConverter` for staying off a subprocess. All the actual crop geometry (which
pixels survive) is `CropGeometry`'s job, read from `ConversionOptions` — this driver only decodes,
hands the real pixel dimensions over, and applies the box it gets back. `$amount` (`Trimmer`'s own
parameter) is unused by this driver; every crop-geometry key is shared plumbing on `ConversionOptions`
now, not a driver-specific shape.

Explicitly does **not** implement the "adaptive, no options given" content-detection default
`Trimmer`'s own docblock describes — an omitted crop-geometry option resolves to `CropGeometry`'s
identity case (the untouched full frame), not a "figure out the real content bounds" pass. Documented
directly on the class rather than left to be discovered, since it is a real gap against what the
interface's docblock promises for the "amount is null" case.

`tests/ImageTrimmerTest.php` — 13 assertions, against real `ext-gd` calls on a real, decodable fixture
(a solid-color canvas with one marker pixel placed at a known coordinate), checking that a crop meant
to keep a region really keeps the marker pixel and a crop meant to remove it really removes it —
proving the actual box `CropGeometry` computed was applied, not just that some smaller image came
back. Requires `italix/converters` `^0.5` now (was `^0.3`).

## [2.0.1] — 2026-08-28

### Changed

No change to this library's own public API — `GdImageConverter` and `PdfPageToImageConverter`
keep the exact same signatures. Internal calls updated to match `italix/converters` 0.3.0's
renamed `ConvertedDocument`/`ConversionOptions`/exception accessors (`extension_c()` →
`extension_code()`, `mime_c()` → `mime()`, `colorspace_c()` → `colorspace_code()`). Requires
`italix/converters` `^0.3` now (was `^0.2`).

## [2.0.0] — 2026-08-27

### Added

- **`GdImageConverter` now reads four `ConversionOptions`**: `QUALITY` (overrides the `QUALITY_N`
  default for jpg/webp), `LOSSLESS` (webp only — GD's `IMG_WEBP_LOSSLESS`, verified directly to
  produce byte-exact round trips, unlike any quality value; `lossless=true` against a jpg/jpeg target
  throws `UnsupportedOptionException` rather than silently encoding lossy, since JPEG has no lossless
  mode GD can reach), and `WIDTH`/`HEIGHT` (resizes the decoded image before encoding — one dimension
  alone preserves aspect ratio via GD's own `imagescale()`, both together is an exact fit).
- **`PdfPageToImageConverter` now reads `PAGE`** (1-based), passed straight through to Ghostscript's
  `-dFirstPage`/`-dLastPage` — this closes the "only the first page" limitation named in `[1.1.0]`,
  which existed specifically because `ConversionOptions` didn't yet. A page past the end of the
  document is not a separate case to detect: verified directly, Ghostscript exits `0` for it (a
  warning on stderr) rather than a nonzero exit — a real surprise, worth naming so nobody "fixes" the
  exit-code check later — but it still writes no output file, and `convert()` already treats that as
  failure regardless of exit code.
- Neither driver reads `COLORSPACE` or `ICC_PROFILE`: `ext-gd` has no CMYK or profile support at all.
  A driver that can — Imagick, with a genuine embedded ICC profile — is future work, not scoped here.

### Changed — BREAKING

- **`GdImageConverter::convert()` and `PdfPageToImageConverter::convert()` both gained a required
  fourth parameter**, `Italix\Converters\ConversionOptions $options`, following `italix/converters`
  0.2.0's contract change. Bumped `2.0.0`, not a `1.x` minor: unlike `italix/documents` and
  `italix/converters` themselves, this library already declared `1.0.0` — a real stability promise —
  so a required-parameter change here is a genuine MAJOR under `VERSIONING.md`'s own table, not a
  judgment call to soften.
- Requires `italix/converters` `^0.2` now (was `^0.1`); `require-dev`'s `italix/documents` bumped to
  `^0.4` to match (the milestone test in `tests/PdfPageToImageConverterTest.php` calls Documents'
  drivers directly).

## [1.1.0] — 2026-08-27

### Added

- **`PdfPageToImageConverter`** — the first page of a PDF, rasterized to PNG at 150 dpi, via
  Ghostscript shelled out to directly. Not the first approach tried: `ext-imagick`'s own PDF delegate
  was tried first and rejected after verifying directly that ImageMagick's default Debian/Ubuntu
  security policy blocks the PDF coder outright, which would have meant this driver silently reporting
  "unavailable" on a large share of real deployments for a reason unrelated to Ghostscript actually
  being present. This is the first subprocess-based driver in the library; every other driver so far
  is in-process. `ConversionException`'s `driver_stderr_c()` field — present in `italix/converters`
  since it shipped, never yet populated by a real driver — is exercised for real here for the first
  time, carrying Ghostscript's actual stderr on a genuinely broken input.
- **Closes the cross-library gap this library's own `[1.0.0]` entry named**: registering
  `MarkdownToHtmlConverter` and `HtmlToPdfConverter` (`italix/documents`) alongside
  `PdfPageToImageConverter` in one `ConverterSet` routes `md → html → pdf → png` automatically — three
  drivers, two libraries that import nothing from each other. Proven in
  `tests/PdfPageToImageConverterTest.php`, not just described.
- Added a WebP-transparency test to `GdImageConverterTest.php` — alpha survives the round trip, unlike
  the deliberate JPEG flattening. Not previously covered.

## [1.0.0] — 2026-08-27

### Added

- **New library.** `1.0.0` per `VERSIONING.md`'s house rule 13 — nothing depends on this library yet,
  so there is nothing a first release could break. Not a claim that the library is feature-complete:
  its own README says so directly ("one driver").
- **`GdImageConverter`** — the first driver, and the first `italix/converters` driver in this
  codebase that isn't a thin wrapper over pre-existing logic. `png`/`jpg`/`jpeg`/`gif`/`webp`,
  pairwise, via `ext-gd`, in-process. Decodes by the bytes' own signature rather than trusting the
  claimed extension. Encoding to JPEG unconditionally flattens onto a white background first — a
  real bug this driver exists specifically to avoid, not a hypothetical one: GD silently keeps
  whatever RGB value a transparent pixel happened to carry when encoding straight to JPEG, which
  reads back as solid black more often than not. Verified directly before writing the fix, not
  assumed from documentation.

### Not included yet

Audio and video drivers — named in this library's own `composer.json` description as its eventual
scope, not built. No cross-library route from `italix/documents`' formats (md/html/pdf) into this
library's formats exists yet either; that needs a driver on one side or the other sharing an
intermediate format (a PDF-page-to-PNG driver would be the natural bridge), and nothing here does
that.
