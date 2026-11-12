# GMS beginner guide

This guide is for a gym owner using GMS for the first time. It also has the full WhatsApp setup, written in the order you should do it.

GMS stores members, plans, attendance, expenses, and invoices. Payments are recorded by you or your staff. There is no online payment button. A membership is not “paid” just because an invoice exists.

## 1. Create your gym

1. Open the site and choose **Create an owner account**.
2. Enter your name, email, a password with at least 8 characters including a letter and a number, your gym name, and a phone number.
3. Sign-in happens automatically and the setup wizard starts. There are six steps:

| Step | What you enter | Why it matters |
| --- | --- | --- |
| 1. Gym identity | Gym name and logo | Printed on invoices and shown in the sidebar |
| 2. Address | Phone, email, address, city, timezone | Printed on the invoice and used for “today” |
| 3. Tax | GST on or off, tax name, percent, GSTIN | Added to new invoices when tax is on |
| 4. Invoices | Invoice prefix, member prefix, currency, footer, terms | Invoice numbers look like `INV-2026-0001` |
| 5. WhatsApp | Leave demo mode on for now | You can connect Meta later. The steps are in section 8 |
| 6. First plan | Name, number of days, price | Example: Monthly, 30 days, 1500 |

4. After step 6 you land on the dashboard. That gym’s data is separate from every other gym in the same database.

If you are only looking around, the sample gym **PulseForge Athletics** is already loaded. Sign in with `owner@pulseforge.demo` and password `Demo@12345`. The other sample logins use the same password: `manager@pulseforge.demo`, `desk@pulseforge.demo`, and `trainer@pulseforge.demo`. The yellow bar means this is sample data. Register your own gym before you add real members.

## 2. What each person can do

| Role | Typical person | Can do |
| --- | --- | --- |
| Gym Owner | You | Everything, including tax, WhatsApp secrets, and voiding a payment |
| Manager | Floor manager | Members, money, staff, reports, announcements |
| Receptionist | Front desk | Members, payments, invoices, check-in. Cannot change gym settings or void a payment |
| Trainer | Coach | Their own members, attendance, and workouts. They do not see invoice amounts |

Create staff from **Staff**. Each person gets an email and a role. They sign in with that email.

## 3. A normal day at the desk

Use the left menu. On a phone, tap the menu icon. The bottom bar has Home, Members, Check-in, and Pay.

### Add a member

1. Open **Members** and choose **Add member**.
2. Fill the name and phone. Email, photo, address, and emergency contact are optional.
3. Pick a trainer if you want that coach to see this member.
4. Tick **Member agrees to WhatsApp messages** only if they actually agreed. Reminders will not go out without this tick.
5. Save. The member ID, such as `PF-1013`, is created for you. Open the profile to see the QR code used at the door.

### Give them a plan

1. Open the member and choose the **Membership** tab.
2. Pick the plan, leave the type as **New**, and set the start date.
3. Discount is optional. Leave **Amount collected now** blank if they have not paid yet.
4. If they paid part of it, type only the amount you actually received and choose Cash, UPI, Bank Transfer, Card, or Other.
5. Save. GMS creates a new subscription and a new invoice. The end date is calculated from the plan length.

Renewal, upgrade, and downgrade also create a **new** subscription and a **new** invoice. The old invoice stays in the history. Tick **Apply unused value as a discount** on an upgrade or downgrade if you want to credit the unused days of the current plan. That credit cannot be larger than the new plan price.

**Freeze** pauses an active plan. The member cannot check in while it is frozen. **Unfreeze** adds the frozen days back onto the end date, up to the freeze limit in Settings (60 days unless you change it).

### Take a payment

1. Open **Payments** or the invoice.
2. Choose the invoice. The balance shown is calculated by the server.
3. Enter the amount received, the date, and the method. A reference number is optional.
4. Save.

Status rules:

- Amount received equals the total: **Paid**
- Some money received, balance still left: **Partially Paid**
- Nothing received: **Unpaid**

You cannot record more than the balance. To fix a wrong amount, void that payment with a reason and record a new one. Both actions are written to the activity log. Changing the method, date, or note does not change the amount.

After a payment, open the invoice. Download the PDF or use Print. If the member opted in, you can share it on WhatsApp from the invoice page.

### Check someone in

1. Open **Attendance**.
2. Choose the member, or type their member ID or phone, then **Check in**.
3. Archived, suspended, frozen, expired, and not-yet-started memberships are refused.
4. An owner or manager can tick the override when the gym allows it.
5. Use **Check out** when they leave. A person who is still inside cannot be checked in again.

The QR kiosk reads the code on the member profile. Each member has their own code.

