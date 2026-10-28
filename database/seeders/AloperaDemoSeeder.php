<?php

namespace Database\Seeders;

use App\Core\Auth\SystemRoles;
use App\Core\Support\NumberSequence;
use App\Core\Tenancy\TenantContext;
use App\Models\PlatformPlan;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantIntegration;
use App\Models\User;
use App\Modules\Finance\Models\Expense;
use App\Modules\Finance\Models\ExpenseCategory;
use App\Modules\Finance\Services\PaymentService;
use App\Modules\Gym\Models\Booking;
use App\Modules\Gym\Models\CheckIn;
use App\Modules\Gym\Models\ClassSchedule;
use App\Modules\Gym\Models\ClassSession;
use App\Modules\Gym\Models\Coupon;
use App\Modules\Gym\Models\Member;
use App\Modules\Gym\Models\MemberNote;
use App\Modules\Gym\Models\Membership;
use App\Modules\Gym\Models\MembershipPlan;
use App\Modules\Gym\Models\Program;
use App\Modules\Gym\Models\PtPackage;
use App\Modules\Gym\Models\Trainer;
use App\Modules\Gym\Services\BookingService;
use App\Modules\Gym\Services\MemberService;
use App\Modules\Gym\Services\MembershipService;
use App\Modules\Gym\Services\PtService;
use App\Modules\Gym\Services\ScheduleService;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductCategory;
use App\Modules\Inventory\Models\Supplier;
use App\Modules\Inventory\Services\PosService;
use App\Modules\Inventory\Services\PurchaseService;
use App\Modules\Messaging\Models\AutoReply;
use App\Modules\Messaging\Models\Conversation;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Services\TemplateCatalog;
use App\Modules\Platform\Services\SubscriptionService;
use App\Modules\Platform\Services\TenantProvisioner;
use App\Modules\Website\Models\SitePost;
use App\Modules\Website\Models\SiteSetting;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Demo data for local development and demos. Never run in production.
 *
 * Builds the platform plans, a super admin, Alopera Gym as the first club
 * (with staff for every role, plans, programs, trainers, timetable, members
 * with realistic history, payments, POS, expenses, WhatsApp log and website
 * content) and a second small club to demonstrate tenant isolation.
 *
 * History is produced by the real services with time travel, so every
 * invoice, receipt, activation and expiry went through production code paths.
 */
class AloperaDemoSeeder extends Seeder
{
    public const PASSWORD = 'password';

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->error('Refusing to seed demo data in production.');

