<?php

namespace Tests\Feature\Books;

use App\Books\Models\Attachment;
use App\Books\Models\BooksCompany;
use App\Books\Models\Invoice;
use App\Filament\Books\RelationManagers\AttachmentsRelationManager;
use App\Filament\Books\Resources\Invoices\Pages\EditInvoice;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BooksAttachmentTest extends TestCase
{
    use RefreshDatabase;

    private User $boekhouder;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        Storage::fake('local');

        $company = BooksCompany::create(['name' => 'Emeq']);
        config(['books.company_id' => $company->id]);

        Role::firstOrCreate(['name' => 'boekhouder']);
        $this->boekhouder = User::factory()->create();
        $this->boekhouder->assignRole('boekhouder');
        $this->actingAs($this->boekhouder);
    }

    private function invoice(): Invoice
    {
        return Invoice::create(['status' => 'draft', 'date' => now()]);
    }

    private function attachmentWithFile(Invoice $invoice, string $path = 'books/attachments/x.pdf'): Attachment
    {
        Storage::disk('local')->put($path, 'pdf-bytes');

        return $invoice->attachments()->create([
            'original_name' => 'bon.pdf',
            'path' => $path,
        ]);
    }

    public function test_attachment_persists_and_derives_metadata(): void
    {
        $invoice = $this->invoice();

        $attachment = $this->attachmentWithFile($invoice);

        $this->assertSame('local', $attachment->disk);
        $this->assertGreaterThan(0, $attachment->size);
        $this->assertSame($this->boekhouder->id, $attachment->uploaded_by);
        $this->assertSame(Invoice::class, $attachment->attachable_type);
        $this->assertSame($invoice->id, $attachment->attachable_id);
    }

    public function test_deleting_attachment_removes_file(): void
    {
        $invoice = $this->invoice();
        $attachment = $this->attachmentWithFile($invoice);

        Storage::disk('local')->assertExists($attachment->path);

        $attachment->delete();

        Storage::disk('local')->assertMissing('books/attachments/x.pdf');
    }

    public function test_deleting_invoice_cascades_attachments(): void
    {
        $invoice = $this->invoice();
        $this->attachmentWithFile($invoice, 'books/attachments/a.pdf');
        $this->attachmentWithFile($invoice, 'books/attachments/b.pdf');

        $this->assertSame(2, Attachment::query()->count());

        $invoice->delete();

        $this->assertSame(0, Attachment::query()->count());
        Storage::disk('local')->assertMissing('books/attachments/a.pdf');
        Storage::disk('local')->assertMissing('books/attachments/b.pdf');
    }

    public function test_relation_manager_renders_on_edit_invoice(): void
    {
        $invoice = $this->invoice();

        Livewire::test(EditInvoice::class, ['record' => $invoice->id])
            ->assertSeeLivewire(AttachmentsRelationManager::class);

        Livewire::test(AttachmentsRelationManager::class, [
            'ownerRecord' => $invoice,
            'pageClass' => EditInvoice::class,
        ])->assertOk();
    }

    public function test_relation_manager_uploads_file(): void
    {
        $invoice = $this->invoice();

        Livewire::test(AttachmentsRelationManager::class, [
            'ownerRecord' => $invoice,
            'pageClass' => EditInvoice::class,
        ])
            ->callAction(TestAction::make(CreateAction::class)->table(), [
                'path' => UploadedFile::fake()->create('receipt.pdf', 120, 'application/pdf'),
            ])
            ->assertHasNoActionErrors();

        $this->assertSame(1, $invoice->attachments()->count());
        $attachment = $invoice->attachments()->first();
        $this->assertSame(Invoice::class, $attachment->attachable_type);
        $this->assertSame('local', $attachment->disk);
    }
}
