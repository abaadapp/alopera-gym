<?php

namespace App\Core\Demo;

use App\Core\Auth\SystemRoles;
use App\Core\Support\NumberSequence;
use App\Core\Tenancy\TenantContext;
use App\Models\Branch;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\ChartOfAccounts;
use App\Modules\Accounting\Services\Ledger;
use App\Modules\Accounting\Services\LedgerProjector;
use App\Modules\Documents\Models\DocumentType;
use App\Modules\Documents\Services\DocumentService;
use App\Modules\Finance\Models\Expense;
use App\Modules\Finance\Models\ExpenseCategory;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Services\CashShifts;
use App\Modules\Finance\Services\CreditNoteService;
use App\Modules\Finance\Services\PaymentService;
use App\Modules\Gym\Models\AccessDevice;
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
use App\Modules\Gym\Models\PtSession;
use App\Modules\Gym\Models\PtSubscription;
use App\Modules\Gym\Models\StaffAttendance;
use App\Modules\Gym\Models\Trainer;
use App\Modules\Gym\Services\BookingService;
use App\Modules\Gym\Services\GiftService;
use App\Modules\Gym\Services\MemberService;
use App\Modules\Gym\Services\MembershipService;
use App\Modules\Gym\Services\PtService;
use App\Modules\Gym\Services\ScheduleService;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductCategory;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Models\Supplier;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Inventory\Services\PosService;
use App\Modules\Inventory\Services\PurchaseService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Inventory\Services\SupplierBillService;
use App\Modules\Inventory\Services\TransferService;
use App\Modules\Messaging\Models\AutoReply;
use App\Modules\Messaging\Models\Campaign;
use App\Modules\Messaging\Models\Conversation;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Services\TemplateCatalog;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\Models\JobTitle;
use App\Modules\Payroll\Services\PayrollService;
use App\Modules\Website\Models\ContactMessage;
use App\Modules\Website\Models\SitePost;
use App\Modules\Website\Models\SiteSetting;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * Fills the demo club (php artisan gym:demo) with made-up data on every screen: staff, two branches, programs,
 * trainers, plans, a timetable, a few hundred members with history, class attendance, check-ins, PT, point of sale,
 * purchasing and stock, expenses, cash shifts, credit notes, payment links, gifts, payroll, documents, WhatsApp
 * conversations and campaigns, and the website.
 *
 * It runs in production (inside the demo club only), so it uses no Faker (a development package) and never sends
 * anything: the demo club has no WhatsApp or payment integration, members have no email, and the staff accounts other
 * than the demo owner get a random password nobody knows. History goes through the real services with time travel,
 * so every invoice, receipt, activation and ledger entry took the production code path.
 *
 * Each part runs on its own: a part that a business rule refuses is reported and skipped, the rest goes on.
 */
final class DemoClubBuilder
{
    private const FIRST = ['مريم', 'فاطمة', 'عائشة', 'سارة', 'نورة', 'هند', 'ريم', 'لمى', 'شهد', 'دانة', 'جود', 'أمل', 'منى', 'رحاب', 'بشرى', 'خلود', 'آمنة', 'زينب', 'أسماء', 'ليلى', 'وعد', 'ميثاء', 'شيخة', 'موزة', 'بدرية', 'غالية', 'رزان', 'هيا', 'نوف', 'العنود', 'سلمى', 'جميلة', 'حنان', 'رقية', 'ابتسام', 'إيمان', 'يسرى', 'تسنيم', 'روان', 'لجين'];

    private const LAST = ['البلوشية', 'الحارثية', 'الهنائية', 'السعدية', 'الكندية', 'المعمرية', 'الرواحية', 'البوسعيدية', 'الشكيلية', 'العامرية', 'الزدجالية', 'الفارسية', 'النعمانية', 'الغافرية', 'الحبسية', 'اللواتية', 'المقبالية', 'الجابرية', 'الريامية', 'العبرية', 'الوهيبية', 'البطاشية'];

    /** @var array<string, string> part => result line */
    private array $report = [];

    private int $phone = 0;

    /** Days of daily history (sales, cash shifts): two months for a real demo, two weeks for a small one (the tests). */
    private int $days = 60;

    private User $owner;

    /** @var array<string, Branch> */
    private array $branches = [];

    /** @var list<User> */
    private array $staff = [];

    /** @var array<string, Program> */
    private array $programs = [];

    /** @var list<Trainer> */
    private array $trainers = [];

    /** @var array<string, MembershipPlan> */
    private array $plans = [];

    /** @var list<Product> */
    private array $products = [];

    /** @var list<Supplier> */
    private array $suppliers = [];

    /** @var list<Member> */
    private array $members = [];

    /** The gym's setup was copied in first (DemoMirror): its plans, programs, products and website are used as they are. */
    private bool $copied = false;

    private bool $welcome = false;

    public function __construct(private readonly TenantContext $tenants) {}

    /** @return array<string, string> what each part made, or why it was skipped */
    public function build(Tenant $club, User $owner, int $members = 240, bool $copiedFromGym = false): array
    {
        $this->owner = $owner;
        $this->copied = $copiedFromGym;
        $this->days = $members >= 100 ? 60 : 14;
        mt_srand(2026);
        $this->phone = 0;
        $previousQueue = config('queue.default');
        config(['queue.default' => 'sync']);
        Auth::guard('web')->setUser($owner);

        try {
            $this->tenants->run($club, function () use ($members) {
                $this->part('club', fn () => $this->club());
                $this->part('staff', fn () => $this->staffAccounts());
                $this->part('programs', fn () => $this->programs());
                $this->part('trainers', fn () => $this->trainers());
                $this->part('plans', fn () => $this->plans());
                $this->part('coupons and PT packages', fn () => $this->couponsAndPackages());
                $this->part('timetable', fn () => $this->timetable());
                $this->part('messaging setup', fn () => $this->messagingSetup());
                $this->part('website', fn () => $this->website());
                $this->part('products and purchasing', fn () => $this->inventory());
                $this->part('members', fn () => $this->members($members));
                $this->part('class history', fn () => $this->classHistory());
                $this->part('check-ins', fn () => $this->checkIns());
                $this->part('upcoming bookings', fn () => $this->futureBookings());
                $this->part('personal training', fn () => $this->personalTraining());
                $this->part('stock work', fn () => $this->stockWork());
                $this->part('point of sale', fn () => $this->sales());
                $this->part('supplier bills', fn () => $this->supplierBills());
                $this->part('expenses', fn () => $this->expenses());
                $this->part('cash shifts', fn () => $this->cashShifts());
                $this->part('credit notes', fn () => $this->creditNotes());
                $this->part('gifts', fn () => $this->gifts());
                $this->part('payroll', fn () => $this->payroll());
                $this->part('documents', fn () => $this->documents());
                $this->part('staff attendance', fn () => $this->staffAttendance());
                $this->part('conversations', fn () => $this->conversations());
                $this->part('message log and campaigns', fn () => $this->messageLog());
                $this->part('ledger', fn () => $this->ledger());
            });
        } finally {
            $this->travelBack();
            config(['queue.default' => $previousQueue]);
        }

        return $this->report;
    }

    private function part(string $name, callable $work): void
    {
        try {
            // Its own transaction: a part refused half way leaves nothing of itself behind.
            $started = microtime(true);
            $this->report[$name] = (string) (DB::transaction($work) ?? 'done').sprintf(' · %.1fs', microtime(true) - $started);
        } catch (Throwable $e) {
            $this->travelBack();
            report($e);
            $this->report[$name] = 'SKIPPED: '.$e->getMessage();
        }
    }

