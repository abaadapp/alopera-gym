import type { SiteInfo } from '@/Layouts/SiteLayout';
import type { ImageData, LinkData, RenderedSection, SiteChrome } from '@/types/site';

/** What every section component receives. */
export interface SectionProps {
    section: RenderedSection;
    site: SiteInfo;
    chrome: SiteChrome | null;
    /** The section before has the same tone: draw a hairline between them. */
    joined: boolean;
}

/** Field readers: the server sends resolved values; these keep a component safe when one is missing. */
export const str = (v: unknown): string => (typeof v === 'string' ? v : '');
export const bool = (v: unknown, fallback = false): boolean => (typeof v === 'boolean' ? v : fallback);
export const img = (v: unknown): ImageData | null => (v && typeof v === 'object' && typeof (v as ImageData).url === 'string' && (v as ImageData).url ? (v as ImageData) : null);
export const link = (v: unknown): LinkData | null => (v && typeof v === 'object' && typeof (v as LinkData).href === 'string' && (v as LinkData).href ? (v as LinkData) : null);
export const list = <T = Record<string, unknown>>(v: unknown): T[] => (Array.isArray(v) ? (v as T[]) : []);
