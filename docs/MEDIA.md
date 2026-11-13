# Uploaded images

Code: `App\Core\Support\ImageStore`, `App\Core\Support\Images\*`, `config/media.php`, `resources/js/lib/image-prep.ts`.

## What is stored

Each upload (member, trainer, program, product, post, website library, logo) is kept three ways:

| Piece | Where | Name | Used by |
|---|---|---|---|
| Original, byte for byte | `local` private disk (default), or the Spaces bucket (`MEDIA_ORIGINALS=spaces`) | `tenants/{id}/originals/{folder}/v2-{ulid}.{ext}` (local) or `v2r-…` (Spaces) | download only, by permission |
| Display copy (WebP; PNG for a logo; a moving GIF as it is) | `public` | `tenants/{id}/{folder}/v2…{ext}.webp` | screens, website, prints, PDF (the record's path) |
| Thumbnail, 360px | `public` | `…{ext}.thumb.webp` | lists, avatars, the till, the door check |

- The record keeps the display path, as before.
- Images stored before this have none of the new names: they have no original or thumbnail, and are shown and printed as they are.
- Originals are never public, because a camera photo may carry its location.
- Every write is checked: a local original by SHA-256 after writing, a Spaces original by the bucket itself (below). Display copies and thumbnails are checked by their size.
- A failure removes what was written and shows the reason on the field. The old files go only after the new record is saved.

## Formats

- The type is read from the bytes: JPEG, PNG, GIF, WebP, AVIF, HEIC and HEIF. Disguised files and pixel bombs are refused from the header.
- The browser makes a light *display source* (at most 2400px):
  - for HEIC/HEIF;
  - for photos over 2400px;
  - for every image when originals go to Spaces.
- The server decodes only that display source, and the original only when it has it and memory allows.
- **HEIC in every browser:**
  - Safari decodes it itself.
  - Chrome, Edge, Firefox and Android use `libheif-js` (below), loaded only when such a file is picked.
  - If decoding still fails, the server explains instead of saving half.

### Review of `libheif-js` 1.23.5 (adopted, pinned exactly)

- **Contents:** libheif 1.23.5 with the libde265 1.0.15 HEVC decoder, compiled to WebAssembly by emscripten.
  - We use `libheif-wasm/libheif-bundle.mjs`, one ES module with the wasm embedded: 2.0 MB, 723 KB gzip.
  - Vite puts it in its own chunk, so the main bundle is unchanged.
- **No network:** no URL, no `fetch` of its own in the bundle, no `eval` or `new Function`. It needs no CSP change; the site sends no CSP today.
- **Where it runs:** in the user's browser, on the file they picked, inside the WebAssembly sandbox.
  - A malicious HEIC can at worst crash or stall that tab.
  - The original is never changed by it; only the display source comes from it.
- **Licence:** LGPL-3.0, no fee.
  - It is shipped unmodified, as a separate file, with its licence (`node_modules/libheif-js/LICENSE`).
  - Source: https://github.com/catdad-experiments/libheif-js.
- **Patents:** HEVC (the codec inside HEIC) is patent-encumbered; that is a legal question, not a technical one.
- **Audit:** `npm audit` reported 0 vulnerabilities at install. Review before each upgrade; do not take `heic2any` (its last release is from 2023, with an old libheif).

## Upload limits and 25 MB photos

The defaults are today's, so production is unchanged until they are raised there.

| Setting | Default | For 25 MB photos |
|---|---|---|
| `GYM_MAX_IMAGE_KB` | 4096 | 25600 |
| `MEDIA_MAX_PIXELS` (a 48MP photo is 48.8M) | 40000000 | 50000000 |
| `MEDIA_MAX_REQUEST_KB` (the library batches under it, the product form warns) | 20480 | 30720 |
| PHP-FPM `upload_max_filesize` / `post_max_size` | server's own | ≥ 25M / ≥ 30M |
| nginx `client_max_body_size` (`deploy/nginx`) | 20m | ≥ 30m |

- **Checking:** `php artisan media:limits` checks these (read-only) and fails while one is short. Check PHP-FPM too: `php-fpm8.4 -i | grep -E "upload_max|post_max"`.
- **With originals in Spaces,** the original never reaches nginx or PHP; only the display source (≤ 4 MB) does, so the PHP and nginx limits matter only without Spaces.
- **Measured locally:**
  - A 24 MB JPEG with its 2400px display source: about 230 ms and 29 MB of extra memory, almost all of it from decoding the 2400px source.
  - The 24 MB original itself is streamed and hashed, never held in memory.

## Originals in Spaces (built, off: `MEDIA_ORIGINALS=spaces` with `SPACES_*`)

1. **Ticket:** the page asks its place's own route for a ticket: `members/uploads` under `members.manage`, `products/uploads` under `products.manage`, and so on.
   - The ticket is encrypted and names the club, the user, the place, the key and its expiry (1 hour).
   - The signed PUT address lasts 10 minutes.
2. **Upload:** the browser puts the original to `incoming/{club}/{uuid}.{ext}`.
   - The address signs `x-amz-content-sha256` with the file's SHA-256, so the bucket refuses any other bytes.
   - It also stores the hash as `x-amz-meta-sha256`.
3. **Checking, without reading the whole file:** the app reads HEAD (size and recorded hash, which must equal the ticket's) and a 256 KB Range (type and dimensions).
4. **Keeping:** the app copies the object inside the bucket to `tenants/{club}/originals/{folder}/v2r-{ulid}.{ext}`, checks the copy by HEAD, makes the display copy and thumbnail from the browser's display source, saves the record, then removes the incoming object.
5. **Download:** after the permission check, a 302 to a 60-second signed GET (attachment, no-store). No bytes pass through the app.
6. **Failures:**
   - Any failed step removes the new objects and files. The record and the old image stay.
   - The incoming object is kept for another try with the same ticket, and the lifecycle rule removes it after a day.
   - A failed delete of an old original leaves an unused object, never a missing one.
7. **Uploads through the app** (old browsers, or saving before the upload finished): streamed to the bucket with the same hash check.

### Before switching it on

- **Bucket:** a private Space in the droplet's region, with:
  - CORS: `PUT` from the panel's address, headers `Content-Type`, `x-amz-content-sha256`, `x-amz-meta-sha256`;
  - lifecycle: delete `incoming/` after 1 day;
  - versioning on, with noncurrent versions expiring after e.g. 30 days;
  - a key limited to that bucket.
- **Test on that real Space first:**
  - the hash check on PUT;
  - HEAD returning `x-amz-meta-sha256`;
  - Range GET;
  - CopyObject keeping the metadata;
  - the signed download.

  These are standard S3, but Spaces does not document them all.
- **Approve the restore plan below.**
- `php artisan media:limits` passes.

## Restore plan for originals in Spaces (for approval)

Versioning alone is **not** a backup.
- It keeps old and deleted versions inside the same bucket, account and region.
- A deleted bucket, a closed or compromised account, a wrong lifecycle rule or a regional loss takes the versions with it.
- Club archives (`backup:tenants`) read only the droplet's disks, so they do not hold these originals. They hold the records, the display copies and the thumbnails, so the screens, prints and PDFs come back from a club archive alone.

Proposed layers (none built or switched on):

1. **Versioning in the bucket** (API only), with noncurrent versions kept 30 days.
   - Covers a wrongly deleted or overwritten original. Restore: copy the previous version back to its key.
2. **An independent copy:**
   - A second Space in another region, under a separate key, filled by a daily one-way copy that never deletes. Spaces cannot copy across regions itself, so the copy runs from the droplet (e.g. rclone).
   - Or a different provider (e.g. Backblaze B2, about $6/TB a month).
   - Covers losing the first bucket or account.
   - Restore: copy back to the same keys; records need nothing, because keys are deterministic.
3. **A monthly restore drill:**
   - Pick 20 random `v2r-` originals.
   - Fetch each from the independent copy and compare its SHA-256 with `x-amz-meta-sha256`.
   - Log the result.
4. **After a club archive restore:** the restored records point at `tenants/{id}/originals/...` keys. Each one missing in the bucket is fetched from layer 1 or 2, and a list of any still missing is reported. No record is changed.

## Disk and cost

- Display copies and thumbnails: 20–120 MB per 1,000 images (measured per place).
- Originals: about 3 GB per 1,000 images at an average of 3 MB (assumed; HEIC around 1.5–2.5 MB, JPEG 3–6 MB).
- **On the droplet** (`MEDIA_ORIGINALS=local`):
  - Each original is also in all 14 daily club archives, so expect about 45 GB per 1,000 images and 450 GB per 10,000.
  - The nightly archive job also reads and zips all of it.
- **In Spaces:**
  - $5/month includes 250 GiB stored and 1 TiB out; extra is $0.02/GiB stored and $0.01/GiB out. The built-in CDN is included.
  - 10,000 originals (about 30 GB) fit in the base plan.
  - Versions add only what is replaced or deleted within 30 days.
  - An independent second Space is another $5/month.
  - Droplet-to-Spaces traffic counts against the droplet's transfer allowance.
- **CDN for the public copies:** `MEDIA_CDN_URL`, optional.
