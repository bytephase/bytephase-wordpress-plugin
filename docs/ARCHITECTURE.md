# BytePhase WordPress Connector — Architecture & Design

## Context

BytePhase exposes an authenticated ingestion endpoint (`POST /api/{store}/integrations/submit`).
This repo is the **WordPress connector** that talks to it — a separate deliverable from the BytePhase
platform itself.

The plugin is a **dumb connector**. It collects submissions from WordPress and hands **raw** data to
BytePhase over an authenticated channel. It performs **no** mapping, dedup, validation, customer/lead
creation, assignment, notifications, analytics, or CRM behaviour — all of that lives in BytePhase, the
single source of truth. Wherever logic *could* live in the plugin or in BytePhase, it lives in BytePhase.

### Core model — mapping-first

> **The site owner designs any form they like, names every field however they like.** The plugin never
> dictates, renames, or validates field names. It captures the raw submission plus a stable **`form_id`**
> and forwards it untouched. **BytePhase owns the mapping** — raw field name → canonical field — configured
> **once per form, in the BytePhase UI.** Mapping is BytePhase's job; forwarding is the plugin's.

A form field called `customer_phone`, `mobile`, or `Your Phone Number` is all the same to the plugin: a
raw key/value it forwards. BytePhase, keyed by `form_id`, knows that for *this* form that field means
`mobile_number`. The plugin ships zero knowledge of canonical field names.

### Settled decisions
1. **Field mapping → pure connector.** The plugin sends **raw** submitted field names untouched, plus
   `provider` + `form_id`. BytePhase owns the field-map layer and the per-form mapping UI, so a new
   form source never needs a plugin release.
2. **Attachments → deferred to v2.** v1 MVP = "connect WordPress → create a Lead" (text fields only).
   `/integrations/submit` is JSON-only today. The design documents the extension point; the native form
   is built so attachments drop in cleanly later.
3. **Connectors, not builder adapters.** A *Connector* only extracts raw fields + form identity from a
   given form source. v1 ships four: a **Generic Action/Filter API**, **Contact Form 7**, **Elementor
   Forms**, and an optional **Native BytePhase Form** (a zero-config shortcut — see §8). Everything else
   (WPForms → v1.5; Gravity/Formidable/Ninja/Fluent → v2) arrives later via the same `Connector` seam
   with no core changes.
4. **v1 simplifications (from review):** **no key encryption** — plaintext in an `autoload=no` option,
   locked down instead (the plugin must use the key, and WP-salt encryption buys little). The retry store
   holds **failed submissions only** (`pending_submissions`), not a full durable queue — successful sends
   never touch a table. **Success rate is windowed** (today / 7-day), never lifetime.

---

## The BytePhase API contract

This is the wire contract the plugin depends on. Everything below is observable from the outside: it is
what the plugin sends and what it must handle coming back.

| Item | Value |
|---|---|
| Endpoint | `POST https://{your-bytephase-domain}/api/{store}/integrations/submit` |
| Store | Header `X-Tenant: <Store ID>` — required. The `{store}` in the path is a route placeholder, **not** the resolver. |
| Auth | Header `X-API-Key: <key>` — a key issued for the WordPress integration; must be active and unexpired |
| Idempotency | Header `Idempotency-Key: <uuid>` (optional) → a replay returns the original record with `200` |
| Accept | `application/json` |
| Body | `{ "form_id"?: string(≤191), "destination"?: "lead"\|"self_checkin", "data": { ...raw fields... } }` |
| Rate limit | 60 requests/minute per integration |
| `201` | new record created (Lead or Self check-in) |
| `200` | duplicate → `{ "status":"duplicate", "data": {...} }` |
| `401` | `{ "message": "..." }` — missing, invalid, inactive or expired key |
| `422` | `{ "message":"...", "errors": { field:[...] } }` — validation failed |
| `429` | rate limited |
| `500` | `{ "message": "..." }` |
| Response header | `X-Request-Id: <ULID>` — **the plugin must capture, display and log this** for support and tracing |

