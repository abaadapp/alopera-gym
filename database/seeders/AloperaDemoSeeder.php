<?php

namespace Database\Seeders;

use App\Core\Auth\SystemRoles;
use App\Core\Support\NumberSequence;
use App\Core\Tenancy\TenantContext;
use App\Core\Tenancy\TenantProvisioner;
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
use App\Modules\Inventory\Services\StockService;
use App\Modules\Messaging\Models\AutoReply;
use App\Modules\Messaging\Models\Conversation;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Services\TemplateCatalog;
use App\Modules\Website\Models\SitePost;
use App\Modules\Website\Models\SiteSetting;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Demo data for local development and demos. Never run in production.
 *
 * Builds Alopera Gym with staff for every role, plans, programs, trainers,
 * timetable, members with realistic history, payments, POS, expenses,
 * WhatsApp log and website content.
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

        $alopera = app(TenantProvisioner::class)->provision(
            ['name' => 'Alopera Gym', 'slug' => 'alopera', 'email' => 'hello@alopera.test', 'phone' => '96824000000', 'city' => 'مسقط', 'primary_color' => '#9d174d', 'secondary_color' => '#f9a8d4'],
            ['name' => 'صاحب النشاط', 'email' => 'owner@alopera.test', 'password' => self::PASSWORD, 'phone' => '96890000001'],
        );
        $alopera->update(['legal_name' => 'Alopera Ladies Fitness LLC', 'address' => 'الخوير، مسقط']);

        app(TenantContext::class)->run($alopera->fresh(), fn () => $this->seedAlopera());

        Carbon::setTestNow();
        CarbonImmutable::setTestNow();

        $this->command?->info('Demo ready. Staff password for all demo accounts: '.self::PASSWORD);
    }

    private function seedAlopera(): void
    {
        $tenant = app(TenantContext::class)->require();
        $tenant->update(['settings' => array_replace($tenant->settings ?? [], ['vat_rate' => 0, 'invoice_footer' => 'شكرًا لاختيارك Alopera Gym'])]);

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
        $make('نورة — نقطة البيع', 'cashier@alopera.test', SystemRoles::CASHIER);

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
            ['الكوتش ريم', 'شهادة تدريب معتمدة في الزومبا والـHIIT وخبرة 8 سنوات.', ['زومبا', 'HIIT', 'كارديو'], '#db2777', $users[0]->id],
            ['الكوتش دانة', 'خبرة متخصصة في البيلاتس والتأهيل الحركي.', ['بيلاتس', 'تأهيل', 'إطالة'], '#7c3aed', $users[1]->id],
            ['الكوتش نوف', 'شهادة يوغا معتمدة RYT-200.', ['يوغا', 'تأمل'], '#0d9488', null],
            ['الكوتش شهد', 'تدريب القوة والتغذية الرياضية والتدريب الشخصي.', ['قوة', 'تدريب شخصي', 'تغذية'], '#b45309', null],
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
        Coupon::create(['code' => 'WELCOME10', 'description' => 'خصم 10% للأعضاء الجدد', 'type' => 'percent', 'value' => 10, 'per_member_limit' => 1]);
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

        AutoReply::create(['keyword' => 'الأسعار', 'reply' => 'أهلًا بك! أسعار باقاتنا هنا: الموقع ← الباقات. يسعدنا خدمتك!']);
        AutoReply::create(['keyword' => 'الدوام', 'reply' => 'أوقات العمل: السبت–الخميس 6 صباحًا – 11 مساءً، الجمعة 4 – 10 مساءً.']);
    }

    private function website(): void
    {
        SiteSetting::query()->update([
            'hero_title' => 'قوتك تبدأ هنا',
            'hero_badge' => 'نادٍ رياضي في قلب مسقط',
            'hero_subtitle' => 'كل ما تحتاجه للياقتك في مكان واحد — حصص جماعية، تدريب شخصي، وفريق تدريب محترف في أجواء مريحة وآمنة.',
            'about_title' => 'عن Alopera Gym',
            'about_body' => "Alopera Gym مساحة صُممت للياقتك: صالة أجهزة حديثة، استوديو للحصص الجماعية، وفريق تدريب معتمد يرافقك خطوة بخطوة.\n\nنؤمن أن اللياقة أسلوب حياة، لذلك نوفر باقات مرنة، وحجزًا أونلاين للحصص، ومتابعة شخصية لأهدافك.",
            'announcement' => 'عرض الافتتاح: خصم 10% بكود WELCOME10 على أول اشتراك',
            'contact_phone' => '96824000000',
            'whatsapp_number' => '96890000000',
            'contact_email' => 'hello@alopera.test',
            'address' => 'الخوير، شارع 33، مسقط',
            'map_url' => 'https://maps.google.com/?q=Al+Khuwair+Muscat',
            'working_hours' => [['days' => 'السبت – الخميس', 'hours' => '6:00 ص – 11:00 م'], ['days' => 'الجمعة', 'hours' => '4:00 م – 10:00 م']],
            'social_links' => ['instagram' => 'https://instagram.com/alopera.gym'],
            'stats' => [['label' => 'عضو سعيد', 'value' => '+500'], ['label' => 'حصة أسبوعيًا', 'value' => '17'], ['label' => 'مدرب معتمد', 'value' => '4']],
        ]);

        SitePost::create(['type' => 'offer', 'title' => 'عرض الصيف: ربع سنوية بخصم 5 ريال', 'slug' => 'summer-offer', 'excerpt' => 'استخدم الكود SUMMER5 عند الاشتراك في الباقة الربع سنوية.', 'body' => 'العرض ساري لفترة محدودة على الباقة الربع سنوية فقط.', 'is_published' => true, 'published_at' => now()->subDays(3), 'ends_on' => today()->addMonths(2)]);
        SitePost::create(['type' => 'news', 'title' => 'إطلاق حصص الكيك بوكس', 'slug' => 'kickboxing-launch', 'excerpt' => 'حصة جديدة كل اثنين مع الكوتش ريم.', 'body' => 'انضم إلى حصص الكيك بوكس الجديدة: لياقة، قوة، وثقة.', 'is_published' => true, 'published_at' => now()->subDays(10)]);
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
            $purchases = app(PurchaseService::class);
            $po = $purchases->approve($purchases->create($supplier, array_map(fn ($r) => ['product_id' => $r[0]->id, 'quantity' => $r[0]->name === 'حصيرة يوغا' ? 6 : 40, 'unit_cost' => $r[1]], $products)));
            // Delivered in full and approved: the stock goes on the shelves through a goods receipt.
            $purchases->receive($po, $po->items()->get()->mapWithKeys(fn ($item) => [$item->id => ['quantity' => $item->quantity]])->all(), approveNow: true);
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
            MemberNote::create(['member_id' => $member->id, 'channel' => 'call', 'direction' => 'outbound', 'body' => 'تم التواصل للتذكير بموعد التجديد، وهناك اهتمام بالباقة الربع سنوية.', 'user_id' => User::ofTenant($member->tenant_id)->value('id')]);
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
                // Only what is on the shelf: a few items are bought in small numbers (6 yoga mats) to show low stock.
                $shelf = app(StockService::class)->quantities();
                $items = collect($products)->shuffle()->take(random_int(1, 3))->map(fn ($p) => ['product_id' => $p->id, 'quantity' => min(random_int(1, 2), (int) ($shelf[$p->id] ?? 0))])
                    ->where('quantity', '>', 0)->values()->all();
                if ($items !== []) {
                    $pos->checkout($items, random_int(0, 1) ? 'cash' : 'card', random_int(0, 1) ? $members[array_rand($members)] : null);
                }
            });
        }
    }

    private function expenses(): void
    {
        $cats = ExpenseCategory::pluck('id', 'name');
        for ($m = 2; $m >= 0; $m--) {
            $month = now()->subMonths($m)->startOfMonth();
            $rows = [['إيجار', 'إيجار المقر', 850], ['رواتب', 'رواتب المدربين والموظفين', 2400], ['كهرباء ومياه', 'فاتورة الكهرباء والمياه', 180 + $m * 15], ['تسويق وإعلانات', 'إعلانات إنستغرام', 120]];
            foreach ($rows as $i => [$cat, $desc, $amount]) {
                $this->travel($month->copy()->addDays(2 + $i), fn () => Expense::create([
                    'expense_category_id' => $cats[$cat] ?? null, 'number' => NumberSequence::next('expense'),
                    'description' => $desc.' — '.$month->translatedFormat('F'), 'amount' => $amount, 'expense_date' => today(), 'payment_method' => 'bank_transfer',
                ]));
            }
        }
    }

    /**
     * Sample WhatsApp conversations for the demo, each marked as such (is_demo): shown with a «تجريبية» badge, left
     * out of the reports, never sent anywhere (the demo runs the log driver). They show the inbox's cases: a new chat,
     * one being answered by reception with an internal note, one with a message from the WhatsApp Business app and an
     * automatic reminder, a customer who is not a member, and a closed one past its 24-hour window.
     *
     * @param  list<Member>  $members
     */
    private function conversations(array $members): void
    {
        $club = app(TenantContext::class)->id();
        $reception = User::where('tenant_id', $club)->whereHas('role', fn ($q) => $q->where('key', SystemRoles::RECEPTION))->first();
        $owner = User::where('tenant_id', $club)->whereHas('role', fn ($q) => $q->where('key', SystemRoles::OWNER))->first();
        $add = function (Conversation $c, string $direction, string $source, string $body, int $minutesAgo, array $extra = []): Message {
            $at = now()->subMinutes($minutesAgo);

            return Message::create($extra + ['conversation_id' => $c->id, 'member_id' => $c->member_id, 'direction' => $direction, 'source' => $source,
                'type' => $extra['type'] ?? 'text', 'to_phone' => $c->phone, 'body' => $body,
                'status' => $direction === 'inbound' ? Message::RECEIVED : ($extra['status'] ?? Message::READ),
                'provider_message_id' => 'log.demo.'.Str::uuid(), 'wa_timestamp' => $at, 'created_at' => $at, 'updated_at' => $at]);
        };
        $chat = fn (array $attributes) => Conversation::create($attributes + ['is_demo' => true]);

        [$a, $b, $c] = array_slice($members, 0, 3);

        // A new chat, two messages unread.
        $one = $chat(['phone' => $a->phone, 'member_id' => $a->id, 'status' => Conversation::NEW, 'unread_count' => 2]);
        $add($one, 'inbound', Message::SOURCE_CUSTOMER, 'مساء الخير', 14);
        $last = $add($one, 'inbound', Message::SOURCE_CUSTOMER, 'هل يوجد حصة زومبا يوم الخميس؟', 12);
        $one->forceFill(['first_inbound_at' => now()->subMinutes(14), 'last_inbound_at' => $last->wa_timestamp, 'last_message_at' => $last->wa_timestamp,
            'last_preview' => $last->body, 'last_direction' => 'inbound'])->save();

        // Being answered by reception, with an internal note.
        $two = $chat(['phone' => $b->phone, 'member_id' => $b->id, 'status' => Conversation::OPEN, 'assigned_to' => $reception?->id, 'assigned_at' => now()->subHours(3)]);
        $add($two, 'inbound', Message::SOURCE_CUSTOMER, 'أبغى أجمّد اشتراكي أسبوع، مسافرة', 190);
        $reply = $add($two, 'outbound', Message::SOURCE_PLATFORM, 'أهلًا بك! تم تسجيل طلب التجميد، يبدأ من الغد لمدة 7 أيام. رحلة سعيدة 🌸', 175, ['sent_by' => $reception?->id]);
        $two->events()->create(['user_id' => $owner?->id, 'type' => 'assigned', 'data' => ['from' => null, 'to' => $reception?->id, 'to_name' => $reception?->name], 'created_at' => now()->subMinutes(185)]);
        $two->events()->create(['user_id' => $reception?->id, 'type' => 'note', 'body' => 'تأكدنا من رصيد أيام التجميد في باقتها قبل الرد.', 'created_at' => now()->subMinutes(176)]);
        $two->forceFill(['first_inbound_at' => now()->subMinutes(190), 'first_response_at' => $reply->wa_timestamp, 'last_inbound_at' => now()->subMinutes(190),
            'last_message_at' => $reply->wa_timestamp, 'last_preview' => $reply->body, 'last_direction' => 'outbound'])->save();

        // The owner wrote from the WhatsApp Business app on the phone; an automatic reminder went out before.
        $three = $chat(['phone' => $c->phone, 'member_id' => $c->id, 'status' => Conversation::PENDING]);
        $add($three, 'outbound', Message::SOURCE_AUTOMATION, 'تذكير: اشتراكك ينتهي بعد 3 أيام. للتجديد: alopera.om/renew', 60 * 26,
            ['type' => 'template', 'template_key' => 'membership_expiring', 'status' => Message::READ]);
        $add($three, 'inbound', Message::SOURCE_CUSTOMER, 'شكرًا على التذكير، أبغى أجدد بنفس الباقة', 60 * 5);
        $echo = $add($three, 'outbound', Message::SOURCE_BUSINESS_APP, 'تمام، جهزنا لك فاتورة التجديد وتقدرين تدفعين في الاستقبال 👍', 60 * 4 + 50, ['status' => Message::DELIVERED]);
        $three->forceFill(['first_inbound_at' => now()->subHours(5), 'first_response_at' => $echo->wa_timestamp, 'last_inbound_at' => now()->subHours(5),
            'last_message_at' => $echo->wa_timestamp, 'last_preview' => $echo->body, 'last_direction' => 'outbound'])->save();

        // Not a member: a new customer, answered by the keyword auto-reply.
        $four = $chat(['phone' => '96890000123', 'profile_name' => 'Laila', 'status' => Conversation::NEW, 'unread_count' => 1]);
        $add($four, 'inbound', Message::SOURCE_CUSTOMER, 'السلام عليكم، كم الأسعار؟', 40);
        $auto = $add($four, 'outbound', Message::SOURCE_AUTOMATION, 'أهلًا بك! أسعار باقاتنا هنا: الموقع ← الباقات. يسعدنا خدمتك!', 40, ['status' => Message::DELIVERED]);
        $four->forceFill(['first_inbound_at' => now()->subMinutes(40), 'last_inbound_at' => now()->subMinutes(40), 'last_message_at' => $auto->wa_timestamp,
            'last_preview' => $auto->body, 'last_direction' => 'outbound'])->save();

        // Closed, its 24-hour window long over: a free reply was refused.
        $five = $chat(['phone' => '96890000456', 'contact_name' => 'ضيفة من الموقع', 'status' => Conversation::CLOSED, 'closed_at' => now()->subDays(2)]);
        $add($five, 'inbound', Message::SOURCE_CUSTOMER, 'هل عندكم حصص للمبتدئات؟', 60 * 24 * 3);
        $done = $add($five, 'outbound', Message::SOURCE_PLATFORM, 'نعم، كل سبت الساعة 6 مساءً.', 60 * 24 * 3 - 20, ['sent_by' => $owner?->id]);
        $five->forceFill(['first_inbound_at' => now()->subDays(3), 'first_response_at' => $done->wa_timestamp, 'last_inbound_at' => now()->subDays(3),
            'last_message_at' => $done->wa_timestamp, 'last_preview' => $done->body, 'last_direction' => 'outbound'])->save();
    }

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
