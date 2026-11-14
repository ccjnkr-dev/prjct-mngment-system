<?php

namespace App\Services;

use App\Exceptions\DriveSetupException;
use App\Models\Asset;
use App\Models\DriveConnection;
use App\Models\Project;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class GoogleDrive
{
    private ?string $token = null;

    public function token(): string
    {
        if ($this->token) {
            return $this->token;
        }
        $connection = DriveConnection::firstOrFail();
        $r = Http::asForm()->post('https://oauth2.googleapis.com/token', ['client_id' => config('services.google.client_id'), 'client_secret' => config('services.google.client_secret'), 'refresh_token' => $connection->refresh_token, 'grant_type' => 'refresh_token'])->throw();

        return $this->token = $r->json('access_token');
    }

    private function api(): PendingRequest
    {
        return Http::withToken($this->token())->timeout(120);
    }

    public function validateParent(): void
    {
        try {
            $response = $this->api()->get('https://www.googleapis.com/drive/v3/files/'.config('services.google.folder_id'), [
                'fields' => 'id,mimeType,capabilities(canAddChildren)',
                'supportsAllDrives' => 'true',
            ])->throw();
        } catch (RequestException $exception) {
            $response = $exception->response;
            $reasons = array_merge(
                $response->json('error.errors.*.reason', []) ?: [],
                $response->json('error.details.*.reason', []) ?: [],
            );
            $message = match (true) {
                (bool) array_intersect($reasons, ['accessNotConfigured', 'SERVICE_DISABLED']) => 'Google Drive API is disabled for your OAuth project. Enable Google Drive API in Google Cloud, then reconnect.',
                (bool) array_intersect($reasons, ['insufficientPermissions', 'ACCESS_TOKEN_SCOPE_INSUFFICIENT']) => 'Google did not grant the required Drive permission. Reconnect and approve the Drive access requested by this app.',
                $response->status() === 404 => 'The configured Drive folder was not found or is not visible to the connected Google account. Check the folder ID and share the folder directly with that account.',
                $response->status() === 401 || $response->json('error') === 'invalid_grant' => 'Google rejected the authorization. Reconnect Google Drive and approve access again.',
                default => 'Google rejected the Drive folder check (HTTP '.$response->status().'). Check folder permissions and any organization restrictions, then reconnect.',
            };
            throw new DriveSetupException($message, 0, $exception);
        } catch (ConnectionException $exception) {
            $message = str_contains($exception->getMessage(), 'cURL error 60')
                ? 'The server could not verify Google’s HTTPS certificate. Configure PHP curl.cainfo and openssl.cafile with a valid CA bundle, then reconnect.'
                : 'The server could not reach Google Drive. Check its internet, DNS, and proxy settings, then reconnect.';
            throw new DriveSetupException($message, 0, $exception);
        }
        if ($response->json('mimeType') !== 'application/vnd.google-apps.folder') {
            throw new DriveSetupException('The configured Drive item is not a folder. Set GOOGLE_DRIVE_FOLDER_ID to the folder ID, then reconnect.');
        }
        if (! $response->json('capabilities.canAddChildren')) {
            throw new DriveSetupException('The connected Google account cannot add files to this folder. Give that exact account Editor access to the folder, then reconnect.');
        }
    }

    private function newId(): string
    {
        return $this->api()->get('https://www.googleapis.com/drive/v3/files/generateIds', ['count' => 1, 'space' => 'drive'])->throw()->json('ids.0');
    }

    private function folder(Project $project): string
    {
        return Cache::lock('drive-project-'.$project->id, 180)->block(30, function () use ($project) {
            $project->refresh();
            if (! $project->drive_folder_id) {
                $project->update(['drive_folder_id' => $this->newId()]);
            }
            $id = $project->drive_folder_id;
            $r = $this->api()->get('https://www.googleapis.com/drive/v3/files/'.$id, ['fields' => 'id', 'supportsAllDrives' => 'true']);
            if ($r->status() === 404) {
                $this->api()->post('https://www.googleapis.com/drive/v3/files?supportsAllDrives=true', ['id' => $id, 'name' => $project->name.' — '.$project->id, 'mimeType' => 'application/vnd.google-apps.folder', 'parents' => [config('services.google.folder_id')]])->throw();
            } else {
                $r->throw();
            }

            return $id;
        });
    }

    public function upload(Asset $asset): void
    {
        $folder = $this->folder($asset->project);
        if (! $asset->drive_id) {
            $asset->update(['drive_id' => $this->newId()]);
        }
        $existing = $this->api()->get('https://www.googleapis.com/drive/v3/files/'.$asset->drive_id, ['fields' => 'id,size,webViewLink', 'supportsAllDrives' => 'true']);
        if ($existing->successful() && (int) $existing->json('size') === $asset->size) {
            $this->complete($asset, $existing->json('webViewLink'));

            return;
        }
        if ($existing->status() !== 404) {
            $existing->throw();
        }
        $response = $this->api()->withHeaders(['X-Upload-Content-Type' => $asset->mime ?? 'application/octet-stream', 'X-Upload-Content-Length' => (string) $asset->size])->post('https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable&supportsAllDrives=true', ['id' => $asset->drive_id, 'name' => $asset->name, 'parents' => [$folder]])->throw();
        $uri = $response->header('Location');
        if (! $uri || parse_url($uri, PHP_URL_HOST) !== 'www.googleapis.com') {
            throw new RuntimeException('Invalid upload session.');
        }
        $stream = Storage::disk('local')->readStream($asset->path);
        if (! $stream) {
            throw new RuntimeException('Upload source is unavailable.');
        }
        try {
            $offset = 0;
            do {
                $chunk = fread($stream, 5 * 1024 * 1024);
                $len = strlen($chunk);
                $headers = ['Content-Range' => $asset->size ? 'bytes '.$offset.'-'.($offset + $len - 1).'/'.$asset->size : 'bytes */0'];
                $r = $this->api()->withHeaders($headers)->withBody($chunk, $asset->mime ?? 'application/octet-stream')->put($uri);
                if ($r->status() !== 308) {
                    $r->throw();
                }
                $offset += $len;
            } while ($offset < $asset->size);
            if (! $r->successful()) {
                throw new RuntimeException('Upload did not finish.');
            }
        } finally {
            fclose($stream);
        }
        $this->complete($asset, 'https://drive.google.com/file/d/'.$asset->drive_id.'/view');
    }

    private function complete(Asset $asset, ?string $url): void
    {
        $path = $asset->path;
        $asset->update(['status' => 'ready', 'url' => $url ?: 'https://drive.google.com/file/d/'.$asset->drive_id.'/view', 'path' => null, 'error' => null]);
        if ($path) {
            Storage::disk('local')->delete($path);
        }
    }
}
