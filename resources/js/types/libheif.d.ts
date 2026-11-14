// libheif-js (LGPL-3.0): the ES module build with its WebAssembly inside, loaded only to show a HEIC/HEIF image.
declare module 'libheif-js/libheif-wasm/libheif-bundle.mjs' {
    interface HeifImage {
        get_width(): number;
        get_height(): number;
        display(target: ImageData, done: (result: ImageData | null) => void): void;
        free?(): void;
    }
    interface LibHeif {
        HeifDecoder: new () => { decode(data: Uint8Array): HeifImage[] };
    }
    const load: () => LibHeif;
    export default load;
}
