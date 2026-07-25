# PHEA — Architecture & Build Contract

This is the single source of truth every module binds to. Routes, permission
names, database columns, booking states and UI component APIs are **fixed**
here. If code and this document disagree, the document is right — fix the code.

---

## 1. Stack (installed and verified)

| Layer     | Choice                                                    |
| --------- | --------------------------------------------------------- |
| Framework | Laravel **12.64**, PHP **8.4**                            |
| Database  | MySQL / MariaDB **10.4** — schema `db_phea`               |
| Frontend  | Inertia **3.1** (server) / **3.6** (client) + Vue **3.5** |
| Styling   | Tailwind **v4** (CSS-first `@theme`, no `tailwind.config`) |
| Build     | Vite **6**                                                |
| RBAC      | spatie/laravel-permission **8.3**                          |
| API auth  | Sanctum **4.3** — reserved for the future mobile app       |
| Routes    | Ziggy **2.6** — `route()` is available inside Vue          |
| UI libs   | SweetAlert2 **11**, ApexCharts **6** + vue3-apexcharts, `@lucide/vue` |

Import icons as `import { Plus } from '@lucide/vue'` — **not** `lucide-vue-next`,
which is deprecated.

---

## 2. Permission catalogue

Permissions are the *only* access primitive. Never check role names in code —
check permissions, so new roles work without code changes.

```
dashboard.view
courts.view      courts.create    courts.update    courts.delete
slots.view       slots.create     slots.update     slots.delete     slots.generate
bookings.view    bookings.verify  bookings.delete
users.view       users.create     users.update     users.delete
roles.view       roles.create     roles.update     roles.delete
settings.view    settings.update
audit.view
reports.view     reports.export
```

**Admin** gets every permission. **Staff** gets exactly:
`dashboard.view`, `courts.view`, `slots.*`, `bookings.view`, `bookings.verify`.

Seeded account: username `admin`, password `admin123`, `must_change_password = true`.
The seeder must print a console warning to change it after first login.

---

## 3. Database schema

Money is `decimal(10,2)` everywhere. Never float.

### `users`

Already migrated. Columns: `name`, `username` (unique, login identifier),
`email` (nullable unique), `phone`, `password`, `avatar_path`, `is_active`,
`must_change_password`, `last_login_at`, `last_login_ip`, soft deletes.

### `audit_trails`

`user_id` (nullable FK, null on delete), `user_name` + `role_name` (**snapshots** —
survive user deletion), `module`, `action`, `description`, `auditable_type` +
`auditable_id` (nullable morph), `old_values` json, `new_values` json,
`ip_address`, `user_agent`, `browser`, `platform`, `url`, `method`, `created_at`.

Actions: `login`, `logout`, `create`, `view`, `update`, `delete`, `activate`, `deactivate`.
Index `(created_at)`, `(user_id)`, `(module, action)`.

### `courts`

`name`, `slug` (unique, route key on the public site), `code` (unique),
`description`, `photo_path`, `base_price` decimal(10,2), `is_active`,
`sort_order`, timestamps, soft deletes.

### `court_slots`

`court_id` FK cascade, `slot_date` date, `start_time` time, `end_time` time,
`price` decimal(10,2), `status` enum, `held_booking_id` nullable, timestamps.

- `unique(court_id, slot_date, start_time)` — the guard against a bulk
  generator run producing duplicates. Generation must upsert/skip on conflict.
- `index(court_id, slot_date, status)` — drives the public availability query.
- `index(status, slot_date)`.

Slot status: `available` → `held` → `booked`, plus `blocked` (admin took it
off the market). Release returns `held` → `available` and nulls `held_booking_id`.

### `bookings`

`code` (unique, public identifier, format `PHEA-XXXXXXXX`), `court_id` FK,
`court_slot_id` FK, `customer_name`, `customer_phone`, `customer_email`
(nullable), `notes`, `amount` decimal(10,2), `status` enum, `payment_reference`,
`payment_method` (nullable), `payment_proof_path`, `payment_submitted_at`,
`hold_expires_at`, `confirmed_at`, `confirmed_by`, `rejected_at`, `rejected_by`,
`rejection_reason`, `cancelled_at`, `ip_address`, `user_agent`, timestamps,
soft deletes.

