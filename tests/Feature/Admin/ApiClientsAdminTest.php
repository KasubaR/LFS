<?php

namespace Tests\Feature\Admin;

use App\Enums\ApiScope;
use App\Models\ApiClient;
use App\Services\ApiClientService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsAdmin;
use Tests\TestCase;

class ApiClientsAdminTest extends TestCase
{
    use ActsAsAdmin, RefreshDatabase;

    public function test_index_requires_admin_session(): void
    {
        $this->get('/admin/api-clients')->assertRedirect($this->adminLoginPath());
    }

    public function test_admin_can_view_the_key_list(): void
    {
        $this->actingAsAdmin()->get('/admin/api-clients')
            ->assertOk()
            ->assertSee('API Keys');
    }

    public function test_admin_can_view_the_issue_form(): void
    {
        $this->actingAsAdmin()->get('/admin/api-clients/create')
            ->assertOk()
            ->assertSee('Issue API Key');
    }

    public function test_issuing_a_key_shows_the_secret_exactly_once(): void
    {
        $response = $this->actingAsAdmin()->post('/admin/api-clients/create', [
            'name' => 'Lusaka Marathon 2026',
            'scopes' => [ApiScope::MembersVerify],
            'rate_limit_per_minute' => 30,
        ]);

        $response->assertRedirect('/admin/api-clients');
        $response->assertSessionHas('new_api_token');

        $client = ApiClient::query()->where('name', 'Lusaka Marathon 2026')->first();
        $this->assertNotNull($client);
        $this->assertSame([ApiScope::MembersVerify], $client->scopes);
        $this->assertSame(30, $client->rate_limit_per_minute);

        $token = session('new_api_token');
        $this->assertStringStartsWith($client->key_id.'.', $token);

        // Only the hash is persisted; the secret is not recoverable.
        $this->assertNotSame($token, $client->key_hash);
        $this->assertSame(64, strlen($client->key_hash));

        // Shown on the landing page immediately after issue...
        $this->actingAsAdmin()->get('/admin/api-clients')->assertOk()->assertSee($token);

        // ...then the flash is consumed and it is never rendered again.
        $this->actingAsAdmin()->get('/admin/api-clients')->assertOk()->assertDontSee($token);
    }

    public function test_issuing_a_key_requires_at_least_one_scope(): void
    {
        $this->actingAsAdmin()->post('/admin/api-clients/create', [
            'name' => 'No Scopes Event',
        ])->assertSessionHasErrors('scopes');

        $this->assertSame(0, ApiClient::query()->count());
    }

    public function test_admin_can_revoke_and_restore_a_key(): void
    {
        $client = app(ApiClientService::class)->create([
            'name' => 'Kafue Trail Run',
            'scopes' => [ApiScope::MembersVerify],
        ])['client'];

        $this->actingAsAdmin()->post('/admin/api-clients/'.$client->id.'/revoke')
            ->assertRedirect('/admin/api-clients');
        $this->assertNotNull($client->fresh()->revoked_at);

        $this->actingAsAdmin()->post('/admin/api-clients/'.$client->id.'/restore')
            ->assertRedirect('/admin/api-clients');
        $this->assertNull($client->fresh()->revoked_at);
    }

    public function test_rotating_a_key_invalidates_the_previous_secret(): void
    {
        $issued = app(ApiClientService::class)->create([
            'name' => 'Livingstone Half',
            'scopes' => [ApiScope::MembersVerify],
        ]);

        $service = app(ApiClientService::class);
        $this->assertNotNull($service->resolveToken($issued['plainToken']));

        $this->actingAsAdmin()->post('/admin/api-clients/'.$issued['client']->id.'/rotate')
            ->assertRedirect('/admin/api-clients');

        $newToken = session('new_api_token');

        $this->assertNull($service->resolveToken($issued['plainToken']));
        $this->assertNotNull($service->resolveToken($newToken));
    }
}
