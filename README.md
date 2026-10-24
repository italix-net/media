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

> **Status: five drivers.** `GdImageConverter` — png/jpg/jpeg/gif/webp, pairwise, via `ext-gd`.
> `PdfPageToImageConverter` — the first page of a PDF to PNG, via Ghostscript. `ImageTrimmer` —
> crops, declared or to the real content. `ImageExtractor` and `ImageGridMerger` — a composite
> image taken apart and put back together. Audio and video are named in the description because
> that is this library's eventual scope, not because anything for them exists yet — nothing here
> should be read as a promise of when.

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

## `ImageTrimmer`

Crops a raster image, `ext-gd`, geometry from `Italix\Converters\CropGeometry` (`WIDTH`, `HEIGHT`,
`RATIO`, `ALIGN_X/Y`, `CROP_*`). With no amount and no crop key it is adaptive: the image cut to its
real content — the background is `BACKGROUND`, or the commonest colour along the border, and a pixel
farther from it than `GAP_TOLERANCE` (default 10, of 100) is content. An image that is nothing but
background is an error.

## `ImageExtractor` and `ImageGridMerger`

Regions taken out of an image — one or many — and a composite put together — geometry from `Italix\Converters\GridGeometry`,
pixels by `ext-gd`:

```php
$sheet = ConversionOptions::none()
    ->with_grid_columns(3)->with_grid_rows(2)
    ->with_grid_gutter_x(8)->with_grid_gutter_y(8)
    ->with_grid_margin_left(16)->with_grid_margin_right(16)
    ->with_grid_margin_top(16)->with_grid_margin_bottom(16)
    ->with_background('#ffffff');

$pieces = (new ImageExtractor())->extract($png, 'png', null, $sheet);   // six pieces, each with its locator()
$again  = (new ImageGridMerger())->merge(array_map(fn ($p) => $p->bytes(), $pieces), 'png', 'png', $sheet);   // the same pixels
```

- **One rectangle**: `extract($png, 'png', [[120, 40, 300, 200]], ConversionOptions::none())` — or
  `ImageTrimmer` with crop keys, when margins, a ratio or an alignment say it more easily.
- **Declared**: the grid keys as above; one column or one row is a strip (`with_grid_rows(4)`).
- **Explicit**: `$boundaries` as `[x, y, width, height]` boxes, after any crop keys.
- **Detected**: `GRID_COLUMNS`/`GRID_ROWS` = `'auto'` — runs of background at least `GAP_MIN`
  (default 3px) long, rows first and then the columns inside each row, so a page whose rows hold
  different numbers of panels comes apart too. Pieces that touch, gradient or photographic
  backgrounds and shadows cannot be detected: declare the grid then, and `GRID_EXPECT` makes a wrong
  count an error instead of a wrong cut.

The merger never scales: a column is as wide as its widest piece (or its pixel track, and a larger
piece is an error), a smaller piece sits where `ALIGN_X`/`ALIGN_Y` put it, and `BACKGROUND` fills
the rest — transparent by default, white for JPEG and GIF.

## Cookbook: `GdImageConverter`

Every example in the four Cookbook sections runs, as written, in `tests/CookbookTest.php` — this one
first.

```php
use Italix\Converters\ConversionOptions;
use Italix\Converters\ConverterSet;
use Italix\Media\GdImageConverter;
use Italix\Media\ImageTrimmer;
use Italix\Media\PdfPageToImageConverter;

$converter = new GdImageConverter();
```

### Formats

```php
$webp = $converter->convert($png, 'png', 'webp', ConversionOptions::none());
// image/webp, the same 1600x900

// A transparent PNG into a JPEG: the transparency becomes white, not black.
$jpg = $converter->convert($cutout, 'png', 'jpg', ConversionOptions::none());

// And back.
$png_again = $converter->convert($webp->bytes(), 'webp', 'png', ConversionOptions::none());
```

### Quality, and lossless

