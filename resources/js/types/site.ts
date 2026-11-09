/**
 * The official website's content, shared by the public pages and «إدارة الموقع».
 *
 * Two shapes:
 * - SiteDoc / SectionDoc: what the owner edits and the server stores (draft, published, revisions). Plain text and
 *   typed fields only: no HTML. Images are ids into the club's media library; links are tokens (see href below).
 * - SitePage / RenderedSection: what a visitor's page receives, built on the server from a SiteDoc. Images arrive
 *   resolved with their size, links resolved to real addresses, and the dynamic sections carry the system's data
 *   (plans, trainers, timetable, products, posts) in `feed`.
 *
 * The server (App\Modules\Website\Content\SectionTypes) is the source of truth for the section types and fields;
 * the editor renders its forms from the schema it sends, so a new section type needs no new form code.
 */

/**
 * A link target as stored: '' (no link), '@plans' | '@programs' | '@schedule' | '@trainers' | '@news' | '@contact'
 * | '@join' | '@shop' | '@portal' | '@home' (a page of the site), '#<section id>' (a section of the home page),
 * 'https://…' | 'http://…' | 'tel:…' | 'mailto:…'. Anything else is refused by the server.
 */
export type Href = string;

export type ImageFit = 'auto' | 'contain' | 'cover';

/** An image as stored in a section: a media library id and how to show it. */
export interface ImageRef {
    id: number | null;
    fit: ImageFit;
}

export interface ButtonDoc {
    label: string;
    href: Href;
}

export type Tone = 'auto' | 'white' | 'beige' | 'dark';

export interface SectionDoc {
    /** Stable, also the anchor (#id) on the home page: [a-z0-9-]{1,40}. */
    id: string;
    type: string;
    visible: boolean;
    tone: Tone;
    /** Field values by key, as described by the type's schema. List items carry their own `id`. */
    data: Record<string, unknown>;
}

export interface SiteDoc {
    v: 1;
    theme: { accent: string; ink: string; surface: string };
    announcement: string;
    header: { links: { id: string; label: string; href: Href }[]; cta: ButtonDoc; show_login: boolean };
    footer: { line: string; links: { id: string; label: string; href: Href }[]; show_hours: boolean; show_social: boolean };
    contact: {
        phone: string;
        whatsapp: string;
        email: string;
        address: string;
        map_url: string;
        hours: { id: string; days: string; hours: string }[];
        social: { instagram: string; x: string; tiktok: string; snapchat: string; facebook: string; youtube: string };
    };
    seo: { title: string; description: string };
    sections: SectionDoc[];
}

/* ---------- Schema (sent by the server to the editor) ---------- */

export type FieldType = 'text' | 'textarea' | 'link' | 'href' | 'image' | 'toggle' | 'select' | 'number' | 'color' | 'video' | 'list';

export interface FieldSchema {
    key: string;
    type: FieldType;
    label: string;
    help?: string;
    placeholder?: string;
    max?: number;
    min?: number;
    rows?: number;
    options?: { value: string; label: string }[];
    /** For 'list': the item fields, the most items, and what one item is called («صورة», «سؤال»…). */
    fields?: FieldSchema[];
    item?: string;
    /** For 'image': where it is used, so the editor can explain the automatic layout. */
    usage?: 'background' | 'side' | 'gallery' | 'card' | 'portrait';
}

export interface SectionTypeSchema {
    type: string;
    label: string;
    description: string;
    /** 'content' (the owner writes it) or 'system' (filled from the gym system's data). */
    group: 'content' | 'system';
    /** Where the data of a 'system' section comes from, in words. */
    source?: string;
    fields: FieldSchema[];
    defaults: Record<string, unknown>;
    tone: Exclude<Tone, 'auto'>;
}

/* ---------- Rendered page (public site and preview) ---------- */

export interface ImageData {
    url: string;
    width: number | null;
    height: number | null;
    orientation: 'landscape' | 'portrait' | 'square' | null;
    alt: string;
    fit: ImageFit;
}

export interface LinkData {
    label: string;
    href: string;
    external: boolean;
}

export interface PlanFeed {
    id: number;
    name: string;
    description: string | null;
    price: string;
    signup_fee: string | null;
    duration_label: string;
    type_label: string;
    features: string[] | null;
    is_featured: boolean;
    join_url: string;
}

