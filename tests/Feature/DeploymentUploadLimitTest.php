<?php

namespace Tests\Feature;

use App\Jobs\UploadDriveAsset;
use App\Models\Client;
use App\Models\DriveConnection;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DeploymentUploadLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_deployment_limit_is_displayed_and_enforced_before_staging(): void
    {
        config(['uploads.max_kilobytes' => 4096]);
        Storage::fake('local');
        Queue::fake();
        $u = User::factory()->create(['role' => 'admin', 'active' => true]);
        $c = Client::create(['name' => 'Company']);
        $p = Project::create(['name' => 'Website', 'client_id' => $c->id]);
        DriveConnection::create(['refresh_token' => 'test-token']);
        $this->actingAs($u)->get('/projects/'.$p->id.'?tab=assets')->assertSee('Up to 4 MB per file');
        $this->post('/projects/'.$p->id.'/assets', ['file' => UploadedFile::fake()->create('too-big.zip', 4097)])->assertSessionHasErrors('file');
        $this->assertDatabaseCount('assets', 0);
        Queue::assertNothingPushed();
        $this->post('/projects/'.$p->id.'/assets', ['file' => UploadedFile::fake()->create('fits.zip', 4096)])->assertRedirect();
        $this->assertDatabaseCount('assets', 1);
        Queue::assertPushed(UploadDriveAsset::class);
    }
}
