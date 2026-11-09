import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { CircleAlert, CircleCheck, Clock, ExternalLink, Eye, History, Mail, MoreHorizontal, RotateCcw, Save, Send } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Badge, Button, Card, Modal, PageHeader, Tabs } from '@/Components/ui';
import { Dropdown, DropdownItem } from '@/Components/ui/Dropdown';
import AddSectionModal from '@/Components/SiteBuilder/AddSectionModal';
import { BuilderContext, sectionLabel, type BuilderContextValue } from '@/Components/SiteBuilder/context';
import { ContactPanel, MenuPanel, SeoPanel, ThemePanel } from '@/Components/SiteBuilder/DocPanels';
import HistoryModal from '@/Components/SiteBuilder/HistoryModal';
import MediaLibrary from '@/Components/SiteBuilder/MediaLibrary';
import PreviewPanel from '@/Components/SiteBuilder/PreviewPanel';
import SectionEditor from '@/Components/SiteBuilder/SectionEditor';
import SectionList from '@/Components/SiteBuilder/SectionList';
import { cn } from '@/lib/cn';
import { date, time } from '@/lib/format';
import { docKey, duplicateSection, errorIndex, hasErrorUnder, imageUses, mapErrors, move, newSection, normalizeDoc, type BuilderError } from '@/lib/site-builder';
import type { BuilderProps, SectionDoc, SectionTypeSchema, SiteDoc } from '@/types/site';

type Tab = 'sections' | 'menu' | 'contact' | 'colors' | 'media' | 'seo';
type Busy = 'save' | 'publish' | 'discard' | null;

const TAB_OF_PATH: [string, Tab][] = [
    ['sections.', 'sections'],
    ['header.', 'menu'],
    ['footer.', 'menu'],
    ['contact.', 'contact'],
    ['theme.', 'colors'],
    ['seo.', 'seo'],
    ['announcement', 'seo'],
];

function tabOf(path: string): Tab | null {
    return TAB_OF_PATH.find(([prefix]) => path.startsWith(prefix))?.[1] ?? null;
}

function when(value: string | null): string {
    return value ? `${date(value)}، ${time(value)}` : '';
}

/**
 * «إدارة الموقع»: the owner edits the official website here and nowhere else. Edits stay in this window until
 * «حفظ المسودة» keeps them on the server (visitors still see the published site); «معاينة» shows the saved draft as a
 * visitor would; «نشر التغييرات» puts it live at once. Every publish is kept, and an older one can be restored.
 */
