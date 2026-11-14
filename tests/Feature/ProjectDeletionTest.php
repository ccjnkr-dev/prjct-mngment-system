<?php

namespace Tests\Feature;

use App\Jobs\UploadDriveAsset;
use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use App\Services\GoogleDrive;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProjectDeletionTest extends TestCase
{
    use RefreshDatabase;

    private function project(User $user): Project
    {
        $client = Client::create(['name' => 'Keep client']);
        $project = Project::create(['name' => 'Delete project test', 'client_id' => $client->id, 'drive_folder_id' => 'preserved-folder']);
        $project->members()->attach($user);

        return $project;
    }

    public function test_admin_deletion_cascades_project_records_and_preserves_client_and_drive(): void
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);
        $project = $this->project($admin);
        $task = $project->tasks()->create(['title' => 'Task']);
        $task->checklistItems()->create(['title' => 'Check']);
        $project->notes()->create(['title' => 'Note', 'body' => 'Body', 'user_id' => $admin->id]);
        $project->activities()->create(['description' => 'Activity', 'user_id' => $admin->id]);
        Storage::disk('local')->put('uploads/delete-me', 'staged');
        $asset = $project->assets()->create(['name' => 'Pending', 'user_id' => $admin->id, 'path' => 'uploads/delete-me', 'status' => 'queued']);
        $project->assets()->create(['name' => 'Drive file', 'user_id' => $admin->id, 'status' => 'ready', 'drive_id' => 'preserved-file']);
        $this->actingAs($admin)->get('/projects/'.$project->id)->assertSee('Delete project')->assertSee('Delete permanently');
        $this->delete('/projects/'.$project->id)->assertRedirect('/projects')->assertSessionHas('success');
        foreach (['projects', 'tasks', 'checklist_items', 'notes', 'assets', 'activities', 'project_user'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertDatabaseHas('clients', ['id' => $project->client_id]);
        Storage::disk('local')->assertMissing('uploads/delete-me');
        Http::assertNothingSent();
        $drive = $this->mock(GoogleDrive::class);
        $drive->shouldNotReceive('upload');
        (new UploadDriveAsset($asset->id))->handle($drive);
        $this->get('/projects/'.$project->id)->assertNotFound();
    }

    public function test_only_active_admin_with_personal_password_can_delete(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);
        $project = $this->project($admin);
        $url = '/projects/'.$project->id;
        $this->delete($url)->assertRedirect('/login');
        $member = User::factory()->create(['role' => 'member', 'active' => true]);
        $project->members()->attach($member);
        $this->actingAs($member)->get($url)->assertOk()->assertDontSee('Delete permanently');
        $this->delete($url)->assertForbidden();
        $admin->update(['must_change_password' => true]);
        $this->actingAs($admin)->deleteJson($url)->assertStatus(423);
        $admin->update(['must_change_password' => false, 'active' => false]);
        $this->actingAs($admin)->delete($url)->assertRedirect('/login');
        $this->assertDatabaseHas('projects', ['id' => $project->id]);
    }

    public function test_running_upload_blocks_deletion_without_removing_records_or_staging(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);
        $project = $this->project($admin);
        Storage::disk('local')->put('uploads/keep', 'staged');
        $asset = $project->assets()->create(['name' => 'Uploading', 'user_id' => $admin->id, 'status' => 'uploading', 'path' => 'uploads/keep']);
        $this->actingAs($admin)->deleteJson('/projects/'.$project->id)->assertUnprocessable()->assertJsonValidationErrors('project_delete');
        $this->assertDatabaseHas('projects', ['id' => $project->id]);
        Storage::disk('local')->assertExists('uploads/keep');
        $asset->update(['status' => 'failed']);
        $this->delete('/projects/'.$project->id)->assertRedirect('/projects');
        Storage::disk('local')->assertMissing('uploads/keep');
    }

    public function test_unrelated_project_survives_and_deleted_project_cannot_be_deleted_twice(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);
        $remove = $this->project($admin);
        $keep = $this->project($admin);
        $this->actingAs($admin)->delete('/projects/'.$remove->id)->assertRedirect('/projects');
        $this->assertDatabaseHas('projects', ['id' => $keep->id]);
        $this->delete('/projects/'.$remove->id)->assertNotFound();
    }

    public function test_theme_switch_is_present_on_login_and_workspace(): void
    {
        $this->get('/login')->assertOk()->assertSee('data-theme-toggle', false)->assertSee('theme-init.js');
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);
        $this->actingAs($admin)->get('/')->assertOk()->assertSee('data-theme-toggle', false)->assertSee('theme-init.js');
    }

    public function test_upload_job_claims_existing_asset_and_skips_completed_assets(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);
        $project = $this->project($admin);
        $asset = $project->assets()->create(['name' => 'Pending', 'user_id' => $admin->id, 'status' => 'queued']);
        $drive = $this->mock(GoogleDrive::class);
        $drive->shouldReceive('upload')->once()->withArgs(fn ($claimed) => $claimed->id === $asset->id && $claimed->status === 'uploading');
        (new UploadDriveAsset($asset->id))->handle($drive);
        $this->assertSame('uploading', $asset->fresh()->status);
        $asset->update(['status' => 'ready']);
        (new UploadDriveAsset($asset->id))->handle($drive);
    }
}
