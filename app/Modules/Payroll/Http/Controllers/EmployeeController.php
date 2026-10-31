<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Core\Audit\AuditLogger;
use App\Core\Audit\AuditRedaction;
use App\Core\Auth\Grants;
use App\Core\Auth\PermissionRegistry;
use App\Core\Auth\SystemRoles;
use App\Core\Support\BusinessRuleException;
use App\Core\Support\Money;
use App\Core\Tenancy\BranchContext;
use App\Core\Tenancy\TenantContext;
use App\Core\Tenancy\TenantRule;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use App\Modules\Gym\Models\StaffAttendance;
use App\Modules\Gym\Models\Trainer;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\Models\JobTitle;
use App\Modules\Payroll\Models\PayrollItem;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Staff files (الموظفون): the list, each employee's file, the add/edit form, and the employee's sign-in account.
 *
 * Every part has its own permission, checked here on the server:
 *   employees.view          the list and the file — never the salary
 *   employees.create        adding a file (and linking trainers)
 *   employees.manage        the basic data: name, contact, job, branch, status, notes
 *   employees.access        the sign-in account, its role, its own permissions, its password
 *   payroll.salaries        reading the salary, allowances and bank details
 *   payroll.compensation    changing them
 *   payroll.payslips        the employee's payslips
 *   staff_attendance.view / .manage   attendance, and correcting it
 *
 * Salary and bank fields are not sent to the page at all without payroll.salaries, and a form without
 * payroll.compensation cannot change them. Nobody but the owner changes their own salary or bank details, and nobody
 * changes their own sign-in role or permissions here.
 */
class EmployeeController extends Controller
{
    public function __construct(private readonly TenantContext $tenants) {}

    public function index(Request $request): Response
    {
        JobTitle::ensureDefaults();
        $user = $request->user();
        $confidential = $user->hasPermission('payroll.salaries');

        $all = BranchContext::scope(Employee::with(['jobTitle:id,name', 'branch:id,name', 'user:id,name,email,role_id,is_active,last_login_at', 'user.role:id,name', 'trainer:id,name']))
            ->orderByRaw("case status when 'active' then 0 when 'on_leave' then 1 else 2 end")->orderBy('name')->get();
        $q = trim($request->string('q')->toString());
        $employees = $all
            ->when($q !== '', fn ($c) => $c->filter(fn (Employee $e) => Str::contains(mb_strtolower($e->name.' '.$e->phone.' '.$e->email), mb_strtolower($q))))
            ->when($request->string('status')->toString(), fn ($c, $s) => $c->where('status', $s))
            ->when($request->integer('job_title_id'), fn ($c, $id) => $c->where('job_title_id', $id))
            ->values();
        $active = $all->where('status', 'active');

        return Inertia::render('Payroll/Employees', [
            'employees' => $employees->map(fn (Employee $e) => $this->row($e, $confidential)),
            'stats' => [
                'active' => $active->count(),
                'on_leave' => $all->where('status', 'on_leave')->count(),
                'with_account' => $all->whereNotNull('user_id')->count(),
                'monthly' => $confidential ? Money::add('0', ...$active->map(fn (Employee $e) => Money::add($e->basic_salary, $e->allowancesTotal()))->all()) : null,
            ],
            'counts' => ['all' => $all->count()] + collect(Employee::STATUSES)->map(fn ($l, $k) => $all->where('status', $k)->count())->all(),
            'jobTitles' => JobTitle::orderBy('name')->get(['id', 'name']),
            'unlinkedTrainers' => Trainer::where('is_active', true)->whereNotIn('id', Employee::whereNotNull('trainer_id')->pluck('trainer_id'))->count(),
            'statuses' => Employee::STATUSES,
            'confidential' => $confidential,
            'can' => $this->abilities($user),
            'filters' => $request->only('q', 'status', 'job_title_id'),
        ]);
    }

    public function create(Request $request): Response
    {
        JobTitle::ensureDefaults();

        return Inertia::render('Payroll/EmployeeForm', $this->formProps($request, null));
    }

    public function edit(Request $request, Employee $employee): Response
    {
        abort_unless($request->user()->hasPermission('employees.manage') || $request->user()->hasPermission('payroll.compensation'), 403);

        return Inertia::render('Payroll/EmployeeForm', $this->formProps($request, $employee));
    }

