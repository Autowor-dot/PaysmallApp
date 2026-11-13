# LipaLink (PaySmallApp)

Installment marketplace: static HTML/JS frontend + PHP/MySQL API in `api/`.

## Project layout

| Area | Files |
| --- | --- |
| Public pages | `index.html`, `how-it-works.html`, `marketplace.html`, `for-sellers.html`, `register-store.html`, `security.html`, `terms.html`, `privacy.html` |
| Auth | `login.html`, `register.html`, `forgot-password.html`, `reset-password.html` |
| Customer app | `dashboard.html`, `agreement.html` (wizard), `agreements.html`, `pay.html`, `transactions.html`, `profile.html`, `settings.html` |
| Diagnostics | `api-status.html` (checks every endpoint from the browser), `api/health.php` |
| Shared assets | `assets/css/app.css` (app shell), `assets/css/public.css`, `assets/css/auth.css`, `assets/js/app-shell.js`, `assets/js/public-nav.js` |
| Backend | `api/*.php`, `api/schema.sql`, `api/.htaccess` |

## Backend endpoints

| Endpoint | Method | Auth | Purpose |
| --- | --- | --- | --- |
| `api/health.php` | GET | no | Connection / schema readiness report |
| `api/install.php?key=` | GET | install key | Applies `schema.sql` (idempotent) |
| `api/register.php` | POST | no | Create customer account |
| `api/login.php` | POST | no | Start session |
| `api/logout.php` | POST | session | End session |
| `api/password-reset.php` | POST | no | `request` / `validate` / `reset` |
| `api/register-store.php` | POST | optional | Register a seller store |
| `api/products.php` | GET | no | Marketplace listings (`?q=`, `?category=`, `?id=`) |
| `api/profile.php` | GET/POST | session | Current user; `update_profile`, `change_password`, `update_settings`, `delete_account` |
| `api/dashboard.php` | GET | session | Summary, plans, upcoming payments, recent receipts, notifications |
| `api/transactions.php` | GET | session | Full payment history |
| `api/notifications.php` | GET/POST | session | List; `mark_read`, `mark_all_read`, `delete` |
| `api/sellers.php?q=` | GET | session | Registered seller search |
| `api/agreement.php` | GET/POST | session | List sent/received, view one, create (wizard payload), `respond` (seller), `cancel` (customer) |
| `api/pay.php` | POST | session | Create a pending payment and reference |
| `api/payments.php` | GET/POST | session | Poll a payment (`?code=`); `simulate` (development only) |
| `api/payment-webhook.php` | POST | webhook secret | Provider callback: settles a payment (`completed` / `failed`) |

All responses are JSON `{ success, message?, data? }`. Unauthenticated calls return HTTP 401, which the frontend turns into a redirect to `login.html`.

## Setup (shared PHP host such as InfinityFree)

1. Upload the whole folder to the web root. Pages and `api/` must share one origin so the session cookie works.
2. Copy `api/config.local.example.php` to `api/config.local.php` (git-ignored, upload it by hand) and set:
   - `db_host`, `db_user`, `db_pass`, `db_name`
   - a unique `install_key` and `webhook_secret`
   - `app_env`: `development` while testing, `production` for launch
3. Open `https://your-domain/api/install.php?key=YOUR_INSTALL_KEY` once. It creates all tables and seeds sample products.
4. Open `https://your-domain/api-status.html`. Everything should be green; the Session card turns green after you log in.
5. Register at `register.html`, log in, create an agreement, open `pay.html` from an upcoming payment. In development mode the success screen has a "Simulate confirmation" button that settles the payment the same way the webhook does.

## Payment provider integration

`pay.html` creates a pending transaction via `api/pay.php` and then polls `api/payments.php?code=TX-...`. Configure your Mobile Money / bank provider to call:

```
POST https://your-domain/api/payment-webhook.php
X-LipaLink-Signature: <webhook_secret>
Content-Type: application/json

{ "transaction_code": "TX-20261113-7F3A9C", "status": "completed", "provider_reference": "MP123456" }
```

Settling a payment marks the installment paid, updates the plan balance and next due date, closes the plan when fully paid and notifies the customer.

## Local preview without PHP

The HTML pages degrade gracefully when the API is unreachable (cached session, sample products, empty states). Any static file server works for layout checks; the full flow needs a PHP host.

## Admin console (`/admin`)

A separate, session-based administration panel for the site owner and staff. It reuses the
site database (`api/config.php`) but has its own accounts (`admin_users`) and its own
session cookie, so customer/seller logins never grant admin access.

**First-time setup**
1. Deploy, then open `/admin/setup.php`.
2. Enter the `install_key` from `api/config.local.php`, your name, email and a password
   (12+ characters, letters and numbers). This applies
   `admin/migrations/001_admin_panel.sql` and creates the first **Super Admin**.
   The page stops creating accounts once an administrator exists; there are no default credentials.
3. Sign in at `/admin/login.php`. Add further Admin / Support Admin accounts under **Administrators**.

**Migration 001 is additive only.** It creates `admin_users`, `admin_login_attempts`,
`admin_audit_log`, `site_settings`, `email_log`, `admin_cases`, `admin_case_notes`, and adds
`users.status/status_reason/status_changed_at/last_login_at`, `sellers.status_reason` and
`transactions.verified_via`. No existing column is renamed and no data is deleted.
Re-running it (from `setup.php`) is safe.

**Roles**: Super Admin (everything, incl. admins and settings), Admin (manage users, sellers,
agreements, payment investigations, reports, view logs), Support Admin (read-only views plus
complaint/dispute cases).

**Money terminology used everywhere in the panel**
- *Expected* – total agreed value of accepted agreements / scheduled installments.
- *Recorded* – transactions with status `completed`.
- *Verified* – recorded **and** confirmed by the payment provider webhook (`verified_via = 'provider'`).
  Simulator confirmations are labelled *Simulated (test)*. Admins can never mark a payment successful.

**Maintenance mode** (Website Settings) blocks the customer API with HTTP 503 and, through
`maintenance.flag` + the root `.htaccess`, redirects public pages to `maintenance.html`.
`/admin`, `api/health.php`, `api/site-status.php` and the payment webhook keep working.
