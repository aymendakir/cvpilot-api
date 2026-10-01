# ATS S0 spike: can `smalot/pdfparser` see PDF layout?

Phase 3, slice S0 (`SPEC-ats.md` §12 R1, §17 answer 3). Question: does `smalot/pdfparser` give enough position data to detect the §4.1 signals (columns, tables, header/footer text, images, glyph issues) in real CV PDFs, and how fast is it? The probe is `tests/fixtures/ats/spike/probe.php` (throwaway, deleted in S1).

## Recommendation

**smalot alone is not enough for layout detection. Adding poppler (`poppler-utils`) to the Docker image needs your approval (§17 answer 3).**

On the 5 real CVs, smalot returned **no usable text positions for 3 of them** (both Canva exports, and a third file with the same internal structure). Those 3 files include 2 of the 3 two-column layouts. With smalot only, the `single_column` check would be `unverified` for most Canva CVs, which is the most common CV source we expect. Poppler's `pdftotext -bbox-layout` gave positions for all 9 PDFs (4 generated, 5 real), and the same heuristics classified every one correctly. It was also 2 to 50 times faster on the real files.

S1 is not blocked either way. Until you decide, S1 can build the parser behind an interface with smalot for text and mark PDF structure checks `unverified` when positions are missing. The poppler adapter would then be a contained addition.

## Files

| File | Source | Pages | Ground truth (checked by eye on a render) |
| --- | --- | --: | --- |
| `clean-en.pdf` | generated (FPDF) | 2 | single column |
| `two-column.pdf` | generated | 2 | two columns (sidebar on both pages) |
| `table-layout.pdf` | generated | 1 | single column with two ruled grids |
| `scanned.pdf` | generated | 1 | image only, no text |
| real-1 | Canva export | 1 | two columns (sidebar), photo, vector contact icons |
| real-2 | Word 2016, then ilovepdf.com | 1 | two columns (sidebar), photo |
| real-3 | Canva export | 1 | single column, no photo |
| real-4 | no producer recorded; same internal structure as the Canva files | 1 | two columns (sidebar), photo, vector icons |
| real-5 | OpenOffice Writer 3.4 | 1 | single column, small photo, symbol-font bullets |

The real CVs were provided by the maintainer, used only on the spike machine, never committed, and deleted after this run. This report names them only as real-1 to real-5 and contains no personal data. None of them is a scanned document, so the scanned case is covered only by the generated `scanned.pdf`.

## Results

`cols` = two-column detection per page (§4.1: line-start clusters of ≥ 8 lines, ≥ 25 % of the page width apart, ≥ 50 % vertical overlap). `tables` = ≥ 3 consecutive rows of ≥ 3 aligned cells. Times are for parsing plus all heuristics, one run on the spike machine.

| File | smalot text runs with positions | smalot cols | smalot tables | smalot ms | poppler cols | poppler tables | poppler ms | Ground truth |
| --- | --: | --- | --- | --: | --- | --- | --: | --- |
| `clean-en.pdf` | 76 | no, no | no | 16 | no, no | no | 20 | single ✅ both |
| `two-column.pdf` | 89 | yes, yes | no | 18 | yes, yes | no | 24 | two columns ✅ both |
| `table-layout.pdf` | 68 | no | **yes** | 14 | no | **yes** | 21 | grids ✅ both |
| `scanned.pdf` | 0 | — | — | 25 | — | — | 15 | no text ✅ both (0 words) |
| real-1 | **0** | no | no | 128 | **yes** | no | 41 | two columns: ❌ smalot, ✅ poppler |
| real-2 | 271 | yes | no | 925 | yes | no | 28 | two columns ✅ both |
| real-3 | **0** | no (nothing to read) | no | 392 | no | no | 34 | single: smalot can't tell, ✅ poppler |
| real-4 | **3** | no | no | 1 390 | **yes** | no | 27 | two columns: ❌ smalot, ✅ poppler |
| real-5 | 111 | no | no | 39 | no | no | 20 | single ✅ both |

Text extraction itself works with smalot on every file. Word counts are within 2–8 % of poppler's (214/207, 324/320, 211/202, 555/516, 311/303). smalot reads two-column files block by block, not interleaved line by line.

