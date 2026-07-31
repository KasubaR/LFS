<?php

namespace Tests\Feature\Auth;

use App\Enums\DocumentCategory;
use App\Enums\MembershipStatus;
use App\Enums\TShirtSize;
use App\Models\Document;
use App\Models\Membership;
use App\Models\MembershipPlan;
use App\Models\Satellite;
use App\Models\User;
use App\Support\Uuid;
use Database\Seeders\MembershipPlanSeeder;
use Database\Seeders\SatelliteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class DocumentLibraryMemberTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SatelliteSeeder::class);
        $this->seed(MembershipPlanSeeder::class);
    }

    protected function tearDown(): void
    {
        $dir = public_path('files/documents');
        if (is_dir($dir)) {
            foreach (glob($dir.DIRECTORY_SEPARATOR.'doc_*') ?: [] as $file) {
                @unlink($file);
            }
        }

        parent::tearDown();
    }

    public function test_guest_cannot_open_documents(): void
    {
        $this->get('/account/documents')->assertRedirect('/login');
    }

    public function test_paid_member_sees_published_documents_grouped(): void
    {
        $user = $this->paidMember();
        $doc = $this->seedDocument([
            'title' => 'Club Constitution',
            'category' => DocumentCategory::Constitution,
            'is_published' => true,
        ]);
        $this->seedDocument([
            'title' => 'Hidden Draft',
            'category' => DocumentCategory::Policies,
            'is_published' => false,
        ]);

        $response = $this->actingAs($user)->get('/account/documents');

        $response->assertOk();
        $response->assertSee('Document Library', false);
        $response->assertSee('Club Constitution', false);
        $response->assertSee('Constitution', false);
        $response->assertSee(route('account.documents.download', $doc->id), false);
        $response->assertDontSee('Hidden Draft', false);
    }

    public function test_unpaid_member_sees_locked_state(): void
    {
        $user = $this->unpaidMember();
        $this->seedDocument([
            'title' => 'Club Constitution',
            'category' => DocumentCategory::Constitution,
        ]);

        $response = $this->actingAs($user)->get('/account/documents');

        $response->assertOk();
        $response->assertSee('Documents unlock after payment', false);
        $response->assertDontSee('Club Constitution', false);
    }

    public function test_paid_member_can_download_published_document(): void
    {
        $user = $this->paidMember();
        $doc = $this->seedDocument([
            'title' => 'Training Guide',
            'category' => DocumentCategory::TrainingMaterial,
            'original_filename' => 'training-guide.pdf',
        ]);

        $response = $this->actingAs($user)->get('/account/documents/'.$doc->id.'/download');

        $response->assertOk();
        $response->assertHeader('content-disposition');
        $this->assertStringContainsString('training-guide.pdf', (string) $response->headers->get('content-disposition'));
    }

    public function test_unpublished_document_cannot_be_downloaded(): void
    {
        $user = $this->paidMember();
        $doc = $this->seedDocument([
            'is_published' => false,
            'title' => 'Secret',
        ]);

        $this->actingAs($user)
            ->get('/account/documents/'.$doc->id.'/download')
            ->assertNotFound();
    }

    public function test_unpaid_member_cannot_download(): void
    {
        $user = $this->unpaidMember();
        $doc = $this->seedDocument();

        $this->actingAs($user)
            ->get('/account/documents/'.$doc->id.'/download')
            ->assertNotFound();
    }

    private function paidMember(): User
    {
        return $this->memberWithStatus(MembershipStatus::Active, 'LFS-DOC-001');
    }

    private function unpaidMember(): User
    {
        return $this->memberWithStatus(MembershipStatus::PendingPayment, null);
    }

    private function memberWithStatus(string $status, ?string $membershipNumber): User
    {
        $user = User::factory()->create([
            'phone' => '+260971111111',
            'gender' => 'male',
            'nationality' => 'Zambian',
            'town' => 'Lusaka',
            't_shirt_size' => TShirtSize::M,
            'satellite_id' => Satellite::query()->first()->id,
        ]);

        $plan = MembershipPlan::query()->first();

        Membership::query()->create([
            'id' => Uuid::v4(),
            'user_id' => $user->id,
            'membership_number' => $membershipNumber,
            'status' => $status,
            'current_plan_id' => $plan->id,
            'approval_status' => $membershipNumber ? 'approved' : 'pending',
            'start_date' => $membershipNumber ? now()->subMonth()->toDateString() : null,
            'expiry_date' => $membershipNumber ? now()->addMonths(5)->toDateString() : null,
        ]);

        return $user;
    }

    private function seedDocument(array $overrides = []): Document
    {
        $dir = public_path('files/documents');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $filename = 'doc_'.uniqid('', true).'.pdf';
        $full = $dir.DIRECTORY_SEPARATOR.$filename;
        File::put($full, '%PDF-1.4 test document');

        return Document::query()->create(array_merge([
            'id' => Uuid::v4(),
            'title' => 'Sample Doc',
            'category' => DocumentCategory::Forms,
            'description' => null,
            'file_path' => '/files/documents/'.$filename,
            'original_filename' => 'sample.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => filesize($full) ?: 20,
            'is_published' => true,
            'sort_order' => 0,
            'published_at' => now()->subHour(),
        ], $overrides));
    }
}