export default function WebsiteBuilder(props: BuilderProps) {
    const { schema, revisions, targets, siteUrl, previewUrl, limits, status, unread } = props;
    const schemas = useMemo(() => Object.fromEntries(schema.map((s) => [s.type, s])) as Record<string, SectionTypeSchema>, [schema]);

    const [doc, setDoc] = useState<SiteDoc>(() => normalizeDoc(props.doc, schemas));
    const [baseline, setBaseline] = useState<string>(() => docKey(normalizeDoc(props.doc, schemas)));
    const [media, setMedia] = useState(props.media);
    const [errors, setErrors] = useState<BuilderError[]>([]);
    const [busy, setBusy] = useState<Busy>(null);
    const [tab, setTab] = useState<Tab>('sections');
    const [selected, setSelected] = useState<string | null>(props.doc.sections[0]?.id ?? null);
    const [adding, setAdding] = useState(false);
    const [previewOpen, setPreviewOpen] = useState(false);
    const [previewStamp, setPreviewStamp] = useState(() => Date.now());
    const [historyOpen, setHistoryOpen] = useState(false);
    const [confirmPublish, setConfirmPublish] = useState(false);
    const [confirmDiscard, setConfirmDiscard] = useState(false);

    const dirty = docKey(doc) !== baseline;
    const dirtyRef = useRef(dirty);
    dirtyRef.current = dirty;

    // What the next server answer means for the document open here: after a save, the saved document is the new
    // starting point (taken as it is if nothing was typed meanwhile); after publish/discard/restore it replaces it.
    const adopt = useRef<'save' | 'replace' | null>(null);
    const sent = useRef<string | null>(null);

    useEffect(() => {
        setMedia(props.media);
    }, [props.media]);

    useEffect(() => {
        const server = normalizeDoc(props.doc, schemas);
        const key = docKey(server);
        if (adopt.current === 'replace' || (adopt.current === null && !dirtyRef.current)) {
            setDoc(server);
        } else if (adopt.current === 'save') {
            setDoc((current) => (docKey(current) === sent.current ? server : current));
        }
        setBaseline(key);
        adopt.current = null;
    }, [props.doc, props.version]);

    // The selected section exists (after a delete, a restore, a discard).
    useEffect(() => {
        if (selected && doc.sections.some((s) => s.id === selected)) return;
        setSelected(doc.sections[0]?.id ?? null);
    }, [doc.sections, selected]);

    const errorMap = useMemo(() => errorIndex(errors), [errors]);
    const localUses = useMemo(() => imageUses(doc.sections, schemas), [doc.sections, schemas]);

    const context: BuilderContextValue = { media, setMedia, targets, sections: doc.sections, schemas, limits, errors: errorMap, localUses, waiting: props.waiting ?? {} };

    const patch = useCallback((fn: (d: SiteDoc) => SiteDoc) => setDoc((d) => fn(d)), []);
    const setSections = (fn: (s: SectionDoc[]) => SectionDoc[]) => patch((d) => ({ ...d, sections: fn(d.sections) }));

    /* ---------- server ---------- */

    const save = (then?: (version: number) => void) => {
        if (busy) return;
        const snapshot = doc;
        sent.current = docKey(snapshot);
        adopt.current = 'save';
        setBusy('save');
        router.put(route('club.website.draft.save'), { doc: snapshot as unknown as Record<string, never>, version: props.version }, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: (page) => {
                setErrors([]);
                if (then) then(Number((page.props as unknown as BuilderProps).version));
            },
            onError: (e) => {
                adopt.current = null;
                setErrors(mapErrors(e as Record<string, string>, snapshot));
            },
            onFinish: () => setBusy((b) => (b === 'save' ? null : b)),
        });
    };

    const publish = () => {
        const run = (version: number) => {
            adopt.current = 'replace';
            setBusy('publish');
            router.post(route('club.website.publish'), { version }, {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => {
                    setErrors([]);
                    setConfirmPublish(false);
                },
                onError: (e) => {
                    adopt.current = null;
                    setErrors(mapErrors(e as Record<string, string>, doc));
                    setConfirmPublish(false);
                },
                onFinish: () => setBusy(null),
            });
        };
        if (dirty) save(run);
        else run(props.version);
    };

    const discard = () => {
        adopt.current = 'replace';
        setBusy('discard');
        router.post(route('club.website.discard'), { version: props.version }, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                setErrors([]);
                setConfirmDiscard(false);
            },
            onError: (e) => {
                adopt.current = null;
                setErrors(mapErrors(e as Record<string, string>, doc));
                setConfirmDiscard(false);
            },
            onFinish: () => setBusy(null),
        });
    };

    const preview = () => {
        if (dirty) {
            save(() => {
                setPreviewStamp(Date.now());
                setPreviewOpen(true);
            });
        } else {
            setPreviewStamp(Date.now());
            setPreviewOpen(true);
        }
    };

    /* ---------- leaving with unsaved edits ---------- */

    useEffect(() => {
        const unload = (e: BeforeUnloadEvent) => {
            if (!dirtyRef.current) return;
            e.preventDefault();
            e.returnValue = '';
        };
        window.addEventListener('beforeunload', unload);
        const off = router.on('before', (event) => {
            if (!dirtyRef.current || event.detail.visit.method !== 'get') return;
            return window.confirm('لديك تعديلات لم تُحفظ. هل تريد مغادرة الصفحة دون حفظها؟');
        });
        return () => {
            window.removeEventListener('beforeunload', unload);
            off();
        };
    }, []);

    // Ctrl+S / ⌘S saves the draft.
    const saveRef = useRef(save);
    saveRef.current = save;
    useEffect(() => {
        const key = (e: KeyboardEvent) => {
            if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') {
                e.preventDefault();
                if (dirtyRef.current) saveRef.current();
            }
        };
        window.addEventListener('keydown', key);
        return () => window.removeEventListener('keydown', key);
    }, []);

    /* ---------- sections ---------- */

    const selectedIndex = doc.sections.findIndex((s) => s.id === selected);
    const current = selectedIndex >= 0 ? doc.sections[selectedIndex] : null;
    const editor = useRef<HTMLDivElement>(null);

    const select = (id: string) => {
        setSelected(id);
        // On a phone the form is under the list: bring it into view.
        if (window.matchMedia('(max-width: 1023px)').matches) window.setTimeout(() => editor.current?.scrollIntoView({ behavior: 'smooth', block: 'start' }), 30);
    };

    const add = (type: SectionTypeSchema) => {
        const section = newSection(type, doc.sections.map((s) => s.id));
        const at = selectedIndex >= 0 ? selectedIndex + 1 : doc.sections.length;
        setSections((list) => [...list.slice(0, at), section, ...list.slice(at)]);
        setAdding(false);
        select(section.id);
    };

    const duplicate = (id: string) => {
        const i = doc.sections.findIndex((s) => s.id === id);
        if (i < 0) return;
        const copy = duplicateSection(doc.sections[i], schemas[doc.sections[i].type], doc.sections.map((s) => s.id));
        setSections((list) => [...list.slice(0, i + 1), copy, ...list.slice(i + 1)]);
        select(copy.id);
    };

    const remove = (id: string) => {
        const i = doc.sections.findIndex((s) => s.id === id);
        setSections((list) => list.filter((s) => s.id !== id));
        if (selected === id) setSelected(doc.sections[i + 1]?.id ?? doc.sections[i - 1]?.id ?? null);
    };

    /* ---------- reading ---------- */

    const statusLine = status.never_published
        ? { tone: 'amber' as const, text: 'لم يُنشر الموقع بعد بهذا التصميم: الزوار يرون التصميم المبدئي ببيانات النادي.' }
        : status.has_unpublished
          ? { tone: 'amber' as const, text: 'توجد تعديلات محفوظة لم تُنشر بعد.' }
          : { tone: 'green' as const, text: 'كل التعديلات المحفوظة منشورة.' };
    const canPublish = dirty || status.has_unpublished || status.never_published;

    const errorLabel = (e: BuilderError): string => {
        if (e.path === 'version') return '';
        if (e.sectionId) {
            const s = doc.sections.find((x) => x.id === e.sectionId);
            const sch = s ? schemas[s.type] : undefined;
            const fieldKey = e.path.split('.')[3];
            const field = sch?.fields.find((f) => f.key === fieldKey);
            return [s ? sectionLabel(s, schemas) : 'قسم محذوف', field?.label].filter(Boolean).join(' — ');
        }
        const t = tabOf(e.path);
        return { menu: 'القائمة والتذييل', contact: 'التواصل', colors: 'الألوان', seo: 'الإعلان ومحركات البحث', sections: 'الأقسام', media: 'الصور' }[t ?? 'sections'] ?? '';
    };

    const goTo = (e: BuilderError) => {
        const t = tabOf(e.path);
        if (t) setTab(t);
        if (e.sectionId) select(e.sectionId);
    };

    const tabErr = (t: Tab) => errors.some((e) => tabOf(e.path) === t);
    const tabs: { key: Tab; label: string; dot?: string }[] = [
        { key: 'sections', label: 'الأقسام', dot: tabErr('sections') ? '#dc2626' : undefined },
        { key: 'menu', label: 'القائمة العلوية والتذييل', dot: tabErr('menu') ? '#dc2626' : undefined },
        { key: 'contact', label: 'التواصل وأوقات العمل', dot: tabErr('contact') ? '#dc2626' : undefined },
        { key: 'colors', label: 'الألوان', dot: tabErr('colors') ? '#dc2626' : undefined },
        { key: 'media', label: 'مكتبة الصور' },
        { key: 'seo', label: 'الإعلان ومحركات البحث', dot: tabErr('seo') ? '#dc2626' : undefined },
    ];

    return (
        <ClubLayout title="إدارة الموقع">
            <BuilderContext.Provider value={context}>
                <PageHeader
                    title="إدارة الموقع"
                    subtitle="عدّل محتوى الموقع الرسمي، واحفظه مسودةً، وعاينه، ثم انشره بضغطة واحدة."
                    actions={
                        <>
                            <Button variant="outline" href={siteUrl} external icon={<ExternalLink />}>عرض الموقع</Button>
                            <Button variant="outline" icon={<Save />} loading={busy === 'save'} disabled={!dirty || busy !== null} onClick={() => save()} title="Ctrl+S">حفظ المسودة</Button>
                            <Button variant="outline" icon={<Eye />} disabled={busy !== null} onClick={preview}>معاينة</Button>
                            <Button icon={<Send />} loading={busy === 'publish'} disabled={!canPublish || busy !== null} onClick={() => setConfirmPublish(true)}>نشر التغييرات</Button>
                            <Dropdown label="خيارات أخرى" trigger={() => <span className="grid size-10 place-items-center rounded-[10px] border border-[#dcdcdc] bg-white text-ink hover:bg-[#fafafa]"><MoreHorizontal className="size-[18px]" /></span>}>
                                <DropdownItem onClick={() => setHistoryOpen(true)}><History />سجل النسخ المنشورة</DropdownItem>
                                <DropdownItem destructive onClick={() => setConfirmDiscard(true)}><RotateCcw />تجاهل التعديلات غير المنشورة</DropdownItem>
                            </Dropdown>
                        </>
                    }
                />

                <div className="mb-4 flex flex-wrap items-center gap-x-4 gap-y-2 rounded-[12px] border border-line bg-white px-4 py-3 text-[13px]">
                    <span className={cn('flex items-center gap-1.5 font-medium', statusLine.tone === 'green' ? 'text-emerald-700' : 'text-amber-800')}>
                        {statusLine.tone === 'green' ? <CircleCheck className="size-4" /> : <Clock className="size-4" />}
                        {statusLine.text}
                    </span>
                    {dirty && <Badge tone="red">تعديلات غير محفوظة</Badge>}
                    {status.draft_saved_at && <span className="text-muted">آخر حفظ: {when(status.draft_saved_at)}{status.draft_saved_by && ` — ${status.draft_saved_by}`}</span>}
                    {status.published_at && <span className="text-muted">آخر نشر: {when(status.published_at)}{status.published_by && ` — ${status.published_by}`}</span>}
                    {unread > 0 && (
                        <Link href={route('club.website.messages')} className="ms-auto flex items-center gap-1.5 font-medium text-ink hover:underline">
                            <Mail className="size-4" />رسائل تواصل جديدة <span className="num">({unread})</span>
                        </Link>
                    )}
                </div>

                {errors.length > 0 && (
                    <div role="alert" className="mb-4 rounded-[12px] border border-red-200 bg-red-50 px-4 py-3 text-[13px] text-red-800">
                        <p className="flex items-center gap-2 font-semibold"><CircleAlert className="size-4" />لم يُحفظ شيء: صحّح ما يلي ثم حاول مرة أخرى.</p>
                        <ul className="mt-2 space-y-1">
                            {errors.map((e) => {
                                const label = errorLabel(e);
                                return (
                                    <li key={e.path}>
                                        <button type="button" className="text-start hover:underline" onClick={() => goTo(e)}>
                                            {label && <span className="font-medium">{label}: </span>}
                                            {e.message}
                                        </button>
                                    </li>
                                );
                            })}
                        </ul>
                    </div>
                )}

                <Tabs tabs={tabs} value={tab} onChange={setTab} className="mb-4" />

                {tab === 'sections' && (
                    <div className="grid items-start gap-4 lg:grid-cols-[minmax(280px,360px)_1fr]">
                        <Card className="lg:sticky lg:top-4">
                            <SectionList
                                sections={doc.sections}
                                selected={selected}
                                onSelect={select}
                                onMove={(from, to) => setSections((list) => move(list, from, to))}
                                onToggle={(id) => setSections((list) => list.map((s) => (s.id === id ? { ...s, visible: !s.visible } : s)))}
                                onDuplicate={duplicate}
                                onDelete={remove}
                                onAdd={() => setAdding(true)}
                            />
                        </Card>
                        <div ref={editor} className="min-w-0 scroll-mt-4">
                            {current ? (
                                <SectionEditor section={current} onChange={(next) => setSections((list) => list.map((s) => (s.id === next.id ? next : s)))} />
                            ) : (
                                <Card><p className="py-10 text-center text-sm text-muted">اختر قسمًا من القائمة لتعديله، أو أضف قسمًا جديدًا.</p></Card>
                            )}
                            {current?.type === 'posts' && (
                                <p className="mt-3 text-[13px] text-muted">
                                    العروض والأخبار نفسها تُكتب من <Link href={route('club.website.posts.index')} className="font-medium text-ink underline">الأخبار والعروض</Link>.
                                </p>
                            )}
                            {current?.type === 'contact' && (
                                <p className="mt-3 text-[13px] text-muted">
                                    الهاتف والعنوان وأوقات العمل تُعدَّل من تبويب <button type="button" className="font-medium text-ink underline" onClick={() => setTab('contact')}>التواصل وأوقات العمل</button>.
                                </p>
                            )}
                        </div>
                    </div>
                )}
                {tab === 'menu' && <MenuPanel doc={doc} patch={patch} />}
                {tab === 'contact' && <ContactPanel doc={doc} patch={patch} />}
                {tab === 'colors' && <ThemePanel doc={doc} patch={patch} />}
                {tab === 'media' && (
                    <Card>
                        <MediaLibrary />
                    </Card>
                )}
                {tab === 'seo' && <SeoPanel doc={doc} patch={patch} />}

                {/* Saved on the server, not yet live: a quiet reminder at the foot of a long page. */}
                {(dirty || canPublish) && (
                    <div className="sticky bottom-3 z-20 mt-6 flex flex-wrap items-center justify-between gap-3 rounded-[14px] border border-line bg-white/95 px-4 py-3 shadow-[0_8px_30px_rgba(0,0,0,0.10)] backdrop-blur">
                        <span className="text-[13px] font-medium text-ink">{dirty ? 'لديك تعديلات غير محفوظة.' : status.draft_saved_at ? 'المسودة محفوظة ولم تُنشر بعد.' : 'لم يُنشر الموقع بهذا التصميم بعد.'}</span>
                        <span className="flex flex-wrap gap-2">
                            {dirty && <Button size="sm" variant="outline" icon={<Save />} loading={busy === 'save'} disabled={busy !== null} onClick={() => save()}>حفظ المسودة</Button>}
                            <Button size="sm" variant="outline" icon={<Eye />} disabled={busy !== null} onClick={preview}>معاينة</Button>
                            <Button size="sm" icon={<Send />} loading={busy === 'publish'} disabled={busy !== null} onClick={() => setConfirmPublish(true)}>نشر التغييرات</Button>
                        </span>
                    </div>
                )}

                <AddSectionModal open={adding} onClose={() => setAdding(false)} schema={schema} onPick={add} />
                <PreviewPanel open={previewOpen} onClose={() => setPreviewOpen(false)} url={previewUrl} stamp={previewStamp} dirty={dirty} />
                <HistoryModal open={historyOpen} onClose={() => setHistoryOpen(false)} revisions={revisions} dirty={dirty} />

                <Modal
                    open={confirmPublish}
                    onClose={() => setConfirmPublish(false)}
                    title="نشر التغييرات على الموقع؟"
                    size="confirm"
                    footer={
                        <>
                            <Button variant="outline" onClick={() => setConfirmPublish(false)}>إلغاء</Button>
                            <Button icon={<Send />} loading={busy !== null} onClick={publish}>نشر الآن</Button>
                        </>
                    }
                >
                    <div className="space-y-2 text-sm leading-7 text-[#4b4b4b]">
                        <p>تظهر النسخة الحالية للزوار على الموقع فورًا.{dirty && ' تُحفظ تعديلاتك أولًا ثم تُنشر.'}</p>
                        <p>تبقى النسخة المنشورة الآن في «سجل النسخ المنشورة»، ويمكنك الرجوع إليها في أي وقت.</p>
                    </div>
                </Modal>

                <Modal
                    open={confirmDiscard}
                    onClose={() => setConfirmDiscard(false)}
                    title="تجاهل التعديلات غير المنشورة؟"
                    size="confirm"
                    footer={
                        <>
                            <Button variant="outline" onClick={() => setConfirmDiscard(false)}>إلغاء</Button>
                            <Button variant="danger" loading={busy === 'discard'} onClick={discard}>تجاهل التعديلات</Button>
                        </>
                    }
                >
                    <p className="text-sm leading-7 text-[#4b4b4b]">ترجع المسودة إلى النسخة المنشورة الآن على الموقع، وتُحذف كل التعديلات التي لم تُنشر (المحفوظة وغير المحفوظة). لا يتغير ما يراه الزوار.</p>
                </Modal>
            </BuilderContext.Provider>
        </ClubLayout>
    );
}