export interface TrainerFeed {
    id: number;
    name: string;
    bio: string | null;
    specialties: string[] | null;
    photo_url: string | null;
}

export interface ProgramFeed {
    id: number;
    name: string;
    description: string | null;
    category: string | null;
    level: string | null;
    duration_minutes: number | null;
    image_url: string | null;
}

export interface SessionFeed {
    id: number;
    program: string | null;
    trainer: string | null;
    date: string;
    time: string;
    end_time: string;
    duration_minutes: number;
    spots_left: number | null;
    full: boolean;
}

export interface ProductFeed {
    id: number;
    name: string;
    price: string;
    category: string | null;
    image_url: string | null;
    in_stock: boolean;
    url: string;
}

export interface PostFeed {
    id: number;
    type: 'news' | 'offer';
    title: string;
    excerpt: string | null;
    image_url: string | null;
    published_at: string | null;
    ends_on: string | null;
    url: string;
}

export interface RenderedSection {
    id: string;
    type: string;
    /** Resolved: 'auto' becomes the type's own tone. */
    tone: Exclude<Tone, 'auto'>;
    /** The section's fields with images as ImageData|null, links as LinkData|null, lists of objects as arrays. */
    data: Record<string, any>;
    /**
     * System data for the dynamic types:
     * programs → { programs: ProgramFeed[], all_url }
     * schedule → { days: { date, label, sessions: SessionFeed[] }[], booking_url, schedule_url }
     * trainers → { trainers: TrainerFeed[], all_url }
     * plans → { plans: PlanFeed[], all_url, currency }
     * products → { products: ProductFeed[], shop_url, currency }
     * posts → { posts: PostFeed[], all_url }
     * hero → { next_class: { name, date, time } | null }
     * join → { plans: PlanFeed[], join_url, currency }
     * contact → { send_url } (the contact form posts to the site's existing form)
     * video → { embed_url } (youtube-nocookie / player.vimeo, built by the server)
     */
    feed?: Record<string, any>;
}

export interface SiteChrome {
    theme: { accent: string; ink: string; surface: string };
    announcement: string | null;
    header: { links: LinkData[]; cta: LinkData | null; show_login: boolean };
    footer: { line: string | null; links: LinkData[]; show_hours: boolean; show_social: boolean };
    home_url: string;
    /** True on «معاينة»: the page shows the saved draft to the club's staff only. */
    preview: boolean;
}

export interface SitePage {
    sections: RenderedSection[];
}

/* ---------- «إدارة الموقع» (Website/Builder page) ---------- */

export interface MediaItem {
    id: number;
    url: string;
    title: string | null;
    alt: string | null;
    width: number | null;
    height: number | null;
    orientation: 'landscape' | 'portrait' | 'square' | null;
    /** Bytes of the stored (optimised) file. */
    size: number;
    mime: string | null;
    /** Where the image is used, in words («المسودة: معرض صور»، «المنشور: الواجهة الرئيسية»). Empty = free to delete. */
    used_in: string[];
}

export interface RevisionItem {
    id: number;
    number: number;
    note: string | null;
    published_at: string;
    published_by: string | null;
}

export interface BuilderStatus {
    /** The saved draft differs from what visitors see. */
    has_unpublished: boolean;
    /** Nothing has been published yet: visitors see the starting design built from the club's data. */
    never_published: boolean;
    published_at: string | null;
    published_by: string | null;
    draft_saved_at: string | null;
    draft_saved_by: string | null;
}

export interface BuilderProps {
    doc: SiteDoc;
    /** The draft's version; sent back on save/publish/discard so two windows cannot overwrite each other. */
    version: number;
    status: BuilderStatus;
    schema: SectionTypeSchema[];
    /** Sections of the saved draft fed by the gym system that show nothing now (no plans yet…): id => why. */
    waiting: Record<string, string>;
    media: MediaItem[];
    revisions: RevisionItem[];
    /** Pages of the site a link can open: [{ value: '@plans', label: 'صفحة الباقات' }, …]. */
    targets: { value: string; label: string }[];
    siteUrl: string;
    previewUrl: string;
    limits: { max_image_kb: number; types: string[] };
    unread: number;
}
