# LipaLink / PaySmallApp — Database structure

MySQL / MariaDB (InfinityFree runs MariaDB 11.x). Character set `utf8mb4`, engine InnoDB.
All access goes through MySQLi prepared statements in `api/config.php` (`db_select`, `db_execute`).

Two migration files, both **additive and idempotent** (safe to re-run):

| File | Applied by | Contents |
| --- | --- | --- |
| `api/schema.sql` | `api/install.php?key=<install_key>` | Core tables (001) + seller hub / contact / reminders (002) |
| `admin/migrations/001_admin_panel.sql` | `admin/setup.php` (install key) | Admin accounts, audit log, settings, email log, cases, status columns |

## Core tables

### users — customer (and seller-owner) accounts
| Column | Type | Notes |
| --- | --- | --- |
| id | INT PK | |
| full_name | VARCHAR(120) | |
| phone | VARCHAR(20) UNIQUE | normalised `2557XXXXXXXX` |
| email | VARCHAR(190) UNIQUE | lower-case |
| password_hash | VARCHAR(255) | `password_hash()` (bcrypt/argon) |
| settings_json | TEXT | notification preferences from settings.html |
| status | ENUM active/suspended/deactivated | set by admins; blocked at login and on every API call |
| status_reason, status_changed_at, last_login_at | | admin migration |
| created_at, updated_at | TIMESTAMP | |

### sellers — merchant stores (one row per store; `user_id` links to the owner's account)
| Column | Notes |
| --- | --- |
| id, user_id (FK users, SET NULL) | linked at registration when signed in, or by matching email at next login |
| business_name, full_name, phone UNIQUE, email UNIQUE, location, category, description | |
| payout_method ENUM mobile_money/bank | |
| status ENUM pending/verified/suspended, status_reason | verification by admins |
| default_deposit_percent, default_frequency, default_installments, delivery_policy | installment defaults (002) |

### products — marketplace listings
id, seller_id (FK sellers, SET NULL), slug UNIQUE, name, description, category, price, installment_amount,
installment_frequency ENUM daily/weekly/biweekly/monthly, installments_count, image_url, icon, stock, featured, status ENUM active/inactive.

### agreements — installment agreement proposals and their lifecycle
| Column | Notes |
| --- | --- |
| id, agreement_code UNIQUE (`PSA-YYYYMMDD-XXXXXX`) | reference shown to both parties |
| customer_id (FK users, CASCADE), seller_id (FK sellers, SET NULL) | |
| seller_type ENUM registered/unregistered, seller_name, seller_email, seller_phone | unregistered sellers are invited by email |
| product_name, total_price, initial_deposit, number_of_installments, payment_frequency | agreed terms (never changed after acceptance) |
| status | `pending_seller_approval` → `accepted` / `declined` / `cancelled` |
| response_note, responded_at | seller's response |
| payload_json | the complete wizard payload (product, payment schedule, delivery, terms) used for the printable document |

### installment_plans — the live payment plan created with each agreement
id, customer_id, agreement_id (FK agreements), product_name, seller_name, product_image, total_price, amount_paid,
next_payment_amount, next_payment_due_date, status ENUM pending/active/overdue/completed/cancelled.
`pending` until the seller accepts; `completed` when amount_paid reaches total_price.

### installment_payments — one row per scheduled installment
id, plan_id (FK plans, CASCADE), customer_id, installment_number, amount_due, due_date,
status ENUM upcoming/due/overdue/paid, paid_at. Statuses roll over daily (`api/tasks-lib.php`).

### transactions — every payment attempt / receipt
| Column | Notes |
| --- | --- |
| id, transaction_code UNIQUE (`TX-YYYYMMDD-XXXXXX`) | receipt number |
| customer_id, plan_id, payment_id | which installment it settles |
| product_name, seller_name, amount, payment_method, payer_phone | |
| provider_reference | gateway / M-Pesa reference |
| status ENUM pending/completed/failed | `pending` until settled |
| verified_via | `provider` (gateway webhook), `manual` (seller/admin recorded), `simulator` (dev), NULL = unverified |
| recorded_by_type ENUM customer/seller/admin/system, recorded_by_id, note | who recorded it (002) |
| created_at, confirmed_at | |

### notifications — in-app notifications
id, user_id (FK users, CASCADE), type (agreement/payment/reminder/success/security/general), title, message, is_read, created_at.

### password_resets — hashed one-hour reset tokens
id, user_id, token_hash CHAR(64) UNIQUE, expires_at.

### contact_messages (002) — website contact form
id, user_id, name, email, phone, subject, message, status ENUM new/read/replied/archived, ip_address, created_at.

### installment_reminders (002) — one reminder per installment per kind
payment_id (FK), kind ENUM upcoming/due_today/overdue, sent_at. UNIQUE (payment_id, kind).

### scheduled_runs (002) — last run of the scheduled tasks
task PK, last_run, last_result.

## Admin tables (`admin/migrations/001_admin_panel.sql`)

| Table | Purpose |
| --- | --- |
| admin_users | administrator accounts: role ENUM super_admin/admin/support, status, lockout fields, last login |
| admin_login_attempts | per-IP rate limiting and failed-login audit |
| admin_audit_log | append-only activity log (admin, action, target, details JSON, IP) |
| site_settings | key/value website settings (branding, contact, maintenance, registration, notification switches, admin security) |
| email_log | every outgoing email attempt: recipient, subject, context, related record, status sent/failed/disabled, error |
| admin_cases, admin_case_notes | disputes / suspicious agreements / complaints with notes |

## Relationships (summary)

```
users 1─∞ sellers            (owner account)
users 1─∞ agreements         (customer)
sellers 1─∞ agreements       (registered seller) · unregistered sellers matched by seller_email
agreements 1─1 installment_plans 1─∞ installment_payments
installment_plans 1─∞ transactions ─1 installment_payments (payment_id)
users 1─∞ notifications, password_resets, contact_messages
installment_payments 1─∞ installment_reminders
```

## Backups

`admin/backup.php` (Super Admin) or `api/backup.php?key=<install_key>` writes a full SQL dump to `/backups`
(web access denied). Restore by importing the file in phpMyAdmin.
