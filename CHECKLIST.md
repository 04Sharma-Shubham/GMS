# GMS development checklist

- [x] Project layout, `.htaccess`, `.env.example`, and shared-hosting guards
- [x] MySQL schema with tenant `gym_id`, keys, and money checks
- [x] Importable demo data in `database/database.sql`
- [x] PDO connection, sessions, CSRF, password hashing, and roles
- [x] Registration, login, logout, forgot-password flow, and onboarding
- [x] Dark lime design system, sidebar, toasts, and responsive navigation
- [x] Dashboard KPIs and charts from SQL
- [x] Members, profile tabs, search, archive, and QR codes
- [x] Plans, assign, renew, upgrade, downgrade, freeze, and history
- [x] Manual payments, balances, void, and audit log
- [x] Invoices, print view, Dompdf output, and protected download
- [x] WhatsApp Cloud API service with demo mode and message logs
- [x] Cron expiry, membership reminders, payment reminders, and cleanup
- [x] Reminder claim rows so the same notice is not sent twice
- [x] Attendance, trainer and staff tools, expenses, reports, CSV, notifications, workouts, settings
- [x] Desk assistant that answers membership and invoice questions from the gym database
- [x] Syntax check, unit checks, and a database workflow test