    private function club(): string
    {
        $club = $this->tenants->require();
        $this->copied || $club->update([
            'legal_name' => 'Alopera Demo Fitness LLC', 'address' => 'الخوير، مسقط', 'city' => 'مسقط',
            'settings' => array_replace($club->settings ?? [], ['is_demo' => true, 'vat_rate' => 0, 'invoice_footer' => 'نسخة تجريبية — بيانات غير حقيقية']),
        ]);

        $this->branches['main'] = Branch::where('is_main', true)->firstOrFail();
        $this->branches['main']->update(['name' => 'فرع الخوير', 'address' => 'الخوير، شارع 33']);
        $this->branches['second'] = Branch::create(['name' => 'فرع العذيبة', 'code' => 'AZB', 'phone' => '96824000011', 'address' => 'العذيبة الجنوبية']);

        // Opening balances, as the owner sets them in Finance › Banks: money in the bank for salaries and suppliers, a
        // small float in the cash box.
        $chart = app(ChartOfAccounts::class);
        foreach ([[$chart->moneyFor('bank_transfer', 'payment'), 40000], [$chart->moneyFor('cash'), 500]] as [$account, $amount]) {
            app(Ledger::class)->post([
                'date' => today()->subMonths(7)->startOfMonth()->toDateString(), 'description' => "الرصيد الافتتاحي — {$account->name}",
                'source_type' => 'account', 'source_id' => $account->id, 'event' => 'opening', 'revision' => 1,
            ], [['account' => $account, 'debit' => $amount], ['account' => $chart->account('opening_equity'), 'credit' => $amount]]);
        }

        return '2 branches, opening balances';
    }

    private function staffAccounts(): string
    {
        $roles = Role::pluck('id', 'key');
        $rows = [
            ['ليلى — الإدارة', 'manager', SystemRoles::MANAGER, 'main'],
            ['هند — الاستقبال', 'reception', SystemRoles::RECEPTION, 'main'],
            ['أسيل — استقبال العذيبة', 'reception2', SystemRoles::RECEPTION, 'second'],
            ['سلمى — المالية', 'finance', SystemRoles::FINANCE, 'main'],
            ['نورة — نقطة البيع', 'cashier', SystemRoles::CASHIER, 'main'],
            ['الكوتش ريم', 'trainer1', SystemRoles::TRAINER, 'main'],
            ['الكوتش دانة', 'trainer2', SystemRoles::TRAINER, 'main'],
            ['الكوتش نوف', 'trainer3', SystemRoles::TRAINER, 'second'],
        ];

        foreach ($rows as [$name, $key, $role, $branch]) {
            // Nobody signs in as these: a random password, an address on the demo subdomain that has no mailbox.
            $user = new User(['name' => $name, 'email' => $key.'@demo.aloperagym.om', 'password' => Str::password(24), 'phone' => $this->nextPhone()]);
            $user->tenant_id = $this->tenants->id();
            $user->role_id = $roles[$role];
            $user->email_verified_at = now();
            if (array_key_exists('branch_id', $user->getAttributes()) || Schema::hasColumn('users', 'branch_id')) {
                $user->branch_id = $this->branches[$branch]->id;
            }
            $user->save();
            $this->staff[] = $user;
        }

        return count($this->staff).' staff accounts (sign-in only as the demo owner)';
    }

    private function programs(): string
    {
        if ($this->programs = $this->reuse(Program::where('is_active', true)->orderBy('sort')->orderBy('id')->get()->all(),
            ['zumba', 'pilates', 'yoga', 'strength', 'hiit', 'stretch', 'kickbox', 'aqua', 'spin'])) {
            return count(array_unique(array_map(fn ($p) => $p->id, $this->programs))).' programs (the gym\'s)';
        }
        $rows = [
            'zumba' => ['زومبا', '#db2777', 'class', 'all', 'رقص لاتيني ممتع يحرق السعرات ويرفع الطاقة.', 50, 20],
            'pilates' => ['بيلاتس', '#7c3aed', 'class', 'all', 'تقوية عضلات الجذع وتحسين القوام والمرونة.', 55, 12],
            'yoga' => ['يوغا', '#0d9488', 'class', 'all', 'توازن وتنفس واسترخاء — للجسم والذهن.', 60, 15],
            'strength' => ['تمارين القوة', '#b45309', 'program', 'intermediate', 'برنامج أوزان ومقاومة لبناء عضلات متناسقة.', 60, 10],
            'hiit' => ['HIIT', '#dc2626', 'class', 'advanced', 'تمارين متقطعة عالية الشدة لحرق سريع.', 45, 14],
            'stretch' => ['إطالة ومرونة', '#2563eb', 'class', 'beginner', 'جلسة إطالة هادئة لتحسين المدى الحركي.', 40, 15],
            'kickbox' => ['كيك بوكس', '#be123c', 'class', 'intermediate', 'لياقة ودفاع عن النفس بإيقاع حماسي.', 50, 12],
            'aqua' => ['أكوا فت', '#0284c7', 'class', 'all', 'تمارين مائية لطيفة على المفاصل.', 45, 10],
            'spin' => ['سبينينغ', '#16a34a', 'class', 'intermediate', 'ركوب دراجات ثابتة مع موسيقى حماسية.', 45, 16],
        ];

        foreach ($rows as $key => [$name, $color, $category, $level, $desc, $minutes, $capacity]) {
            $this->programs[$key] = Program::create([
                'name' => $name, 'slug' => $key, 'color' => $color, 'category' => $category, 'level' => $level,
                'description' => $desc, 'duration_minutes' => $minutes, 'default_capacity' => $capacity, 'sort' => count($this->programs),
            ]);
        }

        return count($this->programs).' programs';
    }

    private function trainers(): string
    {
        $users = array_values(array_filter($this->staff, fn (User $u) => str_starts_with($u->email, 'trainer')));
        $rows = [
            ['الكوتش ريم', 'شهادة تدريب معتمدة في الزومبا والـHIIT وخبرة 8 سنوات.', ['زومبا', 'HIIT', 'كارديو'], '#db2777', $users[0]->id ?? null],
            ['الكوتش دانة', 'خبرة متخصصة في البيلاتس والتأهيل الحركي.', ['بيلاتس', 'تأهيل', 'إطالة'], '#7c3aed', $users[1]->id ?? null],
            ['الكوتش نوف', 'شهادة يوغا معتمدة RYT-200.', ['يوغا', 'تأمل'], '#0d9488', $users[2]->id ?? null],
            ['الكوتش شهد', 'تدريب القوة والتغذية الرياضية والتدريب الشخصي.', ['قوة', 'تدريب شخصي', 'تغذية'], '#b45309', null],
            ['الكوتش جود', 'مدربة كيك بوكس وسبينينغ.', ['كيك بوكس', 'سبينينغ'], '#be123c', null],
            ['الكوتش لمى', 'تمارين مائية وإعادة تأهيل.', ['أكوا فت', 'تأهيل'], '#0284c7', null],
        ];

        foreach ($rows as $i => [$name, $bio, $spec, $color, $userId]) {
            $t = Trainer::create(['name' => $name, 'bio' => $bio, 'specialties' => $spec, 'color' => $color, 'user_id' => $userId, 'sort' => $i, 'phone' => $this->nextPhone()]);
            foreach ([0, 1, 2, 3, 4, 6] as $day) {
                $t->availabilities()->create(['weekday' => $day, 'starts_at' => '07:00', 'ends_at' => '12:00']);
                $t->availabilities()->create(['weekday' => $day, 'starts_at' => '15:00', 'ends_at' => '22:30']);
            }
            $this->trainers[] = $t;
        }

        return count($this->trainers).' trainers';
    }

