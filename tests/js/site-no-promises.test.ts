import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';

// The fixed words of the visitors' pages promise nothing the club has not confirmed: no «soon», no reply time, no
// certified staff, no minutes-to-join. What the club says about itself is the owner's, written in «إدارة الموقع».
const PROMISES = ['قريبًا', 'أقرب وقت', 'سنتواصل', 'سنعاود', 'معتمد', 'خلال دقائق', 'خلال دقيقة', 'مصمم لك', 'نضمن'];

function files(dir: string): string[] {
    return readdirSync(dir).flatMap((name) => {
        const path = join(dir, name);
        return statSync(path).isDirectory() ? files(path) : path.endsWith('.tsx') ? [path] : [];
    });
}

describe('visitor pages', () => {
    const root = join(__dirname, '../../resources/js');
    const sources = [...files(join(root, 'Pages/Site')), ...files(join(root, 'Components/Site')), join(root, 'Layouts/SiteLayout.tsx'), join(root, 'Pages/Portal/Login.tsx')];

    it.each(PROMISES)('never say «%s»', (phrase) => {
        const found = sources.filter((file) => readFileSync(file, 'utf8').includes(phrase));
        expect(found).toEqual([]);
    });
});
