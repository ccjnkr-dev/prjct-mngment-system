<?php

namespace Tests\Feature;

use App\Models\DriveConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class DriveSetupErrorsTest extends TestCase
{
    use RefreshDatabase;

    private function driveCallback(array $body, int $status = 200): TestResponse
    {
        $user = User::factory()->create(['role' => 'admin', 'active' => true]);
        Http::fake(['oauth2.googleapis.com/*' => Http::response(['access_token' => 'access', 'refresh_token' => 'new-refresh']), 'www.googleapis.com/drive/v3/files/*' => Http::response($body, $status)]);

        return $this->actingAs($user)->withSession(['drive_oauth_state' => 'valid'])->get('/settings/drive/callback?state=valid&code=code');
    }

    public function test_disabled_api_has_specific_message_and_does_not_save_failed_connection(): void
    {
        $this->driveCallback(['error' => ['details' => [['reason' => 'SERVICE_DISABLED']]]], 403)->assertRedirect('/settings/drive')->assertSessionHasErrors(['drive' => 'Google Drive API is disabled for your OAuth project. Enable Google Drive API in Google Cloud, then reconnect.']);
        $this->assertDatabaseCount('drive_connections', 0);
    }

    public function test_missing_folder_is_distinguished_from_read_only_folder(): void
    {
        $this->driveCallback(['error' => ['code' => 404]], 404)->assertSessionHasErrors(['drive' => 'The configured Drive folder was not found or is not visible to the connected Google account. Check the folder ID and share the folder directly with that account.']);
        $this->assertDatabaseCount('drive_connections', 0);
    }

    public function test_read_only_folder_requires_editor_permission(): void
    {
        $this->driveCallback(['mimeType' => 'application/vnd.google-apps.folder', 'capabilities' => ['canAddChildren' => false]])->assertSessionHasErrors(['drive' => 'The connected Google account cannot add files to this folder. Give that exact account Editor access to the folder, then reconnect.']);
        $this->assertDatabaseCount('drive_connections', 0);
    }

    public function test_missing_scope_is_reported_and_previous_connection_is_restored(): void
    {
        DriveConnection::create(['refresh_token' => 'previous-refresh']);
        $this->driveCallback(['error' => ['errors' => [['reason' => 'insufficientPermissions']]]], 403)->assertSessionHasErrors(['drive' => 'Google did not grant the required Drive permission. Reconnect and approve the Drive access requested by this app.']);
        $this->assertSame('previous-refresh', DriveConnection::first()->refresh_token);
    }

    public function test_non_folder_id_is_reported(): void
    {
        $this->driveCallback(['mimeType' => 'application/pdf', 'capabilities' => ['canAddChildren' => true]])->assertSessionHasErrors(['drive' => 'The configured Drive item is not a folder. Set GOOGLE_DRIVE_FOLDER_ID to the folder ID, then reconnect.']);
        $this->assertDatabaseCount('drive_connections', 0);
    }

    public function test_certificate_error_does_not_expose_sensitive_exception_details(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'active' => true]);
        Http::fake(['oauth2.googleapis.com/*' => Http::response(['access_token' => 'access', 'refresh_token' => 'refresh']), 'www.googleapis.com/drive/v3/files/*' => fn () => throw new ConnectionException('cURL error 60: secret-detail')]);
        $response = $this->actingAs($user)->withSession(['drive_oauth_state' => 'valid'])->get('/settings/drive/callback?state=valid&code=code');
        $response->assertSessionHasErrors(['drive' => 'The server could not verify Google’s HTTPS certificate. Configure PHP curl.cainfo and openssl.cafile with a valid CA bundle, then reconnect.']);
        $this->assertStringNotContainsString('secret-detail', session('errors')->first('drive'));
    }
}