            return;
        }

        config(['queue.default' => 'sync', 'services.whatsapp.driver' => 'log']);
        mt_srand(2026);
        fake()->seed(2026);

        $plans = $this->platformPlans();
        $this->platformAdmin();

        $alopera = app(TenantProvisioner::class)->provision(
            ['name' => 'Alopera Gym', 'slug' => 'alopera', 'email' => 'hello@alopera.test', 'phone' => '96824000000', 'city' => 'مسقط', 'primary_color' => '#9d174d', 'secondary_color' => '#f9a8d4'],
            ['name' => 'مالكة النادي', 'email' => 'owner@alopera.test', 'password' => self::PASSWORD, 'phone' => '96890000001'],
            $plans['pro'],
        );
        $alopera->update(['legal_name' => 'Alopera Ladies Fitness LLC', 'address' => 'الخوير، مسقط', 'status' => Tenant::STATUS_ACTIVE]);
        app(SubscriptionService::class)->markPaid(
            app(SubscriptionService::class)->subscribe($alopera, $plans['pro'], 'yearly')->invoices()->first(),
            'bank_transfer', 'DEMO-TRX-001'
        );

        app(TenantContext::class)->run($alopera->fresh(), fn () => $this->seedAlopera());

        $demo = app(TenantProvisioner::class)->provision(
            ['name' => 'Demo Fit Club', 'slug' => 'demofit', 'city' => 'صلالة', 'primary_color' => '#0f766e', 'secondary_color' => '#99f6e4'],
            ['name' => 'Demo Owner', 'email' => 'owner@demofit.test', 'password' => self::PASSWORD],
            $plans['starter'],
        );
        app(TenantContext::class)->run($demo, fn () => $this->seedSecondClub());

        Carbon::setTestNow();
        CarbonImmutable::setTestNow();

        $this->command?->info('Demo ready. Staff password for all demo accounts: '.self::PASSWORD);
    }

    /** @return array<string, PlatformPlan> */
    private function platformPlans(): array
    {
        $rows = [
            'starter' => ['name' => 'Starter', 'description' => 'لنادٍ صغير بفرع واحد', 'price_monthly' => 25, 'price_yearly' => 250, 'max_members' => 300, 'max_staff' => 5, 'max_branches' => 1, 'sort' => 1,
                'features' => ['إدارة العضويات والحجوزات', 'الموقع وبوابة المشتركات', 'التقارير الأساسية']],
            'pro' => ['name' => 'Pro', 'description' => 'الأكثر طلبًا للأندية النشطة', 'price_monthly' => 45, 'price_yearly' => 450, 'max_members' => 1500, 'max_staff' => 20, 'max_branches' => 3, 'sort' => 2,
                'features' => ['كل مزايا Starter', 'أتمتة واتساب والحملات', 'الدفع الإلكتروني', 'نقطة البيع والمخزون', 'أجهزة الدخول']],
            'enterprise' => ['name' => 'Enterprise', 'description' => 'سلاسل أندية وفروع متعددة', 'price_monthly' => 95, 'price_yearly' => 950, 'max_members' => null, 'max_staff' => null, 'max_branches' => null, 'sort' => 3,
                'features' => ['كل مزايا Pro', 'فروع غير محدودة', 'خادم وقاعدة بيانات مستقلة', 'دعم أولوية']],
        ];

        return collect($rows)->mapWithKeys(fn ($r, $code) => [$code => PlatformPlan::updateOrCreate(['code' => $code], $r + ['trial_days' => 14])])->all();
    }

    private function platformAdmin(): void
    {
        $admin = User::firstOrNew(['email' => 'admin@alopera.test']);
        $admin->fill(['name' => 'مدير المنصة', 'password' => self::PASSWORD]);
        $admin->forceFill(['is_platform_admin' => true, 'tenant_id' => null, 'email_verified_at' => now()])->save();
    }

    private function seedAlopera(): void
    {
        $tenant = app(TenantContext::class)->require();
        $tenant->update(['settings' => array_replace($tenant->settings ?? [], ['vat_rate' => 0, 'invoice_footer' => 'شكرًا لاختيارك Alopera Gym 🌸'])]);

        $staff = $this->staff($tenant);
        $programs = $this->programs();
        $trainers = $this->trainers($staff['trainers']);
        $plans = $this->plans($programs);
        $this->coupons($plans);
        $this->ptPackages($trainers);
        $this->schedule($programs, $trainers);
        $this->whatsapp();
        $this->website();
        $products = $this->inventory();

        $members = $this->members($plans);
        $this->classHistory($members);
        $this->checkIns($members);
        $this->futureBookings($members);
        $this->ptActivity($members, $trainers);
        $this->sales($products, $members);
        $this->expenses();
        $this->conversations($members);
    }

    /** @return array{trainers: list<User>} */
    private function staff(Tenant $tenant): array
    {
        $roles = Role::pluck('id', 'key');
        $make = function (string $name, string $email, string $role) use ($tenant, $roles) {
            $u = new User(['name' => $name, 'email' => $email, 'password' => self::PASSWORD, 'phone' => '9689'.random_int(1000000, 9999999)]);
            $u->tenant_id = $tenant->id;
            $u->role_id = $roles[$role];
            $u->email_verified_at = now();
            $u->save();

            return $u;
        };

        $make('ليلى — الإدارة', 'manager@alopera.test', SystemRoles::MANAGER);
        $make('هند — الاستقبال', 'reception@alopera.test', SystemRoles::RECEPTION);
        $make('سلمى — المالية', 'finance@alopera.test', SystemRoles::FINANCE);

        return ['trainers' => [
            $make('الكوتش ريم', 'trainer@alopera.test', SystemRoles::TRAINER),
            $make('الكوتش دانة', 'trainer2@alopera.test', SystemRoles::TRAINER),
        ]];
    }

    /** @return array<string, Program> */
    private function programs(): array
    {
        $rows = [
            'zumba' => ['زومبا', '#db2777', 'class', 'all', 'رقص لاتيني ممتع يحرق السعرات ويرفع الطاقة.', 50, 20],
            'pilates' => ['بيلاتس', '#7c3aed', 'class', 'all', 'تقوية عضلات الجذع وتحسين القوام والمرونة.', 55, 12],
            'yoga' => ['يوغا', '#0d9488', 'class', 'all', 'توازن وتنفس واسترخاء — للجسم والذهن.', 60, 15],
            'strength' => ['تمارين القوة', '#b45309', 'program', 'intermediate', 'برنامج أوزان ومقاومة لبناء عضلات متناسقة.', 60, 10],
            'hiit' => ['HIIT', '#dc2626', 'class', 'advanced', 'تمارين متقطعة عالية الشدة لحرق سريع.', 45, 14],
            'stretch' => ['إطالة ومرونة', '#2563eb', 'class', 'beginner', 'جلسة إطالة هادئة لتحسين المدى الحركي.', 40, 15],
            'kickbox' => ['كيك بوكس', '#be123c', 'class', 'intermediate', 'لياقة ودفاع عن النفس بإيقاع حماسي.', 50, 12],
        ];

        $out = [];
        foreach ($rows as $key => [$name, $color, $category, $level, $desc, $minutes, $capacity]) {
            $out[$key] = Program::create([
                'name' => $name, 'slug' => $key, 'color' => $color, 'category' => $category, 'level' => $level,
                'description' => $desc, 'duration_minutes' => $minutes, 'default_capacity' => $capacity, 'sort' => count($out),
            ]);
        }

        return $out;
    }

    /** @param list<User> $users @return list<Trainer> */
    private function trainers(array $users): array
    {
        $rows = [
            ['الكوتش ريم', 'مدربة معتمدة في الزومبا والـHIIT بخبرة 8 سنوات.', ['زومبا', 'HIIT', 'كارديو'], '#db2777', $users[0]->id],
            ['الكوتش دانة', 'متخصصة في البيلاتس والتأهيل الحركي بعد الولادة.', ['بيلاتس', 'تأهيل', 'إطالة'], '#7c3aed', $users[1]->id],
            ['الكوتش نوف', 'مدربة يوغا معتمدة RYT-200.', ['يوغا', 'تأمل'], '#0d9488', null],
            ['الكوتش شهد', 'مدربة قوة وتغذية رياضية، تدريب شخصي.', ['قوة', 'تدريب شخصي', 'تغذية'], '#b45309', null],
        ];

        $out = [];
        foreach ($rows as $i => [$name, $bio, $spec, $color, $userId]) {
            $t = Trainer::create(['name' => $name, 'bio' => $bio, 'specialties' => $spec, 'color' => $color, 'user_id' => $userId, 'sort' => $i, 'phone' => '9689'.random_int(1000000, 9999999)]);
            foreach ([0, 1, 2, 3, 4, 6] as $day) {
                $t->availabilities()->create(['weekday' => $day, 'starts_at' => '08:00', 'ends_at' => '12:00']);
                $t->availabilities()->create(['weekday' => $day, 'starts_at' => '16:00', 'ends_at' => '22:00']);
            }
            $out[] = $t;
        }

        return $out;
    }

    /** @param array<string, Program> $programs @return array<string, MembershipPlan> */
    private function plans(array $programs): array
    {
        $rows = [
            'monthly' => ['الشهرية', 'combo', 1, 'month', 25, 5, null, true, 7, 1, false, ['دخول الصالة الرياضية', 'كل الحصص الجماعية', 'تقييم لياقة مجاني']],
            'quarter' => ['ربع سنوية', 'combo', 3, 'month', 65, 5, null, true, 14, 2, true, ['دخول الصالة الرياضية', 'كل الحصص الجماعية', 'تجميد حتى 14 يومًا', 'خصم 10% على التدريب الشخصي']],
            'semi' => ['نصف سنوية', 'combo', 6, 'month', 120, 0, null, true, 30, 3, false, ['كل مزايا الربع سنوية', 'تجميد حتى 30 يومًا', 'بدون رسوم تسجيل']],
            'yearly' => ['السنوية', 'combo', 1, 'year', 220, 0, null, true, 45, 4, false, ['أفضل قيمة', 'تجميد حتى 45 يومًا', 'جلستا تدريب شخصي مجانًا']],
            'classes12' => ['باقة 12 حصة', 'classes', 2, 'month', 30, 0, 12, false, 0, 0, false, ['12 حصة جماعية خلال شهرين', 'زومبا، بيلاتس، يوغا، إطالة']],
            'gym' => ['الصالة فقط', 'gym', 1, 'month', 18, 5, null, true, 7, 1, false, ['دخول الصالة الرياضية والأجهزة', 'بدون حصص جماعية']],
        ];

        $out = [];
        foreach ($rows as $key => [$name, $type, $dv, $du, $price, $fee, $sessions, $freeze, $freezeDays, $freezes, $featured, $features]) {
            $out[$key] = MembershipPlan::create([
                'name' => $name, 'type' => $type, 'duration_value' => $dv, 'duration_unit' => $du, 'price' => $price, 'signup_fee' => $fee,
                'sessions_limit' => $sessions, 'freeze_allowed' => $freeze, 'max_freeze_days' => $freezeDays, 'max_freezes' => $freezes,
                'is_featured' => $featured, 'features' => $features, 'sort' => count($out),
                'description' => 'باقة '.$name.' في Alopera Gym.',
            ]);
        }

        $out['classes12']->programs()->sync([$programs['zumba']->id, $programs['pilates']->id, $programs['yoga']->id, $programs['stretch']->id]);
        // "gym only" includes no classes: link it to a non-class program so class bookings are refused.
        $out['gym']->programs()->sync([$programs['strength']->id]);

        return $out;
    }

    /** @param array<string, MembershipPlan> $plans */
    private function coupons(array $plans): void
    {
        Coupon::create(['code' => 'WELCOME10', 'description' => 'خصم 10% للمشتركات الجدد', 'type' => 'percent', 'value' => 10, 'per_member_limit' => 1]);
        Coupon::create(['code' => 'SUMMER5', 'description' => 'خصم 5 ريال على الربع سنوية', 'type' => 'fixed', 'value' => 5, 'plan_ids' => [$plans['quarter']->id], 'valid_until' => today()->addMonths(2), 'max_uses' => 100]);
    }

    /** @param list<Trainer> $trainers */
    private function ptPackages(array $trainers): void
    {
        PtPackage::create(['name' => '4 جلسات تدريب شخصي', 'sessions_count' => 4, 'price' => 60, 'validity_days' => 30, 'description' => 'بداية مثالية مع خطة تمارين وتغذية.']);
        PtPackage::create(['name' => '8 جلسات تدريب شخصي', 'sessions_count' => 8, 'price' => 110, 'validity_days' => 60, 'description' => 'الأكثر طلبًا — متابعة أسبوعية مرتين.']);
        PtPackage::create(['name' => '12 جلسة مع الكوتش شهد', 'sessions_count' => 12, 'price' => 150, 'validity_days' => 90, 'trainer_id' => $trainers[3]->id]);
    }

    /** @param array<string, Program> $p @param list<Trainer> $t */
    private function schedule(array $p, array $t): void
    {
        $slots = [
            // weekday 0=Sun … 6=Sat
            [6, '09:00', 'pilates', 1], [6, '17:30', 'zumba', 0], [6, '19:00', 'strength', 3],
            [0, '09:30', 'yoga', 2], [0, '17:30', 'hiit', 0], [0, '19:00', 'pilates', 1],
            [1, '09:00', 'stretch', 1], [1, '18:00', 'zumba', 0], [1, '19:30', 'kickbox', 0],
            [2, '09:30', 'yoga', 2], [2, '17:30', 'pilates', 1], [2, '19:00', 'strength', 3],
            [3, '09:00', 'pilates', 1], [3, '18:00', 'hiit', 0], [3, '19:30', 'yoga', 2],
            [4, '10:00', 'zumba', 0], [4, '17:00', 'stretch', 1],
        ];

        foreach ($slots as [$day, $time, $program, $trainer]) {
            ClassSchedule::create([
                'program_id' => $p[$program]->id, 'trainer_id' => $t[$trainer]->id, 'weekday' => $day, 'starts_at' => $time,
                'duration_minutes' => $p[$program]->duration_minutes, 'capacity' => $p[$program]->default_capacity,
                'room' => in_array($program, ['strength'], true) ? 'صالة الأوزان' : 'الاستوديو', 'valid_from' => today()->subWeeks(5),
            ]);
        }

        app(ScheduleService::class)->generate(4);
    }

    private function whatsapp(): void
    {
        // Demo only: enabled with the log driver so the message log fills; no real messages leave the server.
        TenantIntegration::updateOrCreate(['provider' => TenantIntegration::WHATSAPP], [
            'is_enabled' => true, 'mode' => 'test', 'credentials' => [], 'settings' => ['display_phone' => '+968 2400 0000'],
        ]);
        TemplateCatalog::seedDefaults(approved: true);
        MessageTemplate::query()->update(['status' => 'approved']);

        AutoReply::create(['keyword' => 'الأسعار', 'reply' => 'أهلًا بكِ 🌸 أسعار باقاتنا هنا: الموقع ← الباقات. يسعدنا خدمتك!']);
        AutoReply::create(['keyword' => 'الدوام', 'reply' => 'أوقات العمل: السبت–الخميس 6 صباحًا – 11 مساءً، الجمعة 4 – 10 مساءً.']);
    }

    private function website(): void
    {
        SiteSetting::query()->update([
            'hero_title' => 'قوتكِ تبدأ هنا',
            'hero_subtitle' => 'نادٍ نسائي متكامل في قلب مسقط — حصص جماعية، تدريب شخصي، ومدربات محترفات في أجواء خاصة وآمنة.',
            'about_title' => 'عن Alopera Gym',
            'about_body' => "Alopera Gym مساحة صُممت للمرأة: صالة أجهزة حديثة، استوديو للحصص الجماعية، ومدربات معتمدات يرافقنك خطوة بخطوة.\n\nنؤمن أن اللياقة أسلوب حياة، لذلك نوفر باقات مرنة، وحجزًا أونلاين للحصص، ومتابعة شخصية لأهدافك.",
            'announcement' => 'عرض الافتتاح: خصم 10% بكود WELCOME10 على أول اشتراك',
            'contact_phone' => '96824000000',
            'whatsapp_number' => '96890000000',
            'contact_email' => 'hello@alopera.test',
            'address' => 'الخوير، شارع 33، مسقط',
            'map_url' => 'https://maps.google.com/?q=Al+Khuwair+Muscat',
            'working_hours' => [['days' => 'السبت – الخميس', 'hours' => '6:00 ص – 11:00 م'], ['days' => 'الجمعة', 'hours' => '4:00 م – 10:00 م']],
            'social_links' => ['instagram' => 'https://instagram.com/alopera.gym'],
            'stats' => [['label' => 'مشتركة سعيدة', 'value' => '+500'], ['label' => 'حصة أسبوعيًا', 'value' => '17'], ['label' => 'مدربة معتمدة', 'value' => '4']],
        ]);

        SitePost::create(['type' => 'offer', 'title' => 'عرض الصيف: ربع سنوية بخصم 5 ريال', 'slug' => 'summer-offer', 'excerpt' => 'استخدمي الكود SUMMER5 عند الاشتراك في الباقة الربع سنوية.', 'body' => 'العرض ساري لفترة محدودة على الباقة الربع سنوية فقط.', 'is_published' => true, 'published_at' => now()->subDays(3), 'ends_on' => today()->addMonths(2)]);
        SitePost::create(['type' => 'news', 'title' => 'إطلاق حصص الكيك بوكس', 'slug' => 'kickboxing-launch', 'excerpt' => 'حصة جديدة كل اثنين مع الكوتش ريم.', 'body' => 'انضمي لحصص الكيك بوكس الجديدة: لياقة، قوة، وثقة.', 'is_published' => true, 'published_at' => now()->subDays(10)]);
    }

    /** @return list<Product> */
    private function inventory(): array
    {
        $supplier = Supplier::create(['name' => 'مؤسسة الصحة للتجهيزات الرياضية', 'contact_name' => 'أحمد', 'phone' => '96890000002']);
        Supplier::create(['name' => 'مياه النقاء', 'phone' => '96890000003']);

        $rows = [['مياه 500 مل', 0.2, 0.08, 'مشروبات'], ['مشروب طاقة رياضي', 0.8, 0.45, 'مشروبات'], ['بروتين بار', 1.2, 0.7, 'مكملات'],
            ['منشفة Alopera', 3.5, 1.8, 'إكسسوارات'], ['شيكر بروتين', 2.5, 1.2, 'إكسسوارات'], ['قفازات تمرين', 4.0, 2.2, 'إكسسوارات'], ['حصيرة يوغا', 9.0, 5.0, 'إكسسوارات']];

        $products = [];
        foreach ($rows as $i => [$name, $price, $cost, $cat]) {
            $p = new Product(['name' => $name, 'price' => $price, 'sku' => 'ALP-'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT), 'reorder_level' => 5]);
            $p->product_category_id = ProductCategory::firstOrCreate(['name' => $cat])->id;
            $p->save();
            $products[] = [$p, $cost];
        }

        $this->travel(today()->subDays(40)->setTime(11, 0), function () use ($supplier, $products) {
            $po = app(PurchaseService::class)->create($supplier, array_map(fn ($r) => ['product_id' => $r[0]->id, 'quantity' => $r[0]->name === 'حصيرة يوغا' ? 6 : 40, 'unit_cost' => $r[1]], $products));
            app(PurchaseService::class)->receive($po);
        });

        return array_column($products, 0);
    }

    /** @param array<string, MembershipPlan> $plans @return list<Member> */
    private function members(array $plans): array
    {
        $memberService = app(MemberService::class);
        $memberships = app(MembershipService::class);
        $payments = app(PaymentService::class);
        $today = CarbonImmutable::today();
        $out = [];

        $scenarios = array_merge(
            array_fill(0, 30, 'active'), array_fill(0, 6, 'expiring'), array_fill(0, 8, 'expired'),
            array_fill(0, 6, 'renewed'), array_fill(0, 2, 'frozen'), array_fill(0, 4, 'pending'), array_fill(0, 4, 'partial'),
        );
        $planPool = ['monthly', 'monthly', 'monthly', 'quarter', 'quarter', 'semi', 'yearly', 'classes12', 'gym'];

        foreach ($scenarios as $i => $scenario) {
            $plan = $plans[$planPool[$i % count($planPool)]];
            $days = (int) $today->diffInDays($plan->endDateFrom($today)) + 1;

            $start = match ($scenario) {
                'active', 'frozen' => $today->subDays(random_int(0, max(0, $days - 10))),
                'expiring' => $today->subDays($days - random_int(2, 6)),
                'expired' => $today->subDays($days + random_int(5, 60)),
                'renewed' => $today->subDays($days + random_int(1, 10)),
                default => $today,
            };

            $member = $this->travel($start->setTime(10, 0), function () use ($memberService, $memberships, $payments, $plan, $scenario, $i) {
                $data = Member::factory()->make()->only(['full_name', 'phone', 'email', 'gender', 'date_of_birth', 'source', 'marketing_opt_in']);
                $member = $memberService->create($data + ['joined_on' => today()], $i < 10 ? self::PASSWORD : null);
                $membership = $memberships->sell($member, $plan, $i % 7 === 0 && $scenario === 'active' ? ['coupon_code' => 'WELCOME10'] : []);
                $invoice = $membership->invoice()->first();

                if ($scenario === 'pending') {
                    return $member;
                }
                if ($scenario === 'partial') {
                    $payments->record($invoice, round((float) $invoice->total / 2, 3), 'cash');

                    return $member;
                }

                $method = ['cash', 'cash', 'card', 'bank_transfer'][$i % 4];
                $payment = $payments->record($invoice, $invoice->total, $method, ['reference' => $method === 'card' ? 'POS-'.random_int(100000, 999999) : null]);
                if ($method === 'bank_transfer') {
                    $payments->confirm($payment);
                }

                return $member;
            });

            $member = $member->fresh();
            if ($scenario === 'renewed') {
                $current = Membership::where('member_id', $member->id)->latest('id')->first();
                $this->travel($current->ends_on->subDays(2)->setTime(18, 0), function () use ($memberships, $payments, $member, $plan) {
                    $renewal = $memberships->renew($member, $plan);
                    $payments->record($renewal->invoice()->first(), $renewal->total, 'cash');
                });
            }

            $out[] = $member;
        }

        // Expire finished periods and start renewals exactly as the nightly job does.
        $memberships->runDailyTransitions();

        // Freeze two active members.
        foreach (Membership::where('status', Membership::ACTIVE)->whereHas('plan', fn ($q) => $q->where('freeze_allowed', true))->limit(2)->get() as $m) {
            $memberships->freeze($m, 7, 'سفر');
        }

        // CRM notes
        foreach (array_slice($out, 0, 8) as $member) {
            MemberNote::create(['member_id' => $member->id, 'channel' => 'call', 'direction' => 'outbound', 'body' => 'تم التواصل لتذكيرها بموعد التجديد، أبدت اهتمامًا بالباقة الربع سنوية.', 'user_id' => User::ofTenant($member->tenant_id)->value('id')]);
        }

        return $out;
    }

    /** Past class sessions with bookings and attendance, so class reports have data. @param list<Member> $members */
    private function classHistory(array $members): void
    {
        $schedules = ClassSchedule::all();
        $activeIds = Membership::whereIn('status', [Membership::ACTIVE, Membership::FROZEN])->pluck('id', 'member_id');
        $memberIds = $activeIds->keys()->all();

        for ($d = 21; $d >= 1; $d--) {
            $date = CarbonImmutable::today()->subDays($d);
            foreach ($schedules->where('weekday', $date->dayOfWeek) as $s) {
                $start = CarbonImmutable::parse($date->toDateString().' '.$s->starts_at);
                $session = ClassSession::firstOrCreate(['class_schedule_id' => $s->id, 'starts_at' => $start], [
                    'program_id' => $s->program_id, 'trainer_id' => $s->trainer_id, 'ends_at' => $start->addMinutes($s->duration_minutes),
                    'capacity' => $s->capacity, 'room' => $s->room, 'status' => ClassSession::COMPLETED,
                ]);
                $count = min($s->capacity, random_int((int) ($s->capacity * 0.4), $s->capacity));
                $picked = collect($memberIds)->shuffle()->take($count);
                foreach ($picked as $memberId) {
                    Booking::create([
                        'class_session_id' => $session->id, 'member_id' => $memberId, 'membership_id' => $activeIds[$memberId],
                        'status' => random_int(1, 100) <= 82 ? Booking::ATTENDED : Booking::NO_SHOW, 'source' => random_int(0, 1) ? 'portal' : 'reception',
                        'created_at' => $start->subDay(), 'updated_at' => $start,
                    ]);
                }
                ClassSession::whereKey($session->id)->update(['booked_count' => $picked->count()]);
            }
        }
    }

    /** @param list<Member> $members */
    private function checkIns(array $members): void
    {
        $active = Membership::where('status', Membership::ACTIVE)->get(['id', 'member_id']);
        foreach ($active as $m) {
            for ($i = 0; $i < random_int(3, 14); $i++) {
                $at = now()->subDays(random_int(0, 25))->setTime(random_int(7, 21), random_int(0, 59));
                CheckIn::create(['member_id' => $m->member_id, 'membership_id' => $m->id, 'method' => random_int(0, 2) ? 'qr' : 'manual', 'result' => 'allowed', 'reason' => 'ok', 'checked_in_at' => $at, 'created_at' => $at]);
            }
            Member::whereKey($m->member_id)->update(['last_check_in_at' => now()->subDays(random_int(0, 6))]);
        }

        // A few denied attempts (expired members trying the door)
        foreach (Membership::where('status', Membership::EXPIRED)->limit(4)->get(['member_id']) as $m) {
            CheckIn::create(['member_id' => $m->member_id, 'method' => 'qr', 'result' => 'denied', 'reason' => 'membership_expired', 'checked_in_at' => now()->subDays(random_int(0, 5))]);
        }
    }

    /** @param list<Member> $members */
    private function futureBookings(array $members): void
    {
        $service = app(BookingService::class);
        $sessions = ClassSession::where('status', ClassSession::SCHEDULED)->where('starts_at', '>', now())->where('starts_at', '<', now()->addDays(7))->get();
        $memberIds = Membership::where('status', Membership::ACTIVE)->pluck('member_id')->unique();

        foreach ($sessions as $session) {
            foreach ($memberIds->shuffle()->take(random_int(3, $session->capacity)) as $memberId) {
                try {
                    $service->book(Member::find($memberId), $session, random_int(0, 1) ? 'portal' : 'reception');
                } catch (\Throwable) {
                    // plan does not include the program / overlap — skip, like real life
                }
            }
        }
    }

    /** @param list<Member> $members @param list<Trainer> $trainers */
    private function ptActivity(array $members, array $trainers): void
    {
        $pt = app(PtService::class);
        $member = Member::whereHas('memberships', fn ($q) => $q->where('status', Membership::ACTIVE))->first();
        $sub = $pt->sell($member, PtPackage::where('sessions_count', 8)->first(), $trainers[3]);
        app(PaymentService::class)->record($sub->invoice()->first(), $sub->price, 'card', ['reference' => 'POS-778812']);

        $next = CarbonImmutable::now()->addDays(1)->setTime(17, 0);
        while (! in_array($next->dayOfWeek, [0, 1, 2, 3, 4, 6], true)) {
            $next = $next->addDay();
        }
        $pt->schedule($sub->fresh(), $next);
        $pt->schedule($sub->fresh(), $next->addDays(2)->setTime(18, 0)->dayOfWeek === 5 ? $next->addDays(3)->setTime(18, 0) : $next->addDays(2)->setTime(18, 0));
    }

    /** @param list<Product> $products @param list<Member> $members */
    private function sales(array $products, array $members): void
    {
        $pos = app(PosService::class);
        for ($d = 20; $d >= 0; $d -= 2) {
            $this->travel(now()->subDays($d)->setTime(random_int(9, 20), 15), function () use ($pos, $products, $members) {
                $items = collect($products)->shuffle()->take(random_int(1, 3))->map(fn ($p) => ['product_id' => $p->id, 'quantity' => random_int(1, 2)])->values()->all();
                $pos->checkout($items, random_int(0, 1) ? 'cash' : 'card', random_int(0, 1) ? $members[array_rand($members)] : null);
            });
        }
    }

    private function expenses(): void
    {
        $cats = ExpenseCategory::pluck('id', 'name');
        for ($m = 2; $m >= 0; $m--) {
            $month = now()->subMonths($m)->startOfMonth();
            $rows = [['إيجار', 'إيجار المقر', 850], ['رواتب', 'رواتب المدربات والموظفات', 2400], ['كهرباء ومياه', 'فاتورة الكهرباء والمياه', 180 + $m * 15], ['تسويق وإعلانات', 'إعلانات إنستغرام', 120]];
            foreach ($rows as $i => [$cat, $desc, $amount]) {
                $this->travel($month->copy()->addDays(2 + $i), fn () => Expense::create([
                    'expense_category_id' => $cats[$cat] ?? null, 'number' => NumberSequence::next('expense'),
                    'description' => $desc.' — '.$month->translatedFormat('F'), 'amount' => $amount, 'expense_date' => today(), 'payment_method' => 'bank_transfer',
                ]));
            }
        }
    }

    /** @param list<Member> $members */
    private function conversations(array $members): void
    {
        foreach (array_slice($members, 0, 3) as $i => $member) {
            $c = Conversation::create(['phone' => $member->phone, 'member_id' => $member->id, 'contact_name' => $member->full_name, 'unread_count' => $i === 0 ? 1 : 0,
                'last_message_at' => now()->subHours($i * 5 + 1), 'last_inbound_at' => now()->subHours($i * 5 + 1)]);
            Message::create(['conversation_id' => $c->id, 'member_id' => $member->id, 'direction' => 'inbound', 'type' => 'text', 'to_phone' => $member->phone,
                'body' => ['هل يوجد حصة زومبا يوم الخميس؟', 'أبغى أجمّد اشتراكي أسبوع', 'شكرًا على التذكير 🌸'][$i], 'status' => Message::RECEIVED, 'created_at' => now()->subHours($i * 5 + 1)]);
        }
    }

    private function seedSecondClub(): void
    {
        $program = Program::create(['name' => 'Functional Training', 'slug' => 'functional', 'color' => '#0f766e']);
        $plan = MembershipPlan::create(['name' => 'Monthly', 'type' => 'combo', 'duration_value' => 1, 'duration_unit' => 'month', 'price' => 20]);
        $member = app(MemberService::class)->create(['full_name' => 'عضوة نادٍ آخر', 'phone' => '96890000004']);
        $m = app(MembershipService::class)->sell($member, $plan);
        app(PaymentService::class)->record($m->invoice()->first(), $m->total, 'cash');
        unset($program);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function travel(\DateTimeInterface $at, callable $callback): mixed
    {
        Carbon::setTestNow($at);
        CarbonImmutable::setTestNow($at);
        try {
            return $callback();
        } finally {
            Carbon::setTestNow();
            CarbonImmutable::setTestNow();
        }
    }
}