```php
$fine   = $converter->convert($png, 'png', 'jpg', ConversionOptions::none()->with_quality(95));
$coarse = $converter->convert($png, 'png', 'jpg', ConversionOptions::none()->with_quality(40));
// on a busy picture, the second is less than half the size of the first

// WebP can be lossless: back to PNG, every pixel the same.
$exact = $converter->convert($png, 'png', 'webp', ConversionOptions::none()->with_lossless(true));
```

### Resizing

```php
$converter->convert($png, 'png', 'png', ConversionOptions::none()->with_width(800));
// 1600x900 -> 800x450: one side alone keeps the aspect ratio
$converter->convert($png, 'png', 'png', ConversionOptions::none()->with_height(300));
// -> 533x300
$converter->convert($png, 'png', 'png', ConversionOptions::none()->with_width(400)->with_height(400));
// -> 400x400: both is exactly that size, stretched if the ratio differs

// A square thumbnail without stretching: crop to the ratio first, then resize.
$square    = (new ImageTrimmer())->trim($png, 'png', null, ConversionOptions::none()->with_ratio('1:1'));
$thumbnail = $converter->convert($square->bytes(), 'png', 'webp', ConversionOptions::none()
    ->with_width(256)
    ->with_quality(80));
// 256x256 WebP
```

### What a file really is

```php
// A JPEG saved as photo.png converts all the same: the bytes are read, not the label.
$converter->convert($jpeg_named_png, 'png', 'webp', ConversionOptions::none());
```

### Through a `ConverterSet`

```php
// A PDF's first page as a JPEG: pdf -> png (Ghostscript) -> jpg (GD), routed.
$set     = new ConverterSet([new PdfPageToImageConverter(), new GdImageConverter()]);
$preview = $set->convert($pdf, 'pdf', 'jpg');

// Options reach every hop: WIDTH is read by the GD one.
$small = $set->convert($pdf, 'pdf', 'webp', ConversionOptions::none()->with_width(600));
// a 600px-wide WebP of the first page
```

### When it cannot work, it says so

```php
$converter->convert('not an image at all', 'png', 'webp', ConversionOptions::none());
// ConversionException: GD could not decode this as an image (claimed .png).

$converter->convert($png, 'png', 'jpg', ConversionOptions::none()->with_lossless(true));
// UnsupportedOptionException (option_key_code() 'lossless') — a JPEG cannot be lossless, and is not
// quietly made lossy instead
```

## Cookbook: `ImageExtractor`

Run, as written, in `tests/CookbookTest.php` too, against images painted there — and what each comment
says came back is asserted. When an example here changes, that test changes with it.

```php
use Italix\Converters\ConversionOptions;
use Italix\Media\ImageExtractor;
use Italix\Media\ImageGridMerger;
use Italix\Media\ImageTrimmer;

$extractor = new ImageExtractor();
```

### Declared grids — you know the sheet

```php
// A sprite sheet: 4x4 tiles of 64px, nothing between them.
$sprites = $extractor->extract($png, 'png', null, ConversionOptions::none()
    ->with_grid_columns(4)
    ->with_grid_rows(4));
// 16 pieces, 64x64 each; the last one says: row 4, column 4, x 192, y 192

// A contact sheet: 3x2 thumbnails, 10px between them, a 20px frame round the sheet.
$thumbs = $extractor->extract($png, 'png', null, ConversionOptions::none()
    ->with_grid_columns(3)->with_grid_rows(2)
    ->with_grid_gutter_x(10)->with_grid_gutter_y(10)
    ->with_grid_margin_left(20)->with_grid_margin_right(20)
    ->with_grid_margin_top(20)->with_grid_margin_bottom(20));
// on 1000x700: 314x325, 313x325, 313x325 — 940px shared by three, the spare pixel to the first

// A carousel: a 3240x1080 panorama cut into three square slides.
$slides = $extractor->extract($png, 'png', null, ConversionOptions::none()->with_grid_columns(3));
// 1080x1080, three times

// Uneven columns: a 300px sidebar, then the rest shared 1:2.
$columns = $extractor->extract($png, 'png', null, ConversionOptions::none()
    ->with_grid_columns('300px 1fr 2fr'));
// on 1200x800: 300x800, 300x800, 600x800
```

