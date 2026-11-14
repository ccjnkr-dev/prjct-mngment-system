# Vercel environment import

The local `.env.vercel` file contains real credentials copied from your current `.env`. It is excluded from Git, Docker build contexts and Vercel source uploads. Import it through the Vercel dashboard; never put its contents in a commit, issue, screenshot, or client bundle. Your normal `.env` is unchanged.

## Complete the import file

1. Replace `APP_URL=https://YOUR-PROJECT.vercel.app` with the exact HTTPS production domain assigned to your Vercel project. Do not use a rotating preview URL for production OAuth.
2. Complete `MAIL_HOST`, `MAIL_USERNAME`, `MAIL_PASSWORD` and a verified `MAIL_FROM_ADDRESS` if you need invitations and password-reset emails. The local configuration did not contain SMTP credentials. Match MAIL_SCHEME/MAIL_PORT to your provider.
3. Keep the existing APP_KEY when using the current Supabase database: stored Drive refresh tokens and sessions are encrypted with that key. The file preserves that key, the current database connection, Google OAuth client credentials and the Drive folder ID.
4. Add `https://YOUR-PRODUCTION-DOMAIN/settings/drive/callback` as an authorized redirect URI in the Google OAuth client. Approve the connecting Google account as a tester if the Google app is still in Testing.
5. In Vercel Project Settings → Environment Variables, import `.env.vercel` for **Production**. Redeploy after importing or changing variables. Use a separate environment/database and URL for Preview deployments.

## Environment choices

- Production mode, disabled debug output, stderr logging, encrypted database sessions/cache and secure host-only cookies.
- Laravel-supported temporary storage/cache paths under `/tmp`; `api/index.php` creates the required directories before booting Laravel.
- `QUEUE_CONNECTION=sync` processes work during the web request because this repo has no Vercel Laravel worker deployment. This does not provide the queued upload retries available with the original database worker setup.
- The database URL is copied exactly. Use Supabase's session pooler if the direct endpoint is incompatible with the deployment's network.

## Remaining deployment work

The repo now includes `vercel.json`, `api/index.php`, and a separate static asset build. In Vercel set the Framework Preset to **Other**, Root Directory to this repository root, Install Command to `npm ci --include=dev`, Build Command to `npm run build:vercel`, and Output Directory to `dist`. The source configuration specifies these settings; remove stale Vite/dist overrides from the dashboard if necessary.

`npm run build:vercel` builds Vite assets then copies safe public files into `dist`. PHP files, `.htaccess`, the local Vite `hot` marker, private storage and the server-side manifest are excluded from static hosting. Requests that do not match a static file route to the PHP function.

The function uses the community `vercel-php@0.9.0` runtime (PHP 8.5, Node 22). Its builder installs Composer dependencies and runs the Composer `vercel` hook. That hook builds an independent Vite manifest/assets under `vercel-php-public/build`, which the PHP entrypoint uses for Laravel's public path. Keeping the two output directories independent avoids relying on shared build directories or builder ordering. The function bundle excludes environment files, public source files, local storage, tests and the static output.

`api/index.php` initializes writable storage/cache directories and marks requests HTTPS only when running on Vercel. No migrations, seeders, or admin-account creation run during requests or builds. Run migrations separately against the intended database before release. Use `npm run test:deployment` to verify static-file exclusions.

After committing these deployment files, redeploy from Vercel and verify `/up`, `/login`, built assets, sign-in, a small upload and the production Google callback. A successful local build does not verify the Vercel runtime or live services.

The upload path sends the entire file through Laravel. Vercel Functions have a 4.5 MB request-body limit, including multipart overhead. The app automatically limits uploads to 4 MB when VERCEL is set; the normal deployment retains its 100 MB default. UPLOAD_MAX_KB can lower either limit. Stay within the function duration limit. Production support for larger uploads needs a direct browser upload flow or a separate persistent backend. Database queue workers elsewhere cannot access files staged only in a Vercel invocation's temporary directory. Do not assume this import file fixes those constraints.

No production deployment or live service verification was performed while preparing these files.

References:
- https://vercel.com/docs/environment-variables/managing-environment-variables
- https://vercel.com/docs/functions/runtimes
- https://vercel.com/docs/functions/limitations
- https://github.com/vercel-community/php
