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
- **Licence:** LGPL-3.0, no fee. See the obligations below.
- **Patents:** HEVC (the codec inside HEIC) is patent-encumbered; that is a legal question, not a technical one.

### LGPL-3.0 obligations, and how each is met

Sending the library's file to a user's browser is conveying it, so LGPL-3.0 §4 (with GPL-3.0 §6) applies:

| Obligation | How it is met |
|---|---|
| Prominent notice that the library is used and is under the LGPL (§4a) | The page `/licenses/index.html` (`public/licenses/index.html`), linked from the sign-in page's footer («تراخيص البرامج مفتوحة المصدر», beside «جميع الحقوق محفوظة», same style), `NOTICE.txt` beside the licence, and a notice line at the top of the library's own file (`vite.config.js` adds it at build). |
| Give the GPL-3.0 and LGPL-3.0 texts (§4b) | `public/licenses/libheif-js/LICENSE.txt`: the upstream LICENSE, byte for byte (its SHA-256 equals the libheif-emscripten v1.23.5 release asset). |
| Copyright notices (§4c) | In NOTICE.txt: libheif-js (Kiril Vatev), libheif (Struktur AG, Dirk Farin), libde265 (Struktur AG, Dirk Farin). |
| Let the user use a changed library (§4d) | The library is a separate, unmodified file loaded at run time through its interface; the application's source is public, so it can be rebuilt with another libheif-js. Nothing in the app's terms forbids changing or reverse engineering it. |
| Corresponding Source (GPL-3.0 §6d) | A copy is kept on our own server, in `public/licenses/libheif-js/source/`, with `SHA256SUMS.txt`, and listed on the page with its upstream links. The archives are libheif 1.23.5 (its SHA-256 equals the published one), libde265 1.0.15, the WebAssembly build steps (libheif-emscripten v1.23.5) and libheif-js 1.23.5. Together they are 3.1 MB. |
| Installation Information (§4e) | Not applicable: it is for consumer devices, not a website. |

`OpenSourceNoticesTest` keeps all of this in step with the installed version: the licence text byte for byte, every archive against its checksum and on the page, the version named, and the sign-in page's link. Upgrading the library without its notice and source fails there.

Still open, and not technical: **HEVC patents** are a legal question.
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

## Originals in Spaces (built, off: `MEDIA_ORIGINALS=spaces` with `MEDIA_SPACES_*`)

The settings are `MEDIA_SPACES_KEY`, `_SECRET`, `_REGION`, `_BUCKET` and `_ENDPOINT`.
- They are deliberately not the `SPACES_*` that `scripts/backup-database.sh` reads, so the originals never land in the database copies' bucket. That script is unchanged.
- The endpoint is the region's own (`https://fra1.digitaloceanspaces.com`), never the CDN one: Spaces takes signed uploads through its CDN only up to 8100 KiB.

1. **Ticket:** the page asks its place's own route for a ticket: `members/uploads` under `members.manage`, `products/uploads` under `products.manage`, and so on.
   - The ticket is encrypted. It names the club, the user, the place, the key, the file's SHA-256 and MD5, and its expiry (1 hour).
   - The signed PUT address lasts 10 minutes.
2. **Upload:** the browser puts the original to `incoming/{club}/{uuid}.{ext}`. The address signs:
   - `Content-MD5`: the bucket refuses a body with another MD5.
   - `x-amz-checksum-sha256`: a bucket that keeps checksums refuses another SHA-256.
   - `x-amz-content-sha256`.
   - `x-amz-meta-sha256`: the hash kept beside the object.
3. **Checking, without reading the whole file.** The app reads HEAD and a 256 KB Range (type and dimensions). It compares, against the ticket:
   - the size;
   - the recorded SHA-256;
   - **the ETag, which is the MD5 the bucket computed itself from the bytes it stored**;
   - the bucket's own SHA-256 checksum, when it reports one.
4. **Keeping:**
   - The app copies the object inside the bucket to `tenants/{club}/originals/{folder}/v2r-{ulid}.{ext}` and checks the copy the same way.
   - It makes the display copy and thumbnail from the browser's display source.
   - It saves the record, then removes the incoming object.