Pixels carry their unit: `'300px'` is one 300-pixel column, `'300'` would be three hundred columns.

### Detected layouts — you don't

```php
// A tall strip of screenshots of different heights, white between them.
// The screenshots run into the edges, so no colour owns the border: say what the background is.
$shots = $extractor->extract($png, 'png', null, ConversionOptions::none()
    ->with_grid_rows('auto')
    ->with_background('#ffffff'));
// 400x300, 400x180, 400x480

// A comic page whose rows hold 1, 3 and 2 panels — rows first, then the panels in each row.
$panels = $extractor->extract($png, 'png', null, ConversionOptions::none()
    ->with_grid_columns('auto')->with_grid_rows('auto')
    ->with_grid_expect(6));                        // any other count is an error, not a wrong cut
// r1c1  r2c1 r2c2 r2c3  r3c1 r3c2

// The same page read as a manga: right to left.
$manga = $extractor->extract($png, 'png', null, ConversionOptions::none()
    ->with_grid_columns('auto')->with_grid_rows('auto')
    ->with_grid_order('rl-tb'));
// r1c1  r2c3 r2c2 r2c1  r3c2 r3c1
```

Without `BACKGROUND`, the background is the commonest colour along the border — and when no colour holds
half of it, detection refuses rather than guesses: *"No colour holds even half of the border — the
background is unclear; say it with BACKGROUND."*

### One rectangle, or several

```php
// One rectangle, from coordinates — a model's bounding box, a user's selection.
[$face] = $extractor->extract($png, 'png', [[420, 310, 380, 260]], ConversionOptions::none());
// 380x260, locator: index 1, x 420, y 310, width 380, height 260

// Several, as a detector returns them: [x, y, width, height] each.
$regions = $extractor->extract($png, 'png', [
    [0, 0, 800, 600],
    [800, 600, 800, 600],
    [420, 310, 380, 260],
], ConversionOptions::none());
```

When a rectangle is easier to say as margins, a ratio or an alignment, `ImageTrimmer` with the crop
keys does the same with one result.

### Combinations

```php
// A scan with a 50px border: the crop keys cut first, the grid is laid inside what is left.
$pages = $extractor->extract($png, 'png', null, ConversionOptions::none()
    ->with_crop_left(50)->with_crop_right(50)->with_crop_top(50)->with_crop_bottom(50)
    ->with_grid_columns(2)->with_grid_rows(2));
// 500x350 x4; the first located at x 50, y 50 — in the source's own coordinates

// A game atlas with an empty slot: skip it, the others keep their place.
$icons = $extractor->extract($png, 'png', null, ConversionOptions::none()
    ->with_grid_columns(3)->with_grid_rows(2)
    ->with_grid_skip_empty(true));
// 4 pieces: r1c1 r1c2 r1c3 r2c1

// Then each piece trimmed tight to its own content.
$trimmer = new ImageTrimmer();
$tight   = array_map(
    fn ($piece) => $trimmer->trim($piece->bytes(), 'png', null, ConversionOptions::none()),
    $extractor->extract($png, 'png', null, ConversionOptions::none()->with_grid_columns(2))
);
// 300x300, 300x300 -> 100x120, 200x80

// Files named after where they came from.
foreach ($thumbs as $piece) {
    ['row' => $r, 'column' => $c] = $piece->locator();
    file_put_contents("thumb-r{$r}c{$c}.{$piece->extension_code()}", $piece->bytes());
}
// thumb-r1c1.png, thumb-r1c2.png, ...
```

### Apart and back

```php
$sheet = ConversionOptions::none()
    ->with_grid_columns(3)->with_grid_rows(2)
    ->with_grid_gutter_x(8)->with_grid_gutter_y(8)
    ->with_background('#ffffff');                  // what fills the gutters when putting back

$pieces = $extractor->extract($png, 'png', null, $sheet);
$again  = (new ImageGridMerger())->merge(array_map(fn ($p) => $p->bytes(), $pieces), 'png', 'png', $sheet);
// the same pixels as $png
```

