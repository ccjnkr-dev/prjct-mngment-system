# CCJNKR IT Solutions

Internal project planning workspace: Laravel 12, Livewire 3, Tailwind CSS 4, Supabase PostgreSQL. Includes clients/projects, membership, task checklists, interactive Gantt, Markdown notes, Google Drive assets, activity feed and invitation-only accounts.

## Setup

Requires PHP 8.4+ with PDO PostgreSQL, mbstring, XML/DOM, fileinfo, cURL and ZIP; Composer 2; Node 22+. The container uses PHP 8.4. Enable SQLite too for local tests.

```bash
composer install
cp .env.example .env
php artisan key:generate
npm ci
npm run build
```

Set Supabase, SMTP and Google values in `.env`, then:

```bash
php artisan migrate
php artisan team:admin admin@your-company.com --name="Your Name"
php artisan serve
```

The admin command prompts privately for a password. There are no default accounts. Keep these running in separate terminals:

```bash
php artisan queue:work --tries=3 --timeout=900
php artisan schedule:work
```

Use `npm run dev` for frontend changes. The development `.env` may use SQLite and log mail until credentials are supplied. `MAIL_MAILER=log` writes invitation/reset URLs into `storage/logs/laravel.log`; use configured SMTP for real delivery.

## Supabase

Copy the PostgreSQL **session pooler** values from your Supabase Connect panel into DB_HOST, DB_PORT (5432), DB_DATABASE, DB_USERNAME and DB_PASSWORD. Set DB_CONNECTION=pgsql and DB_SSLMODE=require. Use the database password, not an API key. A direct connection also works when your host supports its network requirements.

Laravel handles authentication; Supabase Auth and Storage are not used. Browser clients never access PostgreSQL directly. Migrations enable RLS and revoke anon/authenticated privileges on application tables. The Laravel connection must use an owner/backend role with appropriate RLS bypass. Disable Supabase Data API if unused. Never expose database credentials or APP_KEY.

## Invitations and permissions

Configure an SMTP provider, verified sender, and MAIL_* settings. Admins create clients/projects, select members, invite users, and manage roles. Members collaboratively edit only their assigned projects. Deactivation blocks existing sessions. Invitations expire in three days; resend rotates the link and revoke invalidates it. At least one active admin is required.

Progress = Done tasks / non-milestone tasks. Checklists do not automatically complete tasks. Milestones use one day and are excluded from progress. Tasks must have valid date ranges and active project-member assignees. The timeline supports drag/resize; keyboard users edit dates through Tasks. Notes support sanitized Markdown. Project dates use Asia/Manila. Dependencies, automatic rescheduling, billing and client accounts are not included.

## Google Drive

1. Enable Drive API in a Google Cloud project and configure OAuth consent.
2. Create a Web OAuth client. Register `APP_URL/settings/drive/callback` as the redirect URI, e.g. `http://localhost:8000/settings/drive/callback` locally.
3. Put GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET in `.env`.
4. Keep GOOGLE_DRIVE_FOLDER_ID set to `13uiMpoQomushJ_-Dqv85wM9VdZNROjTr`.
5. As an admin, open Integrations and connect a Google account with edit access to that parent folder.

The integration requests the Drive scope to use the pre-existing parent folder. Configure allowed test users in Google during development; external production use may require Google verification. Test-mode refresh tokens can expire. Prefer an appropriate Workspace internal consent configuration when available. The refresh token is encrypted server-side. Members do not need individual upload credentials, but still need Drive sharing permission to open links. No sharing permissions are changed.

Each project gets a subfolder on first upload. Files up to 100 MB are privately staged and uploaded by the queue worker using 5 MB chunks. Reserved file IDs prevent duplicate completed uploads on retries. A failed transfer restarts with the same file ID; after three failures the file is marked failed and temporary data is deleted. Upload the source again to retry a terminal failure. The UI displays browser progress and polls queued/uploading/ready/failed status every five seconds. Existing Drive contents are not imported automatically; attach existing links manually. Archiving projects preserves Drive files. Disconnect removes the local credential; revoke the application in Google account settings to revoke consent.

Web and worker processes must share private storage. Configure PHP upload_max_filesize=100M and post_max_size=105M, plus the web server request limit. DB_QUEUE_RETRY_AFTER=1200 must exceed the 900-second worker timeout. Keep the queue worker running; avoid synchronous uploads in production.

## Tests

```bash
php artisan test
npm run build
vendor/bin/pint --test
php artisan route:list
```

Tests use isolated in-memory SQLite and mocked Drive/mail. To test PostgreSQL, use a **dedicated empty test database** with `.env.testing` settings and `php artisan test --configuration=phpunit.pgsql.xml`. Tests reset that database. Never use production credentials. Real Google consent/uploads, SMTP delivery and Supabase connectivity require your service credentials and must be verified before launch.

## Deployment

Included Docker Compose runs Nginx, PHP-FPM, a worker and scheduler against external Supabase. Set production `.env`: APP_ENV=production, APP_DEBUG=false, HTTPS APP_URL, SESSION_SECURE_COOKIE=true, plus SMTP/Google/DB values. Generate APP_KEY once and retain it across releases.

```bash
docker compose up -d --build
docker compose exec app php artisan migrate --force
docker compose exec app php artisan team:admin admin@your-company.com --name="Your Name"
```

Port 8080 must be behind HTTPS for production. Configure trusted proxies in Laravel for your actual TLS reverse proxy. Back up PostgreSQL before migrations. Restart queue workers after updates. Monitor failed_jobs, application logs and failed asset states. Back up APP_KEY securely; encrypted Drive credentials depend on it. Verify `/up`, sign-in, a real invitation and an upload in a clearly named test project before launch. Deployment/container build and live services require verification on your target host.

## Manual user creation and client directory

Admins can use **Team members → Add user** to create an active account directly with a temporary password (at least 10 characters). Share that password securely. The account must replace it with a different password on first sign-in before accessing any workspace pages or actions, including Livewire requests. Existing accounts are unaffected. Email invitations remain available. Completing a verified password reset also satisfies the password-change requirement.

The **Clients** module is available to administrators. It includes searchable, paginated company records with business type, industry, contact person/title, email, phone, website, address, active/inactive status, notes and linked projects. Existing project-client associations are preserved.

After updating, run `php artisan migrate` and `npm run build`. Normal forms show a busy state and disable repeat submissions until navigation; upload errors unlock their forms for retry. Browser back/forward restoration resets the busy state. Timeline loading and saving show the shared loading indicator.

Admins can delete clients from the directory or client profile after confirming the action. Clients with any linked projects, including archived projects, cannot be deleted; mark them inactive instead.

## Project deletion and appearance

Administrators can delete a project from its header after confirmation. This removes its database records (tasks, checklists, notes, membership, asset metadata and activity), cancels queued uploads, and removes private staged upload files. Existing Google Drive folders/files and the client record are preserved. Deletion is blocked while an upload is running; retry after it finishes or fails. Queued jobs for deleted projects exit without contacting Drive.

The header and sign-in pages include a light/dark mode button. The initial appearance follows the operating system. An explicit selection is saved in this browser and applied before the page styles load, including after sign-out and refresh. Cross-tab changes are synchronized. If browser storage is unavailable, switching still works for the current page.