    private function plans(): string
    {
        if ($this->plans = $this->reuse(MembershipPlan::where('is_active', true)->orderBy('sort')->orderBy('id')->get()->all(),
            ['monthly', 'quarter', 'semi', 'yearly', 'classes12', 'gym', 'student'])) {
            return count(array_unique(array_map(fn ($p) => $p->id, $this->plans))).' plans (the gym\'s)';
        }
        $rows = [
            'monthly' => ['الشهرية', 'combo', 1, 'month', 25, 5, null, true, 7, 1, false, ['دخول الصالة الرياضية', 'كل الحصص الجماعية', 'تقييم لياقة مجاني']],
            'quarter' => ['ربع سنوية', 'combo', 3, 'month', 65, 5, null, true, 14, 2, true, ['دخول الصالة الرياضية', 'كل الحصص الجماعية', 'تجميد حتى 14 يومًا', 'خصم 10% على التدريب الشخصي']],
            'semi' => ['نصف سنوية', 'combo', 6, 'month', 120, 0, null, true, 30, 3, false, ['كل مزايا الربع سنوية', 'تجميد حتى 30 يومًا', 'بدون رسوم تسجيل']],
            'yearly' => ['السنوية', 'combo', 1, 'year', 220, 0, null, true, 45, 4, false, ['أفضل قيمة', 'تجميد حتى 45 يومًا', 'جلستا تدريب شخصي مجانًا']],
            'classes12' => ['باقة 12 حصة', 'classes', 2, 'month', 30, 0, 12, false, 0, 0, false, ['12 حصة جماعية خلال شهرين', 'زومبا، بيلاتس، يوغا، إطالة']],
            'gym' => ['الصالة فقط', 'gym', 1, 'month', 18, 5, null, true, 7, 1, false, ['دخول الصالة الرياضية والأجهزة', 'بدون حصص جماعية']],
            'student' => ['باقة الطالبات', 'combo', 1, 'month', 20, 0, null, false, 0, 0, false, ['للطالبات بالبطاقة الجامعية', 'كل الحصص الجماعية']],
        ];

        foreach ($rows as $key => [$name, $type, $dv, $du, $price, $fee, $sessions, $freeze, $freezeDays, $freezes, $featured, $features]) {
            $this->plans[$key] = MembershipPlan::create([
                'name' => $name, 'type' => $type, 'duration_value' => $dv, 'duration_unit' => $du, 'price' => $price, 'signup_fee' => $fee,
                'sessions_limit' => $sessions, 'freeze_allowed' => $freeze, 'max_freeze_days' => $freezeDays, 'max_freezes' => $freezes,
                'is_featured' => $featured, 'features' => $features, 'sort' => count($this->plans),
                'description' => 'باقة '.$name.' — نسخة تجريبية.',
            ]);
        }

        $p = $this->programs;
        $this->plans['classes12']->programs()->sync([$p['zumba']->id, $p['pilates']->id, $p['yoga']->id, $p['stretch']->id]);
        $this->plans['gym']->programs()->sync([$p['strength']->id]);

        return count($this->plans).' plans';
    }

    private function couponsAndPackages(): string
    {
        $made = [];
        if (! Coupon::exists()) {
            $this->madeUpCoupons();
            $made[] = '4 coupons';
        }
        if (! PtPackage::exists()) {
            $this->madeUpPackages();
            $made[] = '4 PT packages';
        }

        return $made === [] ? 'the gym\'s coupons and PT packages' : implode(', ', $made);
    }

    private function madeUpCoupons(): void
    {
        Coupon::create(['code' => 'WELCOME10', 'description' => 'خصم 10% للأعضاء الجدد', 'type' => 'percent', 'value' => 10, 'per_member_limit' => 1]);
        Coupon::create(['code' => 'SUMMER5', 'description' => 'خصم 5 ريال على الربع سنوية', 'type' => 'fixed', 'value' => 5, 'plan_ids' => [$this->plans['quarter']->id], 'valid_until' => today()->addMonths(2), 'max_uses' => 100]);
        Coupon::create(['code' => 'FRIEND15', 'description' => 'خصم 15% عند دعوة صديقة', 'type' => 'percent', 'value' => 15, 'max_uses' => 50]);
        Coupon::create(['code' => 'EID2026', 'description' => 'عرض العيد — منتهي', 'type' => 'fixed', 'value' => 8, 'valid_until' => today()->subMonth(), 'max_uses' => 30]);
    }

    private function madeUpPackages(): void
    {
        $trainer = $this->trainers[3] ?? $this->trainers[0] ?? null;
        PtPackage::create(['name' => '4 جلسات تدريب شخصي', 'sessions_count' => 4, 'price' => 60, 'validity_days' => 30, 'description' => 'بداية مثالية مع خطة تمارين وتغذية.']);
        PtPackage::create(['name' => '8 جلسات تدريب شخصي', 'sessions_count' => 8, 'price' => 110, 'validity_days' => 60, 'description' => 'الأكثر طلبًا — متابعة أسبوعية مرتين.']);
        PtPackage::create(['name' => '12 جلسة مع الكوتش شهد', 'sessions_count' => 12, 'price' => 150, 'validity_days' => 90, 'trainer_id' => $trainer?->id]);
        PtPackage::create(['name' => '20 جلسة — برنامج التحول', 'sessions_count' => 20, 'price' => 230, 'validity_days' => 120, 'description' => 'برنامج متكامل لثلاثة أشهر.']);
    }

    /**
     * The gym's rows under the demo's names (monthly, yoga…), in turn, so the rest of the build works on whatever the
     * gym sells; nothing when the gym has none (the made-up catalogue is used).
     *
     * @template T
     *
     * @param  list<T>  $rows
     * @param  list<string>  $names
     * @return array<string, T>
     */
    private function reuse(array $rows, array $names): array
    {
        if (! $this->copied || $rows === []) {
            return [];
        }
        $out = [];
        foreach ($names as $i => $name) {
            $out[$name] = $rows[$i % count($rows)];
        }

        return $out;
    }

    private function timetable(): string
    {
        $slots = [
            // weekday 0=Sun … 6=Sat; [day, time, program, trainer, branch]
            [6, '07:30', 'spin', 4, 'main'], [6, '09:00', 'pilates', 1, 'main'], [6, '17:30', 'zumba', 0, 'main'], [6, '19:00', 'strength', 3, 'main'], [6, '20:15', 'yoga', 2, 'second'],
            [0, '09:30', 'yoga', 2, 'main'], [0, '11:00', 'aqua', 5, 'main'], [0, '17:30', 'hiit', 0, 'main'], [0, '19:00', 'pilates', 1, 'main'], [0, '19:30', 'kickbox', 4, 'second'],
            [1, '09:00', 'stretch', 1, 'main'], [1, '18:00', 'zumba', 0, 'main'], [1, '19:30', 'kickbox', 4, 'main'], [1, '18:30', 'spin', 4, 'second'],
            [2, '09:30', 'yoga', 2, 'main'], [2, '11:00', 'aqua', 5, 'main'], [2, '17:30', 'pilates', 1, 'main'], [2, '19:00', 'strength', 3, 'main'], [2, '20:00', 'zumba', 0, 'second'],
            [3, '09:00', 'pilates', 1, 'main'], [3, '18:00', 'hiit', 0, 'main'], [3, '19:30', 'yoga', 2, 'main'], [3, '18:30', 'stretch', 5, 'second'],
            [4, '10:00', 'zumba', 0, 'main'], [4, '17:00', 'stretch', 1, 'main'], [4, '18:30', 'spin', 4, 'main'],
        ];

        foreach ($slots as [$day, $time, $program, $trainer, $branch]) {
            $p = $this->programs[$program];
            $row = [
                'program_id' => $p->id, 'trainer_id' => $this->trainers[$trainer]->id, 'weekday' => $day, 'starts_at' => $time,
                'duration_minutes' => $p->duration_minutes ?: 60, 'capacity' => $p->default_capacity ?: 15,
                'room' => match ($program) {
                    'strength' => 'صالة الأوزان', 'aqua' => 'المسبح', 'spin' => 'قاعة الدراجات', default => 'الاستوديو'
                },
                'valid_from' => today()->subWeeks(8),
            ];
            if (Schema::hasColumn('gym_class_schedules', 'branch_id')) {
                $row['branch_id'] = $this->branches[$branch]->id;
            }
            ClassSchedule::create($row);
        }

        app(ScheduleService::class)->generate(4);

        return count($slots).' weekly classes, '.ClassSession::count().' sessions ahead';
    }

    private function messagingSetup(): string
    {
        // Templates and keyword replies only. No WhatsApp integration is set: the demo club never sends anything.
        TemplateCatalog::seedDefaults(approved: true);
        $this->copied || MessageTemplate::query()->update(['status' => 'approved']);
        if (AutoReply::exists()) {
            return MessageTemplate::count().' templates, the gym\'s auto replies';
        }
        foreach ([
            ['الأسعار', 'أهلًا بك! أسعار باقاتنا هنا: الموقع ← الباقات. يسعدنا خدمتك!'],
            ['الدوام', 'أوقات العمل: السبت–الخميس 6 صباحًا – 11 مساءً، الجمعة 4 – 10 مساءً.'],
            ['الموقع', 'نحن في الخوير، شارع 33 — وفرعنا الثاني في العذيبة الجنوبية.'],
            ['تجميد', 'لتجميد اشتراكك راسلي الاستقبال باسمك ومدة التجميد.'],
        ] as [$keyword, $reply]) {
            AutoReply::create(['keyword' => $keyword, 'reply' => $reply]);
        }

        return MessageTemplate::count().' templates, 4 auto replies';
    }

