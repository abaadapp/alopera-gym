<?php

namespace App\Modules\Documents\Services;

use App\Core\Audit\AuditLogger;
use App\Core\Support\BusinessRuleException;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Documents\Models\BusinessDocument;
use App\Modules\Documents\Models\DocumentAlert;
use App\Modules\Documents\Models\DocumentRenewal;
use App\Modules\Messaging\Services\WhatsAppService;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * The business's documents: files on the private disk, renewals that archive the replaced version, and expiry
 * alerts raised once per threshold — in the panel always, over WhatsApp only when the club switched it on and
 * has an approved template.
 */
final class DocumentService
{
    public const DEFAULT_ALERT_DAYS = [60, 30, 15, 7, 1];

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly AuditLogger $audit,
        private readonly WhatsAppService $whatsapp,
    ) {}

    /** @return array{alert_days: list<int>, whatsapp: bool} */
    public static function settings(Tenant $tenant): array
    {
        $stored = (array) $tenant->setting('documents', []);
        $days = collect($stored['alert_days'] ?? self::DEFAULT_ALERT_DAYS)->map(fn ($d) => (int) $d)->filter(fn ($d) => $d >= 1 && $d <= 365)->unique()->sortDesc()->values()->all();

        return ['alert_days' => $days ?: self::DEFAULT_ALERT_DAYS, 'whatsapp' => (bool) ($stored['whatsapp'] ?? false)];
    }

    /** The earliest alert, in days: a document inside it counts as "expiring". */
    public static function soonDays(Tenant $tenant): int
    {
        return max(self::settings($tenant)['alert_days']);
    }

    /** @param array<string, mixed> $data */
    public function create(array $data, ?UploadedFile $file): BusinessDocument
    {
        return DB::transaction(function () use ($data, $file) {
            $document = new BusinessDocument($data);
            $document->created_by = Auth::guard('web')->id();
            $document->updated_by = Auth::guard('web')->id();
            $document->save();
            if ($file) {
                $this->attach($document, $file);
                $document->save();
            }

            return $document;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(BusinessDocument $document, array $data, ?UploadedFile $file): BusinessDocument
    {
        return DB::transaction(function () use ($document, $data, $file) {
            $previousExpiry = $document->expiry_date?->toDateString();
            $document->fill($data);
            $document->updated_by = Auth::guard('web')->id();
            if ($file) {
                // A correction of the current file (not a renewal): the old one is not kept.
                $old = $document->file_path;
                $this->attach($document, $file);
                if ($old && $old !== $document->file_path && ! $this->pathInArchive($document, $old)) {
                    DB::afterCommit(fn () => Storage::disk('local')->delete($old));
                }
            }
            $document->save();
            if ($previousExpiry !== $document->expiry_date?->toDateString()) {
                $this->resolveAlerts($document);
            }

            return $document;
        });
    }

    /**
     * Renew: the new expiry date (and usually the new copy) replaces the current one; the replaced version goes
     * to the archive with who renewed and when; alerts of the old expiry are closed and the next ones follow
     * the new date by themselves.
     *
     * @param  array{expiry_date: string, issue_date?: string|null, reference_number?: string|null, notes?: string|null}  $data
     */
    public function renew(BusinessDocument $document, array $data, ?UploadedFile $file): DocumentRenewal
    {
        return DB::transaction(function () use ($document, $data, $file) {
            $document = BusinessDocument::whereKey($document->id)->lockForUpdate()->firstOrFail();
            $newExpiry = CarbonImmutable::parse($data['expiry_date']);
            if ($document->expiry_date && $newExpiry->lessThanOrEqualTo($document->expiry_date)) {
                throw new BusinessRuleException('document.renew_date', 'تاريخ الانتهاء الجديد يجب أن يكون بعد تاريخ الانتهاء الحالي ('.$document->expiry_date->toDateString().').', 'expiry_date');
            }
            if (! empty($data['issue_date']) && CarbonImmutable::parse($data['issue_date'])->greaterThan($newExpiry)) {
                throw new BusinessRuleException('document.issue_date', 'تاريخ الإصدار بعد تاريخ الانتهاء.', 'issue_date');
            }

            $renewal = DocumentRenewal::create([
                'document_id' => $document->id,
                'previous_issue_date' => $document->issue_date,
                'previous_expiry_date' => $document->expiry_date,
                'previous_reference' => $document->reference_number,
                'previous_file_path' => $document->file_path,
                'previous_file_name' => $document->file_name,
                'previous_file_mime' => $document->file_mime,
                'previous_file_size' => $document->file_size,
                'new_issue_date' => $data['issue_date'] ?? null,
                'new_expiry_date' => $newExpiry,
                'new_reference' => $data['reference_number'] ?? null,
                'notes' => $data['notes'] ?? null,
                'renewed_by' => Auth::guard('web')->id(),
            ]);

            $document->forceFill([
                'issue_date' => $data['issue_date'] ?? $document->issue_date,
                'expiry_date' => $newExpiry,
                'reference_number' => ($data['reference_number'] ?? null) ?: $document->reference_number,
                'status' => 'active',
                'updated_by' => Auth::guard('web')->id(),
            ]);
            if ($file) {
                $this->attach($document, $file); // the previous file stays on disk, now in the archive
            }
            $document->save();
            $this->resolveAlerts($document);
            $this->audit->log('document.renewed', $document, ['from' => $renewal->previous_expiry_date?->toDateString(), 'to' => $newExpiry->toDateString()]);

            return $renewal;
        });
    }

    public function delete(BusinessDocument $document): void
    {
        $document->delete(); // soft: the record and its archive stay for the audit trail
        $this->audit->log('document.deleted', $document, ['name' => $document->name]);
    }

    /**
     * Raise the alerts due today, once each: the smallest threshold the document has reached (or 0 once expired).
     * Returns how many new alerts were raised.
     */
    public function raiseAlerts(?CarbonImmutable $today = null): int
    {
        $tenant = $this->tenants->require();
        $settings = self::settings($tenant);
        $today ??= CarbonImmutable::today();
        $horizon = $today->addDays(max($settings['alert_days']));
        $raised = 0;

        BusinessDocument::where('status', 'active')->whereNotNull('expiry_date')->where('expiry_date', '<=', $horizon)
            ->with('responsible.role')->orderBy('id')
            ->each(function (BusinessDocument $document) use ($settings, $today, &$raised) {
                $left = $document->daysLeft($today);
                $threshold = $left < 0 ? 0 : collect($settings['alert_days'])->filter(fn ($d) => $left <= $d)->min();
                if ($threshold === null) {
                    return;
                }
                $alert = DocumentAlert::firstOrCreate(
                    ['document_id' => $document->id, 'expiry_date' => $document->expiry_date->toDateString(), 'threshold_days' => $threshold],
                    ['days_left' => $left],
                );
                if (! $alert->wasRecentlyCreated) {
                    return;
                }
                $raised++;
                if ($settings['whatsapp']) {
                    $alert->forceFill(['whatsapp_status' => $this->sendWhatsApp($document, $alert)])->save();
                }
            });

        return $raised;
    }

    public function acknowledge(DocumentAlert $alert): void
    {
        $alert->forceFill(['acknowledged_at' => now(), 'acknowledged_by' => Auth::guard('web')->id()])->save();
    }

    /** queued | skipped:<reason> — never a promise: only an enabled integration, an approved template and an authorised recipient send. */
    private function sendWhatsApp(BusinessDocument $document, DocumentAlert $alert): string
    {
        $user = $document->responsible;
        if (! $user instanceof User) {
            return 'skipped:no_responsible';
        }
        // Only to someone still allowed to see this document: an active account with access to documents (and, for a
        // confidential one, the confidential permission or being its responsible — which they are).
        if (! $user->is_active || ! $user->can('documents.view') || ! $document->isVisibleTo($user)) {
            return 'skipped:not_authorized';
        }
        $message = $this->whatsapp->queueStaffTemplate($user, 'document_expiry', [
            'user_name' => $user->name,
            'document_name' => $document->name,
            'expiry_date' => $document->expiry_date->toDateString(),
            'days_left' => (string) max(0, $alert->days_left),
        ], $document, 'document-alert:'.$alert->id);

        return $message === null ? 'skipped:whatsapp_off' : ($message->error_code ? 'skipped:'.$message->error_code : 'queued');
    }

    /** Alerts about an expiry date that no longer applies are closed. */
    private function resolveAlerts(BusinessDocument $document): void
    {
        DocumentAlert::where('document_id', $document->id)->whereNull('acknowledged_at')
            ->where(fn ($q) => $document->expiry_date ? $q->where('expiry_date', '!=', $document->expiry_date->toDateString()) : $q)
            ->update(['acknowledged_at' => now(), 'acknowledged_by' => Auth::guard('web')->id()]);
    }

    private function attach(BusinessDocument $document, UploadedFile $file): void
    {
        $path = $file->store('tenants/'.$this->tenants->require()->id.'/documents/'.$document->id, 'local');
        $document->forceFill([
            'file_path' => $path,
            'file_name' => mb_substr($file->getClientOriginalName(), 0, 200),
            'file_mime' => $file->getMimeType(),
            'file_size' => $file->getSize(),
        ]);
    }

    private function pathInArchive(BusinessDocument $document, string $path): bool
    {
        return DocumentRenewal::where('document_id', $document->id)->where('previous_file_path', $path)->exists();
    }
}