`court_id` / `court_slot_id` are the booking's **primary** (earliest) court and
slot — a backward-compatible singular representative, not a claim that a booking
touches only one court. A booking may span several slots across **different**
courts in one combined booking: reserved, paid and confirmed together, with a
single `amount`, a single payment, and a single customer notification (one
email, not one per court). The full, immutable set of slots is recorded in the
`booking_slots` pivot (`booking_id`, `court_slot_id`, written once at reserve
time and never rewritten) and read via `Booking::slots()`. Every legacy
single-court read keeps using `court_id` / `court_slot_id` unchanged, and a
booking that happens to span one court renders identically to before.

`payment_method` records which app the reference came from (`gcash` | `gotyme`,
per `Booking::PAYMENT_METHODS`) so a verifying admin knows which ledger to open.
It is nullable because every booking taken before the choice existed has no
answer, as does one paid over the phone on a site that publishes no method at
all — render that as an em dash, never as `gcash`.

Checkout therefore demands a method **only when it published one to choose**:
`SubmitPaymentRequest` and the checkout page both read the published set from
`PaymentMethodService`, so the form can never reject a submission for omitting
a chooser it never rendered.

Index `(status, hold_expires_at)` — the release job scans this.
Index `(court_id, status)`, `(code)`.

### `settings`

Generic and extensible: `group` (`payment` | `company` | `theme` | `system`),
`key`, `value` (text), `type` (`string|text|boolean|integer|json|image`),
`unique(group, key)`. Read through a cached repository, never raw queries in
controllers. Payment settings ship now; company/theme use the same table.

Payment keys: `gcash_qr_path`, `gcash_account_name`, `gcash_account_number`,
`gotyme_qr_path`, `gotyme_account_name`, `gotyme_account_number`,
`payment_instructions`.

Checkout offers one QR per configured method. GCash is **required** — it is the
live method and must never become unconfigurable. The three `gotyme_*` keys are
**optional**: leave them empty and the funnel behaves exactly as it did when
GCash was the only choice. A method is only offered once it has a QR or an
account number, so a half-filled one never renders an empty card.
`payment_instructions` is shared by both. GoTyme is a bank, not a wallet, so
`gotyme_account_number` is digits only (6–20) and is never validated against the
`09XXXXXXXXX` mobile-number rule that `gcash_account_number` carries. Whichever
method the customer picks is stored on `bookings.payment_method`.

---

## 4. Booking state machine — the critical logic

This is the part most likely to carry race-condition bugs. Treat it as the
highest-risk code in the system.

```
                 reserve()                submitPayment()
   [ slot available ] ────────► awaiting_payment ────────► pending_verification
                                    │                            │
                                    │ hold expires               ├── confirm() ──► confirmed ──► completed
                                    │ or cancel()                └── reject()  ──► rejected
                                    ▼                                              │
                                 expired / cancelled ◄─────────────────────────────┘
                                    │
                                    └──► slot returns to `available`
```

Rules that must hold:

1. **`reserve()` and `confirm()` run inside a transaction with
   `lockForUpdate()` on the slot row.** Re-read the slot status *after*
   acquiring the lock — checking before the lock is the classic bug.
2. A slot may only move to `held` from `available`. Anything else throws a
   domain exception surfaced as a friendly "sorry, just taken" message.
3. `hold_expires_at` = `now() + hold_minutes` (default 30) on reserve.
   Submitting the reference **extends** it to `verification_hold_minutes`
   (default 720) so the admin has time to check whichever app the customer
   paid with. Both are editable at Admin > Settings > Booking
   (`Setting::GROUP_SYSTEM`, via `SettingsService`);
   `config('booking.hold_minutes')` / `config('booking.verification_hold_minutes')`
   are only the fallback for a fresh install where nothing has been saved yet.
4. `ReleaseExpiredBookingHolds` (scheduled every minute) releases bookings in
   `awaiting_payment` or `pending_verification` whose `hold_expires_at` has
   passed, marking them `expired` and freeing the slot. It must be **idempotent**
   and chunk the query — thousands of rows are expected.
5. Confirming sets the slot to `booked` permanently and clears `hold_expires_at`.
6. Rejecting/expiring/cancelling frees the slot back to `available`.
7. Every transition writes an audit trail entry and fires a customer
   notification (mail always; SMS only when `sms.enabled`).

