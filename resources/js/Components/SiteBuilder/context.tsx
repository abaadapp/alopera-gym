import { createContext, useContext, type Dispatch, type SetStateAction } from 'react';
import type { MediaItem, SectionDoc, SectionTypeSchema } from '@/types/site';

/** What every part of «إدارة الموقع» reads: the media library, link targets, the sections (for anchors), errors. */
export interface BuilderContextValue {
    media: MediaItem[];
    setMedia: Dispatch<SetStateAction<MediaItem[]>>;
    targets: { value: string; label: string }[];
    sections: SectionDoc[];
    schemas: Record<string, SectionTypeSchema>;
    limits: { max_image_kb: number; types: string[] };
    /** Errors by path relative to the document (`sections.<id>.data.title`, `contact.email`). */
    errors: Record<string, string>;
    /** Saved sections fed by the gym system that are not on the site now for want of data: id => why. */
    waiting: Record<string, string>;
    /** Images the document open in the editor uses (saved or not), by media id: the sections' names. */
    localUses: Record<number, string[]>;
}

export const BuilderContext = createContext<BuilderContextValue | null>(null);

export function useBuilder(): BuilderContextValue {
    const value = useContext(BuilderContext);
    if (!value) throw new Error('useBuilder outside «إدارة الموقع»');
    return value;
}

/** A section's name in the editor: its template's label, then its own title. */
export function sectionLabel(section: SectionDoc, schemas: Record<string, SectionTypeSchema>): string {
    return schemas[section.type]?.label ?? section.type;
}