**Destination resolution.** BytePhase uses `destination` from the envelope when the plugin sends one,
otherwise the destination configured for that `form_id`. The canonical field names a record ends up with
live in BytePhase only — the plugin never references them, which is what keeps it a pure connector.

### Custom field definitions (read-only)

The one place the plugin *reads* from BytePhase rather than writing to it. It exists so the built-in
forms can show the fields a shop has already defined, without the plugin ever holding a definition of
its own.

| Item | Value |
|---|---|
| Endpoint | `GET https://{your-bytephase-domain}/api/{store}/integrations/custom-fields` |
| Auth | The same `X-Tenant` + `X-API-Key` headers as the submit call |
| `?form_type=<type>` | `{ "form_type": "...", "fields": [ { field_name, field_type, placeholder, is_field_required, select_box_items } ] }` |
| no query | `{ "form_types": [ { "form_type": "...", "field_count": n } ] }` — only types that have fields |
| `field_type` | `Text`, `Number` or `Dropdown` |
| Rate limit | shares the submit endpoint's 60/minute |

The plugin caches a response for 12 hours and treats any failure as "no custom fields": a form renders
without them rather than not rendering at all. Definitions are never stored as plugin settings — only
the site's own choices are (see §7).

Values travel back inside the normal submit body, as `data.custom_fields`:

```json
[{ "label": "Warranty status", "field_type": "Dropdown", "field_value": "In warranty",
   "select_box_items": ["In warranty", "Out of warranty"] }]
```

This is the shape BytePhase's own screens write and render, so a field filled in on the website is
indistinguishable from one a staff member typed. Anything the plugin sends that is not a known field is
still absorbed as a custom field by BytePhase, which is what makes unmapped extras survive.

### Destination field contract (the rule every entry point must satisfy)

BytePhase validates every record it creates, whatever created it. The rule that matters to this plugin:

| Field | Lead | Self check-in |
|---|---|---|
| A name | **required** | **required** |
| An email address **or** a phone number | **at least one of the two** | **at least one of the two** |
| Contact, source, follow-up, comment, device and address fields | optional | optional |

The exact canonical names are defined in BytePhase and can change there without a plugin release — that
is the point of mapping-first. What the plugin guarantees is that the site owner's raw fields arrive
intact, so a mapping can always be built.

**Every entry point must satisfy the required set:**
- **Native form** — enforces `name` (HTML `required`) + `email`-or-`mobile_number` (server-side guard on
  submit, plus an inline hint). A submission missing the required set is rejected *before* it reaches
  BytePhase, so it never becomes a logged failure.
- **Field mapping (CF7 / Elementor / generic)** — the per-form `field_map` in BytePhase MUST map the source
  form onto `name` and at least one of `email`/`mobile_number`, or BytePhase returns 422. The source form
  must therefore collect those fields.
- **Device fields are optional** and persist only when they resolve to existing BytePhase records:
  `device_brand`/`device_model` need a `device_type` to resolve, and leads (unlike self check-in) keep no
  custom-name fallback — so unrecognised device text is dropped on leads.

---

## 1. Overall Architecture

Three thin layers, native WP APIs throughout, no framework:

```
  WordPress submission  (any form, any field names)
        │
  ┌─────▼─────────────────┐   Connectors (extract raw fields + form_id only)
  │ Generic action/filter │   do_action('bytephase_submit_form', $submission)
  │ Contact Form 7        │   wpcf7_mail_sent
  │ Elementor Forms       │   elementor_pro/forms/new_record
  │ Native form / block   │   [bytephase_lead_form]  (zero-config shortcut)
  └─────┬─────────────────┘
        │  Submission { provider, form_id, destination?, data{raw}, meta }
  ┌─────▼─────────────────┐   Core (shared, connector-agnostic)
  │ Dispatcher → Sender   │   canonical envelope + X-API-Key + Idempotency-Key
  │ Retry (WP-Cron)       │   backoff on 429/5xx/network, failures only
  │ Activity log (capped) │   operational only, never business data
  └─────┬─────────────────┘
  ┌─────▼─────────────────┐   Admin (lazy, admin-only)
  │ Settings + Health UI  │   connect, test, status, recent activity, retry
  └───────────────────────┘
        │
        ▼  raw fields + form_id  →  BytePhase maps → validates → dedups → persists
```