All of this lives in `App\Services\BookingService`. Controllers stay thin —
they validate, call the service, and redirect with a flash message.

---

## 5. Routes

`routes/web.php` is **already written and is the contract**. Do not add,
rename, or remove routes — implement controllers that match it. Read it before
writing any controller. `routes/api.php` mounts at `/api/v1`.

Middleware aliases: `role`, `permission`, `role_or_permission`, `active`
(logs out deactivated users mid-session), `password.changed` (forces rotation
of the seeded password).

---

## 6. Shared Inertia props

`HandleInertiaRequests` already shares, on every request:

```js
appName
auth.user = { id, name, username, email, initials, is_active,
              must_change_password, roles[], permissions[] }   // null when guest
flash = { success, error, warning, info }
ziggy
```

Gate UI on `auth.user.permissions`, never on role names.

---

## 7. Frontend conventions

### File layout

```
resources/js/
  Components/      UI kit — generic, no business logic
  Layouts/         AppLayout (admin shell), PublicLayout, AuthLayout
  Composables/     useSwal, useDataTable, useConfirm, usePermissions
  Pages/           Inertia pages, mirroring the route names
```

Inertia resolves `Pages/<name>.vue`. `render('Admin/Courts/Index')` maps to
`resources/js/Pages/Admin/Courts/Index.vue`.

### Design tokens

Defined in `resources/css/app.css` via Tailwind v4 `@theme`. **Never hardcode a
hex value in a component.** Available:

- `brand-50..900` — primary actions
- `ink-50..900` — text and surfaces
- `success-*`, `warn-*`, `danger-*`, `info-*` — status
- `shadow-card`, `shadow-card-hover`, `shadow-float`, `shadow-modal`
- `.skeleton` — shimmer loading block

### Visual language

Modern SaaS (Stripe / Linear / Vercel), never default Laravel:
rounded-xl cards on `bg-white` over an `ink-50` page, `shadow-card` lifting to
`shadow-card-hover`, generous white space, `text-sm` body, `font-semibold`
headings, 150–250ms transitions on `--ease-out-soft`. Every list has a designed
empty state and a skeleton loader. Fully responsive down to 360px.

### Tables (the standard, applied everywhere)

Server-side search, sort, filter and pagination — 25 rows default. Row hover
darkens slightly (`hover:bg-ink-50`). Actions are **icon-only** buttons:
View = `info` blue, Edit = `success` green, Delete = `danger` red,
Warning/block = `warn` orange. Preserve query state across visits with
Inertia partial reloads.

### SweetAlert2

Only through the `useSwal()` composable — no direct imports in pages. It must
apply the `phea-swal` / `phea-swal-toast` custom classes already styled in
`app.css`. Provides: `toastSuccess`, `toastError`, `confirmDelete`,
`confirmAction`, `showValidationErrors`, `confirmLogout`, `sessionExpired`.
Flash props are consumed centrally in `AppLayout` — pages never re-handle them.

---

## 8. Backend conventions

- Validation lives in Form Requests, never inline in controllers.
- Authorisation lives in Policies + the `permission:` middleware.
- Anything non-trivial (booking transitions, slot generation, reporting,
  settings) lives in a Service under `App\Services`.
- Eager-load relations on every index query — N+1 is a defect, not a nit.
  Paginate with `->withQueryString()`.
- Models needing audit coverage use the `App\Traits\Auditable` trait, which
  hooks `created`/`updated`/`deleted`. Login, logout and view events are
  recorded explicitly via `AuditTrailService`.
- Config lives in `config/booking.php` and `config/sms.php`. **No magic
  numbers in code** — the 30-minute hold, page sizes and booking-code prefix
  are all config.

---

## 9. Non-negotiables

1. **Stability** — the app must boot, migrate and build after every change.
2. **Security** — CSRF on, mass-assignment guarded, file uploads validated by
   MIME and size, public routes throttled, no secret in a client prop.
3. **Scalability** — nothing may assume a small dataset. Index every column
   that is filtered or sorted.
4. **Performance** — no N+1, no unbounded query, no eager `SELECT *` on wide
   tables in list views.
5. **UX / premium UI** — see §7. Loading, empty and error states are part of
   "done", not polish to add later.
