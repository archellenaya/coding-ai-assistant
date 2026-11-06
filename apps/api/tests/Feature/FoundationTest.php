<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeader('Origin', 'http://localhost');
    }

    private function registerUser(): string
    {
        $this->postJson('/api/v1/register', ['name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'long-password-123', 'password_confirmation' => 'long-password-123'])->assertCreated()->assertJsonMissingPath('data.password');

        return Organization::firstOrFail()->id;
    }

    public function test_registration_login_logout_and_validation(): void
    {
        $this->getJson('/api/v1/user')->assertUnauthorized();
        $this->postJson('/api/v1/register', ['name' => 'Bad', 'email' => 'bad', 'password' => 'short'])->assertUnprocessable();
        $this->registerUser();
        $this->getJson('/api/v1/user')->assertOk()->assertJsonPath('data.email', 'owner@example.com');
        $this->postJson('/api/v1/logout')->assertNoContent();
        // Clear the guard cache to emulate a new HTTP request in this test process.
        auth()->forgetGuards();
        $this->getJson('/api/v1/user')->assertUnauthorized();
        $this->postJson('/api/v1/login', ['email' => 'owner@example.com', 'password' => 'wrong'])->assertUnprocessable();
        $this->postJson('/api/v1/login', ['email' => 'owner@example.com', 'password' => 'long-password-123'])->assertOk()->assertJsonPath('data.email', 'owner@example.com');
    }

    public function test_project_lifecycle_persists_and_cascades_memberships(): void
    {
        $org = $this->registerUser();
        $id = $this->postJson('/api/v1/projects', ['organization_id' => $org, 'name' => 'Example'])->assertCreated()->assertJsonPath('data.cloud_processing_allowed', false)->json('data.id');
        $this->assertDatabaseHas('projects', ['id' => $id, 'name' => 'Example']);
        $this->getJson('/api/v1/projects')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/projects/'.$id)->assertOk();
        $this->patchJson('/api/v1/projects/'.$id, ['name' => 'Renamed', 'archived' => true, 'cloud_processing_allowed' => true])->assertOk()->assertJsonPath('data.name', 'Renamed')->assertJsonPath('data.cloud_processing_allowed', true);
        $this->assertNotNull(Project::findOrFail($id)->archived_at);
        $this->patchJson('/api/v1/projects/'.$id, ['archived' => false])->assertOk()->assertJsonPath('data.archived_at', null);
        $this->deleteJson('/api/v1/projects/'.$id)->assertNoContent();
        $this->assertDatabaseMissing('project_members', ['project_id' => $id]);
        $this->assertDatabaseHas('audit_events', ['project_id' => $id, 'action' => 'DELETE api/v1/projects/{project}']);
    }

    public function test_cross_tenant_access_and_assignment_are_forbidden(): void
    {
        $org = $this->registerUser();
        $id = $this->postJson('/api/v1/projects', ['organization_id' => $org, 'name' => 'Private'])->json('data.id');
        $this->app['session']->flush();
        auth()->forgetGuards();
        $this->actingAs(User::factory()->create(), 'web');
        $this->getJson('/api/v1/projects')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/organizations')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/projects/'.$id)->assertForbidden();
        $this->patchJson('/api/v1/projects/'.$id, ['name' => 'Stolen'])->assertForbidden();
        $this->deleteJson('/api/v1/projects/'.$id)->assertForbidden();
        $this->postJson('/api/v1/projects', ['organization_id' => $org, 'name' => 'Injected'])->assertForbidden();
    }

    public function test_organization_members_need_project_membership_and_members_cannot_write(): void
    {
        $org = $this->registerUser();
        $id = $this->postJson('/api/v1/projects', ['organization_id' => $org, 'name' => 'Private'])->json('data.id');
        $member = User::factory()->create();
        DB::table('organization_members')->insert(['organization_id' => $org, 'user_id' => $member->id, 'role' => 'member']);
        $this->app['session']->flush();
        auth()->forgetGuards();
        $this->actingAs($member, 'web')->getJson('/api/v1/projects/'.$id)->assertForbidden();
        DB::table('project_members')->insert(['project_id' => $id, 'user_id' => $member->id]);
        $this->getJson('/api/v1/projects/'.$id)->assertOk();
        $this->patchJson('/api/v1/projects/'.$id, ['name' => 'No'])->assertForbidden();
        $this->deleteJson('/api/v1/projects/'.$id)->assertForbidden();
    }

    public function test_auth_rate_limit_and_anonymous_project_access(): void
    {
        $this->getJson('/api/v1/projects')->assertUnauthorized();
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/login', ['email' => 'none@example.com', 'password' => 'bad'])->assertUnprocessable();
        }
        $this->postJson('/api/v1/login', ['email' => 'none@example.com', 'password' => 'bad'])->assertStatus(429);
    }

    public function test_invalid_project_input_is_rejected_and_tenant_cannot_be_changed(): void
    {
        $org = $this->registerUser();
        $this->postJson('/api/v1/projects', ['organization_id' => $org, 'name' => ''])->assertUnprocessable();
        $id = $this->postJson('/api/v1/projects', ['organization_id' => $org, 'name' => 'Valid'])->json('data.id');
        $this->patchJson('/api/v1/projects/'.$id, ['organization_id' => '00000000-0000-0000-0000-000000000000', 'name' => 'Updated'])->assertOk()->assertJsonPath('data.organization_id', $org);
    }
}
