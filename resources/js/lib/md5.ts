/**
 * MD5 of bytes, in hex (RFC 1321). Used only so the Spaces bucket can check an upload itself: the signed address
 * carries it as Content-MD5 and the bucket refuses other bytes, and the server then compares the bucket's own ETag
 * (the MD5 it computed from what it stored). Not a security hash; the SHA-256 is sent beside it.
 * Web Crypto has no MD5, hence this small version; it reads the bytes in place, 64 at a time.
 */
const S = [7, 12, 17, 22, 7, 12, 17, 22, 7, 12, 17, 22, 7, 12, 17, 22, 5, 9, 14, 20, 5, 9, 14, 20, 5, 9, 14, 20, 5, 9, 14, 20, 4, 11, 16, 23, 4, 11, 16, 23, 4, 11, 16, 23, 4, 11, 16, 23, 6, 10, 15, 21, 6, 10, 15, 21, 6, 10, 15, 21, 6, 10, 15, 21];

const K = new Int32Array([
    0xd76aa478, 0xe8c7b756, 0x242070db, 0xc1bdceee, 0xf57c0faf, 0x4787c62a, 0xa8304613, 0xfd469501, 0x698098d8, 0x8b44f7af, 0xffff5bb1, 0x895cd7be, 0x6b901122, 0xfd987193, 0xa679438e, 0x49b40821,
    0xf61e2562, 0xc040b340, 0x265e5a51, 0xe9b6c7aa, 0xd62f105d, 0x02441453, 0xd8a1e681, 0xe7d3fbc8, 0x21e1cde6, 0xc33707d6, 0xf4d50d87, 0x455a14ed, 0xa9e3e905, 0xfcefa3f8, 0x676f02d9, 0x8d2a4c8a,
    0xfffa3942, 0x8771f681, 0x6d9d6122, 0xfde5380c, 0xa4beea44, 0x4bdecfa9, 0xf6bb4b60, 0xbebfbc70, 0x289b7ec6, 0xeaa127fa, 0xd4ef3085, 0x04881d05, 0xd9d4d039, 0xe6db99e5, 0x1fa27cf8, 0xc4ac5665,
    0xf4292244, 0x432aff97, 0xab9423a7, 0xfc93a039, 0x655b59c3, 0x8f0ccc92, 0xffeff47d, 0x85845dd1, 0x6fa87e4f, 0xfe2ce6e0, 0xa3014314, 0x4e0811a1, 0xf7537e82, 0xbd3af235, 0x2ad7d2bb, 0xeb86d391,
]);

export function md5Hex(bytes: Uint8Array): string {
    const state = new Int32Array([0x67452301, 0xefcdab89, 0x98badcfe, 0x10325476]);
    const words = new Int32Array(16);
    const view = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength);
    const full = bytes.length - (bytes.length % 64);
    for (let at = 0; at < full; at += 64) {
        for (let i = 0; i < 16; i++) words[i] = view.getInt32(at + i * 4, true);
        block(state, words);
    }

    // The last bytes, the 0x80 marker, zeros, and the length in bits (64-bit little-endian): one or two blocks.
    const rest = bytes.length - full;
    const tail = new Uint8Array(rest < 56 ? 64 : 128);
    tail.set(bytes.subarray(full));
    tail[rest] = 0x80;
    const bits = bytes.length * 8;
    const tailView = new DataView(tail.buffer);
    tailView.setUint32(tail.length - 8, bits >>> 0, true);
    tailView.setUint32(tail.length - 4, Math.floor(bits / 0x100000000), true);
    for (let at = 0; at < tail.length; at += 64) {
        for (let i = 0; i < 16; i++) words[i] = tailView.getInt32(at + i * 4, true);
        block(state, words);
    }

    const out = new Uint8Array(16);
    const outView = new DataView(out.buffer);
    for (let i = 0; i < 4; i++) outView.setInt32(i * 4, state[i], true);
    let hex = '';
    for (const b of out) hex += b.toString(16).padStart(2, '0');
    return hex;
}

function block(state: Int32Array, m: Int32Array): void {
    let a = state[0];
    let b = state[1];
    let c = state[2];
    let d = state[3];
    for (let i = 0; i < 64; i++) {
        let f: number;
        let g: number;
        if (i < 16) {
            f = (b & c) | (~b & d);
            g = i;
        } else if (i < 32) {
            f = (d & b) | (~d & c);
            g = (5 * i + 1) & 15;
        } else if (i < 48) {
            f = b ^ c ^ d;
            g = (3 * i + 5) & 15;
        } else {
            f = c ^ (b | ~d);
            g = (7 * i) & 15;
        }
        const x = (a + f + K[i] + m[g]) | 0;
        a = d;
        d = c;
        c = b;
        b = (b + ((x << S[i]) | (x >>> (32 - S[i])))) | 0;
    }
    state[0] = (state[0] + a) | 0;
    state[1] = (state[1] + b) | 0;
    state[2] = (state[2] + c) | 0;
    state[3] = (state[3] + d) | 0;
}
