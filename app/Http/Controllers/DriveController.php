<?php

namespace App\Http\Controllers;

use App\Exceptions\DriveSetupException;
use App\Models\DriveConnection;
use App\Services\GoogleDrive;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class DriveController extends Controller
{
    private function admin(): void
    {
        abort_unless(auth()->user()->role === 'admin', 403);
    }

    public function index(): mixed
    {
        $this->admin();

        return view('drive', ['connected' => DriveConnection::exists(), 'configured' => (bool) (config('services.google.client_id') && config('services.google.client_secret'))]);
    }

    public function connect(Request $r): mixed
    {
        $this->admin();
        abort_unless(config('services.google.client_id') && config('services.google.client_secret'), 422, 'Configure Google OAuth first.');
        $state = Str::random(48);
        $r->session()->put('drive_oauth_state', $state);

        return redirect('https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query(['client_id' => config('services.google.client_id'), 'redirect_uri' => url('/settings/drive/callback'), 'response_type' => 'code', 'scope' => 'https://www.googleapis.com/auth/drive', 'access_type' => 'offline', 'prompt' => 'consent', 'state' => $state]));
    }

    public function callback(Request $r): mixed
    {
        $this->admin();
        $expected = $r->session()->pull('drive_oauth_state');
        abort_unless(is_string($expected) && is_string($r->query('state')) && hash_equals($expected, $r->query('state')), 403);
        if ($r->has('error')) {
            return redirect('/settings/drive')->withErrors(['drive' => 'Google authorization was cancelled.']);
        }
        $r->validate(['code' => 'required|string']);
        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', ['code' => $r->input('code'), 'client_id' => config('services.google.client_id'), 'client_secret' => config('services.google.client_secret'), 'redirect_uri' => url('/settings/drive/callback'), 'grant_type' => 'authorization_code']);
        if (! $response->successful() || ! $response->json('refresh_token')) {
            return redirect('/settings/drive')->withErrors(['drive' => 'Google did not provide offline access. Try connecting again.']);
        }
        $previous = DriveConnection::first()?->refresh_token;
        $connection = DriveConnection::firstOrNew();
        $connection->refresh_token = $response->json('refresh_token');
        $connection->save();
        try {
            app(GoogleDrive::class)->validateParent();
        } catch (\Throwable $e) {
            if ($previous) {
                $connection->update(['refresh_token' => $previous]);
            } else {
                $connection->delete();
            }

            logger()->warning('Google Drive setup check failed.', ['exception_type' => get_class($e)]);

            $message = $e instanceof DriveSetupException
                ? $e->getMessage()
                : 'Drive connection validation failed on the server. Check the application logs and reconnect.';

            return redirect('/settings/drive')->withErrors(['drive' => $message]);
        }

        return redirect('/settings/drive')->with('success', 'Google Drive connected.');
    }

    public function disconnect(): mixed
    {
        $this->admin();
        DriveConnection::query()->delete();

        return back()->with('success', 'Drive disconnected from this application.');
    }
}
