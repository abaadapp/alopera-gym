import type { ComponentType } from 'react';
import type { SectionProps } from './types';
import Hero from './Hero';
import { Cta, FaqSection, Gallery, ImageText, Intro, Services, Stats, Testimonials, Video } from './Content';
import { Contact, Join, Plans, Posts, Products, Programs, Schedule, Trainers } from './System';

/**
 * One component per section type of App\Modules\Website\Content\SectionTypes. A type the page does not know
 * (a newer server, an old browser tab) renders nothing rather than breaking the page.
 */
export const SECTION_COMPONENTS: Record<string, ComponentType<SectionProps>> = {
    hero: Hero,
    intro: Intro,
    image_text: ImageText,
    gallery: Gallery,
    services: Services,
    video: Video,
    testimonials: Testimonials,
    stats: Stats,
    faq: FaqSection,
    cta: Cta,
    programs: Programs,
    schedule: Schedule,
    trainers: Trainers,
    plans: Plans,
    products: Products,
    posts: Posts,
    join: Join,
    contact: Contact,
};

export function SiteSection(props: SectionProps) {
    const Component = SECTION_COMPONENTS[props.section.type];
    return Component ? <Component {...props} /> : null;
}

export type { SectionProps };
