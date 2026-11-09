<?php

namespace App\Modules\Website\Content;

/**
 * The section templates the owner can add to the official website, and the fields each one has.
 *
 * Every value a section stores is described here: the editor builds its forms from this schema, and
 * SiteDocument keeps only these keys and checks each value against its field (plain text with a length limit,
 * a safe link, an image from the club's own library…). No HTML is stored or rendered anywhere, so a new section
 * type is a new entry here and a component in resources/js/Components/Site/Sections — no change to the storage.
 *
 * 'system' sections show data the gym system already keeps (plans, trainers, timetable, products, posts); the
 * owner chooses only their headings and how many items to show.
 */
final class SectionTypes
{
    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        $eyebrow = self::text('eyebrow', 'سطر تمهيدي صغير فوق العنوان', 60);
        $title = self::text('title', 'العنوان', 120);
        $subtitle = self::textarea('subtitle', 'وصف قصير', 400, 2);
        $button = fn (string $key = 'button', string $label = 'زر') => ['key' => $key, 'type' => 'link', 'label' => $label, 'help' => 'اترك النص فارغًا لإخفاء الزر.'];

        return [
            'hero' => [
                'label' => 'الواجهة الرئيسية',
                'description' => 'أول ما يراه الزائر: عنوان كبير وصورة وزرّان.',
                'group' => 'content',
                'tone' => 'dark',
                'fields' => [
                    $eyebrow,
                    self::text('title', 'العنوان (السطر الأول)', 60),
                    self::text('title_2', 'العنوان (السطر الثاني)', 60),
                    self::textarea('subtitle', 'الوصف', 400, 3),
                    $button('primary', 'الزر الرئيسي'),
                    $button('secondary', 'الزر الثاني'),
                    self::image('image', 'صورة الواجهة', 'background'),
                    self::lst('facts', 'نقاط مميزة أسفل الواجهة', 'نقطة', 4, [self::text('title', 'العنوان', 40), self::text('text', 'التفصيل', 60)]),
                    self::toggle('show_next_class', 'إظهار الحصة القادمة من جدول الحصص'),
                ],
                'defaults' => ['eyebrow' => '', 'title' => '', 'title_2' => '', 'subtitle' => '', 'primary' => ['label' => 'ابدئي عضويتكِ', 'href' => '@join'], 'secondary' => ['label' => 'جدول الحصص', 'href' => '#schedule'], 'image' => null, 'facts' => [], 'show_next_class' => true],
            ],
            'intro' => [
                'label' => 'قسم تعريفي',
                'description' => 'عنوان وفقرة للتعريف بالنادي، مع زر اختياري.',
                'group' => 'content',
                'tone' => 'white',
                'fields' => [$eyebrow, $title, self::textarea('body', 'النص', 3000, 6), $button(), self::select('align', 'محاذاة النص', ['start' => 'إلى اليمين', 'center' => 'في الوسط'])],
                'defaults' => ['eyebrow' => '', 'title' => '', 'body' => '', 'button' => ['label' => '', 'href' => ''], 'align' => 'start'],
            ],
            'image_text' => [
                'label' => 'صورة ونص',
                'description' => 'صورة بجانب عنوان وفقرة. يتغير التخطيط تلقائيًا حسب اتجاه الصورة.',
                'group' => 'content',
                'tone' => 'white',
                'fields' => [$eyebrow, $title, self::textarea('body', 'النص', 3000, 6), self::image('image', 'الصورة', 'side'), self::select('image_side', 'مكان الصورة', ['end' => 'على اليسار', 'start' => 'على اليمين']), $button()],
                'defaults' => ['eyebrow' => '', 'title' => '', 'body' => '', 'image' => null, 'image_side' => 'end', 'button' => ['label' => '', 'href' => '']],
            ],
            'gallery' => [
                'label' => 'معرض صور',
                'description' => 'صور النادي كاملة دون قص، مرتبة حسب أبعادها.',
                'group' => 'content',
                'tone' => 'white',
                'fields' => [$eyebrow, $title, self::select('layout', 'طريقة العرض', ['auto' => 'تلقائي حسب أبعاد كل صورة', 'grid' => 'شبكة منتظمة (الصورة كاملة داخل إطار)']), self::lst('items', 'الصور', 'صورة', 30, [self::image('image', 'الصورة', 'gallery'), self::text('caption', 'تعليق (اختياري)', 120)])],
                'defaults' => ['eyebrow' => '', 'title' => '', 'layout' => 'auto', 'items' => []],
            ],
            'services' => [
                'label' => 'بطاقات خدمات أو مرافق',
                'description' => 'بطاقات بعنوان ووصف وصورة اختيارية: الخدمات أو المرافق أو المزايا.',
                'group' => 'content',
                'tone' => 'white',
                'fields' => [$eyebrow, $title, $subtitle, self::select('style', 'الشكل', ['cards' => 'بطاقات', 'list' => 'قائمة']), self::lst('items', 'البطاقات', 'بطاقة', 12, [self::text('title', 'العنوان', 80), self::textarea('text', 'الوصف', 300, 2), self::image('image', 'صورة (اختيارية)', 'card')])],
                'defaults' => ['eyebrow' => '', 'title' => '', 'subtitle' => '', 'style' => 'cards', 'items' => []],
            ],
            'video' => [
                'label' => 'فيديو',
                'description' => 'فيديو من YouTube أو Vimeo مع عنوان ووصف.',
                'group' => 'content',
                'tone' => 'beige',
                'fields' => [$eyebrow, $title, self::textarea('body', 'الوصف', 600, 3), ['key' => 'url', 'type' => 'video', 'label' => 'رابط الفيديو', 'help' => 'انسخ رابط الفيديو من YouTube أو Vimeo.', 'placeholder' => 'https://www.youtube.com/watch?v=…']],
                'defaults' => ['eyebrow' => '', 'title' => '', 'body' => '', 'url' => ''],
            ],
            'testimonials' => [
                'label' => 'آراء العضوات',
                'description' => 'أقوال العضوات عن النادي. الرأي الأول يظهر كبيرًا.',
                'group' => 'content',
                'tone' => 'beige',
                'fields' => [$eyebrow, $title, self::lst('items', 'الآراء', 'رأي', 12, [self::textarea('quote', 'الرأي', 500, 3), self::text('name', 'الاسم', 60), self::text('meta', 'تفصيل (مثل: عضوة منذ ٢٠٢٤)', 60)])],
                'defaults' => ['eyebrow' => '', 'title' => '', 'items' => []],
            ],
            'stats' => [
                'label' => 'أرقام وإحصائيات',
                'description' => 'أرقام بارزة مثل عدد الحصص أو سنوات الخبرة.',
                'group' => 'content',
                'tone' => 'dark',
                'fields' => [$eyebrow, $title, self::lst('items', 'الأرقام', 'رقم', 6, [self::text('value', 'الرقم', 20), self::text('label', 'الوصف', 60)])],
                'defaults' => ['eyebrow' => '', 'title' => '', 'items' => []],
            ],
            'faq' => [
                'label' => 'الأسئلة الشائعة',
                'description' => 'أسئلة وأجوبة تُفتح بالضغط عليها.',
                'group' => 'content',
                'tone' => 'white',
                'fields' => [$eyebrow, $title, self::lst('items', 'الأسئلة', 'سؤال', 30, [self::text('q', 'السؤال', 200), self::textarea('a', 'الجواب', 1500, 3)])],
                'defaults' => ['eyebrow' => '', 'title' => '', 'items' => []],
            ],
            'cta' => [
                'label' => 'دعوة لإجراء',
                'description' => 'شريط بارز بعنوان وزرّين: اشتراك أو حجز أو تواصل.',
                'group' => 'content',
                'tone' => 'dark',
                'fields' => [$eyebrow, $title, self::textarea('body', 'النص', 600, 2), $button('primary', 'الزر الرئيسي'), $button('secondary', 'الزر الثاني')],
                'defaults' => ['eyebrow' => '', 'title' => '', 'body' => '', 'primary' => ['label' => '', 'href' => '@join'], 'secondary' => ['label' => '', 'href' => '']],
            ],
            'programs' => [
                'label' => 'البرامج',
                'description' => 'البرامج المنشورة في النظام.',
                'group' => 'system',
                'source' => 'من «البرامج» في النظام: ما كان منها ظاهرًا في الموقع.',
                'tone' => 'white',
                'fields' => [$eyebrow, $title, $subtitle, self::number('limit', 'أقصى عدد', 1, 12), $button('button', 'زر «كل البرامج»')],
                'defaults' => ['eyebrow' => '', 'title' => '', 'subtitle' => '', 'limit' => 6, 'button' => ['label' => '', 'href' => '@programs']],
            ],
            'schedule' => [
                'label' => 'جدول الحصص',
                'description' => 'الحصص القادمة ومقاعدها المتاحة، والحجز من بوابة العضوات.',
                'group' => 'system',
                'source' => 'من «جدول الحصص» في النظام: الحصص المجدولة لبرامج ظاهرة في الموقع.',
                'tone' => 'beige',
                'fields' => [$eyebrow, $title, self::number('days', 'عدد الأيام المعروضة', 1, 14), self::toggle('show_trainer', 'إظهار اسم المدربة')],
                'defaults' => ['eyebrow' => '', 'title' => '', 'days' => 7, 'show_trainer' => true],
            ],
            'trainers' => [
                'label' => 'المدربات',
                'description' => 'فريق التدريب وصوره من النظام.',
                'group' => 'system',
                'source' => 'من «المدربين» في النظام: من كان ظاهرًا في الموقع، بصورته ونبذته فقط.',
                'tone' => 'white',
                'fields' => [$eyebrow, $title, $subtitle, self::number('limit', 'أقصى عدد', 1, 12)],
                'defaults' => ['eyebrow' => '', 'title' => '', 'subtitle' => '', 'limit' => 4],
            ],
            'plans' => [
                'label' => 'الباقات',
                'description' => 'باقات العضوية وأسعارها، والاشتراك من صفحة التسجيل.',
                'group' => 'system',
                'source' => 'من «باقات العضوية» في النظام: المنشورة والمفعّلة فقط.',
                'tone' => 'white',
                'fields' => [$eyebrow, $title, $subtitle, self::number('limit', 'أقصى عدد', 1, 12), self::text('featured_label', 'وسم الباقة المميزة', 30), self::text('button_label', 'نص زر الاشتراك', 30), $button('button', 'زر «كل الباقات»')],
                'defaults' => ['eyebrow' => '', 'title' => '', 'subtitle' => '', 'limit' => 3, 'featured_label' => 'الأكثر طلباً', 'button_label' => 'اختاري الباقة', 'button' => ['label' => '', 'href' => '@plans']],
            ],
            'products' => [
                'label' => 'المنتجات',
                'description' => 'منتجات المتجر المعروضة في الموقع.',
                'group' => 'system',
                'source' => 'من «المنتجات» في النظام: المخصصة للعرض في الموقع، والمتجر مفتوح.',
                'tone' => 'white',
                'fields' => [$eyebrow, $title, self::number('limit', 'أقصى عدد', 1, 12)],
                'defaults' => ['eyebrow' => '', 'title' => '', 'limit' => 4],
            ],
            'posts' => [
                'label' => 'العروض والأخبار',
                'description' => 'آخر العروض والأخبار المنشورة.',
                'group' => 'system',
                'source' => 'من «الأخبار والعروض» في إدارة الموقع: المنشورة فقط.',
                'tone' => 'white',
                'fields' => [$eyebrow, $title, self::select('kind', 'المعروض', ['all' => 'العروض والأخبار', 'offer' => 'العروض فقط', 'news' => 'الأخبار فقط']), self::number('limit', 'أقصى عدد', 1, 9)],
                'defaults' => ['eyebrow' => '', 'title' => '', 'kind' => 'all', 'limit' => 3],
            ],
            'join' => [
                'label' => 'التسجيل',
                'description' => 'دعوة للتسجيل بخطوات واضحة، تفتح صفحة الاشتراك الحالية.',
                'group' => 'system',
                'source' => 'يفتح صفحة التسجيل والدفع الموجودة، ويعرض الباقات المنشورة للاختيار السريع.',
                'tone' => 'white',
                'fields' => [$eyebrow, $title, $subtitle, self::lst('steps', 'الخطوات', 'خطوة', 5, [self::text('text', 'الخطوة', 80)]), self::text('button_label', 'نص الزر', 30), self::toggle('show_plans', 'عرض الباقات للاختيار السريع')],
                'defaults' => ['eyebrow' => '', 'title' => '', 'subtitle' => '', 'steps' => [], 'button_label' => 'ابدئي التسجيل', 'show_plans' => true],
            ],
            'contact' => [
                'label' => 'معلومات التواصل',
                'description' => 'الهاتف والعنوان وأوقات العمل ونموذج مراسلة. تُعدَّل البيانات من تبويب «التواصل».',
                'group' => 'system',
                'source' => 'من تبويب «التواصل وأوقات العمل»، والرسائل تصل إلى «رسائل التواصل».',
                'tone' => 'white',
                'fields' => [$eyebrow, $title, self::toggle('show_form', 'إظهار نموذج المراسلة'), self::toggle('show_hours', 'إظهار أوقات العمل'), self::toggle('show_map', 'إظهار زر الخريطة')],
                'defaults' => ['eyebrow' => '', 'title' => '', 'show_form' => true, 'show_hours' => true, 'show_map' => true],
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    public static function get(string $type): ?array
    {
        return self::all()[$type] ?? null;
    }

    /** The schema as the editor receives it. @return list<array<string, mixed>> */
    public static function forEditor(): array
    {
        return collect(self::all())->map(fn ($t, $type) => [
            'type' => $type,
            'label' => $t['label'],
            'description' => $t['description'],
            'group' => $t['group'],
            'source' => $t['source'] ?? null,
            'fields' => $t['fields'],
            'defaults' => $t['defaults'],
            'tone' => $t['tone'],
        ])->values()->all();
    }

    /** @return array<string, mixed> */
    private static function text(string $key, string $label, int $max): array
    {
        return ['key' => $key, 'type' => 'text', 'label' => $label, 'max' => $max];
    }

    /** @return array<string, mixed> */
    private static function textarea(string $key, string $label, int $max, int $rows): array
    {
        return ['key' => $key, 'type' => 'textarea', 'label' => $label, 'max' => $max, 'rows' => $rows];
    }

    /** @return array<string, mixed> */
    private static function image(string $key, string $label, string $usage): array
    {
        return ['key' => $key, 'type' => 'image', 'label' => $label, 'usage' => $usage];
    }

    /** @return array<string, mixed> */
    private static function toggle(string $key, string $label): array
    {
        return ['key' => $key, 'type' => 'toggle', 'label' => $label];
    }

    /** @param array<string, string> $options @return array<string, mixed> */
    private static function select(string $key, string $label, array $options): array
    {
        return ['key' => $key, 'type' => 'select', 'label' => $label, 'options' => collect($options)->map(fn ($l, $v) => ['value' => $v, 'label' => $l])->values()->all()];
    }

    /** @return array<string, mixed> */
    private static function number(string $key, string $label, int $min, int $max): array
    {
        return ['key' => $key, 'type' => 'number', 'label' => $label, 'min' => $min, 'max' => $max];
    }

    /** @param list<array<string, mixed>> $fields @return array<string, mixed> */
    private static function lst(string $key, string $label, string $item, int $max, array $fields): array
    {
        return ['key' => $key, 'type' => 'list', 'label' => $label, 'item' => $item, 'max' => $max, 'fields' => $fields];
    }
}
