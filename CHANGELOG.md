# Changelog — italix/media

Format: [Keep a Changelog](https://keepachangelog.com/). Versioning policy: `VERSIONING.md` at the
project root.

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
