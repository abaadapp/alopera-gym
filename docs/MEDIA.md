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

## Upload limits (settings; production unchanged until they are raised there)

| Setting | Default | For 25 MB phone photos |
|---|---|---|
| `GYM_MAX_IMAGE_KB` (one image) | 4096 | 25600 |
| `MEDIA_MAX_PIXELS` (header only; a 48MP photo is 48.8M) | 40000000 | 50000000 |
| `MEDIA_MAX_REQUEST_KB` (one request; the library batches under it, the product form warns) | 20480 | e.g. 30720 |
| PHP `upload_max_filesize` / `post_max_size` (server php.ini, FPM) | server's own | ≥ 25M / ≥ 30M |
| nginx `client_max_body_size` (`deploy/nginx`, today `20m`) | 20m | ≥ 30m |

- Every image header is read without decoding it, and every copy is made as a stream, so a 25 MB file costs 25 MB of disk, not 25 MB of PHP memory.
- The server decodes only what fits its memory (about 5 bytes a pixel); anything bigger is shown from the browser's display source (at most 2400px).
- A file over PHP's limit is refused by PHP before the app sees it. Its message (`validation.uploaded`) now says it may be too large.
- A request over `post_max_size` comes back with a plain message instead of an error page.

## Disk: what to expect

These figures were measured on a 12MP photo (display copy and thumbnail, made from the browser's 2400px source):

| Place | Display | Thumbnail |
|---|---|---|
| members (600px) | 14–22 KB | 1–6 KB |
| trainers (800px) | 19–63 KB | 2–6 KB |
| products (1000px) | 24–111 KB | 2–6 KB |
| programs, posts (1600px) | 42–396 KB | 2–6 KB |
| website library (2400px) | 81–1347 KB | 2–6 KB |

- Originals are what they were: about 1.5–2.5 MB for a 12MP HEIC, and 3–6 MB for a 12MP JPEG (assumed, not measured on the club's photos).
- At an average of 3 MB, originals add about 3 GB per 1,000 images and 30 GB per 10,000; display copies and thumbnails add 20–120 MB per 1,000.
- **Club archives:**
  - `backup:tenants` keeps 14 daily zip archives of `tenants/{id}/` from both disks, on the droplet.
  - Images do not compress, so each original kept locally takes about 15 times its size: the file plus 14 archives.
  - Typical totals: about 45 GB per 1,000 images and about 450 GB per 10,000.
  - The nightly job also reads and zips all of it.
  - Turn originals off (`MEDIA_KEEP_ORIGINALS=false`) or keep them outside the droplet (below) before photos accumulate. Changing the archives themselves is a separate decision.

## HEIC in browsers that cannot read it (decision pending)

Safari on iPhone and Mac decodes HEIC itself. Chrome (Windows, Android, and Mac too) does not, so there the server refuses with a message. The options:

1. **A decoder in the browser**, loaded only when needed (WebAssembly, about 1.5–3 MB downloaded once):
   - Candidates: `libheif-js` 1.23.5, or `heic-to` 1.6.7.
   - Licence: both are LGPL-3.0. Ship the file unchanged and separate, with its licence and a link to its source.
   - Cost: none.
   - Server: no change.
   - Note: HEVC (the codec inside HEIC) is patent-encumbered; that is a legal question, not a technical one.
   - `heic2any` is not recommended: its last release is from 2023, with an old libheif.
2. **A decoder on the server** (libheif/ImageMagick packages on the droplet):
   - It needs a production package install.
   - It costs the host CPU and memory: tens of MB and up to a second per photo.
3. **A paid image service**: a monthly fee, and member photos leave the club's servers.

## Proposal (not built): originals in Spaces without passing through the droplet

1. The browser asks for a signed PUT for `incoming/{club}/{uuid}.{ext}`.
   - The signature covers `Content-Type` and `Content-MD5`, so a corrupted upload is refused; this must be tested on a real Space.
   - The browser also hashes the file (SHA-256) and the signature stores it as `x-amz-meta-sha256`.
2. The browser sends the light display source to the app as today, which makes the display copy and the thumbnail.
3. The app checks the object without fetching it whole:
   - HEAD for the size and ETag (MD5 for a single-part PUT);
   - a Range GET of the first 256 KB for the type and dimensions.
   - It then copies it server-side (CopyObject, same region) to `originals/{club}/{folder}/v2-{ulid}.{ext}`, and records the image.
4. Download: the permission-checked route answers with a 302 to a 60-second signed GET, so no bytes pass through the app.
5. Deletion and replacement: the old original is deleted only after the record is saved, as now. Uploads never used are removed by a lifecycle rule on `incoming/`.
6. Restore: these originals are not in club archives. Recovery rests on the Space itself:
   - versioning, which Spaces supports through the API only;
   - plus a lifecycle rule on old versions, e.g. 30 days.
   - A restored database finds its originals under the same keys.
7. Cost:
   - The Spaces subscription is $5/month for 250 GiB and 1 TiB out; extra is $0.02/GiB stored and $0.01/GiB out.
   - The built-in CDN is included.
   - 10,000 originals at 3 MB is about 30 GB, inside the base plan.
   - Old versions add only what is replaced.
8. Work: about 1–1.5 days, plus tests on a real Space (CORS, Content-MD5, ETag, Range).