    private function madeUpSite(): void
    {
        SiteSetting::query()->update([
            'hero_title' => 'قوتك تبدأ هنا',
            'hero_badge' => 'نسخة تجريبية',
            'hero_subtitle' => 'كل ما تحتاجه للياقتك في مكان واحد — حصص جماعية، تدريب شخصي، وفريق تدريب محترف.',
            'about_title' => 'عن النادي',
            'about_body' => "هذه نسخة تجريبية من النظام ببيانات غير حقيقية.\n\nصالة أجهزة حديثة، استوديو للحصص الجماعية، وفريق تدريب معتمد.",
            'announcement' => 'عرض الافتتاح: خصم 10% بكود WELCOME10 على أول اشتراك',
            'contact_phone' => '96824000010',
            'address' => 'الخوير، شارع 33، مسقط',
            'working_hours' => [['days' => 'السبت – الخميس', 'hours' => '6:00 ص – 11:00 م'], ['days' => 'الجمعة', 'hours' => '4:00 م – 10:00 م']],
            'stats' => [['label' => 'عضوة', 'value' => '+'.max(100, count($this->members))], ['label' => 'حصة أسبوعيًا', 'value' => '26'], ['label' => 'مدربة معتمدة', 'value' => '6']],
        ]);

        $posts = [
            ['offer', 'عرض الصيف: ربع سنوية بخصم 5 ريال', 'summer-offer', 'استخدمي الكود SUMMER5 عند الاشتراك في الباقة الربع سنوية.', 3, today()->addMonths(2)],
            ['news', 'إطلاق حصص الكيك بوكس', 'kickboxing-launch', 'حصة جديدة كل اثنين مع الكوتش جود.', 10, null],
            ['news', 'افتتاح فرع العذيبة', 'azaiba-branch', 'فرعنا الثاني يفتح أبوابه بحصص مسائية يوميًا.', 25, null],
            ['offer', 'ادعي صديقتك واحصلي على خصم 15%', 'friend-offer', 'كود FRIEND15 لك ولصديقتك.', 6, today()->addMonth()],
            ['news', 'حصص أكوا فت في المسبح', 'aqua-fit', 'تمارين مائية لطيفة على المفاصل كل أحد وثلاثاء.', 40, null],
        ];
        foreach ($posts as [$type, $title, $slug, $excerpt, $ago, $ends]) {
            SitePost::create(['type' => $type, 'title' => $title, 'slug' => $slug, 'excerpt' => $excerpt, 'body' => $excerpt, 'is_published' => true, 'published_at' => now()->subDays($ago), 'ends_on' => $ends]);
        }
        SitePost::create(['type' => 'news', 'title' => 'مسودة: جدول رمضان', 'slug' => 'ramadan-draft', 'excerpt' => 'لم يُنشر بعد.', 'body' => 'جدول الحصص في رمضان.', 'is_published' => false]);
    }

    private function website(): string
    {
        $this->copied ? null : $this->madeUpSite();

        $messages = [
            ['سارة', 'استفسار عن الأسعار', 'كم سعر الاشتراك الشهري؟ وهل يوجد خصم للطالبات؟', 'new'],
            ['نوال', 'حصص المبتدئات', 'هل عندكم حصص للمبتدئات في اليوغا؟', 'new'],
            ['منى', 'التدريب الشخصي', 'أرغب بمدربة خاصة ثلاث مرات أسبوعيًا.', 'read'],
            ['هيفاء', 'شكوى', 'التكييف في الاستوديو ضعيف مساءً.', 'read'],
            ['ريما', 'شراكة', 'نحن شركة ونرغب باشتراكات لموظفاتنا.', 'new'],
            ['خلود', 'الدوام', 'هل تفتحون يوم الجمعة صباحًا؟', 'replied'],
            ['عبير', 'هدية', 'أريد إهداء اشتراك لأختي، كيف؟', 'new'],
            ['أمل', 'فرع العذيبة', 'متى تبدأ الحصص في فرع العذيبة؟', 'read'],
        ];
        foreach ($messages as $i => [$name, $subject, $body, $status]) {
            $at = now()->subDays($i * 2)->subHours($i);
            ContactMessage::forceCreate(['tenant_id' => $this->tenants->id(), 'name' => $name, 'phone' => $this->nextPhone(), 'subject' => $subject, 'message' => $body,
                'status' => in_array($status, ['new', 'read', 'replied'], true) ? $status : 'new', 'created_at' => $at, 'updated_at' => $at]);
        }

        return ($this->copied ? 'the gym\'s website, ' : '6 posts, ').count($messages).' contact messages';
    }

    private function inventory(): string
    {
        $this->suppliers[] = Supplier::create(['name' => 'مؤسسة الصحة للتجهيزات الرياضية', 'contact_name' => 'أحمد', 'phone' => $this->nextPhone()]);
        $this->suppliers[] = Supplier::create(['name' => 'مياه النقاء', 'contact_name' => 'سالم', 'phone' => $this->nextPhone()]);
        $this->suppliers[] = Supplier::create(['name' => 'مكملات الخليج', 'contact_name' => 'خالد', 'phone' => $this->nextPhone()]);

        $rows = [
            ['مياه 500 مل', 0.2, 0.08, 'مشروبات', 0], ['مياه 1.5 لتر', 0.4, 0.18, 'مشروبات', 1], ['مشروب طاقة رياضي', 0.8, 0.45, 'مشروبات', 1],
            ['عصير برتقال طبيعي', 1.0, 0.55, 'مشروبات', 1], ['قهوة باردة', 1.5, 0.8, 'مشروبات', 1],
            ['بروتين بار', 1.2, 0.7, 'مكملات', 2], ['بروتين واي 1 كغ', 18.0, 12.0, 'مكملات', 2], ['كرياتين 300 غ', 9.5, 6.0, 'مكملات', 2],
            ['منشفة Alopera', 3.5, 1.8, 'إكسسوارات', 0], ['شيكر بروتين', 2.5, 1.2, 'إكسسوارات', 0], ['قفازات تمرين', 4.0, 2.2, 'إكسسوارات', 0],
            ['حصيرة يوغا', 9.0, 5.0, 'إكسسوارات', 0], ['حبل قفز', 3.0, 1.4, 'إكسسوارات', 0], ['قارورة رياضية', 2.8, 1.3, 'إكسسوارات', 0],
            ['تيشيرت Alopera', 6.0, 3.0, 'ملابس', 0], ['ليقنز رياضي', 11.0, 6.5, 'ملابس', 0],
        ];

        $byProduct = [];
        $theirs = $this->copied ? Product::where('is_active', true)->orderBy('id')->get() : collect();
        foreach ($theirs as $i => $p) {
            $this->products[] = $p;
            if ($p->track_stock ?? true) {
                $byProduct[$i % 3][] = ['product_id' => $p->id, 'quantity' => 60, 'unit_cost' => (float) ($p->cost ?: round((float) $p->price * 0.55, 3))];
            }
        }
        foreach ($theirs->isEmpty() ? $rows : [] as $i => [$name, $price, $cost, $cat, $supplier]) {
            $p = new Product(['name' => $name, 'price' => $price, 'sku' => 'DEM-'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT), 'reorder_level' => 5]);
            $p->product_category_id = ProductCategory::firstOrCreate(['name' => $cat])->id;
            $p->save();
            $this->products[] = $p;
            $qty = in_array($name, ['حصيرة يوغا', 'بروتين واي 1 كغ', 'ليقنز رياضي'], true) ? 6 : 60;
            $byProduct[$supplier][] = ['product_id' => $p->id, 'quantity' => $qty, 'unit_cost' => $cost];
        }

        $purchases = app(PurchaseService::class);
        ksort($byProduct);
        foreach ($byProduct as $supplier => $items) {
            $this->travel(today()->subDays(75 - $supplier * 5)->setTime(11, 0), function () use ($purchases, $supplier, $items) {
                $po = $purchases->approve($purchases->create($this->suppliers[$supplier], $items));
                $purchases->receive($po, $po->items()->get()->mapWithKeys(fn ($item) => [$item->id => ['quantity' => $item->quantity]])->all(), approveNow: true);
            });
        }

        // A second round for the drinks a month later. No order left waiting: everything ordered was received.
        isset($byProduct[1]) && $this->travel(today()->subDays(30)->setTime(10, 0), function () use ($purchases, $byProduct) {
            $po = $purchases->approve($purchases->create($this->suppliers[1], array_map(fn ($l) => ['quantity' => 40] + $l, $byProduct[1])));
            $purchases->receive($po, $po->items()->get()->mapWithKeys(fn ($item) => [$item->id => ['quantity' => $item->quantity]])->all(), approveNow: true);
        });

