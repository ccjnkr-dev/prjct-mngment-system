<?php

namespace Tests\Feature;

use App\Jobs\UploadDriveAsset;
use App\Livewire\AssetList;
use App\Mail\TeamInvitation;
use App\Models\Asset;
use App\Models\Client;
use App\Models\DriveConnection;
use App\Models\Invitation;
use App\Models\Project;
use App\Models\User;
use App\Services\GoogleDrive;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class WorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private function member(string $role = 'member', bool $active = true): User
    {
        return User::factory()->create(compact('role', 'active'));
    }

    private function project(?User $member = null): Project
    {
        $client = Client::create(['name' => 'Acme']);
        $p = Project::create(['name' => 'Website redesign', 'client_id' => $client->id]);
        if ($member) {
            $p->members()->attach($member);
        }

        return $p;
    }

    public function test_guest_access(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->get('/register')->assertNotFound();
        $this->get('/login')->assertOk();
    }

    public function test_login_and_deactivation(): void
    {
        $u = $this->member();
        $this->post('/login', ['email' => $u->email, 'password' => 'password'])->assertRedirect('/');
        $this->assertAuthenticatedAs($u);
        $u->update(['active' => false]);
        $this->get('/')->assertRedirect('/login');
        $this->assertGuest();
        $this->post('/login', ['email' => $u->email, 'password' => 'password'])->assertSessionHasErrors('email');
    }

    public function test_project_isolation(): void
    {
        $u = $this->member();
        $p = $this->project();
        $this->actingAs($u)->get('/')->assertDontSee($p->name);
        $this->get('/projects/'.$p->id)->assertForbidden();
        $this->get('/projects/'.$p->id.'/timeline')->assertForbidden();
        $p->members()->attach($u);
        $this->get('/')->assertSee($p->name);
    }

    public function test_all_project_tabs_render_with_data(): void
    {
        $u = $this->member('admin');
        $p = $this->project($u);
        $t = $p->tasks()->create(['title' => 'Design', 'start_date' => '2026-11-01', 'due_date' => '2026-11-20']);
        $t->checklistItems()->create(['title' => 'Review']);
        $p->notes()->create(['title' => 'Brief', 'body' => '**Hello**', 'user_id' => $u->id]);
        $p->assets()->create(['name' => 'Logo', 'user_id' => $u->id, 'status' => 'ready', 'url' => 'https://drive.google.com/file/d/test/view']);
        foreach (['overview', 'tasks', 'timeline', 'notes', 'assets', 'settings'] as $tab) {
            $this->actingAs($u)->get('/projects/'.$p->id.'?tab='.$tab)->assertOk();
        }$this->get('/users')->assertOk();
        $this->get('/settings/drive')->assertOk();
    }

    public function test_admin_create_and_member_restrictions(): void
    {
        $u = $this->member('admin');
        $this->actingAs($u)->post('/clients', ['name' => 'New client'])->assertRedirect();
        $this->post('/projects', ['name' => 'Build', 'client_id' => Client::first()->id, 'members' => [$u->id]])->assertRedirect();
        $this->assertDatabaseHas('project_user', ['user_id' => $u->id]);
        $this->actingAs($this->member())->post('/clients', ['name' => 'No'])->assertForbidden();
        $this->post('/projects', [])->assertForbidden();
        $this->get('/users')->assertForbidden();
        $this->get('/settings/drive')->assertForbidden();
    }

    public function test_tasks_validate_dates_assignees_and_project_boundaries(): void
    {
        $u = $this->member();
        $p = $this->project($u);
        $d = ['title' => 'Design', 'status' => 'todo', 'priority' => 'high', 'assignee_id' => $u->id];
        $this->actingAs($u)->post('/projects/'.$p->id.'/tasks', $d)->assertRedirect();
        $this->post('/projects/'.$p->id.'/tasks', [...$d, 'start_date' => '2026-11-20', 'due_date' => '2026-11-01'])->assertSessionHasErrors('due_date');
        $this->post('/projects/'.$p->id.'/tasks', [...$d, 'assignee_id' => $this->member()->id])->assertStatus(422);
        $other = $this->project()->tasks()->create(['title' => 'Private']);
        $this->post('/projects/'.$p->id.'/tasks/'.$other->id, $d)->assertNotFound();
        $this->patch('/projects/'.$p->id, ['name' => 'No'])->assertForbidden();
    }

    public function test_checklists_progress_and_timeline(): void
    {
        $u = $this->member();
        $p = $this->project($u);
        $t = $p->tasks()->create(['title' => 'Build', 'start_date' => '2026-11-01', 'due_date' => '2026-11-20', 'assignee_id' => $u->id]);
        $p->tasks()->create(['title' => 'Done', 'status' => 'done']);
        $p->tasks()->create(['title' => 'Launch', 'milestone' => true]);
        $this->actingAs($u)->post('/projects/'.$p->id.'/tasks/'.$t->id.'/checklist', ['title' => 'Review'])->assertRedirect();
        $item = $t->checklistItems()->first();
        $this->post('/projects/'.$p->id.'/checklist/'.$item->id)->assertRedirect();
        $this->assertTrue($item->fresh()->done);
        $this->assertSame('todo', $t->fresh()->status);
        $this->assertSame(50, $p->fresh()->progress);
        $this->assertSame(0, $this->project()->progress);
        $this->getJson('/projects/'.$p->id.'/timeline')->assertOk()->assertJsonPath('data.0.start', '2026-11-01')->assertJsonPath('data.0.assignee_id', $u->id);
    }

    public function test_notes_sanitize_markdown_and_can_be_updated(): void
    {
        $u = $this->member();
        $p = $this->project($u);
        $this->actingAs($u)->post('/projects/'.$p->id.'/notes', ['title' => 'Brief', 'body' => '<script>alert(1)</script> **hello**', 'pinned' => 1])->assertRedirect();
        $n = $p->notes()->first();
        $this->get('/projects/'.$p->id.'?tab=notes')->assertDontSee('<script>alert(1)</script>', false);
        $this->post('/projects/'.$p->id.'/notes/'.$n->id, ['title' => 'Updated', 'body' => '**hello**'])->assertRedirect();
        $this->get('/projects/'.$p->id.'?tab=notes')->assertSee('<strong>hello</strong>', false);
    }

    public function test_invitation_is_hashed_single_use_and_expiring(): void
    {
        Mail::fake();
        $admin = $this->member('admin');
        $this->actingAs($admin)->post('/invitations', ['email' => 'new@example.com', 'role' => 'member'])->assertRedirect();
        $link = '';
        Mail::assertSent(TeamInvitation::class, function ($mail) use (&$link) {
            $link = $mail->link;

            return true;
        });
        $token = basename($link);
        $this->assertNotSame($token, Invitation::first()->token);
        $this->post('/logout');
        $this->get('/invitations/'.$token)->assertOk();
        $this->post('/invitations/'.$token, ['name' => 'New', 'password' => 'secure-password-123', 'password_confirmation' => 'secure-password-123'])->assertRedirect('/login');
        $this->get('/invitations/'.$token)->assertNotFound();
        $i = Invitation::create(['email' => 'expired@example.com', 'token' => hash('sha256', 'expired'), 'expires_at' => now()->subDay()]);
        $this->get('/invitations/expired')->assertNotFound();
        $this->actingAs($admin)->delete('/invitations/'.$i->id)->assertRedirect();
    }

    public function test_last_admin_and_account_deactivation(): void
    {
        $a = $this->member('admin');
        $u = $this->member();
        $this->actingAs($a)->patch('/users/'.$a->id, ['role' => 'member', 'active' => 0])->assertStatus(422);
        $this->patch('/users/'.$u->id, ['role' => 'member', 'active' => 0])->assertRedirect();
        $this->assertFalse($u->fresh()->active);
    }

    public function test_password_reset(): void
    {
        $u = $this->member();
        $token = Password::createToken($u);
        $this->get('/reset-password/'.$token.'?email='.$u->email)->assertOk();
        $this->post('/reset-password', ['email' => $u->email, 'token' => $token, 'password' => 'new-password-123', 'password_confirmation' => 'new-password-123'])->assertRedirect('/login');
        $this->assertTrue(Hash::check('new-password-123', $u->fresh()->password));
    }

    public function test_upload_queue_limits_and_connection(): void
    {
        Queue::fake();
        Storage::fake('local');
        $u = $this->member();
        $p = $this->project($u);
        $url = '/projects/'.$p->id.'/assets';
        $this->actingAs($u)->post($url, ['file' => UploadedFile::fake()->create('big.zip', 102401)])->assertSessionHasErrors('file');
        $this->post($url, ['file' => UploadedFile::fake()->create('small.txt', 1)])->assertStatus(422);
        DriveConnection::create(['refresh_token' => 'secret']);
        $this->post($url, ['file' => UploadedFile::fake()->create('brief.pdf', 20, 'application/pdf')])->assertRedirect();
        $a = Asset::first();
        Storage::disk('local')->assertExists($a->path);
        Queue::assertPushed(UploadDriveAsset::class, fn ($j) => $j->assetId === $a->id);
        $this->assertNotSame('secret', \DB::table('drive_connections')->value('refresh_token'));
    }

    public function test_link_validation_and_oauth_state(): void
    {
        $u = $this->member('admin');
        $p = $this->project($u);
        $this->actingAs($u)->post('/projects/'.$p->id.'/links', ['name' => 'Bad', 'url' => 'https://evil.com/'])->assertSessionHasErrors('url');
        $this->post('/projects/'.$p->id.'/links', ['name' => 'Logo', 'url' => 'https://drive.google.com/file/d/123/view'])->assertRedirect();
        $this->withSession(['drive_oauth_state' => 'expected'])->get('/settings/drive/callback?state=wrong&code=secret')->assertForbidden();
    }

    public function test_failed_upload_cleans_private_staging(): void
    {
        Storage::fake('local');
        $u = $this->member();
        $p = $this->project($u);
        Storage::disk('local')->put('uploads/source', 'hello');
        $a = $p->assets()->create(['name' => 'File', 'user_id' => $u->id, 'path' => 'uploads/source']);
        (new UploadDriveAsset($a->id))->failed(new \RuntimeException('sensitive error'));
        Storage::disk('local')->assertMissing('uploads/source');
        $this->assertSame('failed', $a->fresh()->status);
        $this->assertStringNotContainsString('sensitive', $a->fresh()->error);
    }

    public function test_drive_upload_and_duplicate_prevention(): void
    {
        Storage::fake('local');
        $u = $this->member();
        $p = $this->project($u);
        $p->update(['drive_folder_id' => 'folder']);
        DriveConnection::create(['refresh_token' => 'secret']);
        Storage::disk('local')->put('uploads/source', 'hello');
        $a = $p->assets()->create(['name' => 'File', 'mime' => 'text/plain', 'size' => 5, 'user_id' => $u->id, 'path' => 'uploads/source']);
        Http::fake(['oauth2.googleapis.com/*' => Http::response(['access_token' => 'access']), 'www.googleapis.com/drive/v3/files/generateIds*' => Http::response(['ids' => ['reserved']]), 'www.googleapis.com/drive/v3/files/folder*' => Http::response(['id' => 'folder']), 'www.googleapis.com/drive/v3/files/reserved*' => Http::sequence()->push([], 404)->push(['id' => 'reserved', 'size' => '5']), 'www.googleapis.com/upload/drive/v3/files*' => Http::response([], 200, ['Location' => 'https://www.googleapis.com/upload/session']), 'www.googleapis.com/upload/session' => Http::response(['id' => 'reserved'])]);
        app(GoogleDrive::class)->upload($a);
        $this->assertSame('ready', $a->fresh()->status);
        Storage::disk('local')->assertMissing('uploads/source');
        app(GoogleDrive::class)->upload($a->fresh());
        $this->assertSame('ready', $a->fresh()->status);
        $this->assertCount(1, Http::recorded(fn ($r) => str_contains($r->url(), 'uploadType=resumable')));
    }

    public function test_livewire_rejects_nonmembers(): void
    {
        $u = $this->member();
        $p = $this->project();
        $this->actingAs($u);
        Livewire::test(AssetList::class, ['project' => $p])->assertForbidden();
    }

    public function test_due_date_only_and_milestone_conversion(): void
    {
        $u = $this->member();
        $p = $this->project($u);
        $url = '/projects/'.$p->id.'/tasks';
        $data = ['title' => 'Launch', 'status' => 'todo', 'priority' => 'medium', 'due_date' => '2026-12-01'];
        $this->actingAs($u)->post($url, $data)->assertRedirect();
        $task = $p->tasks()->first();
        $this->assertNull($task->start_date);
        $this->post($url.'/'.$task->id, [...$data, 'start_date' => '2026-11-20', 'milestone' => true])->assertRedirect();
        $this->assertTrue($task->fresh()->milestone);
        $this->assertSame($task->fresh()->start_date, $task->fresh()->due_date);
        $this->post($url.'/'.$task->id, $data)->assertRedirect();
        $this->assertFalse($task->fresh()->milestone);
    }

    public function test_admin_updates_membership_and_archives_without_removing_assets(): void
    {
        $u = $this->member('admin');
        $m = $this->member();
        $p = $this->project($m);
        $p->assets()->create(['name' => 'Existing', 'user_id' => $u->id, 'status' => 'ready', 'drive_id' => 'keep']);
        $this->actingAs($u)->patch('/projects/'.$p->id, ['name' => 'Archived project', 'status' => 'archived', 'due_date' => '2026-12-01', 'members' => [$u->id]])->assertRedirect();
        $this->assertSame('archived', $p->fresh()->status);
        $this->assertFalse($p->members()->where('users.id', $m->id)->exists());
        $this->assertDatabaseHas('assets', ['drive_id' => 'keep']);
    }

    public function test_drive_connection_validates_folder_and_can_disconnect(): void
    {
        $u = $this->member('admin');
        config(['services.google.client_id' => 'client', 'services.google.client_secret' => 'secret']);
        Http::fake(['oauth2.googleapis.com/*' => Http::response(['access_token' => 'access', 'refresh_token' => 'refresh']), 'www.googleapis.com/drive/v3/files/*' => Http::response(['id' => 'parent', 'mimeType' => 'application/vnd.google-apps.folder', 'capabilities' => ['canAddChildren' => true]])]);
        $this->actingAs($u)->withSession(['drive_oauth_state' => 'valid'])->get('/settings/drive/callback?state=valid&code=code')->assertRedirect('/settings/drive');
        $this->assertSame('refresh', DriveConnection::first()->refresh_token);
        $this->post('/settings/drive/disconnect')->assertRedirect();
        $this->assertDatabaseCount('drive_connections', 0);
    }
}