`BACKGROUND` is part of the deal: the pieces carry nothing of what lay between them.

### When it cannot work, it says so

```php
// Pieces that touch cannot be told apart:
$extractor->extract($png, 'png', null, ConversionOptions::none()
    ->with_grid_columns('auto')->with_grid_rows('auto')
    ->with_grid_expect(3));
// ConversionException: Expected 3 piece(s), found 1.
```

## Cookbook: `ImageGridMerger`

Run, as written, in `tests/CookbookTest.php` too — its second half.

```php
use Italix\Converters\ConversionOptions;
use Italix\Media\GdImageConverter;
use Italix\Media\ImageGridMerger;

$merger = new ImageGridMerger();
```

### Side by side

```php
// "Before / after": no grid keys, one row.
$pair = $merger->merge([$before, $after], 'png', 'png', ConversionOptions::none());
// two 400x300 images: 800x300

// With a white gutter between them.
$framed = $merger->merge([$before, $after], 'png', 'png', ConversionOptions::none()
    ->with_grid_gutter_x(20)
    ->with_background('#ffffff'));
// 820x300
```

### A contact sheet from pieces of different sizes

```php
$contact = $merger->merge($photos, 'png', 'png', ConversionOptions::none()
    ->with_grid_columns(3)
    ->with_grid_gutter_x(10)->with_grid_gutter_y(10)
    ->with_grid_margin_left(20)->with_grid_margin_right(20)
    ->with_grid_margin_top(20)->with_grid_margin_bottom(20)
    ->with_background('#f0f0f0'));
// six photos, 120x90 to 80x80: each column as wide as its widest piece, each row as tall as its
// tallest — 370x250; a smaller photo sits centred in its cell (ALIGN_X/ALIGN_Y, default 50)
```

### A vertical strip

```php
$strip = $merger->merge($screenshots, 'png', 'png', ConversionOptions::none()
    ->with_grid_columns(1)
    ->with_grid_gutter_y(16)
    ->with_align_x(0)                              // a narrower one flush left
    ->with_background('#ffffff'));
// 400x200, 380x150, 400x100: 400x482
```

### Fixed cells — and never scaled

```php
$tiles = $merger->merge([$small, $exact], 'png', 'png', ConversionOptions::none()
    ->with_grid_columns('200px 200px')
    ->with_grid_rows('150px')
    ->with_background('#ffffff'));
// 400x150; the 180x120 piece centred in its 200x150 cell

$merger->merge([$too_wide], 'png', 'png', ConversionOptions::none()->with_grid_columns('200px'));
// ConversionException: A 220px piece does not fit track 1 (200px) — resize it first; a merge never scales.

// Resize first, said, not implied:
$converter = new GdImageConverter();
$fitted    = array_map(
    fn (string $png) => $converter->convert($png, 'png', 'png', ConversionOptions::none()->with_width(200))->bytes(),
    $images
);
$gallery = $merger->merge($fitted, 'png', 'png', ConversionOptions::none()->with_grid_columns('200px 200px'));
// 400x300 and 800x400 become 200x150 and 200x100: a 400x150 sheet
```

### Order, and a grid that is not full

```php
// Down the columns, like a newspaper: the second piece goes below the first.
$paper = $merger->merge($four, 'png', 'png', ConversionOptions::none()
    ->with_grid_rows(2)
    ->with_grid_order('tb-lr'));

// Seven pieces in three columns: three rows, the two missing cells background of the same size.
$seven = $merger->merge($pieces, 'png', 'png', ConversionOptions::none()
    ->with_grid_columns(3)
    ->with_background('#ffffff'));
// seven 100x100: 300x300
```

### Formats and background

```php
// PNG without BACKGROUND: gutters and empty cells are transparent.
$merger->merge([$before, $after], 'png', 'png', ConversionOptions::none()->with_grid_gutter_x(20));

// Into a JPEG: white by default (a JPEG has no transparency), QUALITY as GdImageConverter reads it.
$merger->merge([$before, $after], 'png', 'jpg', ConversionOptions::none()->with_grid_gutter_x(20)->with_quality(85));

// Into a lossless WebP: exact colours.
$merger->merge([$before, $after], 'png', 'webp', ConversionOptions::none()->with_lossless(true));
```

