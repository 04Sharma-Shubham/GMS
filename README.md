# GMS — Gym Management System

New to the app? Read [GUIDE.md](GUIDE.md) for a beginner walkthrough and the WhatsApp setup steps. Owners can also open **Desk** in the sidebar and ask who still has membership time left, who owes money, and what a member’s invoices say. Those answers are read from this gym’s database.

GMS is a multi-gym management application for PHP 8.2+ and MySQL 8 (or MariaDB 10.4+) on shared hosting. Gym staff record payments by hand. There is no payment gateway, payment link, or online collection.

Each gym’s members, plans, invoices, attendance, expenses, and messages stay inside that gym. Roles are Gym Owner, Manager, Receptionist, and Trainer.

## What it does

- Members, plans, renewals, upgrades, downgrades, and freezes
- Manual payments: Paid, Partially Paid, and Unpaid, calculated on the server
- Invoices with print view and PDF download when Dompdf is installed
- WhatsApp Cloud API, plus a labeled demo mode and a click-to-chat link when credentials are missing
- QR and manual attendance
- Trainers, salaries, commissions, and schedules
- Expenses, reports, CSV export, and notifications
- Cron reminders that will not send the same reminder twice on the same day

## Requirements

- PHP 8.2 or newer with PDO MySQL, curl, mbstring, openssl, fileinfo, gd, and bcmath
- MySQL 8 or MariaDB 10.4+
- Apache with `mod_rewrite`, or another server that sends requests to `router.php`
- Composer only if you need to refresh `vendor/`

The `vendor/` directory is part of the project so Hostinger can run PDF invoices, QR codes, and PHPMailer without SSH.

## Install on Hostinger

1. In hPanel, create a MySQL database and a user. Note the host (often `localhost`), database name, user, and password.
2. Open phpMyAdmin, select that database, then Import `database/database.sql`. Do not create a second database from the file. The script does not include `CREATE DATABASE` or `USE`.
3. Upload the project so `index.php` and `.htaccess` sit in the site’s document root (`public_html`). If the site must live in a subfolder, set `APP_BASE` to that path (for example `/gms`) and change `RewriteBase` in `.htaccess` to the same path.
4. Copy `.env.example` to `.env` and set:

```
APP_ENV=production
APP_DEBUG=0
APP_URL=https://your-domain.example
APP_BASE=
APP_KEY=a-long-random-string
APP_TIMEZONE=Asia/Kolkata
DB_HOST=localhost
DB_PORT=3306
DB_NAME=your_database
DB_USER=your_user
DB_PASS=your_password
CRON_SECRET=another-long-random-string
```

`APP_KEY` encrypts the WhatsApp token. Changing it later makes the stored token unreadable.

5. Confirm these folders are writable by PHP: `storage/invoices`, `storage/logs`, `storage/receipts`, `assets/uploads/members`, `assets/uploads/logos`.
6. Open `/login`. The import includes a demo gym. Create your own gym from `/register` and complete the six-step setup. Delete or ignore the demo gym before real members use the site.

Local PHP settings in `.user.ini` raise the upload limit for logos and receipts. Hostinger reads that file for the domain.

## Demo accounts

The sample gym is PulseForge Athletics (`pulseforge-demo`). Every sample password is `Demo@12345`.

| Email | Role |
| --- | --- |
| owner@pulseforge.demo | Gym Owner |
| manager@pulseforge.demo | Manager |
| desk@pulseforge.demo | Receptionist |
| trainer@pulseforge.demo | Trainer |

Sample dates are anchored to 11 November 2026 so the dashboard, expiring plans, and reminder examples line up with that day. The demo gym is labeled in the interface. WhatsApp for that gym starts in demo mode.

`php database/seed.php` prints these logins after the database is imported. `php database/seed.php --apply` re-imports `database.sql` with the `mysql` client. It refuses to run when `APP_ENV=production` unless you pass `--force`.

## Cron jobs

The jobs are normal PHP scripts. They do not need a background worker. On Hostinger, use the cron path to PHP shown in hPanel.

```
10 0 * * * /usr/bin/php /home/USER/public_html/cron/membership_expiry.php
0 8 * * * /usr/bin/php /home/USER/public_html/cron/membership_reminders.php
0 9 * * * /usr/bin/php /home/USER/public_html/cron/payment_reminders.php
0 2 * * * /usr/bin/php /home/USER/public_html/cron/cleanup.php
```

If the host only allows a URL, call the same files with the secret from `.env`:

```
https://your-domain.example/cron/membership_expiry.php?key=YOUR_CRON_SECRET
https://your-domain.example/cron/membership_reminders.php?key=YOUR_CRON_SECRET
https://your-domain.example/cron/payment_reminders.php?key=YOUR_CRON_SECRET
https://your-domain.example/cron/cleanup.php?key=YOUR_CRON_SECRET
```

A missing or wrong key returns 403. CLI runs do not need the key.

