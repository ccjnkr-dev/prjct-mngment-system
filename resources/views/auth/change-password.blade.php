<x-auth-layout>
<span class="eyebrow">MAKE THIS ACCOUNT YOURS</span><h1>Choose your password.</h1>
<p class="muted">Before continuing, replace the temporary password provided by your administrator.</p>
<form method="post" action="{{ route('password.change.update') }}" class="stack">@csrf
<label>Current password<input type="password" name="current_password" autocomplete="current-password" required></label>
<label>New password<input type="password" name="password" autocomplete="new-password" minlength="10" required aria-describedby="password-hint"></label>
<small id="password-hint">Use at least 10 characters and a different password from your temporary one.</small>
<label>Confirm new password<input type="password" name="password_confirmation" autocomplete="new-password" required></label>
<button class="button" data-loading-label="Updating password…">Update password and continue</button></form>
<form method="post" action="/logout" class="space-top">@csrf<button class="text-button">Sign out</button></form>
</x-auth-layout>
