<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Invitation;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class AccountsAndClientsTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $role = 'admin', bool $temporary = false): User
    {
        return User::factory()->create(['role' => $role, 'must_change_password' => $temporary]);
    }

    private function newUserData(): array
    {
        return ['name' => 'New Member', 'email' => 'new@example.test', 'role' => 'member', 'password' => 'Temporary-pass-123', 'password_confirmation' => 'Temporary-pass-123'];
    }

    public function test_admin_can_manually_create_hashed_temporary_account_and_remove_pending_invite(): void
    {
        $admin = $this->account();
        Invitation::create(['email' => 'new@example.test', 'token' => hash('sha256', 'pending'), 'expires_at' => now()->addDay()]);
        $this->actingAs($admin)->post('/users', $this->newUserData())->assertRedirect('/users');
        $user = User::where('email', 'new@example.test')->firstOrFail();
        $this->assertTrue($user->active);
        $this->assertTrue($user->must_change_password);
        $this->assertTrue(Hash::check('Temporary-pass-123', $user->password));
        $this->assertNotSame('Temporary-pass-123', $user->password);
        $this->assertDatabaseMissing('invitations', ['email' => $user->email]);
        $this->get('/users')->assertSee('Add user')->assertSee('Must change password');
    }

    public function test_manual_creation_is_admin_only_and_rejects_duplicates_and_weak_passwords(): void
    {
        $this->post('/users', $this->newUserData())->assertRedirect('/login');
        $member = $this->account('member');
        $this->actingAs($member)->post('/users', $this->newUserData())->assertForbidden();
        $this->actingAs($this->account())->post('/users', [...$this->newUserData(), 'password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password');
        $this->post('/users', [...$this->newUserData(), 'password_confirmation' => 'different'])->assertSessionHasErrors('password');
        $this->post('/users', $this->newUserData())->assertRedirect();
        $this->post('/users', $this->newUserData())->assertSessionHasErrors('email');
        $this->assertDatabaseCount('users', 3);
    }

    public function test_temporary_login_redirects_and_cannot_access_pages_or_actions(): void
    {
        $user = $this->account('admin', true);
        $client = Client::create(['name' => 'Private company']);
        $project = Project::create(['name' => 'Private project', 'client_id' => $client->id]);
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/change-password');
        foreach (['/', '/users', '/clients', '/projects/'.$project->id, '/settings/drive'] as $url) {
            $this->get($url)->assertRedirect('/change-password');
        }
        $this->post('/users', $this->newUserData())->assertRedirect('/change-password');
        $this->postJson('/clients', ['name' => 'Blocked'])->assertStatus(423)->assertJsonPath('redirect', url('/change-password'));
        $this->postJson('/livewire/update', [])->assertStatus(423);
        $this->get('/change-password')->assertOk()->assertSee('Choose your password');
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_password_change_requires_current_password_confirmation_and_different_password(): void
    {
        $u = $this->account('member', true);
        $this->actingAs($u)->post('/change-password', ['current_password' => 'wrong', 'password' => 'New-password-123', 'password_confirmation' => 'New-password-123'])->assertSessionHasErrors('current_password');
        $this->post('/change-password', ['current_password' => 'password', 'password' => 'password', 'password_confirmation' => 'password'])->assertSessionHasErrors('password');
        $this->post('/change-password', ['current_password' => 'password', 'password' => 'New-password-123', 'password_confirmation' => 'Mismatch-123'])->assertSessionHasErrors('password');
        $this->assertTrue($u->fresh()->must_change_password);
    }

    public function test_successful_password_change_unlocks_account_and_old_password_stops_working(): void
    {
        $u = $this->account('member', true);
        $this->actingAs($u)->post('/change-password', ['current_password' => 'password', 'password' => 'Personal-password-123', 'password_confirmation' => 'Personal-password-123'])->assertRedirect('/');
        $this->assertFalse($u->fresh()->must_change_password);
        $this->assertTrue(Hash::check('Personal-password-123', $u->fresh()->password));
        $this->assertFalse(Hash::check('password', $u->fresh()->password));
        $this->get('/')->assertOk();
        $this->post('/logout');
        $this->post('/login', ['email' => $u->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->post('/login', ['email' => $u->email, 'password' => 'Personal-password-123'])->assertRedirect('/');
    }

    public function test_existing_users_are_not_forced_to_change_password_and_reset_clears_requirement(): void
    {
        $u = $this->account('member');
        $this->actingAs($u)->get('/')->assertOk();
        $this->post('/logout');
        $u->update(['must_change_password' => true]);
        $token = Password::createToken($u);
        $this->post('/reset-password', ['token' => $token, 'email' => $u->email, 'password' => 'Self-reset-password-123', 'password_confirmation' => 'Self-reset-password-123'])->assertRedirect('/login');
        $this->assertFalse($u->fresh()->must_change_password);
    }

    public function test_client_directory_preserves_details_searches_and_lists_projects(): void
    {
        $u = $this->account();
        $data = ['name' => 'Example Company', 'business_type' => 'Consulting', 'industry' => 'Technology', 'contact_name' => 'Jane Doe', 'contact_position' => 'Owner', 'email' => 'jane@example.test', 'phone' => '+63 900 123 4567', 'website' => 'https://example.test', 'address' => 'Manila, Philippines', 'status' => 'active', 'notes' => 'Prefers email updates.'];
        $this->actingAs($u)->post('/clients', $data)->assertRedirect();
        $client = Client::firstOrFail();
        foreach ($data as $key => $value) {
            $this->assertSame($value, $client->$key);
        }
        Project::create(['name' => 'Client website', 'client_id' => $client->id]);
        $this->get('/clients')->assertOk()->assertSee('Example Company')->assertSee('Jane Doe')->assertSee('+63 900 123 4567');
        $this->get('/clients?q=jane')->assertSee('Example Company');
        $this->get('/clients?q=Unrelated')->assertDontSee('Example Company');
        $this->get('/clients/'.$client->id.'/edit')->assertOk()->assertSee('Client website')->assertSee('Prefers email updates.');
        $this->patch('/clients/'.$client->id, [...$data, 'name' => 'Updated Company', 'status' => 'inactive'])->assertRedirect('/clients/'.$client->id.'/edit');
        $this->assertDatabaseHas('clients', ['id' => $client->id, 'name' => 'Updated Company', 'status' => 'inactive']);
        $this->get('/clients?status=active')->assertDontSee('Updated Company');
        $this->get('/clients?status=inactive')->assertSee('Updated Company');
    }

    public function test_client_management_is_admin_only_and_validates_information(): void
    {
        $client = Client::create(['name' => 'Protected']);
        $this->actingAs($this->account('member'));
        $this->get('/clients')->assertForbidden();
        $this->get('/clients/'.$client->id.'/edit')->assertForbidden();
        $this->post('/clients', ['name' => 'No'])->assertForbidden();
        $this->patch('/clients/'.$client->id, ['name' => 'No'])->assertForbidden();
        $this->actingAs($this->account())->post('/clients', ['name' => 'Valid', 'email' => 'invalid', 'website' => 'javascript:alert(1)', 'status' => 'invalid'])->assertSessionHasErrors(['email', 'website', 'status']);
        $this->post('/clients', [])->assertSessionHasErrors('name');
    }
}
