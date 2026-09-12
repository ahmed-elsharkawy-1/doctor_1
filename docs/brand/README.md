# Brand assets

Everything the platform shows of itself lives in `public/images/brand`, and
every path is a key under `clinic.brand` in `config/clinic.php`. Nothing reads
these files by any other name, so replacing the artwork is a matter of dropping
new files in — no view, no controller, no deploy step.

| File | Size | Where it appears |
|---|---|---|
| `logo.png` | 512×512 | root signpost, clinic app bar, Filament panel |
| `logo-white.png` | 1024×480 | **only on the brand blue** — see the warning below |
| `cover.jpg` | 1200×630 | WhatsApp / Facebook link previews |
| `favicon.png` | 32×32 | browser tab, every page |
| `apple-touch-icon.png` | 180×180 | iOS home screen |
| `icon-192.png` | 192×192 | Android home screen |

`public/favicon.ico` is a copy of `favicon.png`, kept because browsers request
that path directly whatever the page declares.

## The white logo is white

`logo-white.png` is the mark knocked out in white on transparency. It is
invisible on a light surface. Use it on `clinic.brand.color` (#0174D6) or
another dark ground; everywhere else use `logo.png`, which carries its own blue
tile and is safe anywhere.

## Replacing the artwork

Drop the new files in under the same names and clear the view cache. Keep the
dimensions above — the share card in particular is cropped to 1.91:1 because
that is what WhatsApp and Facebook expect, and a different ratio gets cropped
by them instead of by us.

If a name has to change, change it in `config/clinic.php`; the pages follow.
`tests/Feature/Web/BrandAssetsTest.php` fails if a config key ever points at a
file that is not there, which is the failure mode that would otherwise ship as
a silently broken image.

## Sources

The originals delivered by the designer are not in the repo. What ships here is
derived from them:

- `logo.png` ← `Logo_Final.png` (1024²), resized and quantised to 256 colours
- `logo-white.png` ← `Logo_No_Background.png` (4358², trimmed to content)
- `cover.jpg` ← `Cover_final.png`, fitted to 1200 wide then centre-cropped to 630
- icons ← `Favicon_Final.png` and `Logo_Final.png`

The brand blue `#0174D6` was sampled from the artwork rather than eyeballed.
