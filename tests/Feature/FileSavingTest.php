<?php

namespace Tests\Feature;

use App\Core\Support\Files\FileNotStored;
use App\Models\AuditLog;
use App\Modules\Documents\Models\BusinessDocument;
use App\Modules\Documents\Models\DocumentRenewal;
use App\Modules\Finance\Models\Expense;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\InvoiceAttachment;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Services\InvoiceService;
use App\Modules\Gym\Models\MemberDocument;
use App\Modules\Inventory\Models\Supplier;
use App\Modules\Inventory\Models\SupplierBill;
use App\Modules\Messaging\Services\MediaStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Concerns\BuildsClubs;
use Tests\Support\FailingDisk;
use Tests\TestCase;

/**
 * A file is kept whole or not at all: a disk that refuses a write (full, read-only, unreachable) or keeps only part of
 * it gives an error and no "saved", no record points at a missing or cut file, a save that fails after the file was
 * written leaves no file behind, and a replaced file goes only after its replacement is saved. Files are kept byte for
 * byte (never compressed), shown and downloaded again after the page is opened anew, and only to whom may see them.
 */
class FileSavingTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    /** Every file on the private disk, as [path => sha256]. */
    private function filesOnDisk(): array
    {
        return collect(Storage::disk('local')->allFiles())->mapWithKeys(fn ($p) => [$p => hash('sha256', Storage::disk('local')->get($p))])->all();
    }

    private function pdf(string $name = 'contract.pdf', int $kb = 60): UploadedFile
    {
        // Real bytes, so a cut copy differs from the upload.
        $path = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($path, "%PDF-1.4\n".random_bytes($kb * 1024)."\n%%EOF\n");

        return new UploadedFile($path, $name, 'application/pdf', null, true);
    }

    public function test_a_member_document_on_a_disk_that_refuses_or_cuts_it_is_an_error_and_nothing_is_recorded(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $member = $this->member($club);

        foreach (['refuse', 'short'] as $mode) {
            FailingDisk::install('local', $mode);
            $this->actingAs($owner)->from(route('club.members.show', $member))
                ->post(route('club.members.documents.store', $member), ['type' => 'contract', 'title' => 'العقد', 'file' => $this->pdf()])
                ->assertSessionHasErrors('file')->assertSessionMissing('success');

            $this->asTenant($club, fn () => $this->assertSame(0, MemberDocument::count(), "$mode: no record points at a missing or cut file"));
            $this->assertSame([], $this->filesOnDisk(), "$mode: nothing is left on the disk");
        }
    }

    public function test_a_member_document_is_kept_byte_for_byte_shown_after_reopening_and_downloaded_only_by_its_club(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $member = $this->member($club);
        $file = $this->pdf('هوية.pdf');
        $sha = hash_file('sha256', $file->getRealPath());

        $this->actingAs($owner)->post(route('club.members.documents.store', $member), ['type' => 'id', 'title' => 'الهوية', 'file' => $file])
            ->assertSessionHasNoErrors()->assertSessionHas('success');

        $document = $this->asTenant($club, fn () => MemberDocument::sole());
        $this->assertSame($sha, hash('sha256', Storage::disk('local')->get($document->path)), 'kept as it came, not compressed or changed');
        $this->actingAs($owner)->get(route('club.members.show', $member))->assertOk()
            ->assertInertia(fn ($page) => $page->where('documents.0.title', 'الهوية'));
        $download = $this->actingAs($owner)->get(route('club.documents.download', $document))->assertOk();
        $this->assertSame($sha, hash('sha256', $download->streamedContent()));

        $other = $this->club('Other');
        $this->actingAs($this->owner($other))->get(route('club.documents.download', $document))->assertNotFound();
    }

    public function test_a_member_document_whose_record_fails_leaves_no_file_and_one_whose_removal_fails_keeps_its_file(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $member = $this->member($club);

        // A switch, not flushEventListeners(): that would also drop the listener that sets the club (BelongsToTenant).
        $fail = true;
        MemberDocument::creating(function () use (&$fail) {
            if ($fail) {
                throw new RuntimeException('database down');
            }
        });
        MemberDocument::deleting(function () use (&$fail) {
            if ($fail) {
                throw new RuntimeException('database down');
            }
        });
        $this->withoutExceptionHandling();
        try {
            $this->actingAs($owner)->post(route('club.members.documents.store', $member), ['type' => 'contract', 'title' => 'العقد', 'file' => $this->pdf()]);
            $this->fail('the failure reaches the person');
        } catch (RuntimeException) {
        }
        $this->assertSame([], $this->filesOnDisk(), 'no file is left without its record');

        $fail = false;
        $this->actingAs($owner)->post(route('club.members.documents.store', $member), ['type' => 'contract', 'title' => 'العقد', 'file' => $this->pdf()]);
        $document = $this->asTenant($club, fn () => MemberDocument::sole());
        $fail = true;
        try {
            $this->actingAs($owner)->delete(route('club.documents.destroy', $document));
        } catch (RuntimeException) {
        }
        Storage::disk('local')->assertExists($document->path);
        $fail = false;

        $this->actingAs($owner)->delete(route('club.documents.destroy', $document));
        Storage::disk('local')->assertMissing($document->path);
        $this->asTenant($club, fn () => $this->assertSame(0, MemberDocument::count()));
    }

    public function test_a_business_document_keeps_its_old_file_until_the_new_one_is_saved(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $form = ['name' => 'السجل التجاري', 'issue_date' => today()->subYear()->toDateString(), 'expiry_date' => today()->addDays(10)->toDateString(), 'renewal_months' => 12];

        $disk = FailingDisk::install('local', 'refuse');
        $this->actingAs($owner)->post(route('club.business-documents.store'), $form + ['file' => $this->pdf()])->assertSessionHasErrors('file')->assertSessionMissing('success');
        $this->asTenant($club, fn () => $this->assertSame(0, BusinessDocument::count(), 'a document without its file is not added'));

        $disk->mode('ok');
        $this->actingAs($owner)->post(route('club.business-documents.store'), $form + ['file' => $this->pdf()])->assertSessionHasNoErrors();
        $document = $this->asTenant($club, fn () => BusinessDocument::sole());
        $old = $document->file_path;
        $oldSha = hash('sha256', Storage::disk('local')->get($old));

        // A correction whose new copy the disk refuses: the old copy, its name and the record stay.
        $disk->mode('short');
        $this->actingAs($owner)->post(route('club.business-documents.update', $document), $form + ['name' => 'اسم جديد', 'file' => $this->pdf('new.pdf')])->assertSessionHasErrors('file');
        $document->refresh();
        $this->assertSame([$old, 'السجل التجاري'], [$document->file_path, $document->name]);
        $this->assertSame($oldSha, hash('sha256', Storage::disk('local')->get($old)));
        $this->assertSame([$old], array_keys($this->filesOnDisk()), 'the cut copy is not left behind');

        // A renewal whose copy the disk refuses: nothing renewed, nothing archived.
        $disk->mode('refuse');
        $this->actingAs($owner)->post(route('club.business-documents.renew', $document), ['expiry_date' => today()->addYear()->toDateString(), 'file' => $this->pdf()])->assertSessionHasErrors('file');
        $this->assertSame(today()->addDays(10)->toDateString(), $document->fresh()->expiry_date->toDateString());
        $this->asTenant($club, fn () => $this->assertSame(0, DocumentRenewal::count()));

        // A correction whose record fails after the new copy was written: the new copy goes, the old one stays.
        $disk->mode('ok');
        $fail = true;
        BusinessDocument::saving(function () use (&$fail) {
            if ($fail) {
                throw new RuntimeException('database down');
            }
        });
        try {
            $this->withoutExceptionHandling()->actingAs($owner)->post(route('club.business-documents.update', $document), $form + ['file' => $this->pdf('new.pdf')]);
        } catch (RuntimeException) {
        }
        $fail = false;
        $this->withExceptionHandling();
        $this->assertSame([$old], array_keys($this->filesOnDisk()));

        // The correction that works: the new copy is downloaded, the old one is gone.
        $new = $this->pdf('new.pdf');
        $this->actingAs($owner)->post(route('club.business-documents.update', $document), $form + ['file' => $new])->assertSessionHasNoErrors();
        $document->refresh();
        Storage::disk('local')->assertMissing($old);
        $this->assertSame(hash_file('sha256', $new->getRealPath()), hash('sha256', $this->actingAs($owner)->get(route('club.business-documents.download', $document))->assertOk()->streamedContent()));
    }

    public function test_a_payment_proof_the_disk_refuses_records_no_payment_and_a_refused_payment_leaves_no_proof(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $this->fundAccounts($club); // the accounts exist before a request fails (they are cached for the test's later requests)
        $invoice = $this->asTenant($club, fn () => app(InvoiceService::class)->issue(null, [['description' => 'خدمة', 'unit_price' => '40']], ['type' => 'other'], 'عميل'));
        $pay = fn (array $extra) => $this->actingAs($owner)->post(route('club.payments.store', $invoice), ['amount' => '40', 'method' => 'bank_transfer', 'reference' => 'TR-1', 'client_key' => (string) Str::uuid()] + $extra);

        $disk = FailingDisk::install('local', 'refuse');
        $pay(['proof' => $this->pdf('proof.pdf')])->assertSessionHasErrors('proof')->assertSessionMissing('success');
        $this->asTenant($club, fn () => $this->assertSame(0, Payment::count()));

        // The payment's own record fails after its proof was written: the proof is not kept.
        $disk->mode('ok');
        $fail = true;
        Payment::creating(function () use (&$fail) {
            if ($fail) {
                throw new RuntimeException('database down');
            }
        });
        $pay(['proof' => $this->pdf('proof.pdf')])->assertServerError();
        $fail = false;
        $this->asTenant($club, fn () => $this->assertSame(0, Payment::count()));
        $this->assertSame([], $this->filesOnDisk(), 'no proof is left without its payment');

        $proof = $this->pdf('proof.pdf');
        $pay(['proof' => $proof])->assertSessionHasNoErrors();
        $payment = $this->asTenant($club, fn () => Payment::sole());
        $this->assertSame(hash_file('sha256', $proof->getRealPath()), hash('sha256', $this->actingAs($owner)->get(route('club.payments.proof', $payment))->assertOk()->streamedContent()));
    }

    public function test_invoice_attachments_the_disk_refuses_issue_no_invoice(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $form = ['customer_name' => 'زبون', 'lines' => [['description' => 'تأجير قاعة', 'quantity' => 1, 'unit_price' => 20]], 'client_key' => (string) Str::uuid()];

        FailingDisk::install('local', 'short');
        $this->actingAs($owner)->post(route('club.invoices.store'), $form + ['issue' => 1, 'attachments' => [$this->pdf('a.pdf'), $this->pdf('b.pdf')]])
            ->assertSessionHasErrors('attachments')->assertSessionMissing('success');
        $this->asTenant($club, fn () => $this->assertSame([0, 0], [Invoice::count(), InvoiceAttachment::count()]));
        $this->assertSame([], $this->filesOnDisk(), 'the first attachment, written whole, is removed with the failed invoice');
    }

    public function test_invoice_attachments_and_the_payment_proof_of_an_issued_invoice_are_kept_shown_and_downloaded(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $this->fundAccounts($club);
        [$attachment, $proof] = [$this->pdf('عقد-القاعة.pdf'), $this->pdf('تحويل.pdf')];

        $this->actingAs($owner)->post(route('club.invoices.store'), [
            'customer_name' => 'زبون', 'lines' => [['description' => 'تأجير قاعة', 'quantity' => 1, 'unit_price' => 20]], 'client_key' => (string) Str::uuid(),
            'issue' => 1, 'attachments' => [$attachment], 'payment_method' => 'bank_transfer', 'paid_amount' => '20', 'payment_reference' => 'TR-9', 'payment_proof' => $proof,
        ])->assertSessionHasNoErrors()->assertSessionHas('success');
        [$invoice, $payment] = $this->asTenant($club, fn () => [Invoice::sole(), Payment::sole()]);

        // Opened again: the invoice lists its attachment, the payments list says the transfer has its proof; both download as they came.
        $url = '';
        $this->actingAs($owner)->get(route('club.invoices.show', $invoice))->assertOk()->assertInertia(function ($page) use (&$url) {
            $this->assertSame('عقد-القاعة.pdf', $page->toArray()['props']['invoice']['attachments'][0]['name']);
            $url = $page->toArray()['props']['invoice']['attachments'][0]['url'];
        });
        $this->assertSame(hash_file('sha256', $attachment->getRealPath()), hash('sha256', $this->actingAs($owner)->get($url)->assertOk()->streamedContent()));
        $this->actingAs($owner)->get(route('club.payments.index'))->assertOk()->assertInertia(fn ($page) => $page->where('payments.data.0.has_proof', true));
        $this->assertSame(hash_file('sha256', $proof->getRealPath()), hash('sha256', $this->actingAs($owner)->get(route('club.payments.proof', $payment))->assertOk()->streamedContent()));

        // Never to another club.
        $stranger = $this->owner($this->club('Other'));
        $this->actingAs($stranger)->get($url)->assertNotFound();
        $this->actingAs($stranger)->get(route('club.payments.proof', $payment))->assertNotFound();
    }

    public function test_a_payment_proof_on_the_invoice_form_the_disk_refuses_issues_nothing(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $this->fundAccounts($club);

        FailingDisk::install('local', 'refuse');
        $this->actingAs($owner)->post(route('club.invoices.store'), [
            'customer_name' => 'زبون', 'lines' => [['description' => 'تأجير قاعة', 'quantity' => 1, 'unit_price' => 20]], 'client_key' => (string) Str::uuid(),
            'issue' => 1, 'payment_method' => 'bank_transfer', 'paid_amount' => '20', 'payment_reference' => 'TR-9', 'payment_proof' => $this->pdf('تحويل.pdf'),
        ])->assertSessionHasErrors('payment_proof')->assertSessionMissing('success');
        $this->asTenant($club, fn () => $this->assertSame([0, 0], [Invoice::count(), Payment::count()]));
        $this->assertSame([], $this->filesOnDisk());
    }

    public function test_an_expense_attachment_replaced_keeps_the_old_receipt_and_downloads_the_new_one(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $this->fundAccounts($club);
        $form = ['description' => 'ماء', 'amount' => '3', 'expense_date' => today()->toDateString(), 'payment_method' => 'cash'];

        $disk = FailingDisk::install('local', 'refuse');
        $this->actingAs($owner)->post(route('club.expenses.store'), $form + ['attachment' => $this->pdf(), 'client_key' => (string) Str::uuid()])
            ->assertSessionHasErrors('attachment')->assertSessionMissing('success');
        $this->asTenant($club, fn () => $this->assertSame(0, Expense::count()));

        $disk->mode('ok');
        $this->actingAs($owner)->post(route('club.expenses.store'), $form + ['attachment' => $this->pdf(), 'client_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $expense = $this->asTenant($club, fn () => Expense::sole());
        $old = $expense->attachment_path;

        $disk->mode('short');
        $this->actingAs($owner)->put(route('club.expenses.update', $expense), $form + ['attachment' => $this->pdf()])->assertSessionHasErrors('attachment');
        $this->assertSame($old, $expense->fresh()->attachment_path);
        $this->assertSame([$old], array_keys($this->filesOnDisk()));

        $disk->mode('ok');
        $receipt = $this->pdf('receipt-2.pdf');
        $this->actingAs($owner)->put(route('club.expenses.update', $expense), $form + ['attachment' => $receipt])->assertSessionHasNoErrors()->assertSessionHas('success');
        $new = $expense->fresh()->attachment_path;
        $this->assertNotSame($old, $new);
        // A financial record: the receipt it replaces stays on the disk as before, and the audit trail keeps its path.
        $this->assertEqualsCanonicalizing([$old, $new], array_keys($this->filesOnDisk()));
        $this->asTenant($club, fn () => $this->assertTrue(AuditLog::where('subject_id', $expense->id)->where('old_values', 'like', '%'.basename($old).'%')->exists()));
        $download = $this->actingAs($owner)->get(route('club.expenses.attachment', $expense))->assertOk();
        $this->assertSame(hash_file('sha256', $receipt->getRealPath()), hash('sha256', $download->streamedContent()));
    }

    public function test_a_supplier_bill_attachment_the_disk_refuses_records_no_bill(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $supplier = $this->asTenant($club, fn () => Supplier::create(['name' => 'مورد']));

        FailingDisk::install('local', 'refuse');
        $this->actingAs($owner)->post(route('club.purchases.bills.store'), ['supplier_id' => $supplier->id, 'reference' => 'B-1', 'issue_date' => today()->toDateString(), 'subtotal' => '5', 'attachment' => $this->pdf()])
            ->assertSessionHasErrors('attachment')->assertSessionMissing('success');
        $this->asTenant($club, fn () => $this->assertSame(0, SupplierBill::count()));
        $this->assertSame([], $this->filesOnDisk());
    }

    public function test_a_chat_file_the_disk_refuses_or_cuts_is_never_given_a_path(): void
    {
        $store = app(MediaStore::class);
        foreach (['refuse', 'short'] as $mode) {
            FailingDisk::install('local', $mode);
            foreach ([fn () => $store->storeBytes(random_bytes(4096), 'application/pdf', 7), fn () => $store->storeUpload($this->pdf(), 7)] as $write) {
                try {
                    $write();
                    $this->fail("$mode: a path was given for a file that is not there");
                } catch (FileNotStored) {
                }
            }
            $this->assertSame([], $this->filesOnDisk());
        }
        FailingDisk::install('local', 'ok');
        $bytes = random_bytes(4096);
        $this->assertSame($bytes, Storage::disk('local')->get($store->storeBytes($bytes, 'application/pdf', 7)));
    }
}
