# Uploaded images

Code: `App\Core\Support\ImageStore`, `App\Core\Support\Images\*`, `config/media.php`, `resources/js/lib/image-prep.ts`.

## What is stored

Each upload (member, trainer, program, product, post, website library, logo) is kept three ways, in the club's folder:

| Piece | Disk | Path | Used by |
|---|---|---|---|
| Original, byte for byte (SHA-256 checked after writing) | `local` (private, `storage/app/private`) | `tenants/{id}/originals/{folder}/v2-{ulid}.{ext}` | download only (`…/photo`, `…/original` routes, by permission) |
| Display copy: WebP (PNG for a logo), scaled to fit, upright, transparency kept; a moving GIF as it is | `public` | `tenants/{id}/{folder}/v2-{ulid}.{ext}.webp` | screens, website, prints, PDF (the record's path) |
| Thumbnail, 360px | `public` | `tenants/{id}/{folder}/v2-{ulid}.{ext}.thumb.webp` | lists, avatars, the till, the door check |

- The record keeps the display path, as before. Images stored before this have none of the new names: they have no original or thumbnail and are shown as they are.
- Originals stay private because a camera photo may carry its location (EXIF GPS).
- Club archives (`backup:tenants`) already collect `tenants/{id}/` from both disks, so originals are in them. The backup code was not changed.

## Formats and limits

- The type is read from the file's bytes: JPEG, PNG, GIF, WebP, AVIF, HEIC and HEIF.
- A renamed script, an HTML page, an archive or a video is refused before any decoder opens it.
- Limits:
  - size: `gym.max_image_kb`;
  - each side up to 20,000 px;
  - 40 megapixels in all;
  - the server decodes only what GD reads and has memory for (about 5 bytes a pixel).
- HEIC/HEIF, and photos longer than 2400 px, get a light *display source* made by the browser (`<field>_preview`):
  - The server checks that it is a plain JPEG, PNG or WebP of the same shape (either way up), and works from it.
  - Safari on iPhone and Mac decodes HEIC itself.
  - Where the browser cannot decode HEIC (Chrome on Windows and Android), the server cannot either, and it asks for a JPG.
  - Adding a HEIC decoder to the browser would need a new dependency, such as heic2any, which bundles LGPL-3.0 libheif. That needs the owner's approval first.
- A failure removes every piece already written and shows the reason on the field. Replacing an image removes the old files only after the record is saved.

## Optional: CDN, Spaces, direct upload (all off by default)

Nothing below is switched on until the settings are added to the server's `.env`.

- **CDN for the public copies:** `MEDIA_CDN_URL=https://cdn.example.com`.
  - Any pull CDN whose origin is the app's address works; it fetches `/storage/…`.
  - The display and thumbnail names never change once written, so they can be cached for a long time.
  - The nginx rule `/storage/` currently says `expires 30d`; `1y, immutable` would also be safe for `v2-*` files.
- **Direct upload** (the website library): the browser puts the original straight into a Spaces bucket through a signed address (10 minutes); the server fetches it once, stores it as above, and removes it from the bucket.
  - `MEDIA_DIRECT_UPLOAD=true`
  - `SPACES_KEY`
  - `SPACES_SECRET`
  - `SPACES_REGION` (e.g. `fra1`)
  - `SPACES_BUCKET`
  - `SPACES_ENDPOINT=https://fra1.digitaloceanspaces.com`
  - The bucket needs a CORS rule allowing `PUT` from the panel's address, with header `Content-Type`.
  - It also needs a lifecycle rule deleting `incoming/` after 1 day.
  - Signing is done in code (`SpacesSigner`, checked against AWS's published example), so no SDK is needed for this.
- **Originals on Spaces** (`MEDIA_ORIGINALS_DISK`): needs a Laravel `s3` disk, which needs `league/flysystem-aws-s3-v3`. That package is not installed; adding it is a separate decision.
  - Originals on Spaces would not be in club archives, which read the local disks.
