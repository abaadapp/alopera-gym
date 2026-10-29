<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Core\Audit\AuditLogger;
use App\Core\Support\BusinessRuleException;
use App\Core\Support\Money;
use App\Core\Tenancy\BranchContext;
use App\Core\Tenancy\TenantContext;
use App\Core\Tenancy\TenantRule;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\User;
use App\Modules\Gym\Models\Trainer;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\Models\JobTitle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Staff files. Salary, allowances and bank details are confidential: they reach the screen, and are accepted
 * from a form, only for payroll.salaries — and nobody but the owner changes her own.
 */
class EmployeeController extends Controller
{
    public function index(Request $request, TenantContext $tenants): Response
    {
        JobTitle::ensureDefaults();
        $confidential = $request->user()->hasPermission('payroll.salaries');

        $employees = BranchContext::scope(Employee::with(['jobTitle:id,name', 'branch:id,name', 'user:id,name,email,role_id', 'user.role:id,name', 'trainer:id,name']))
            ->when($request->string('q')->toString(), fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'ilike', "%{$s}%")->orWhere('phone', 'ilike', "%{$s}%")->orWhere('email', 'ilike', "%{$s}%")))
            ->when($request->string('status')->toString(), fn ($q, $s) => $q->where('status', $s))
            ->when($request->integer('job_title_id'), fn ($q, $id) => $q->where('job_title_id', $id))
            ->orderByRaw("case status when 'active' then 0 when 'on_leave' then 1 else 2 end")->orderBy('name')
            ->get();

        $linkedUsers = Employee::whereNotNull('user_id')->pluck('user_id');
        $linkedTrainers = Employee::whereNotNull('trainer_id')->pluck('trainer_id');
        $active = $employees->where('status', 'active');

        return Inertia::render('Payroll/Employees', [
            'employees' => $employees->map(fn (Employee $e) => $this->row($e, $confidential)),
            'stats' => [
                'active' => $active->count(),
                'on_leave' => $employees->where('status', 'on_leave')->count(),
                'with_account' => $employees->whereNotNull('user_id')->count(),
                'monthly' => $confidential ? Money::add('0', ...$active->map(fn (Employee $e) => Money::add($e->basic_salary, $e->allowancesTotal()))->all()) : null,
            ],
            'jobTitles' => JobTitle::orderBy('name')->get(['id', 'name']),
            'branches' => Branch::orderByDesc('is_main')->orderBy('name')->get(['id', 'name']),
            'users' => User::ofTenant($tenants->require()->id)->with('role:id,name')->orderBy('name')->get(['id', 'name', 'email', 'role_id'])
                ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'role' => $u->role?->name, 'linked' => $linkedUsers->contains($u->id)]),
            'trainers' => Trainer::orderBy('name')->get(['id', 'name'])->map(fn (Trainer $t) => ['id' => $t->id, 'name' => $t->name, 'linked' => $linkedTrainers->contains($t->id)]),
            'unlinkedTrainers' => Trainer::where('is_active', true)->whereNotIn('id', $linkedTrainers)->count(),
            'statuses' => Employee::STATUSES,
            'confidential' => $confidential,
            'filters' => $request->only('q', 'status', 'job_title_id'),
        ]);
    }

    public function store(Request $request, AuditLogger $audit): RedirectResponse
    {
        $data = $this->validated($request);
        $this->guardSelf($request, null, $data);
        $employee = Employee::create($data);
        $audit->log('employee.created', $employee, ['name' => $employee->name]);

        return back()->with('success', 'أُضيف ملف الموظف.');
    }

    public function update(Request $request, Employee $employee, AuditLogger $audit): RedirectResponse
    {
        $data = $this->validated($request, $employee);
        $this->guardSelf($request, $employee, $data);
        $before = Arr::only($employee->getAttributes(), ['basic_salary', 'status', 'job_title_id']);
        $employee->update($data);
        $audit->log('employee.updated', $employee, ['name' => $employee->name], $before, Arr::only($employee->getAttributes(), array_keys($before)));

        return back()->with('success', 'حُفظ ملف الموظف.');
    }

    /** Trainers get a staff file each — linked, not copied twice — so they can be put on the payroll. */
    public function importTrainers(AuditLogger $audit): RedirectResponse
    {
        $count = DB::transaction(function () {
            // Clubs created before the neutral wording keep their existing «مدربة» title instead of getting a second one.
            $title = JobTitle::firstWhere('name', 'مدرب') ?? JobTitle::firstWhere('name', 'مدربة') ?? JobTitle::create(['name' => 'مدرب']);
            $linkedTrainers = Employee::whereNotNull('trainer_id')->pluck('trainer_id');
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

    private function validated(Request $request, ?Employee $employee = null): array
    {
        $confidential = $request->user()->hasPermission('payroll.salaries');
        $rules = [
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:190'],
            'hired_at' => ['nullable', 'date'],
            'status' => ['required', Rule::in(array_keys(Employee::STATUSES))],
            'job_title_id' => ['nullable', TenantRule::exists('gym_job_titles')],
            'branch_id' => ['nullable', TenantRule::exists('branches')],
            'user_id' => ['nullable', TenantRule::exists('users'), TenantRule::unique('gym_employees', 'user_id')->ignore($employee?->id)],
            'trainer_id' => ['nullable', TenantRule::exists('gym_trainers'), TenantRule::unique('gym_employees', 'trainer_id')->ignore($employee?->id)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
        if ($confidential) {
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

        $data = $request->validate($rules, [], ['user_id' => 'حساب الدخول', 'trainer_id' => 'ملف المدرب', 'basic_salary' => 'الراتب الأساسي']);
        if ($confidential) {
            $data['basic_salary'] = Money::normalize($data['basic_salary']);
            $data['allowances'] = array_values(array_map(fn ($a) => ['name' => $a['name'], 'amount' => Money::normalize($a['amount'])], $data['allowances'] ?? []));
        }

        // Without payroll.salaries the confidential fields are neither read nor written.
        return $confidential ? $data : Arr::except($data, Employee::CONFIDENTIAL);
    }

    /** Nobody but the owner sets her own salary or bank details. */
    private function guardSelf(Request $request, ?Employee $employee, array $data): void
    {
        $user = $request->user();
        $own = ($employee && $employee->user_id === $user->id) || (int) ($data['user_id'] ?? 0) === $user->id;
        if (! $own || $user->isOwner()) {
            return;
        }

        foreach (Employee::CONFIDENTIAL as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }
            $now = $employee?->{$field};
            $changed = $field === 'basic_salary' ? Money::cmp($now ?? 0, $data[$field]) !== 0 : $now != $data[$field];
            if ($changed) {
                throw new BusinessRuleException('payroll.self', 'لا يمكنك تعديل راتبك أو بياناتك البنكية بنفسك. يتولى ذلك صاحب صلاحية أخرى.', 'basic_salary');
            }
        }
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
            'user' => $e->user ? ['name' => $e->user->name, 'email' => $e->user->email, 'role' => $e->user->role?->name] : null,
            'trainer_id' => $e->trainer_id,
            'trainer' => $e->trainer?->name,
            'notes' => $e->notes,
            // Confidential: null unless payroll.salaries.
            'basic_salary' => $confidential ? $e->basic_salary : null,
            'allowances' => $confidential ? ($e->allowances ?? []) : null,
            'monthly' => $confidential ? Money::add($e->basic_salary, $e->allowancesTotal()) : null,
            'payment_method' => $confidential ? $e->payment_method : null,
            'bank_name' => $confidential ? $e->bank_name : null,
            'iban' => $confidential ? $e->iban : null,
            'account_number' => $confidential ? $e->account_number : null,
        ];
    }
}