5. **Download:** after the permission check, a 302 to a 60-second signed GET (attachment, no-store). No bytes pass through the app.
6. **Failures:**
   - Any failed step removes the new objects and files; the record and the old image stay.
   - A lost connection, a timeout or a bad answer from the bucket shows «تعذر الاتصال بمخزن الصور…» on the field.
   - The incoming object is kept, so the same ticket works once the bucket is back. The lifecycle rule removes it after a day.
   - A failed delete of an old original leaves an unused object, never a missing one.
7. **Uploads through the app** (old browsers, a failed direct upload, or saving before it finished): streamed to the bucket. The app computes both hashes itself.

### Whose word is the hash?

**Before (86988a3), the browser's.** The ticket and the recorded hash both came from the browser, and the app only compared them with each other. The real check of the bytes was left to the bucket honouring `x-amz-content-sha256`. A Ceph gateway, which Spaces runs, may not check that header on a signed address.

**Now:**
- The bucket checks `Content-MD5` on every put.
- Spaces documents its ETag as "MD5 hash of the object", so **the app compares a fingerprint the bucket computed from what it stored**. A bucket that checked nothing would still be caught. The simulated bucket in `SpacesOriginalsTest` is that worst case.
- Where the bucket also checks SHA-256 (`media:spaces-check` says whether it does), both are the bucket's.
- What remains:
  - The hashes prove that what was stored is what the browser read from the file.
  - Someone with the upload permission can still upload any image they choose, as with any upload.
  - If the bucket checks only MD5, the recorded SHA-256 is the browser's. The daily copy (`media:originals-copy`) computes it from the bytes and refuses to spread a mismatch.

### Before switching it on

- **Bucket:** a private Space in the droplet's region, with:
  - CORS: `PUT` from the panel's address, with headers `Content-Type`, `Content-MD5`, `x-amz-checksum-sha256`, `x-amz-content-sha256` and `x-amz-meta-sha256`;
  - lifecycle: delete `incoming/` after 1 day;
  - versioning on (API only), with noncurrent versions expiring after 30 days;
  - a key limited to that bucket.
- **`php artisan media:spaces-check` passes on it.** It writes three 16 KB objects under `selftest/` and removes them. It shows:
  - whether the bucket refuses bytes not matching their MD5 and their SHA-256;
  - the ETag;
  - Range;
  - copy;
  - signed download;
  - CORS;
  - versioning and lifecycle.
- **The live tests pass on a test Space** (below).
- **Approve the restore plan below.**
- `php artisan media:limits` passes.

## Tests against real buckets

`tests/Feature/SpacesLiveTest.php` (group `spaces-live`) is left out of the normal run and CI. It runs through one script, from this computer, against two test buckets:

```
scripts/spaces-live-test.sh <keys file> <name>_testing                 # self-tests, then the live tests
scripts/spaces-live-test.sh <keys file> <name>_testing serve [port]    # the isolated app on the test bucket, for a browser check
```

- **The keys file** holds `MEDIA_SPACES_KEY`, `_SECRET`, `_REGION`, `_BUCKET`, `_ENDPOINT` and `MEDIA_COPY_KEY`, `_SECRET`, `_REGION`, `_BUCKET`, `_ENDPOINT`.
  - It must be outside the repository and `chmod 600`. Nothing is ever written to a tracked file or to GitHub.
- **The script refuses:**
  - a bucket whose name lacks `test`;
  - the database copies' bucket (`SPACES_BUCKET`);
  - a CDN endpoint;
  - a database without `test`;
  - the production server.
- **Nothing printed carries a key or a signed address.** Everything goes through a filter that hides the keys, the secrets and every signature, and the app's log is off for the run.
- **In the app itself:**
  - an image refusal is not logged;
  - a lost connection to the bucket is a warning naming the bucket, never the address.

It covers:
- the self-test on both buckets;
- JPG, HEIC and a 25 MB photo byte for byte, with the requests the app sends (never a whole GET);
- permissions and two clubs;
- replacement, with the old original kept by versioning;
- a lost connection, with the same ticket working after;
- older local images after the switch;
- the copy, a loss and the restore, plus a damaged original that is not spread and is repaired from the copy.