    public function show(Request $request, Employee $employee): Response
    {
        $user = $request->user();
        $employee->load(['jobTitle:id,name', 'branch:id,name', 'trainer:id,name,user_id', 'user.role', 'user.branch:id,name']);
        $confidential = $user->hasPermission('payroll.salaries');
        $account = $employee->user && $employee->user->tenant_id === $this->tenants->id() ? $employee->user : null;
        $month = CarbonImmutable::now()->startOfMonth();

        $attendance = $account && $user->hasPermission('staff_attendance.view')
            ? StaffAttendance::where('user_id', $account->id)->latest('clock_in_at')->limit(40)->get()
            : collect();
        $monthRows = $account ? StaffAttendance::where('user_id', $account->id)->where('clock_in_at', '>=', $month)->get(['clock_in_at', 'clock_out_at']) : collect();

        $payslips = $user->hasPermission('payroll.payslips')
            ? PayrollItem::with(['run:id,number,period,status', 'payout:id,number,paid_on'])->where('employee_id', $employee->id)->latest('id')->limit(24)->get()
            : collect();

        $history = AuditLog::where(function ($q) use ($employee, $account) {
            $q->where(fn ($w) => $w->where('subject_type', 'Employee')->where('subject_id', $employee->id));
            if ($account) {
                $q->orWhere(fn ($w) => $w->where('subject_type', 'user')->where('subject_id', $account->id));
            }
        })->latest('id')->limit(40)->get();

        $effective = $account ? $account->permissions() : [];
        $labels = collect(PermissionRegistry::groups())->flatMap(fn ($g) => $g['permissions']);

        return Inertia::render('Payroll/EmployeeShow', [
            'employee' => $this->row($employee, $confidential) + [
                'trainer_id' => $employee->trainer_id,
                'notes' => $employee->notes,
                'bank_masked' => $confidential ? self::masked($employee->iban ?: $employee->account_number) : null,
            ],
            'account' => $account ? [
                'id' => $account->id, 'name' => $account->name, 'email' => $account->email, 'phone' => $account->phone,
                'is_active' => $account->is_active, 'last_login_at' => $account->last_login_at?->format('Y-m-d H:i'),
                'role_id' => $account->role_id, 'role' => $account->role?->name, 'role_key' => $account->role?->key,
                'branch_id' => $account->branch_id, 'branch' => $account->branch?->name,
                'own_permissions' => $account->hasOwnPermissions(),
                'custom' => $account->hasOwnPermissions() ? PermissionRegistry::sanitize($account->custom_permissions ?? []) : null,
                'is_me' => $account->id === $user->id,
                // An account opening more than the viewer is shown, never edited, by them.
                'may_manage' => $account->id !== $user->id && Grants::mayManage($user, $account),
            ] : null,
            'stats' => [
                'monthly' => $confidential ? Money::add($employee->basic_salary, $employee->allowancesTotal()) : null,
                'days' => $monthRows->map(fn ($r) => $r->clock_in_at->toDateString())->unique()->count(),
                'hours' => round($monthRows->sum(fn ($r) => $r->clock_out_at ? $r->clock_in_at->diffInMinutes($r->clock_out_at) : 0) / 60, 1),
                'last_net' => $confidential ? $payslips->first()?->net : null,
            ],
            'attendance' => $attendance->map(fn (StaffAttendance $a) => [
                'id' => $a->id, 'date' => $a->clock_in_at->toDateString(), 'in' => $a->clock_in_at->format('H:i'), 'out' => $a->clock_out_at?->format('H:i'),
                'hours' => $a->clock_out_at ? round($a->clock_in_at->diffInMinutes($a->clock_out_at) / 60, 2) : null, 'method' => $a->method, 'notes' => $a->notes,
            ]),
            'payslips' => $payslips->map(fn (PayrollItem $i) => [
                'id' => $i->id, 'period' => $i->run?->label(), 'number' => $i->run?->number, 'run_status' => $i->run?->status,
                'net' => $i->net, 'earnings' => $i->earnings(), 'deductions' => $i->deductions,
                'paid_on' => $i->payout?->paid_on?->toDateString(), 'payout' => $i->payout?->number,
            ]),
            'history' => $history->map(fn (AuditLog $l) => [
                'id' => $l->id, 'event' => $l->event, 'actor' => $l->actor_name, 'at' => $l->created_at->format('Y-m-d H:i'),
                'old' => AuditRedaction::values($l->old_values, $l->subject_type, $user),
                'new' => AuditRedaction::values($l->new_values, $l->subject_type, $user),
                'properties' => AuditRedaction::values($l->properties, $l->subject_type, $user),
            ]),
            'permissions' => collect($effective)->map(fn ($p) => $labels[$p] ?? $p)->values(),
            'access' => $user->hasPermission('employees.access') ? $this->accessOptions($user) : null,
            'confidential' => $confidential,
            'can' => $this->abilities($user),
        ]);
    }