## 4. Money, reports, and alerts

- **Invoices** lists every bill and its Paid, Partially Paid, or Unpaid state.
- **Expenses** is rent, salaries, and other costs. Add a category first if you need a new one.
- **Reports** shows collections, expenses, and net for the dates you pick. Net can be negative. That number is honest.
- **Export** on Settings downloads CSV files for members, payments, invoices, attendance, and expenses.
- The bell and **Alerts** show expiring plans, payments, new members, and failed WhatsApp sends. Open an alert to mark it read.

The dashboard date filter changes the charts. “This month” means from the first of the month through today in the gym’s timezone.

## 5. Ask the desk

Open **Desk** in the sidebar. Type a question in plain language. It answers from this gym’s database only, and it will say when it does not understand.

Examples:

- Whose membership is still running?
- Who still owes money?
- Billing for Meera Iyer
- Who is here today?
- Who joined this month?
- Who is frozen?

Membership answers include the days left. Billing answers include the invoice number, what has been paid, and the balance, with a link to the invoice. A trainer only sees their own members and does not see amounts.

This is not a chat model that invents numbers. If a balance is ₹1,270, that is the sum of recorded payments subtracted from the invoice total.

## 6. Settings you will actually use

Open **Settings**.

- **Gym** — name, address, logo, timezone.
- **Invoices & tax** — GST and the prefixes used for invoice and member numbers.
- **WhatsApp** — section 8 below. Only the owner can save the token.
- **Reminders** — which days to nudge people. Defaults are 7, 3, and 0 days before a plan ends, and payment reminders when an invoice is 1, 3, and 7 days old, then every 7 days. The same person is not messaged twice on the same day for the same reason.
- **Account** — your name and password. You must type the current password to set a new one.
- **Activity** — who changed what.
- **Export** — the CSV downloads.

## 7. What happens automatically

These jobs run only when the host’s cron calls them. They do not run by themselves on a laptop.

| Job | What it does |
| --- | --- |
| `cron/membership_expiry.php` | Marks plans past their end date as expired, and unfreezes a plan that hit the freeze cap |
| `cron/membership_reminders.php` | WhatsApp expiry reminders for opted-in members |
| `cron/payment_reminders.php` | WhatsApp reminders for unpaid and partially paid invoices |
| `cron/cleanup.php` | Deletes old password-reset links, old rate limits, and old cron history. Retries WhatsApp sends that failed for a temporary reason |

On Hostinger, use the PHP path shown in hPanel:

```
10 0 * * * /usr/bin/php /home/USER/public_html/cron/membership_expiry.php
0 8 * * * /usr/bin/php /home/USER/public_html/cron/membership_reminders.php
0 9 * * * /usr/bin/php /home/USER/public_html/cron/payment_reminders.php
0 2 * * * /usr/bin/php /home/USER/public_html/cron/cleanup.php
```

If the host only allows a web address, add `?key=` and the `CRON_SECRET` from `.env`. A wrong key is rejected. The same commands are shown on the **Automation** page.

## 8. WhatsApp, step by step

You can use GMS with no WhatsApp account. Demo mode prepares a click-to-chat link and writes a log. That link is a manual send. The screen will not say the message was delivered.

Automatic sending needs the official WhatsApp Cloud API from Meta, an approved template for each message type, and the member’s consent tick.

### A. Create the Meta app