Suggested order: expiry just after midnight in the gym’s local offset, membership reminders at 08:00, payment reminders at 09:00, cleanup at 02:00. Times in Hostinger cron are the server clock. Adjust them if the server is not on the gym’s timezone.

What each job does:

- `membership_expiry.php` ends active plans whose end date has passed, unfreezes a plan that hit the freeze cap, and writes an in-app notice once.
- `membership_reminders.php` sends WhatsApp expiry reminders 7 days before, 3 days before, and on the end date. Change the days under Settings → Reminders.
- `payment_reminders.php` reminds opted-in members about unpaid and partially paid invoices on days 1, 3, and 7, then repeats every 7 days. A paid invoice is skipped.
- `cleanup.php` removes expired password resets, old rate-limit rows, and cron history older than 30 days.

A reminder is claimed in `reminder_logs` before it is sent. The unique key is gym, member, type, reference, and date, so a second run the same day does not send it again. A message is stored as sent only when Meta returns a message id. Demo mode is stored as `demo`.

## WhatsApp Cloud API

Settings → WhatsApp stores the Phone Number ID and access token. The token is encrypted with `APP_KEY` and is not printed in HTML or JavaScript.

1. In Meta Business Manager, add a WhatsApp Business account and a phone number.
2. Create a permanent system-user token with `whatsapp_business_messaging`.
3. Copy the Phone Number ID into GMS. The API version defaults to `v21.0`.
4. Turn off demo mode only after the token is saved.
5. In Automation, set the approved template name and language for each message: welcome, expiry reminder, payment reminder, payment confirmation, invoice document, renewal confirmation, and announcement.
6. Members must have WhatsApp consent checked. Without it, the reminder is logged as skipped.
7. For the webhook, set the callback URL to `https://your-domain.example/api/whatsapp_webhook` and the verify token shown in WhatsApp settings. Delivery updates are matched by message id.

Business-initiated messages use approved templates. If demo mode is on, or the token is missing, GMS writes a demo log and shows a `wa.me` link. That link is a manual click-to-chat fallback. It is not an automatic send.

Invoice files are shared with a signed link at `/media/invoice?t=...` that lasts seven days. Meta must be able to fetch that URL over public HTTPS, so `APP_URL` must be the live `https://` address before invoice documents can be attached. Until then, staff can download the PDF and use the click-to-chat link.

## Payments and invoices

Staff choose Cash, UPI, Bank Transfer, Card, or Other, then enter the amount received. The server sums recorded payments:

- paid equals the total → Paid
- some amount, but less than the total → Partially Paid
- nothing received → Unpaid

An invoice is not treated as settled just because it was generated. Overpayment is rejected. To correct an amount, void the payment with a reason and record a new one. Method, date, reference, and notes can be edited and are written to the audit log.

Invoice numbers are `PREFIX-YEAR-SEQUENCE`, locked per gym. PDFs are stored in `storage/invoices/{gym_id}/` and the download route checks the signed-in gym. If Dompdf is missing, use the print view and the browser’s Save as PDF.

## Attendance

Each member has a QR code. The desk can also check in by name, code, or phone. Archived, suspended, frozen, expired, and not-yet-started memberships are refused. An owner or manager can override that refusal when the gym allows it. A member who is still checked in cannot be checked in again.

## Local development

```
php -S 127.0.0.1:8080 router.php
```

Create a database named `gms`, import `database/database.sql`, and point `.env` at it. Without WhatsApp credentials the app stays in demo mode. Without SMTP, a local forgot-password request writes the reset link to `storage/logs/mail.log` and shows it on the page.

```
php tests/selftest.php
php tests/workflow.php
```

`workflow.php` needs the imported demo data. It registers a second gym, assigns a plan, records a partial payment and the balance, checks tenant isolation, generates an invoice, checks in a member, and runs the reminder jobs twice.

## Production checklist

- Set `APP_ENV=production` and `APP_DEBUG=0`.
- Replace `APP_KEY` and `CRON_SECRET` with long random values.
- Use a database user that can only access this database.
- Serve the site over HTTPS and set `APP_URL` to that HTTPS origin.
- Confirm `.env`, `config/`, `includes/`, `database/`, `storage/`, `vendor/`, and `tools/` are not web-readable. `.htaccess` blocks them on Apache.
- Confirm uploaded PHP files in `assets/uploads` cannot execute.
- Remove or stop using the PulseForge demo logins before real member data is added.
- Leave WhatsApp in demo mode until the Phone Number ID, token, and approved template names are saved.
- Schedule the four cron commands and open Automation once to confirm a `cron_runs` row.
- Do not commit `.env`.

## Layout

`index.php` is the dashboard. Feature pages live in `modules/`. JSON endpoints live in `api/`. Shared rules live in `includes/services/`. Money is calculated with bcmath in `includes/functions.php` and is never trusted from the browser.