## Why smalot fails on the Canva files

1. **All page text sits inside one Form XObject.** The page's content stream only sets a transform (`0.24 0 0 -0.24 0 850 cm`, i.e. scaled and flipped) and calls `/X12 Do`. `Page::getDataTm()` only reads the page's own content stream, so it returns 0 runs (3 runs for real-4).
2. **Reaching into the form doesn't help.** I fed the form's commands to `getDataTm()` directly. It returned the whole page as **one run** (1 554 and 1 659 characters) at a single position. Line positions inside the form are set with operators smalot does not track there.
3. **The transform (`cm`) is ignored.** Even where runs exist, coordinates are in the form's space (≈ 2 481 × 3 509, y flipped), not page points.

Fixing this inside smalot means writing our own content-stream interpreter: graphics-state stack, `cm` concatenation, Form XObject recursion, `TJ` displacement and font widths. That is a parser project, not an ATS feature, and we would own its bugs. Poppler already does all of this.

## Other findings (all go into S1/S2 regardless of the decision)

- **FPDF producer quirk.** smalot takes a different, broken code path (`Page::isFpdf()`) for any PDF whose Producer starts with "FPDF": `getDataTm()` fails. Real CVs don't carry it; the fixture builder writes another producer string. S1's PDF adapter should still catch it.
- **`Page::getXObjects()` lists every object twice** (by resource name and by index), and `Page::getContent()` came back empty on FPDF files. Read `Contents` directly.
- **Image placement.** smalot only sees images placed directly on the page with a simple `w 0 0 h x y cm /Name Do` (the generated files). It cannot see the photos inside the Canva forms. Poppler's `pdftohtml -xml` reports placed image boxes for all four real photos: about 11 %, 7 %, 3 % and 2 % of the page, all below the 15 % rule.
- **Header/footer band.** The 7 % top band catches the candidate's name or a heading on most files: all 5 real CVs in poppler's output, and both pages of `clean-en.pdf`. PDF header/footer detection needs "repeated on every page" or "outside the main text flow", not the band alone. No file had contact details only in a header/footer.
- **Private-use glyphs are not always icons.** real-5 has 19 private-use characters, and all are **list bullets** from a symbol font (Word/OpenOffice "Wingdings" bullets). They are not contact icons. If `clean_characters` failed on any private-use character, it would flag a very common, harmless bullet style. S2 should exclude private-use glyphs used as list markers at line starts. The contact icons on real-1 and real-4 are vector drawings or images, not glyphs, so there is nothing to detect in the text there.
- **Letter-spaced heading** detected on real-2 (one heading set as spaced capitals), as §6 expects.
- **No false positives:** no table detected on any real CV; no columns detected on the single-column files.
- **Speed:** smalot took up to **1.4 s for a one-page CV** (real-4) and 0.9 s for real-2. That is inside the §8.4 budget (≤ 1.5 s for F1/F2, p95 ≤ 3 s), but with little margin. Poppler stayed under 45 ms on every file.

## What adding poppler would mean (for your decision)

- **Docker:** `apt-get install -y --no-install-recommends poppler-utils` (the binaries `pdftotext`, `pdftohtml`, `pdfimages`). Local tests can skip the poppler adapter's tests when the binary is missing, and CI installs it.
- **How it runs:** a `Symfony\Component\Process\Process` call on the temp file the request already has (§3 assumption 2). No shell, a hard timeout (e.g. 5 s) and output size limits. The CV is still never stored or sent anywhere.
- **Risk:** poppler parses untrusted PDFs, and has had security fixes over the years. Mitigation: keep the base image updated (it is Debian's package), enforce the timeout and the 15 MB upload limit, and run it with no network (it needs none).
- **smalot stays** as the fallback when poppler fails or times out: text still comes from smalot, and the PDF structure checks become `unverified`.

## Not covered by this spike

Scanned real CVs (none provided). Multi-page real CVs (all 5 are one page). Tables in real CVs (none present). Right-hand sidebars and three-column layouts. RTL/Arabic text (Phase 5).
