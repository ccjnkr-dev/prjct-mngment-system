<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_asynchronous_deletion_returns_same_origin_destination_and_flashes_success(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);
        $item = Client::create(['name' => 'Async deletion']);
        $this->actingAs($admin)->deleteJson('/clients/'.$item->id)
            ->assertOk()->assertExactJson(['redirect' => '/clients'])->assertSessionHas('success');
        $this->assertDatabaseMissing('clients', ['id' => $item->id]);
    }

    public function test_admin_can_delete_client_without_projects(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $client = Client::create(['name' => 'Disposable company', 'contact_name' => 'Contact', 'notes' => 'Details']);
        $this->actingAs($admin)->get('/clients')->assertOk()->assertSee('Delete Disposable company')->assertSee('Delete client')->assertSee('action="/clients/'.$client->id.'"', false);
        $this->get('/clients/'.$client->id.'/edit')->assertOk()->assertSee('Delete Disposable company');
        $this->delete('/clients/'.$client->id)->assertRedirect('/clients')->assertSessionHas('success', 'Client deleted.');
        $this->assertDatabaseMissing('clients', ['id' => $client->id]);
        $this->get('/clients/'.$client->id.'/edit')->assertNotFound();
    }

    public function test_guests_members_and_temporary_password_accounts_cannot_delete_clients(): void
    {
        $client = Client::create(['name' => 'Protected']);
        $url = '/clients/'.$client->id;
        $this->delete($url)->assertRedirect('/login');
        $this->actingAs(User::factory()->create(['role' => 'member']))->delete($url)->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'admin', 'must_change_password' => true]))->deleteJson($url)->assertStatus(423);
        $this->assertDatabaseHas('clients', ['id' => $client->id]);
    }

    public function test_linked_projects_block_deletion_including_archived_projects(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $client = Client::create(['name' => 'Keep company']);
        $project = Project::create(['name' => 'Archived website', 'client_id' => $client->id, 'status' => 'archived']);
        $this->actingAs($admin)->get('/clients')->assertSee('This client has linked projects');
        $this->from('/clients')->delete('/clients/'.$client->id)->assertRedirect('/clients')->assertSessionHasErrors('client_delete');
        $this->assertDatabaseHas('clients', ['id' => $client->id]);
        $this->assertDatabaseHas('projects', ['id' => $project->id]);
        $this->deleteJson('/clients/'.$client->id)->assertUnprocessable()->assertJsonValidationErrors('client_delete');
    }

    public function test_deleting_one_client_preserves_other_clients_and_projects(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $remove = Client::create(['name' => 'Remove']);
        $keep = Client::create(['name' => 'Keep']);
        $project = Project::create(['name' => 'Keep project', 'client_id' => $keep->id]);
        $this->actingAs($admin)->delete('/clients/'.$remove->id)->assertRedirect('/clients');
        $this->assertDatabaseHas('clients', ['id' => $keep->id]);
        $this->assertDatabaseHas('projects', ['id' => $project->id]);
        $this->delete('/clients/'.$remove->id)->assertNotFound();
    }
}