To take a sheet apart and put it back, see the `ImageExtractor` Cookbook's "Apart and back".

## Cookbook: `ImageTrimmer`

Run, as written, in `tests/CookbookTest.php` too — its third part. One image in, one image out, only
the edges touched: with no crop key it finds the content itself; with crop keys it cuts where they say.

```php
use Italix\Converters\ConversionOptions;
use Italix\Media\ImageTrimmer;

$trimmer = new ImageTrimmer();
```

### Cut to the content — adaptive

```php
// A scan with a white border: the background is the commonest colour along the border.
$content = $trimmer->trim($scan, 'png', null, ConversionOptions::none());
// 1000x800 with the page at 100,80: 700x600, the border gone

// A logo with transparent padding.
$tight = $trimmer->trim($logo, 'png', null, ConversionOptions::none());
// 500x300 -> 200x100

// A product on light grey, with a faint shadow: say the background, and how far from it still counts as it.
$packed = $trimmer->trim($product, 'png', null, ConversionOptions::none()
    ->with_background('#f5f5f5'));                 // GAP_TOLERANCE 10 (of 100) by default: the shadow is background
// 400x300 — the product alone

$shadowed = $trimmer->trim($product, 'png', null, ConversionOptions::none()
    ->with_background('#f5f5f5')
    ->with_gap_tolerance(2));                      // stricter: the shadow is content
// 410x310 — the product and its shadow
```

### Declared crops

```php
// 10% off every side.
$inner = $trimmer->trim($png, 'png', null, ConversionOptions::none()
    ->with_crop_left('10%')->with_crop_right('10%')
    ->with_crop_top('10%')->with_crop_bottom('10%'));
// 1000x800 -> 800x640

// One side only: a caption bar along the bottom.
$captioned = $trimmer->trim($png, 'png', null, ConversionOptions::none()->with_crop_bottom(60));
// 1000x800 -> 1000x740

// A rectangle by coordinates: x and y as the left and top cut, then the size.
$box = $trimmer->trim($photo, 'png', null, ConversionOptions::none()
    ->with_crop_left(420)->with_crop_top(310)
    ->with_width(380)->with_height(260));
// 380x260 — the same pixels as ImageExtractor with the boundary [420, 310, 380, 260]
```

On each axis `start + size + end = source`: any two of them decide the third, all three must agree.

### Sizes and ratios

```php
// A square thumbnail, centred.
$square = $trimmer->trim($photo, 'png', null, ConversionOptions::none()->with_ratio('1:1'));
// 1600x900 -> 900x900, cut from x 350

// A 16:9 banner from the top of a square picture.
$banner = $trimmer->trim($picture, 'png', null, ConversionOptions::none()
    ->with_ratio('16:9')
    ->with_align_y(0));                            // 0 top, 50 centre (the default), 100 bottom
// 1200x1200 -> 1200x675, the first row kept

// A fixed size out of the middle — or from the left edge.
$centre = $trimmer->trim($big, 'png', null, ConversionOptions::none()->with_width(800)->with_height(600));
// 1600x1200 -> 800x600, cut from 400,300
$left = $trimmer->trim($big, 'png', null, ConversionOptions::none()->with_width(800)->with_height(600)->with_align_x(0));
// cut from 0,300
```

A ratio only ever removes: the axis that would have to grow stays whole, the other is cut.

### When it cannot work, it says so

```php
$trimmer->trim($blank, 'png', null, ConversionOptions::none());
// ConversionException: Nothing but background: there is no content to trim to.

$trimmer->trim($png, 'png', null, ConversionOptions::none()->with_width(5000));
// ConversionException: This crop removes more than the source has (1000px): ... — never padded
```

To trim every piece of a sheet, see the `ImageExtractor` Cookbook's "Combinations".

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