        return count($this->products).' products, 3 suppliers, '.PurchaseOrder::count().' purchase orders';
    }

    private function members(int $count): string
    {
        $memberService = app(MemberService::class);
        $this->welcome = Coupon::where('code', 'WELCOME10')->where('is_active', true)->exists();
        $memberships = app(MembershipService::class);
        $payments = app(PaymentService::class);
        $today = CarbonImmutable::today();

        // Every sale paid in full on the spot: the gym records nothing before payment, so no waiting or part-paid membership.
        $mix = ['active' => 60, 'expiring' => 8, 'expired' => 16, 'renewed' => 10, 'frozen' => 3, 'lead' => 3];
        $scenarios = [];
        foreach ($mix as $scenario => $share) {
            $scenarios = array_merge($scenarios, array_fill(0, (int) round($count * $share / 100), $scenario));
        }
        $scenarios = array_slice(array_pad($scenarios, $count, 'active'), 0, $count);
        shuffle($scenarios);
        $planPool = ['monthly', 'monthly', 'monthly', 'quarter', 'quarter', 'semi', 'yearly', 'classes12', 'gym', 'student'];
        $sources = ['walk_in', 'website', 'instagram', 'referral'];

        foreach ($scenarios as $i => $scenario) {
            $plan = $this->plans[$planPool[$i % count($planPool)]];
            $days = (int) $today->diffInDays($plan->endDateFrom($today)) + 1;
            $start = match ($scenario) {
                'active', 'frozen' => $today->subDays(mt_rand(0, max(0, $days - 10))),
                'expiring' => $today->subDays($days - mt_rand(2, 6)),
                'expired' => $today->subDays($days + mt_rand(5, 120)),
                'renewed' => $today->subDays($days + mt_rand(1, 10)),
                'lead' => $today->subDays(mt_rand(0, 20)),
                default => $today->subDays(mt_rand(0, 3)),
            };

            $member = $this->travel($start->setTime(mt_rand(8, 20), mt_rand(0, 59)), function () use ($memberService, $memberships, $payments, $plan, $scenario, $i, $sources) {
                $data = [
                    'full_name' => self::FIRST[mt_rand(0, count(self::FIRST) - 1)].' '.self::LAST[mt_rand(0, count(self::LAST) - 1)],
                    'phone' => $this->nextPhone(), 'email' => null, 'gender' => 'female',
                    'date_of_birth' => today()->subYears(mt_rand(16, 50))->subDays(mt_rand(0, 360))->toDateString(),
                    'source' => $sources[$i % 4], 'marketing_opt_in' => mt_rand(0, 9) < 7, 'joined_on' => today(),
                ];
                $member = $memberService->create($data, null);
                if ($scenario === 'lead') {
                    return $member;
                }
                $membership = $memberships->sell($member, $plan, $i % 9 === 0 && $scenario === 'active' && $this->welcome ? ['coupon_code' => 'WELCOME10'] : []);
                $invoice = $membership->invoice()->first();
                $method = ['cash', 'cash', 'card', 'bank_transfer'][$i % 4];
                $payment = $payments->record($invoice, $invoice->total, $method, ['reference' => $method === 'card' ? 'POS-'.mt_rand(100000, 999999) : null]);
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
            $this->members[] = $member;
        }

        $memberships->runDailyTransitions();

        foreach (Membership::where('status', Membership::ACTIVE)->whereHas('plan', fn ($q) => $q->where('freeze_allowed', true))->inRandomOrder()->limit((int) max(2, $count * 0.03))->get() as $m) {
            try {
                DB::transaction(fn () => $memberships->freeze($m, mt_rand(5, 14), ['سفر', 'ظرف صحي', 'امتحانات'][mt_rand(0, 2)]));
            } catch (Throwable) {
            }
        }

        $notes = ['تم التواصل للتذكير بموعد التجديد، وهناك اهتمام بالباقة الربع سنوية.', 'سألت عن التدريب الشخصي، أرسلنا لها الأسعار.', 'طلبت تغيير موعد الحصة.', 'مهتمة بباقة السنوية بعد انتهاء الحالية.', 'اشتكت من الزحام وقت الذروة.'];
        foreach (array_slice($this->members, 0, 40) as $k => $member) {
            MemberNote::create(['member_id' => $member->id, 'channel' => ['call', 'whatsapp', 'visit'][$k % 3], 'direction' => $k % 2 ? 'inbound' : 'outbound',
                'body' => $notes[$k % count($notes)], 'user_id' => $this->staff[1]->id ?? $this->owner->id, 'follow_up_on' => $k % 4 === 0 ? today()->addDays($k % 10) : null]);
        }

        return count($this->members).' members, '.Membership::count().' memberships, '.Invoice::count().' invoices';
    }

    private function classHistory(): string
    {
        $schedules = ClassSchedule::all();
        $active = Membership::whereIn('status', [Membership::ACTIVE, Membership::FROZEN, Membership::EXPIRED])->pluck('id', 'member_id');
        $memberIds = $active->keys()->all();
        $bookings = 0;

        for ($d = 42; $d >= 1; $d--) {
            $date = CarbonImmutable::today()->subDays($d);
            foreach ($schedules->where('weekday', $date->dayOfWeek) as $s) {
                $start = CarbonImmutable::parse($date->toDateString().' '.$s->starts_at);
                $session = ClassSession::firstOrCreate(['class_schedule_id' => $s->id, 'starts_at' => $start], array_filter([
                    'program_id' => $s->program_id, 'trainer_id' => $s->trainer_id, 'ends_at' => $start->addMinutes($s->duration_minutes),
                    'capacity' => $s->capacity, 'room' => $s->room, 'status' => ClassSession::COMPLETED, 'branch_id' => $s->branch_id ?? null,
                ], fn ($v) => $v !== null));
                $picked = collect($memberIds)->shuffle()->take(mt_rand((int) ($s->capacity * 0.4), $s->capacity));
                foreach ($picked as $memberId) {
                    Booking::create([
                        'class_session_id' => $session->id, 'member_id' => $memberId, 'membership_id' => $active[$memberId],
                        'status' => mt_rand(1, 100) <= 82 ? Booking::ATTENDED : Booking::NO_SHOW, 'source' => mt_rand(0, 1) ? 'portal' : 'reception',
                        'created_at' => $start->subDay(), 'updated_at' => $start,
                    ]);
                    $bookings++;
                }
                ClassSession::whereKey($session->id)->update(['booked_count' => $picked->count()]);
            }
        }

        return $bookings.' past bookings';
    }

    private function checkIns(): string
    {
        $n = 0;
        foreach (Membership::whereIn('status', [Membership::ACTIVE, Membership::FROZEN])->get(['id', 'member_id']) as $m) {
            for ($i = 0, $visits = mt_rand(4, 22); $i < $visits; $i++) {
                $at = now()->subDays(mt_rand(0, 45))->setTime(mt_rand(6, 22), mt_rand(0, 59));
                CheckIn::create(['member_id' => $m->member_id, 'membership_id' => $m->id, 'method' => mt_rand(0, 2) ? 'qr' : 'manual', 'result' => 'allowed', 'reason' => 'ok', 'checked_in_at' => $at, 'created_at' => $at]);
                $n++;
            }
            Member::whereKey($m->member_id)->update(['last_check_in_at' => now()->subDays(mt_rand(0, 6))->setTime(mt_rand(7, 21), 10)]);
        }
        // Today at the door, for the reception screen and the dashboard.
        foreach (Membership::where('status', Membership::ACTIVE)->inRandomOrder()->limit(25)->get(['id', 'member_id']) as $m) {
            $at = now()->subMinutes(mt_rand(5, 600));
            CheckIn::create(['member_id' => $m->member_id, 'membership_id' => $m->id, 'method' => 'qr', 'result' => 'allowed', 'reason' => 'ok', 'checked_in_at' => $at, 'created_at' => $at]);
            $n++;
        }
        foreach (Membership::where('status', Membership::EXPIRED)->limit(10)->get(['member_id']) as $m) {
            CheckIn::create(['member_id' => $m->member_id, 'method' => 'qr', 'result' => 'denied', 'reason' => 'membership_expired', 'checked_in_at' => now()->subDays(mt_rand(0, 10))]);
            $n++;
        }

        return $n.' check-ins';
    }

    private function futureBookings(): string
    {
        $service = app(BookingService::class);
        $n = 0;
        $sessions = ClassSession::where('status', ClassSession::SCHEDULED)->where('starts_at', '>', now())->where('starts_at', '<', now()->addDays(10))->get();
        $memberIds = Membership::where('status', Membership::ACTIVE)->pluck('member_id')->unique();
        foreach ($sessions as $session) {
            foreach ($memberIds->shuffle()->take(mt_rand(4, $session->capacity)) as $memberId) {
                try {
                    DB::transaction(fn () => $service->book(Member::find($memberId), $session, mt_rand(0, 1) ? 'portal' : 'reception'));
                    $n++;
                } catch (Throwable) {
                    // the plan leaves out this class, or the session is full — as in real life
                }
            }
        }

        return $n.' upcoming bookings';
    }

    private function personalTraining(): string
    {
        $pt = app(PtService::class);
        $payments = app(PaymentService::class);
        $packages = PtPackage::orderBy('sessions_count')->get();
        $candidates = Member::whereHas('memberships', fn ($q) => $q->where('status', Membership::ACTIVE))->inRandomOrder()->limit(14)->get();
        $closed = 0;
        $booked = 0;

        foreach ($candidates as $k => $member) {
            $package = $packages[$k % $packages->count()];
            $trainer = $package->trainer_id ? Trainer::find($package->trainer_id) : $this->trainers[[3, 0, 1, 4][$k % 4]];
            $soldAt = CarbonImmutable::today()->subDays(mt_rand(10, 25))->setTime(12, 0);

            $sub = $this->travel($soldAt, function () use ($pt, $payments, $member, $package, $trainer, $k) {
                $sub = $pt->sell($member, $package, $trainer);
                $payments->record($sub->invoice()->first(), $sub->price, $k % 2 ? 'card' : 'cash', ['reference' => $k % 2 ? 'POS-'.mt_rand(100000, 999999) : null]);

                return $sub->fresh();
            });
            if ($sub->status !== PtSubscription::ACTIVE) {
                continue;
            }

            // Past sessions, closed as they happened, then two ahead.
            $hour = 7 + ($k % 4);
            for ($s = 0; $s < min(4, $package->sessions_count - 2); $s++) {
                $at = $soldAt->addDays(2 + $s * 3)->setTime($hour, 0);
                if ($at->dayOfWeek === 5 || $at->isFuture()) {
                    continue;
                }
                try {
                    DB::transaction(function () use ($pt, $sub, $at) {
                        $session = $this->travel($at->subDay(), fn () => $pt->schedule($sub->fresh(), $at));
                        $this->travel($at->addHour(), fn () => $pt->close($session, mt_rand(0, 9) ? PtSession::COMPLETED : PtSession::NO_SHOW));
                    });
                    $closed++;
                } catch (Throwable) {
                }
            }
            foreach ([1, 3] as $ahead) {
                $at = CarbonImmutable::today()->addDays($ahead + $k % 3)->setTime(16 + $k % 5, 0);
                if ($at->dayOfWeek === 5) {
                    $at = $at->addDay();
                }
                try {
                    DB::transaction(fn () => $pt->schedule($sub->fresh(), $at));
                    $booked++;
                } catch (Throwable) {
                }
            }
        }

        return $candidates->count().' PT subscriptions, '.$closed.' sessions done, '.$booked.' ahead';
    }

    private function sales(): string
    {
        $pos = app(PosService::class);
        $stock = app(StockService::class);
        $n = 0;
        for ($d = $this->days; $d >= 0; $d--) {
            for ($k = 0, $count = mt_rand(2, 6); $k < $count; $k++) {
                $this->travel(now()->subDays($d)->setTime(mt_rand(8, 21), mt_rand(0, 59)), function () use ($pos, $stock, &$n) {
                    $shelf = $stock->quantities();
                    $items = collect($this->products)->shuffle()->take(mt_rand(1, 3))
                        ->map(fn ($p) => ['product_id' => $p->id, 'quantity' => ($p->track_stock ?? true) ? min(mt_rand(1, 3), (int) ($shelf[$p->id] ?? 0)) : mt_rand(1, 3)])
                        ->where('quantity', '>', 0)->values()->all();
                    if ($items !== []) {
                        $pos->checkout($items, mt_rand(0, 2) ? 'cash' : 'card', mt_rand(0, 1) ? $this->members[array_rand($this->members)] : null);
                        $n++;
                    }
                });
            }
        }

        return $n.' sales';
    }

    private function stockWork(): string
    {
        $done = [];
        $main = $this->branches['main']->id;
        $second = $this->branches['second']->id;
        $shelf = app(StockService::class)->quantities($main);
        // Only what the main branch holds plenty of, so every transfer and request below can be met.
        $tracked = collect($this->products)->filter(fn (Product $p) => (bool) ($p->fresh()->track_stock ?? true) && (float) ($shelf[$p->id] ?? 0) >= 20)->values();

        $transfers = app(TransferService::class);
        $this->travel(today()->subDays(20)->setTime(10, 0), function () use ($transfers, $main, $second, $tracked) {
            $t = $transfers->create($main, $second, $tracked->take(5)->map(fn ($p) => ['product_id' => $p->id, 'quantity' => 6])->all(), 'تجهيز فرع العذيبة', direct: true);
            $transfers->receive($t->fresh());
        });
        $this->travel(today()->subDays(4)->setTime(10, 0), fn () => $transfers->create($main, $second, $tracked->slice(5, 3)->map(fn ($p) => ['product_id' => $p->id, 'quantity' => 4])->all(), 'دفعة ثانية', direct: true));
        $done[] = '2 transfers';

        $inventory = app(InventoryService::class);
        $this->travel(today()->subDays(15)->setTime(22, 0), function () use ($inventory, $main) {
            $count = $inventory->startCount($main, [], 'جرد نصف الشهر');
            $counts = [];
            foreach ($count->lines()->get() as $j => $line) {
                $counts[$line->id] = ['counted' => max(0, (float) $line->system_quantity - ($j % 4 === 0 ? 1 : 0)), 'reason' => $j % 4 === 0 ? 'damaged' : null];
            }
            $inventory->approveCount($inventory->saveCounts($count, $counts));
        });
        $done[] = '1 stock count';

        $first = $tracked->first() ?? $this->products[0];
        $inventory->adjust($first, $main, -2, 'damaged', 'عبوات تالفة');
        $done[] = '1 adjustment';

        return implode(', ', $done);
    }

    private function supplierBills(): string
    {
        $bills = app(SupplierBillService::class);
        $bank = app(ChartOfAccounts::class)->moneyFor('bank_transfer', 'payment');
        $orders = PurchaseOrder::with('supplier')->where('status', PurchaseOrder::RECEIVED)->orderBy('id')->get();
        $n = 0;
        foreach ($orders as $k => $order) {
            $total = (float) $order->items()->get()->sum(fn ($i) => (float) $i->quantity * (float) $i->unit_cost);
            $issued = CarbonImmutable::parse($order->created_at)->addDays(1);
            $bill = $this->travel($issued->setTime(12, 0), fn () => $bills->approve($bills->create($order->supplier, [
                'reference' => 'INV-'.(7100 + $k), 'issue_date' => $issued->toDateString(), 'due_date' => $issued->addDays(30)->toDateString(),
                'subtotal' => round($total, 3), 'discount' => 0, 'tax' => 0, 'purchase_order_id' => $order->id,
            ])));
            $n++;
            $paidOn = $issued->addDays(10);
            $this->travel($paidOn->setTime(12, 0), fn () => $bills->pay($bill, $bank, $bill->total, $paidOn->toDateString(), 'TRF-'.(5500 + $k)));
        }

        return $n.' supplier bills';
    }

    private function expenses(): string
    {
        $cats = ExpenseCategory::pluck('id', 'name');
        $n = 0;
        for ($m = 5; $m >= 0; $m--) {
            $month = now()->subMonths($m)->startOfMonth();
            $rows = [['إيجار', 'إيجار المقر', 850], ['كهرباء ومياه', 'فاتورة الكهرباء والمياه', 180 + $m * 15], ['تسويق وإعلانات', 'إعلانات إنستغرام', 120 + $m * 10],
                ['صيانة', 'صيانة أجهزة المشي', 45 + $m * 5], ['مستلزمات', 'مواد تنظيف ومناشف', 60], ['أخرى', 'ضيافة وقهوة', 25]];
            foreach ($rows as $i => [$cat, $desc, $amount]) {
                $at = $month->copy()->addDays(2 + $i * 3);
                if ($at->isFuture()) {
                    continue;
                }
                $this->travel($at->setTime(11, 0), fn () => Expense::create([
                    'expense_category_id' => $cats[$cat] ?? null, 'number' => NumberSequence::next('expense'),
                    'description' => $desc.' — '.$month->translatedFormat('F'), 'amount' => $amount, 'expense_date' => today(), 'payment_method' => $i < 2 ? 'bank_transfer' : 'cash',
                ]));
                $n++;
            }
        }

        return $n.' expenses';
    }

    private function cashShifts(): string
    {
        $shifts = app(CashShifts::class);
        $drawer = app(ChartOfAccounts::class)->moneyFor('cash');
        $cashier = collect($this->staff)->first(fn (User $u) => str_starts_with($u->email, 'cashier')) ?? $this->owner;
        $n = 0;
        for ($d = min(6, (int) ($this->days / 7)); $d >= 1; $d--) {
            $day = CarbonImmutable::today()->subDays($d);
            $shift = $this->travel($day->setTime(7, 0), fn () => $shifts->open($drawer, 20, $cashier));
            $this->travel($day->setTime(23, 0), function () use ($shifts, $shift, $cashier, $d) {
                $expected = (float) ($shifts->summary($shift->fresh())['expected'] ?? 20);
                $shifts->close($shift->fresh(), round($expected + ($d === 3 ? -1.5 : 0), 3), $d === 3 ? 'نقص بسيط في الصندوق' : null, $cashier);
            });
            $n++;
        }
        $this->travel(CarbonImmutable::today()->setTime(7, 0), fn () => $shifts->open($drawer, 20, $cashier));

        return ($n + 1).' cash shifts (one open now)';
    }

    private function creditNotes(): string
    {
        $notes = app(CreditNoteService::class);
        $n = 0;
        foreach (Invoice::where('status', 'paid')->where('total', '>', 20)->inRandomOrder()->limit(3)->get() as $k => $invoice) {
            try {
                DB::transaction(fn () => $notes->issue($invoice, $k === 0 ? $invoice->total : 5, $k === 0 ? 'إلغاء الاشتراك خلال فترة التجربة' : 'تعويض عن حصة ملغاة', ['method' => 'cash']));
                $n++;
            } catch (Throwable) {
            }
        }

        return $n.' credit notes';
    }

    private function gifts(): string
    {
        $gifts = app(GiftService::class);
        $payments = app(PaymentService::class);
        $rows = [
            ['أم سارة', 'سارة', 'monthly', true, 'كل عام وأنتِ بخير يا بنتي 🌸'],
            ['ريم', 'نوف', 'quarter', true, 'هدية تخرجك!'],
            ['خالد', 'منى', 'monthly', true, null],
        ];
        $n = 0;
        foreach ($rows as [$giver, $recipient, $plan, $paid, $message]) {
            $gift = $gifts->issue($this->plans[$plan], ['giver_name' => $giver, 'giver_phone' => $this->nextPhone(), 'recipient_name' => $recipient, 'recipient_phone' => $this->nextPhone(), 'message' => $message], 'panel');
            if ($paid && ($invoice = $gift->fresh()->invoice)) {
                $payments->record($invoice, $invoice->total, 'cash');
            }
            $n++;
        }

        return $n.' gifts';
    }

    private function payroll(): string
    {
        JobTitle::ensureDefaults();
        $titles = JobTitle::pluck('id', 'name');
        $rows = [
            ['ليلى البلوشية', 'مدير النادي', 900, [['name' => 'بدل سكن', 'amount' => 150]], 'main'],
            ['هند الحارثية', 'موظف استقبال', 380, [['name' => 'بدل نقل', 'amount' => 40]], 'main'],
            ['أسيل الكندية', 'موظف استقبال', 360, [['name' => 'بدل نقل', 'amount' => 40]], 'second'],
            ['سلمى الرواحية', 'محاسب', 650, [], 'main'],
            ['الكوتش ريم', 'مدرب', 550, [['name' => 'عمولة تدريب شخصي', 'amount' => 80]], 'main'],
            ['الكوتش دانة', 'مدرب', 520, [], 'main'],
            ['الكوتش نوف', 'مدرب', 500, [], 'second'],
            ['الكوتش شهد', 'مدرب', 600, [['name' => 'عمولة تدريب شخصي', 'amount' => 120]], 'main'],
            ['الكوتش جود', 'مدرب', 480, [], 'second'],
            ['فاطمة', 'عامل نظافة', 220, [], 'main'],
            ['جميلة', 'عامل نظافة', 220, [], 'second'],
        ];
        foreach ($rows as $k => [$name, $title, $salary, $allowances, $branch]) {
            Employee::create(['name' => $name, 'job_title_id' => $titles[$title] ?? null, 'branch_id' => $this->branches[$branch]->id, 'phone' => $this->nextPhone(),
                'hired_at' => today()->subMonths(8 + $k)->toDateString(), 'basic_salary' => $salary, 'allowances' => $allowances, 'payment_method' => 'bank', 'bank_name' => 'بنك مسقط']);
        }

        $bank = app(ChartOfAccounts::class)->moneyFor('bank_transfer', 'payment');
        $payroll = app(PayrollService::class);
        $runs = 0;
        for ($m = 3; $m >= 1; $m--) {
            $month = CarbonImmutable::today()->subMonths($m)->startOfMonth();
            $this->travel($month->endOfMonth()->setTime(12, 0), function () use ($payroll, $month, $bank) {
                $run = $payroll->approve($payroll->open($month->format('Y-m')));
                $payroll->pay($run->fresh(), $run->items()->pluck('id')->all(), $bank, $month->endOfMonth()->toDateString(), 'SAL-'.$month->format('Ym'));
            });
            $runs++;
        }

        return count($rows).' employees, '.$runs.' payroll runs, all paid';
    }

    private function documents(): string
    {
        DocumentType::ensureDefaults();
        $types = DocumentType::pluck('id', 'name');
        $service = app(DocumentService::class);
        $rows = [
            ['السجل التجاري', 'CR-1234567', 'وزارة التجارة والصناعة', -300, 65],
            ['رخصة البلدية', 'MB-55821', 'بلدية مسقط', -350, 15],
            ['عقد إيجار المقر — الخوير', 'LS-2025-14', 'المالك', -200, 165],
            ['عقد إيجار — العذيبة', 'LS-2026-03', 'المالك', -60, 305],
            ['شهادة الدفاع المدني', 'CD-7781', 'الدفاع المدني', -340, 25],
            ['تأمين المنشأة', 'POL-99812', 'شركة التأمين', -370, -5],
            ['ترخيص الإعلان', 'AD-332', 'بلدية مسقط', -100, 265],
            ['شهادة صحية — المسبح', 'HL-5520', 'وزارة الصحة', -170, 7],
        ];
        $n = 0;
        $typeIds = $types->values()->all();
        foreach ($rows as $k => [$name, $ref, $issuer, $issued, $expires]) {
            $service->create(['name' => $name, 'reference_number' => $ref, 'issuer' => $issuer, 'document_type_id' => $types[$name] ?? ($typeIds[$k % max(1, count($typeIds))] ?? null),
                'branch_id' => $this->branches[$k === 3 ? 'second' : 'main']->id, 'responsible_user_id' => $this->owner->id,
                'issue_date' => today()->addDays($issued)->toDateString(), 'expiry_date' => today()->addDays($expires)->toDateString(), 'renewal_months' => 12], null);
            $n++;
        }
        $service->raiseAlerts();

        return $n.' documents';
    }

    private function staffAttendance(): string
    {
        $n = 0;
        $people = array_values(array_filter($this->staff, fn (User $u) => ! str_starts_with($u->email, 'manager')));
        for ($d = 30; $d >= 0; $d--) {
            $day = CarbonImmutable::today()->subDays($d);
            if ($day->dayOfWeek === 5) {
                continue;
            }
            foreach ($people as $k => $user) {
                if (mt_rand(0, 9) === 0) {
                    continue;
                }
                $in = $day->setTime($k % 2 ? 14 : 6, mt_rand(0, 25));
                $out = $d === 0 ? null : $in->addHours(8)->addMinutes(mt_rand(0, 40));
                StaffAttendance::create(['user_id' => $user->id, 'clock_in_at' => $in, 'clock_out_at' => $out, 'method' => mt_rand(0, 3) ? 'device' : 'manual', 'created_at' => $in, 'updated_at' => $out ?? $in]);
                $n++;
            }
        }

        AccessDevice::forceCreate(['tenant_id' => $this->tenants->id(), 'branch_id' => $this->branches['main']->id, 'name' => 'بوابة المدخل الرئيسي', 'type' => 'turnstile',
            'token_hash' => hash('sha256', Str::random(40)), 'token_last4' => (string) mt_rand(1000, 9999), 'is_active' => true, 'last_seen_at' => now()->subMinutes(3)]);

        return $n.' attendance rows, 1 door device';
    }

    private function conversations(): string
    {
        $reception = $this->staff[1] ?? $this->owner;
        $add = function (Conversation $c, string $direction, string $source, string $body, int $minutesAgo, array $extra = []): Message {
            $at = now()->subMinutes($minutesAgo);

            return Message::create($extra + ['conversation_id' => $c->id, 'member_id' => $c->member_id, 'direction' => $direction, 'source' => $source,
                'type' => $extra['type'] ?? 'text', 'to_phone' => $c->phone, 'body' => $body,
                'status' => $direction === 'inbound' ? Message::RECEIVED : ($extra['status'] ?? Message::READ),
                'provider_message_id' => 'log.demo.'.Str::uuid(), 'wa_timestamp' => $at, 'created_at' => $at, 'updated_at' => $at]);
        };

        $threads = [
            [Conversation::NEW, 2, [['in', 'مساء الخير', 14], ['in', 'هل يوجد حصة زومبا يوم الخميس؟', 12]]],
            [Conversation::OPEN, 0, [['in', 'أبغى أجمّد اشتراكي أسبوع، مسافرة', 190], ['out', 'أهلًا بك! تم تسجيل طلب التجميد، يبدأ من الغد لمدة 7 أيام. رحلة سعيدة 🌸', 175]]],
            [Conversation::CLOSED, 0, [['in', 'شكرًا على التذكير، أبغى أجدد بنفس الباقة', 300], ['out', 'تمام، ننتظرك في الاستقبال للتجديد 👍', 290]]],
            [Conversation::NEW, 1, [['in', 'السلام عليكم، كم الأسعار؟', 40]]],
            [Conversation::CLOSED, 0, [['in', 'هل عندكم حصص للمبتدئات؟', 4320], ['out', 'نعم، كل سبت الساعة 6 مساءً.', 4300]]],
            [Conversation::OPEN, 1, [['in', 'نسيت قارورتي في الاستوديو أمس', 95], ['out', 'وجدناها، محفوظة في الاستقبال 😊', 80], ['in', 'شكرًا جزيلًا', 70]]],
            [Conversation::NEW, 3, [['in', 'متى تبدأ حصة البيلاتس؟', 9], ['in', 'وهل أحتاج حجز مسبق؟', 8], ['in', '؟', 5]]],
            [Conversation::CLOSED, 0, [['in', 'أبغى أغير موعد جلستي مع الكوتش شهد', 2880], ['out', 'تم نقلها إلى الأربعاء 6 مساءً', 2860]]],
            [Conversation::CLOSED, 0, [['in', 'كم سعر بروتين واي؟', 600], ['out', '18 ريال للعبوة 1 كغ', 590]]],
            [Conversation::OPEN, 0, [['in', 'هل يمكن الدفع بالتحويل البنكي؟', 230], ['out', 'نعم، نرسل لك رقم الحساب الآن', 220]]],
        ];

        foreach ($threads as $k => [$status, $unread, $lines]) {
            $member = $this->members[$k] ?? null;
            $conversation = Conversation::create(['phone' => $member?->phone ?? $this->nextPhone(), 'member_id' => $member?->id, 'status' => $status, 'unread_count' => $unread, 'is_demo' => true,
                'assigned_to' => $status === Conversation::OPEN ? $reception->id : null, 'closed_at' => $status === Conversation::CLOSED ? now()->subDays(1) : null]);
            $first = null;
            $last = null;
            $firstReply = null;
            foreach ($lines as [$dir, $body, $ago]) {
                $last = $add($conversation, $dir === 'in' ? 'inbound' : 'outbound', $dir === 'in' ? Message::SOURCE_CUSTOMER : Message::SOURCE_PLATFORM, $body, $ago, $dir === 'out' ? ['sent_by' => $reception->id] : []);
                $first ??= $dir === 'in' ? $last : null;
                $firstReply ??= $dir === 'out' ? $last : null;
            }
            $conversation->forceFill(['first_inbound_at' => $first?->wa_timestamp, 'first_response_at' => $firstReply?->wa_timestamp, 'last_inbound_at' => $first?->wa_timestamp,
                'last_message_at' => $last->wa_timestamp, 'last_preview' => $last->body, 'last_direction' => $last->direction])->save();
        }
        $conversation = Conversation::where('status', Conversation::OPEN)->first();
        $conversation?->events()->create(['user_id' => $reception->id, 'type' => 'note', 'body' => 'تأكدنا من رصيد أيام التجميد في باقتها قبل الرد.', 'created_at' => now()->subMinutes(176)]);

        return count($threads).' conversations (marked as samples)';
    }

    private function messageLog(): string
    {
        $templates = MessageTemplate::pluck('id', 'key');
        $keys = $templates->keys()->values()->all();
        $n = 0;
        foreach (array_slice($this->members, 0, 120) as $k => $member) {
            $key = $keys[$k % max(1, count($keys))] ?? null;
            if (! $key) {
                break;
            }
            $at = now()->subDays(mt_rand(0, 30))->subMinutes(mt_rand(0, 600));
            Message::create(['member_id' => $member->id, 'direction' => 'outbound', 'source' => Message::SOURCE_AUTOMATION, 'type' => 'template', 'template_key' => $key,
                'to_phone' => $member->phone, 'body' => 'رسالة تجريبية ('.$key.') — لم تُرسل فعليًا.',
                'status' => [Message::READ, Message::READ, Message::DELIVERED, Message::FAILED][$k % 4],
                'provider_message_id' => 'log.demo.'.Str::uuid(), 'wa_timestamp' => $at, 'created_at' => $at, 'updated_at' => $at]);
            $n++;
        }

        $template = MessageTemplate::query()->first();
        if ($template) {
            Campaign::create(['message_template_id' => $template->id, 'name' => 'عرض الصيف للعضوات المنتهية اشتراكاتهن', 'audience' => ['status' => 'expired'], 'status' => Campaign::COMPLETED,
                'started_at' => now()->subDays(12), 'completed_at' => now()->subDays(12)->addMinutes(9), 'recipients_count' => 34, 'sent_count' => 34, 'delivered_count' => 31, 'read_count' => 22, 'failed_count' => 3, 'created_by' => $this->owner->id]);
            Campaign::create(['message_template_id' => $template->id, 'name' => 'تهنئة العيد', 'audience' => ['status' => 'active'], 'status' => Campaign::COMPLETED,
                'started_at' => now()->subDays(40), 'completed_at' => now()->subDays(40)->addMinutes(15), 'recipients_count' => 128, 'sent_count' => 126, 'delivered_count' => 124, 'read_count' => 97, 'failed_count' => 2, 'created_by' => $this->owner->id]);
            Campaign::create(['message_template_id' => $template->id, 'name' => 'إطلاق حصص السبينينغ (مسودة)', 'audience' => ['status' => 'active'], 'status' => Campaign::DRAFT, 'created_by' => $this->owner->id]);
        }

        return $n.' logged messages, 3 campaigns';
    }

    private function ledger(): string
    {
        $result = app(LedgerProjector::class)->syncClub();

        return 'ledger in sync ('.JournalEntry::count().' journal entries)'.(is_array($result) && $result ? '' : '');
    }

    private function nextPhone(): string
    {
        // 9689 + 7 digits from a block of its own, so no two demo rows share a number.
        return '96899'.str_pad((string) (300000 + ++$this->phone), 6, '0', STR_PAD_LEFT);
    }

    private function travel(\DateTimeInterface $at, callable $callback): mixed
    {
        Carbon::setTestNow($at);
        CarbonImmutable::setTestNow($at);
        try {
            return $callback();
        } finally {
            $this->travelBack();
        }
    }

    private function travelBack(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
    }
}