1. Go to [Meta for Developers](https://developers.facebook.com/) and create an app of type **Business**.
2. Add the **WhatsApp** product.
3. In WhatsApp, open **API Setup**.
4. Copy the **Phone number ID**. This is not your mobile number. It is a long ID next to the test or business number.
5. Copy the **WhatsApp Business Account ID** if it is shown.
6. Create a permanent token: Business Settings → Users → System users → add a system user → assign the WhatsApp account → generate a token with `whatsapp_business_messaging`. Temporary tokens from the API Setup page expire in about 24 hours. Do not use those for a live gym.
7. Copy the **App secret** from App Settings → Basic. GMS uses it to check that webhook calls really came from Meta. You can leave it blank at first.

Keep the token in a password manager. GMS stores it encrypted and will not show it again. If you change `APP_KEY` in `.env` later, you must paste the token again.

### B. Create the message templates

Meta must approve a template before GMS can start a chat. In WhatsApp Manager, create one template per row. Use category **Utility** except for announcements, which are usually **Marketing**. Language should match what you type in GMS, usually `en`.

The body variables must be in this order. `{{1}}` is the first variable.

| GMS key | When it is sent | Body variables, in order |
| --- | --- | --- |
| `welcome` | New member saved, if they opted in | 1 member name, 2 gym name |
| `expiry_reminder` | Cron, on the days you configured | 1 member name, 2 plan name, 3 expiry date, 4 “in 7 days” or “today” |
| `payment_reminder` | Cron, while a balance remains | 1 member name, 2 invoice number, 3 balance, 4 status |
| `payment_confirmation` | After you record a payment and choose to share it | 1 member name, 2 amount, 3 invoice number, 4 remaining balance |
| `invoice_document` | When a PDF is attached | Header: document. Body: 1 member name, 2 invoice number, 3 total |
| `renewal_confirmation` | After a renewal | 1 member name, 2 plan name, 3 new end date |
| `announcement` | When you send from Automation | 1 member name, 2 your message |

Example body for the expiry template:

```
Hi {{1}}, your {{2}} at our gym expires on {{3}} ({{4}}). Reply here to renew.
```

Wait until each template status is **Approved**. Copy the template name exactly, including capitals. `membership_expiry` and `Membership Expiry` are different.

For `invoice_document`, add a **Document** header. Meta downloads the PDF from your site, so the site must be public `https://` and `APP_URL` in `.env` must be that exact address. Until then, download the PDF yourself and use the manual chat link.

### C. Paste the credentials into GMS

1. Sign in as the owner.
2. Open **Settings → WhatsApp**.
3. Untick **Demo mode** only when the token and template names are ready.
4. Phone number ID, business account ID, API version (`v21.0` unless Meta tells you otherwise), and country code (`91` for India).
5. Paste the access token. Leave the box blank later if you are only changing something else. The saved token stays.
6. Paste the app secret if you have it.
7. The webhook verify token is already filled. Copy it. You will give the same string to Meta.
8. For each of the seven keys, type the approved template name and language, and leave **Active** ticked.
9. Save WhatsApp.
10. Type your own mobile number in **Test phone** and choose **Send test**.

How to read the result:

- **Sent** — Meta accepted the message and returned an id. Delivery to the phone can still take a moment.
- **Demo** — credentials are missing or demo mode is still on. Use the manual link. Nothing was sent automatically.
- **Failed** — read the error under **Automation → Message log**. The usual causes are a wrong template name, a template that is not approved, the wrong number of variables, or an expired token.

### D. Turn on the webhook

1. In the Meta app, WhatsApp → Configuration → Webhook.
2. Callback URL: `https://your-domain.example/api/whatsapp_webhook`
3. Verify token: the value shown in GMS.
4. Subscribe to the **messages** field.
5. GMS uses this to store delivered and read updates. It does not mark a message sent from the webhook. Sent means Meta accepted it at send time.

### E. Day-to-day WhatsApp use

- New member with the consent tick: a welcome message is attempted.
- You record a payment and tick the WhatsApp box: a payment confirmation is attempted.
- You renew a plan and tick the box: a renewal confirmation is attempted.
- The invoice page can send the PDF once `APP_URL` is HTTPS.
- **Automation** sends an announcement to people you pick, or to every active member who opted in. Cap is 200 people at once.
- Members without the consent tick are skipped and the skip is logged.
- Open **Automation** to see the message log and whether cron has run.

Consent, template approval, and the cron jobs are all required. A saved token alone does not send expiry reminders.

## 9. First week checklist

1. Finish the six setup steps with your real gym name, address, and tax choice.
2. Add at least one plan for each duration you sell.
3. Add yourself a test member, with WhatsApp consent off.
4. Assign a plan and record a partial cash payment. Confirm the invoice says Partially Paid and the balance is the total minus that cash.
5. Download the PDF.
6. Check the test member in, then check them out.
7. Add one expense so Reports shows a net figure.
8. Create the receptionist login and confirm they can take a payment but cannot open WhatsApp settings.
9. When you are ready for real messages, follow section 8 and send the test to your own phone before you message members.
10. Add the four cron jobs and, the next morning, confirm **Automation** shows a successful cron row.

## 10. If something looks wrong

- You cannot sign in: check the email, and use **Forgot password**. On a local computer without email set up, the reset link is written to `storage/logs/mail.log`.
- A page says you do not have permission: your role does not include that action. The owner can do it.
- The invoice will not become Paid: record the remaining amount. Creating the invoice does not pay it.
- WhatsApp says sent but the phone is quiet: check the template language and that the member number includes the country code. GMS adds the country code from settings when the number is local.
- Reminders never leave: demo mode is on, the template name is empty, the member did not opt in, or cron is not scheduled.
- Two gyms seem mixed: they are not. Every member, invoice, and message is stored against one gym. Sign out and sign in with the other gym’s owner if you need the other set of records.