    public function store(Request $request, AuditLogger $audit): RedirectResponse
    {
        $data = $this->validated($request, null);
        $data['branch_id'] = app(BranchContext::class)->assign($data['branch_id'] ?? null);
        $this->guardSelf($request, null, $data);
        $account = $this->accountRequest($request, null);

        $employee = DB::transaction(function () use ($data, $account, $request) {
            $employee = Employee::create(Arr::except($data, ['user_id']) + ['user_id' => $account && $account['mode'] === 'link' ? $account['user_id'] : null]);
            if ($account && $account['mode'] === 'new') {
                $this->createAccount($request, $employee, $account);
            }

            return $employee;
        });
        $audit->log('employee.created', $employee, ['name' => $employee->name]);

        return to_route('club.employees.show', $employee)->with('success', 'أُضيف ملف الموظف.');
    }

    public function update(Request $request, Employee $employee, AuditLogger $audit): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->hasPermission('employees.manage') || $user->hasPermission('payroll.compensation'), 403);
        $data = $this->validated($request, $employee);
        $this->guardSelf($request, $employee, $data);
        $account = $this->accountRequest($request, $employee);

        $before = $employee->getAttributes();
        DB::transaction(function () use ($employee, $data, $account, $request) {
            $employee->update(Arr::except($data, ['user_id']) + ($account && $account['mode'] === 'link' ? ['user_id' => $account['user_id']] : []));
            if ($account && $account['mode'] === 'new') {
                $this->createAccount($request, $employee, $account);
            }
        });
        $this->logChanges($audit, $employee, $before);

        return to_route('club.employees.show', $employee)->with('success', 'حُفظ ملف الموظف.');
    }

    /** Role, branch reach, own permissions and on/off of the employee's sign-in account (employees.access). */
    public function access(Request $request, Employee $employee, AuditLogger $audit): RedirectResponse
    {
        $actor = $request->user();
        $account = $this->accountOf($employee);
        abort_if($account->id === $actor->id, 403, 'لا تعدّل دورك أو صلاحياتك بنفسك.');
        abort_unless(Grants::mayManage($actor, $account), 403, 'هذا الحساب يفتح ما لا تفتحه، فلا تعدّله.');

        $data = $request->validate([
            'role_id' => ['required', TenantRule::exists('roles')],
            'branch_id' => ['nullable', TenantRule::exists('branches')],
            'is_active' => ['boolean'],
            'custom' => ['boolean'],
            'permissions' => ['array'],
            'permissions.*' => ['string', 'in:'.implode(',', PermissionRegistry::all())],
        ], [], ['role_id' => 'الدور']);

        $role = Role::findOrFail($data['role_id']);
        if ($role->id !== $account->role_id && ! Grants::mayAssign($actor, $role)) {
            throw ValidationException::withMessages(['role_id' => 'لا يمكنك إسناد دور يفتح ما لا تملكه.']);
        }
        $this->keepAnOwner($account, $role, (bool) ($data['is_active'] ?? true));

        $old = ['role_id' => $account->role_id, 'branch_id' => $account->branch_id, 'is_active' => $account->is_active, 'custom_permissions' => $account->custom_permissions];
        $custom = null;
        if (($data['custom'] ?? false) && $role->key !== SystemRoles::OWNER) {
            // Starting from the role's list when there was none of their own; the actor decides only what they hold.
            $stored = $account->custom_permissions !== null ? PermissionRegistry::sanitize($account->custom_permissions) : $role->expandedPermissions();
            $custom = [...Grants::merge($actor, $stored, $data['permissions'] ?? []), ...PermissionRegistry::dormant($account->custom_permissions ?? [])];
        }
        $account->forceFill([
            'role_id' => $role->id,
            'branch_id' => $data['branch_id'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? true),
            'custom_permissions' => $custom,
        ])->save();
        $account->flushPermissionCache();
        $audit->log('user.access_updated', $account, ['employee' => $employee->name], $old, ['role_id' => $account->role_id, 'branch_id' => $account->branch_id, 'is_active' => $account->is_active, 'custom_permissions' => $account->custom_permissions]);

        return back()->with('success', 'حُفظ دخول الموظف وصلاحياته.');
    }

    /** A new temporary password, shown once (employees.access). */
    public function password(Request $request, Employee $employee, AuditLogger $audit): RedirectResponse
    {
        $actor = $request->user();
        $account = $this->accountOf($employee);
        abort_if($account->id === $actor->id, 403, 'غيّر كلمة مرورك من «حسابي».');
        abort_unless(Grants::mayManage($actor, $account), 403, 'هذا الحساب يفتح ما لا تفتحه، فلا تغيّر كلمة مروره.');
        $password = self::temporaryPassword();
        $account->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
        $audit->log('user.password_reset', $account, ['employee' => $employee->name]);

        return back()->with('success', 'أُنشئت كلمة مرور مؤقتة.')->with('password', $password);
    }

    /** A manual attendance entry or correction for the employee's account (staff_attendance.manage). */
    public function attendance(Request $request, Employee $employee, AuditLogger $audit): RedirectResponse
    {
        $account = $this->accountOf($employee);
        $data = $request->validate([
            'date' => ['required', 'date', 'before_or_equal:today'],
            'in' => ['required', 'date_format:H:i'],
            'out' => ['nullable', 'date_format:H:i', 'after:in'],
            'notes' => ['required', 'string', 'max:255'],
        ], [], ['date' => 'التاريخ', 'in' => 'وقت الحضور', 'out' => 'وقت الانصراف', 'notes' => 'سبب التسجيل']);
        $in = CarbonImmutable::parse($data['date'].' '.$data['in']);
        $out = isset($data['out']) ? CarbonImmutable::parse($data['date'].' '.$data['out']) : null;
        if ($out && $out->isFuture()) {
            throw ValidationException::withMessages(['out' => 'وقت الانصراف لم يأتِ بعد.']);
        }
        $overlap = StaffAttendance::where('user_id', $account->id)->where('clock_in_at', '<', $out ?? $in->addMinute())
            ->where(fn ($q) => $q->whereNull('clock_out_at')->where('clock_in_at', '>=', $in->startOfDay())->orWhere('clock_out_at', '>', $in))->exists();
        if ($overlap) {
            throw ValidationException::withMessages(['in' => 'يتداخل مع حضور مسجّل في الوقت نفسه.']);
        }
        $row = StaffAttendance::create([
            'user_id' => $account->id, 'branch_id' => $employee->branch_id, 'clock_in_at' => $in, 'clock_out_at' => $out,
            'method' => 'correction', 'notes' => $data['notes'],
        ]);
        $audit->log('staff_attendance.corrected', $row, ['employee' => $employee->name, 'date' => $data['date']]);

        return back()->with('success', 'سُجّل الحضور.');
    }

    /** Trainers get a staff file each — linked, not copied twice — so they can be put on the payroll. */
    public function importTrainers(AuditLogger $audit): RedirectResponse
    {
        $count = DB::transaction(function () {
            // Clubs created before the neutral wording keep their existing «مدربة» title instead of getting a second one.
            $title = JobTitle::firstWhere('name', 'مدرب') ?? JobTitle::firstWhere('name', 'مدربة') ?? JobTitle::create(['name' => 'مدرب']);
            $linkedTrainers = Employee::whereNotNull('trainer_id')->lockForUpdate()->pluck('trainer_id');
            $linkedUsers = Employee::whereNotNull('user_id')->pluck('user_id');
            $made = 0;
            foreach (Trainer::where('is_active', true)->whereNotIn('id', $linkedTrainers)->orderBy('name')->get() as $trainer) {
                Employee::create([
                    'name' => $trainer->name, 'phone' => $trainer->phone, 'email' => $trainer->email, 'branch_id' => $trainer->branch_id,
                    'trainer_id' => $trainer->id, 'job_title_id' => $title->id,
                    'user_id' => $trainer->user_id && ! $linkedUsers->contains($trainer->user_id) ? $trainer->user_id : null,
                ]);
                $made++;
            }

            return $made;
        });
        $audit->log('employee.trainers_imported', null, ['count' => $count]);

        return back()->with('success', $count ? "أُنشئت ملفات لعدد {$count} من المدربين." : 'كل المدربين لديهم ملفات بالفعل.');
    }

    /**
     * The fields this user may write: basic data with employees.manage (or employees.create for a new file), salary
     * and bank with payroll.compensation. Anything else sent is ignored.
     */
    private function validated(Request $request, ?Employee $employee): array
    {
        $user = $request->user();
        $basic = $employee ? $user->hasPermission('employees.manage') : $user->hasPermission('employees.create');
        $money = $user->hasPermission('payroll.compensation');
        abort_unless($basic || ($employee && $money), 403);

        $rules = [];
        if ($basic) {
            $rules += [
                'name' => ['required', 'string', 'max:120'],
                'phone' => ['nullable', 'string', 'max:32'],
                'email' => ['nullable', 'email', 'max:190'],
                'hired_at' => ['nullable', 'date'],
                'status' => ['required', Rule::in(array_keys(Employee::STATUSES))],
                'job_title_id' => ['nullable', TenantRule::exists('gym_job_titles')],
                'branch_id' => ['nullable', ...app(BranchContext::class)->rule($employee?->branch_id)],
                'trainer_id' => ['nullable', TenantRule::exists('gym_trainers'), TenantRule::unique('gym_employees', 'trainer_id')->ignore($employee?->id)],
                'notes' => ['nullable', 'string', 'max:2000'],
            ];
        }
        if ($money) {
            $rules += [
                'basic_salary' => ['required', 'numeric', 'min:0', 'max:99999999'],
                'allowances' => ['nullable', 'array', 'max:10'],
                'allowances.*.name' => ['required', 'string', 'max:60'],
                'allowances.*.amount' => ['required', 'numeric', 'min:0', 'max:99999999'],
                'payment_method' => ['required', Rule::in(['bank', 'cash'])],
                'bank_name' => ['nullable', 'string', 'max:120'],
                'iban' => ['nullable', 'string', 'max:34'],
                'account_number' => ['nullable', 'string', 'max:34'],
            ];
        }

        $data = $request->validate($rules, [], ['trainer_id' => 'ملف المدرب', 'basic_salary' => 'الراتب الأساسي', 'name' => 'الاسم']);
        if ($money) {
            $data['basic_salary'] = Money::normalize($data['basic_salary']);
            $data['allowances'] = array_values(array_map(fn ($a) => ['name' => $a['name'], 'amount' => Money::normalize($a['amount'])], $data['allowances'] ?? []));
        }

        return $data;
    }

    /**
     * The sign-in part of the form, for employees.access: none, link an existing account, or open a new one.
     *
     * @return array{mode: string, user_id?: int, email?: string, role_id?: int, branch_id?: int|null}|null
     */
    private function accountRequest(Request $request, ?Employee $employee): ?array
    {
        $mode = $request->string('account_mode')->toString();
        if (! in_array($mode, ['link', 'new'], true) || ! $request->user()->hasPermission('employees.access') || $employee?->user_id) {
            return null;
        }
        if ($mode === 'link') {
            $data = $request->validate(['account_user_id' => ['required', TenantRule::exists('users'), TenantRule::unique('gym_employees', 'user_id')->ignore($employee?->id)]], [], ['account_user_id' => 'حساب الدخول']);
            $target = User::ofTenant($this->tenants->id())->findOrFail($data['account_user_id']);
            if (! Grants::mayManage($request->user(), $target)) {
                throw ValidationException::withMessages(['account_user_id' => 'هذا الحساب يفتح ما لا تفتحه، فلا تربطه.']);
            }

            return ['mode' => 'link', 'user_id' => $target->id];
        }
        $data = $request->validate([
            'account_email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'account_role_id' => ['required', TenantRule::exists('roles')],
            'account_branch_id' => ['nullable', TenantRule::exists('branches')],
        ], [], ['account_email' => 'البريد الإلكتروني للدخول', 'account_role_id' => 'الدور']);
        if (! Grants::mayAssign($request->user(), Role::findOrFail($data['account_role_id']))) {
            throw ValidationException::withMessages(['account_role_id' => 'لا يمكنك إسناد دور يفتح ما لا تملكه.']);
        }

        return ['mode' => 'new', 'email' => strtolower($data['account_email']), 'role_id' => (int) $data['account_role_id'], 'branch_id' => $data['account_branch_id'] ?? null];
    }

    private function createAccount(Request $request, Employee $employee, array $account): void
    {
        $password = self::temporaryPassword();
        $user = new User(['name' => $employee->name, 'email' => $account['email'], 'phone' => $employee->phone, 'password' => $password]);
        $user->tenant_id = $this->tenants->id();
        $user->role_id = $account['role_id'];
        $user->branch_id = $account['branch_id'];
        $user->save();
        $employee->forceFill(['user_id' => $user->id])->save();
        app(AuditLogger::class)->log('user.created', $user, ['role_id' => $user->role_id, 'employee' => $employee->name]);
        // Shown once on the next page, never stored readable.
        session()->flash('password', $password);
    }

    private function accountOf(Employee $employee): User
    {
        $account = $employee->user_id ? User::ofTenant($this->tenants->id())->find($employee->user_id) : null;
        if (! $account) {
            throw new BusinessRuleException('employee.no_account', 'لا يوجد حساب دخول لهذا الموظف.');
        }

        return $account;
    }

    /** The club always keeps one active owner. */
    private function keepAnOwner(User $account, Role $role, bool $active): void
    {
        if (! $account->isOwner() || ($role->key === SystemRoles::OWNER && $active)) {
            return;
        }
        $owners = User::ofTenant($account->tenant_id)->where('role_id', $account->role_id)->where('is_active', true)->count();
        if ($owners <= 1) {
            throw ValidationException::withMessages(['role_id' => 'يجب أن يبقى مالك واحد نشط على الأقل.']);
        }
    }

    /** Nobody but the owner sets their own salary or bank details. */
    private function guardSelf(Request $request, ?Employee $employee, array $data): void
    {
        $user = $request->user();
        if ($user->isOwner() || ! $employee || $employee->user_id !== $user->id) {
            return;
        }
        foreach (Employee::CONFIDENTIAL as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }
            $now = $employee->{$field};
            $changed = $field === 'basic_salary' ? Money::cmp($now ?? 0, $data[$field]) !== 0 : $now != $data[$field];
            if ($changed) {
                throw new BusinessRuleException('payroll.self', 'لا يمكنك تعديل راتبك أو بياناتك البنكية بنفسك. يتولى ذلك صاحب صلاحية أخرى.', 'basic_salary');
            }
        }
    }

    /** What changed, for the file's history: values for ordinary fields, only the names of salary and bank fields. */
    private function logChanges(AuditLogger $audit, Employee $employee, array $before): void
    {
        $after = $employee->getAttributes();
        $watched = ['name', 'phone', 'email', 'hired_at', 'status', 'job_title_id', 'branch_id', 'trainer_id', 'user_id', 'notes', ...Employee::CONFIDENTIAL];
        $old = [];
        $new = [];
        $hidden = [];
        foreach ($watched as $key) {
            if ((string) ($before[$key] ?? '') === (string) ($after[$key] ?? '')) {
                continue;
            }
            if (in_array($key, Employee::CONFIDENTIAL, true)) {
                $hidden[] = $key;

                continue;
            }
            $old[$key] = $before[$key] ?? null;
            $new[$key] = $after[$key] ?? null;
        }
        if ($old !== [] || $hidden !== []) {
            $audit->log('employee.updated', $employee, ['name' => $employee->name] + ($hidden !== [] ? ['confidential_changed' => $hidden] : []), $old ?: null, $new ?: null);
        }
    }

    private function formProps(Request $request, ?Employee $employee): array
    {
        $user = $request->user();
        $linkedUsers = Employee::whereNotNull('user_id')->when($employee, fn ($q) => $q->whereKeyNot($employee->id))->pluck('user_id');
        $linkedTrainers = Employee::whereNotNull('trainer_id')->when($employee, fn ($q) => $q->whereKeyNot($employee->id))->pluck('trainer_id');
        $confidential = $user->hasPermission('payroll.salaries') || $user->hasPermission('payroll.compensation');

        return [
            'employee' => $employee ? $this->row($employee->load(['jobTitle:id,name', 'branch:id,name', 'user:id,name,email,role_id', 'user.role:id,name', 'trainer:id,name']), $confidential) + ['notes' => $employee->notes, 'trainer_id' => $employee->trainer_id] : null,
            'jobTitles' => JobTitle::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'branches' => app(BranchContext::class)->choices(true),
            'trainers' => Trainer::orderBy('name')->get(['id', 'name'])->map(fn (Trainer $t) => ['id' => $t->id, 'name' => $t->name, 'linked' => $linkedTrainers->contains($t->id)]),
            'statuses' => Employee::STATUSES,
            'access' => $user->hasPermission('employees.access') && ! $employee?->user_id ? $this->accessOptions($user) + [
                'users' => User::ofTenant($this->tenants->id())->with('role')->orderBy('name')->get(['id', 'name', 'email', 'role_id', 'custom_permissions', 'is_active', 'tenant_id'])
                    ->reject(fn (User $u) => $linkedUsers->contains($u->id))
                    ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'role' => $u->role?->name, 'linkable' => Grants::mayManage($user, $u)])->values(),
            ] : null,
            'can' => $this->abilities($user) + ['basic' => $employee ? $user->hasPermission('employees.manage') : $user->hasPermission('employees.create')],
            'confidential' => $user->hasPermission('payroll.salaries'),
        ];
    }

    private function accessOptions(User $actor): array
    {
        $labels = collect(PermissionRegistry::editorGroups())->map(fn ($g, $key) => ['key' => $key, 'label' => $g['label'], 'permissions' => collect($g['permissions'])->map(fn ($label, $perm) => ['key' => $perm, 'label' => $label])->values()])->values();

        return [
            'roles' => Role::orderBy('id')->get()->map(fn (Role $r) => ['id' => $r->id, 'name' => $r->name, 'key' => $r->key, 'assignable' => Grants::mayAssign($actor, $r), 'permissions' => $r->expandedPermissions()]),
            'branches' => Branch::where('is_active', true)->orderByDesc('is_main')->orderBy('name')->get(['id', 'name']),
            'groups' => $labels,
            'grantable' => Grants::grantable($actor),
        ];
    }

    private function abilities(User $u): array
    {
        return [
            'create' => $u->hasPermission('employees.create'),
            'manage' => $u->hasPermission('employees.manage'),
            'access' => $u->hasPermission('employees.access'),
            'salaries' => $u->hasPermission('payroll.salaries'),
            'compensation' => $u->hasPermission('payroll.compensation'),
            'payslips' => $u->hasPermission('payroll.payslips'),
            'attendance' => $u->hasPermission('staff_attendance.view'),
            'attendance_manage' => $u->hasPermission('staff_attendance.manage'),
            'audit' => $u->hasPermission('employees.view'),
        ];
    }

    private function row(Employee $e, bool $confidential): array
    {
        return [
            'id' => $e->id,
            'name' => $e->name,
            'phone' => $e->phone,
            'email' => $e->email,
            'hired_at' => $e->hired_at?->toDateString(),
            'status' => $e->status,
            'job_title_id' => $e->job_title_id,
            'job_title' => $e->jobTitle?->name,
            'branch_id' => $e->branch_id,
            'branch' => $e->branch?->name,
            'user_id' => $e->user_id,
            'user' => $e->user ? ['name' => $e->user->name, 'email' => $e->user->email, 'role' => $e->user->role?->name, 'is_active' => (bool) $e->user->is_active] : null,
            'trainer' => $e->trainer?->name,
            // Confidential: null unless payroll.salaries (or payroll.compensation, which must see what it changes).
            'basic_salary' => $confidential ? $e->basic_salary : null,
            'allowances' => $confidential ? ($e->allowances ?? []) : null,
            'monthly' => $confidential ? Money::add($e->basic_salary, $e->allowancesTotal()) : null,
            'payment_method' => $confidential ? $e->payment_method : null,
            'bank_name' => $confidential ? $e->bank_name : null,
            'iban' => $confidential ? $e->iban : null,
            'account_number' => $confidential ? $e->account_number : null,
        ];
    }

    private static function masked(?string $value): ?string
    {
        $value = preg_replace('/\s+/', '', (string) $value);

        return $value === '' ? null : '•••• '.mb_substr($value, -4);
    }

    private static function temporaryPassword(): string
    {
        return Str::password(12, symbols: false);
    }
}