**Run so far:** against two local MinIO servers, through the script, each with a key limited to its bucket (the copy's key cannot delete). That is a real S3 implementation that checks the signatures and hashes itself, but **not Spaces**. The same run on a test Space is still to do.

**What Spaces allows for keys:**
- Keys are made only in the DigitalOcean control panel, not by API or CLI.
- A limited key is **read** or **read/write/delete** on a bucket. So on Spaces, the independent copy's key can always delete. The live test says so instead of failing.
  - For the real copy, a provider whose keys can write without deleting (for example Backblaze B2, with object lock) is the safer choice.
- Bucket settings need a full-access key, or the control panel for CORS:
  - versioning (API only);
  - lifecycle rules;
  - CORS.

## Restore plan for originals in Spaces (for approval)

Versioning alone is **not** a backup.
- It keeps old and deleted versions inside the same bucket, account and region.
- A deleted bucket, a closed or compromised account, a wrong lifecycle rule or a regional loss takes the versions with it.
- Club archives (`backup:tenants`) read only the droplet's disks, so they do not hold these originals. They do hold the records, the display copies and the thumbnails, so the screens, prints and PDFs come back from a club archive alone.

Layers (the tools are built and tested; nothing is scheduled or switched on):

1. **Versioning in the bucket,** with noncurrent versions kept 30 days.
   - Covers a wrongly deleted or overwritten original.
   - Restore: the previous version by its version id (tested: the removed original came back byte for byte).
2. **An independent copy** (`MEDIA_COPY_*`): another bucket under a key of its own that **cannot delete** (possible at Backblaze B2 or with object lock; not with a Spaces limited key, which is read or read/write/delete).
   - Filled by `php artisan media:originals-copy --from=main --to=copy`, once a day after approval. The command:
     - streams each original;
     - checks its bytes against the recorded SHA-256 and the source's MD5;
     - puts it with both hashes signed, then checks it there;
     - never deletes;
     - never spreads a damaged original.
   - Where:
     - **another provider** (e.g. Backblaze B2) is independent of the DigitalOcean account;
     - **another Spaces region** is not: it shares the account and the subscription.
   - Restore: `php artisan media:originals-copy --from=copy --to=main` (with `--repair` to replace a damaged one). Records need nothing, because the keys are the same.
3. **A monthly drill:** `php artisan media:originals-check --bucket=copy --deep=20` fetches 20 random originals from the copy and compares their bytes with both hashes.
4. **After a club archive restore:** the display copies come back with the archive, and each names its original's key.
   - `php artisan media:originals-check` lists the originals missing from the bucket.
   - `media:originals-copy --from=copy --to=main` brings them back. No record is changed.

Tested (`OriginalsCopyTest` simulated; `SpacesLiveTest` on real S3 servers):
- copy, then check every byte;
- the copy's key refusing a delete;
- all originals deleted from the main bucket, the check naming them, and the restore bringing them back byte for byte;
- records unchanged;
- the HEIC downloading as before;
- bit rot in the main bucket found by the drill, not spread to the copy, and repaired from it.

## Disk and cost

- Display copies and thumbnails: 20–120 MB per 1,000 images (measured per place).
- Originals: about 3 GB per 1,000 images at an average of 3 MB (assumed; HEIC around 1.5–2.5 MB, JPEG 3–6 MB).
- **On the droplet** (`MEDIA_ORIGINALS=local`):
  - Each original is also in all 14 daily club archives, so expect about 45 GB per 1,000 images and 450 GB per 10,000.
  - The nightly archive job also reads and zips all of it.
- **In Spaces:**
  - $5/month per account, for all its buckets together: 250 GiB stored and 1 TiB out. Extra is $0.02/GiB stored and $0.01/GiB out. The built-in CDN is included.
  - 10,000 originals (about 30 GB) fit in the base plan.
  - Versions add only what is replaced or deleted within 30 days.
  - A second bucket in another region adds no subscription, only its storage beyond the 250 GiB, but it shares the account.
  - Droplet-to-Spaces traffic counts against the droplet's transfer allowance.
  - Spaces-to-droplet traffic in the same region is free.
- **Independent copy at another provider:** Backblaze B2 lists about $6 per TB a month, so about $0.20 a month for 30 GB. Check the price when choosing.
- **CDN for the public copies:** `MEDIA_CDN_URL`, optional.