Every Connector produces the **same `Submission`** with raw fields; everything after the Connector seam
is shared. Adding a builder = one small `Connector` class, zero core edits (Open/Closed). **The mapping
that turns raw fields into a Lead never happens here — it happens in BytePhase.**

## 2. Plugin Folder Structure

PSR-4 (`BytePhase\Connector\` → `src/`), one class per file, Composer autoload with a bundled
fallback loader. WordPress core functions are used directly; everything the plugin authors is
namespaced PSR-4 (PascalCase classes, camelCase methods).

```
bytephase-wordpress-plugin/
├── bytephase-connector.php            # WP entry: header + constants + PSR-4 autoload + boot
├── uninstall.php                      # delete options + scheduled events + pending-submissions table
├── composer.json                      # BytePhase\Connector\ → src/ ; dev: phpcs, phpstan
├── readme.txt  README.md  LICENSE(GPL-2.0+)  .gitignore
├── src/
│   ├── Plugin.php                     # composition root; boot() wires collaborators, registers cron
│   ├── Core/
│   │   ├── Submission.php             # readonly value object (provider, formId, data, destination, meta)
│   │   ├── Envelope.php               # Submission → canonical JSON body for /submit
│   │   ├── ApiResult.php              # typed outcome classified from the HTTP response
│   │   ├── ApiClient.php              # WP HTTP API wrapper (SSL verify, timeout, headers)
│   │   ├── CustomFieldCatalog.php     # the shop's field definitions, cached 12h; stale-safe
│   │   ├── Dispatcher.php             # send once; log; queue only on retryable failure; retry worker
│   │   ├── PendingSubmissions.php     # failed-only store (custom table) + WP-Cron backoff
│   │   └── ActivityLog.php            # capped operational log (last 50 / 30 days)
│   ├── Connectors/
│   │   ├── Connector.php              # interface: slug(), isAvailable(), register()
│   │   ├── ConnectorRegistry.php      # collects connectors via filter, boots the available ones
│   │   ├── GenericConnector.php       # do_action/apply_filters public API
│   │   ├── Cf7Connector.php           # Contact Form 7
│   │   ├── ElementorConnector.php     # Elementor Pro Forms
│   │   └── NativeConnector.php        # shortcodes; pre-canonical; nonce + honeypot
│   └── Settings/
│       ├── Settings.php               # connection data accessor (base url, tenant, submitUrl)
│       ├── Credentials.php            # key storage (autoload=no), masked/never-redisplayed, never logged
│       ├── CustomFields.php           # per-form: show or not, which form type, which fields published
│       ├── FormDestinations.php       # per-form destination choice (lead / self check-in / ignore)
│       ├── SettingsPage.php           # Connection screen + Test Connection
│       ├── FormsPage.php              # Forms screen: destinations + custom fields
│       └── HealthPage.php             # health/status/recent-activity/retry screen
└── languages/                         # .pot
```

*(Deferred to a later iteration: `blocks/native-form/` Gutenberg block — v1 ships the shortcodes only;
`assets/admin/` — the health page's tiny copy-to-clipboard script is inlined for now.)*

## 3. Component Responsibilities

- **Connector** — *extract only.* Reads a form's payload, returns a `Submission` with **raw** field names
  and the form's stable `form_id`. Never transforms, renames, validates, or maps. One class per form source.
- **Connector Registry** — discovers Connectors (built-ins + those added via `bytephase_register_connectors`
  filter), boots each only if its builder is active.
- **Submission** — a plain value object: `provider`, `form_id`, optional `destination`, `data` (raw assoc
  array), `meta` (ip/ua/page — operational). Nothing fancier than that.
- **Envelope** — serialises a `Submission` → the exact `{form_id, destination?, data}` body. Single place
  the wire format lives. `data` is the raw fields, verbatim.
- **Custom Field Catalog** — reads the shop's field definitions from BytePhase and caches them in a
  transient for 12 hours, because every visitor paying for a round trip would also burn the
  integration's rate limit. A failed read returns nothing and caches nothing, so the next render
  retries; a form never breaks because BytePhase is unreachable. Discards any definition it cannot
  make sense of rather than rendering it.
- **Custom Fields (settings)** — the site's decisions only: whether custom fields show on a form, which
  form type they come from, and which of them are published. Labels, types, options and which are
  required are never stored here — they are read from BytePhase every time, so a field is edited in one
  place. Required fields are always published; withholding one would only produce a guaranteed 422.
- **API Client** — one `wp_remote_post` wrapper plus a read-only `wp_remote_get` for definitions: base URL + tenant slug from settings, injects
  `X-API-Key`, `Idempotency-Key`, `Accept`; `sslverify=true`; bounded timeout; returns a typed result
  (created / duplicate / auth_error / validation_error / retryable / fatal) + `X-Request-Id`.
- **Dispatcher** — orchestrates one submission: build envelope → attempt the HTTP request immediately →
  **only on a retryable failure** store a row in `pending_submissions`; write an activity-log row either
  way. Successful sends never touch a table — the request path stays fast.
- **Pending-submissions store + Worker** — a custom table holding **failed/retryable rows only** (not
  every submission); a WP-Cron worker retries them with exponential backoff, caps attempts, and surfaces
  exhausted rows to the health screen for one-click manual retry.
- **Activity Log** — capped operational ledger (status, destination, HTTP code, request id, error
  message, timestamps). **No customer/business fields ever.**
- **Settings / Credentials / Health** — admin-only, lazily loaded; connect + test + status + retry.

## 4. Data Flow

1. Visitor submits a WP form — built and named however the owner chose.
2. The matching Connector `extract()`s a `Submission`: raw fields + the form's stable `form_id`.
3. Dispatcher builds the envelope (`{form_id, destination?, data:{raw}}`), generates a stable
   `Idempotency-Key`, and POSTs immediately via the API Client. Successful sends are done here — no queue.
4. **Sync boundary** = the HTTP response. BytePhase looks up `config.forms[form_id]` → **maps raw→canonical
   via `field_map`** → resolves destination → validates → dedups → persists → returns
   `201`/`200(duplicate)`/`422`/`401`/`429`/`500` + `X-Request-Id`.
5. Dispatcher records an activity row; **only** retryable failures (429/5xx/network) are stored in
   `pending_submissions` (with their idempotency key so retries reuse it).
6. WP-Cron worker retries stored failures with backoff; exhausted rows are shown for one-click manual retry.
7. Everything downstream (lead/customer/repair creation, assignment, notifications, analytics) happens
   **inside BytePhase**. The plugin only knows: left WordPress? accepted? if not, why? retry.

## 5. Security Design (highest priority)

- **API key at rest** — stored plaintext in a single **`autoload=no`** option. The plugin must *use* the
  key on every send, and encrypting it with WordPress salts buys little: an attacker who can read the DB
  row can also read the salts and PHP that would decrypt it. So we skip encryption and lock down access
  instead — a simpler, honest posture rather than security theatre.
- **Never exposed / never logged** — the key is write-only in the UI (masked `••••`, "Replace key" flow);
  never rendered back after save, never in the activity log, never in error text, never in `X-Request-Id`
  traces. Log scrubber strips any `X-API-Key`/`Authorization` before persistence.
- **Capabilities** — every admin action requires `manage_options` (single site) / `manage_network_options`
  (multisite network settings).
- **Nonces / CSRF** — Settings API nonce on save; dedicated nonces on Test-Connection and Retry
  (admin-post/REST). The native form deliberately carries **no nonce**: it is a public, logged-out
  form (no privileged action to forge) and page caches serve HTML longer than a nonce lives, which
  would break submissions from cached pages. Spam is blunted with a honeypot + minimum-fill-time
  trap instead — both cache-safe.
- **Sanitisation / escaping** — all input through `sanitize_*`; all output through `esc_*`; the raw
  submission `data` is passed through untouched **as values in a JSON body only** (never interpolated into
  HTML/SQL), so arbitrary field names/values cannot XSS the WP admin or the site.
- **No SQL injection surface** — only `$wpdb->prepare`d writes to the pending-submissions/log tables; no
  dynamic SQL.
- **Transport** — `sslverify=true` always; refuse non-HTTPS BytePhase URLs; bounded connect/read timeouts;
  no following cross-host redirects.
- **Rate-limit aware** — respects `429`/`Retry-After`; client-side minimum spacing so a spam flood can't
  hammer BytePhase or the shop's own key quota.
- **Secret rotation** — "Replace key" overwrites the stored value and re-tests; keys expire server-side
  (`expires_at`) and a `401` flips the health card to "Reconnect".
- **Tenant privacy** — tenant slug / BytePhase URL live in admin-only options; never printed on the public
  site or in front-end markup.

## 6. WordPress Hooks Used

- Lifecycle: `register_activation_hook` / `register_deactivation_hook` (schedule/clear cron + create
  tables), `uninstall.php`, `plugins_loaded` (boot), `init` (register shortcodes/block/connectors).
- Admin (lazy): `admin_menu`, `admin_init` (Settings API), `admin_enqueue_scripts` (only on our pages),
  `admin_post_bytephase_test` / `admin_post_bytephase_retry`, `network_admin_menu` (multisite).
- Connectors: `wpcf7_mail_sent`, `elementor_pro/forms/new_record`, our own
  `do_action('bytephase_submit_form', $submission)` + `apply_filters('bytephase_submission', …)`.
- Retry: a custom WP-Cron schedule + worker action; `add_filter('cron_schedules', …)`.
- Extension: `bytephase_register_connectors` (filter), plus the payload filters in §14.

## 7. Admin Settings Design (≤5-minute setup)

Single menu **"BytePhase"** with three screens, plain language, no jargon:

- **Connection** — three fields: *BytePhase Web Address*, *Store ID (tenant slug)*, *API Key* (masked),
  and a big **Test Connection** button → green "Connected" or a friendly fix-it message. A short "Where do
  I find these?" link to BytePhase docs. That is the entire required setup **in WordPress**.
- **Forms** — which record each form creates (Lead / Self check-in / don't send), and, per built-in
  shortcode, the three custom field choices below.
- **Health** — the operational dashboard in §13.

**Field mapping is not configured in WordPress.** After connecting, the owner maps each form's fields once
in BytePhase (keyed by `form_id`). The settings screen never shows or configures business data.

**Custom fields are displayed, not defined.** The Forms screen decides three things per built-in form —
whether custom fields show, which form type to pull them from, and which of them are published on a
public page — and nothing else. Every property of a field is read from BytePhase (§"Custom field
definitions"), so a shop edits a label, an option or a required flag in one place and the website
follows. A **Refresh** action drops the 12-hour cache for a shop that has just added a field. When a
form type has no fields yet, the screen says where to create them rather than showing an empty list.

## 8. Form Integration Strategy (Connectors) — mapping-first

**The primary model:** bring **any** form, built with **any** builder, with **any** field names. The
Connector captures the raw fields + a stable `form_id` and forwards them. The owner maps that form once in
BytePhase. Nothing about the form has to change to work with BytePhase, and the plugin holds no opinion
about field names.

A Connector's whole contract is `extract(): Submission` — pull the raw fields and the form's identity out
of a builder's payload. Everything downstream is shared.

- **Generic Action/Filter API** — any theme or plugin feeds BytePhase without us shipping an adapter. One
  documented example is worth a page of prose:
  ```php
  do_action( 'bytephase_submit_form', [
      'form_id' => 'contact-us',      // stable id; BytePhase looks up this form's mapping + destination
      'data'    => [                  // raw field names exactly as the form author wrote them
          'customer_name'  => 'John',
          'customer_phone' => '9999999999',
      ],
  ] );
  ```
  `destination` is optional — BytePhase resolves it from the form's config when omitted.
  `apply_filters( 'bytephase_submission', … )` is available for callers that need the result.
- **Contact Form 7** and **Elementor Forms** — built-in Connectors (the two largest free/agency install
  bases on repair-shop sites). Each grabs the raw fields + the builder's own form id on submit. **No field
  config in WordPress** — the shop maps the fields once in BytePhase.
- **Native BytePhase Form ⭐ (optional zero-config shortcut):** `[bytephase_lead_form]` /
  `[bytephase_self_checkin]` shortcodes + Gutenberg blocks. Because *we* author this markup, its field
  names are already canonical, so it's the one path that needs **no mapping step at all** — drop it in and
  it works. It is a convenience for new/simple sites, **not** a requirement: owners with an existing form
  never need it. It's also the natural home for v2 attachments.
- Deferred: WPForms (v1.5); Gravity/Formidable/Ninja/Fluent (v2) — each a new `Connector`, no core change.

### The `form_id` requirement

Mapping is keyed per form, so every submission must carry a **stable `form_id`**. CF7 and Elementor supply
one automatically; the generic hook and custom HTML forms set it explicitly (once). A changed `form_id`
means "a new, unmapped form" in BytePhase — so it stays stable for the life of the form.

### Supported features (for the readme + docs)

| Feature       | Generic hook | CF7     | Elementor | Native |
|---------------|--------------|---------|-----------|--------|
| Leads         | ✅           | ✅      | ✅        | ✅     |
| Self Check-In | ✅           | ✅      | ✅        | ✅     |
| Field mapping | Backend      | Backend | Backend   | n/a¹   |
| Attachments   | ❌           | ❌      | ❌        | ⬜ v2  |

¹ The native form emits canonical fields, so no mapping is needed anywhere.

## 9. Attachment Handling (v2, designed-for now)

Deferred, but the native form and `Submission` reserve a `files[]` slot. v2 plan: native form uploads to
WP media (respecting `wp_max_upload_size`, allowed mime types), then the connector references them in a
**follow-up** authenticated call to BytePhase's attachment endpoint, keyed by the returned
entity + `X-Request-Id` — no duplicate permanent storage, multipart only where required. No attachment
logic ships in v1; the seam exists so v1 needs no rework.

## 10. Error Handling

Typed client results → user-friendly admin messaging; **never** technical errors to site visitors (the
visitor always sees the form's normal success/failure):

| Condition | Plugin behaviour | Admin sees |
|---|---|---|
| 401 invalid/expired key | stop; flip health to "Reconnect" | "BytePhase disconnected — update your API key" |
| 422 validation | log terminal (not retryable); keep `X-Request-Id` | "BytePhase rejected a submission — view details" (often a missing mapping) |
| 429 / Retry-After | store + backoff | counts as pending, not failed |
| 5xx / offline / timeout | store + backoff | "Temporary issue — retrying automatically" |
| network/DNS | store + backoff | same |
| 201 / 200 duplicate | success | green tick + destination |

## 11. Retry Strategy

Only **failed/retryable** submissions are persisted (in `pending_submissions`); successful sends never
enter it — no full durable queue for the common path. Each stored row carries its **stable
`Idempotency-Key`** so BytePhase collapses duplicate deliveries. WP-Cron worker: exponential backoff
(e.g. 1m → 5m → 30m → 2h → 6h), capped attempts (~6), jitter to avoid thundering herds. Exhausted →
"Failed", one-click **Retry** in the health screen (reuses the same idempotency key). No retries on
`401`/`422` (not transient).

## 12. Logging Strategy

- Operational only: `{ time, provider, form_id, destination, status, http_code, request_id, error }`.
- **Capped**: last 50 rows *or* 30 days, pruned on write + a daily cron. The plugin is never a database.
- **Secret-safe**: scrubber removes any key/authorization header; error strings truncated; no customer PII
  persisted (name/phone/email/device all stay in BytePhase).
- Optional `WP_DEBUG`-gated verbose transport log to `error_log`, still key-scrubbed.

## 13. Health / Status UI ("UPS tracking page, not the warehouse")

Answers only: *Is it connected? Did my submission leave WordPress? Did BytePhase accept it? If not, why?
Can I retry?* — never who the customer is.

**Health card**
```
BytePhase Connection      ● Connected
API Key      Valid              Last Connected   2 minutes ago
API Version  v1                 Last Submission  10:42 AM
Success (7d) 98%                Failed Requests  1
                                          View Dashboard →
```
**Success rate is windowed — Today and Last 7 days only** (never lifetime, so one bad week months ago
doesn't haunt the card forever).

**Today's counters:** Total / Successful / Failed / Last submission / Last success / Last failure. No charts.

**Recent activity (last 10):** `Time | Status | Destination`; row click → `Submission ID · Request ID ·
Destination · HTTP Status · Error`, with a **[Copy]** button on the Request ID (support teams live on
these) and a **Retry** button. Business data is replaced by a **"View in BytePhase →"** deep link
(`https://{tenant}.bytephase.com/...`). BytePhase stays the source of truth; the plugin shows shipping
status, not the parcel's contents.

**Sync status:** plugin version + "Update available?" + connected state + API version + last sync.

## 14. Future Extension Points

- `bytephase_register_connectors` (filter) — third parties add a `Connector`.
- `bytephase_submission` (filter) / `bytephase_submit_form` (action) — feed arbitrary payloads.
- `bytephase_envelope` (filter) — advanced payload shaping without core edits.
- New destinations (`repair`, `customer`, …) require **zero** plugin change — destination is a server
  concept resolved by `form_id`/native shortcode type.
- Reserved `files[]` in `Submission` for attachments; API-version field in settings for future contract bumps.
- New auth mechanisms (e.g. signed webhooks) slot behind the API Client without touching Connectors.

## 15. Testing Strategy

- **PHPUnit + WP test scaffold + Brain Monkey** for units: each Connector `extract()` (raw fidelity — field
  names survive untouched, `form_id` captured), Envelope serialisation (exact wire shape), API Client
  result classification, pending-submissions backoff math, log capping + secret scrubbing, credential
  store round-trip (write / masked read-back / never-logged).
- **Integration**: mock BytePhase with WP HTTP filters (`pre_http_request`) to assert headers
  (`X-API-Key`, `Idempotency-Key`), retry on 429/5xx, no-retry on 401/422, idempotency-key stability across
  retries.
- **Contract test** against a real BytePhase endpoint (raw payload + mapped `form_id` → 201 Lead;
  duplicate → 200; bad key → 401) — the acceptance gate: *install → paste key → map a form → submit →
  Lead appears*.
- **PHPCS** with the WordPress-Extra ruleset in CI (this repo only).
- **Security smoke**: nonce/cap failures rejected; key never present in rendered HTML or logs.

## 16. Development Roadmap

- **v1.0 (MVP — this scope):** Connection settings + plaintext key (`autoload=no`) + Test; Generic
  action/filter API; CF7 + Elementor Connectors; optional Native form (shortcode + block); envelope + API
  client + failed-only retry store + capped activity log; health screen. Acceptance: connect a WordPress
  site, map a form → create a Lead.
- **v1.5:** WPForms Connector; per-form "View in BytePhase" deep links; i18n pass.
- **v2:** Attachments (native uploads); Gravity/Formidable/Ninja/Fluent
  Connectors; optional signed-webhook auth; multisite network-level connection.

---

## Verification checklist

Run this end to end after a change that touches delivery.

1. Local WordPress (Docker) with the plugin, and a reachable BytePhase account. Create the WordPress
   integration in BytePhase and copy its API key.
2. Settings → paste the Web Address + Store ID + key → **Test Connection** returns Connected (200).
3. Build a CF7/Elementor form with arbitrary field names; in BytePhase, map that `form_id`'s fields →
   canonical + destination. Submit the form → assert a Lead appears and the health screen shows a green row
   with a valid `X-Request-Id`.
4. Confirm the raw field names travelled untouched (inspect the stored submission) and that mapping happened
   server-side, not in WordPress.
5. Kill network mid-submit → row goes Pending → WP-Cron retry (same idempotency key) → Success, and a
   replayed delivery returns `200 duplicate` (no double Lead).
6. Invalid/expired key → `401` → health flips to "Reconnect"; verify the key never appears in page source,
   options dump, or logs.
7. Drop `[bytephase_lead_form]` and submit with no mapping configured → still creates a Lead (native form
   is pre-canonical), proving the zero-config shortcut.
