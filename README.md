# AI-Powered Form Builder

**🔗 Live Demo:** https://laravel-ai-form-builder.onrender.com/forms
**🔑 Credentials:** None required — the demo is fully open, no login/signup needed.

> **Cold start warning:** hosted on Render's free tier, which spins down after
> ~15 minutes of inactivity. The **first** request after idle takes **30–50
> seconds** to wake up (the Aiven MySQL database may also take a moment).
> Please wait for it to load — it is not broken.

A form builder (like Google Forms) with three ways to create forms — a visual
drag-and-drop builder, AI generation from a text prompt, and import from
Word/Excel documents. Built with Laravel 11, Livewire 4, and MySQL.

---

## What it does

- **Part A — Visual form builder:** Drag-and-drop / click-to-add builder with
  12 field types, sections, live-synced JSON schema editor, a public fill page
  with server-side validation, and a submissions list with search + CSV export.
- **Part B — AI form generation:** Describe a form in plain English and Google
  Gemini generates a complete, editable form. Also supports AI editing of
  existing forms ("add an emergency contact section").
- **Part C — Word/Excel import:** Upload a `.docx` or `.xlsx` file, the app
  parses it into a form (headings → sections, questions → fields, lists →
  options), shows a preview where you can fix detected field types, then
  commits it as a real form.
- **Part D — Extras:** Form versioning (every save is snapshotted), AI editing,
  and publish/unpublish + delete management.

---

## Tech stack

| Layer | Choice |
|---|---|
| Framework | Laravel 11 |
| Frontend | Livewire 4 + Tailwind (CDN) + SortableJS (drag-drop) |
| Database | MySQL 8 (local: XAMPP; production: Aiven managed MySQL) |
| AI | Google Gemini API (`gemini-3.5-flash-lite`, free tier) |
| Import parsing | PhpWord + PhpSpreadsheet |
| File storage | Local disk (dev) / Supabase Storage S3 (production) |
| Hosting | Render (web, Docker) + Aiven (MySQL) + Supabase (file storage) |

---

## Local setup

**Requirements:** PHP 8.2+, Composer, MySQL 8, Node.js.

```bash
# 1. Clone and install
git clone https://github.com/MazharSayed/laravel-ai-form-builder.git
cd laravel-ai-form-builder
composer install
npm install

# 2. Environment
cp .env.example .env
php artisan key:generate
```

Set these in `.env`:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=form_builder
DB_USERNAME=root
DB_PASSWORD=

QUEUE_CONNECTION=database
CACHE_STORE=database

# AI (get a free key at https://aistudio.google.com/apikey)
GEMINI_API_KEY=your_key_here
GEMINI_MODEL=gemini-3.5-flash-lite

# File uploads: 'local' for dev, 'supabase' for production
UPLOAD_DISK=local
```

```bash
# 3. Create the database `form_builder` in phpMyAdmin, then:
php artisan migrate --seed

# 4. Run (two terminals)
php artisan queue:work    # required — AI generation & imports run as queued jobs locally
php artisan serve
```

Visit `http://localhost:8000/forms`.

---

## Environment variables

| Variable | Purpose |
|---|---|
| `APP_KEY` | Laravel encryption key (`php artisan key:generate`) |
| `DB_*` | MySQL connection |
| `GEMINI_API_KEY` / `GEMINI_MODEL` | Google Gemini AI config |
| `AI_FORM_GEN_MAX_RETRIES` | AI schema repair attempts (default 2) |
| `QUEUE_CONNECTION` | `database` (local, with worker) or `sync` (production, inline) |
| `UPLOAD_DISK` | `local` or `supabase` |
| `SUPABASE_*` | S3-compatible storage config (production file uploads) |
| `MYSQL_ATTR_SSL_CA` | Optional — MySQL SSL for Aiven (production) |

---

## Architecture overview

**Schema-first design.** Every form is stored as one JSON document in the
`forms.schema` column. This single document is the source of truth — the visual
builder, AI generator, and importer all read/write the same structure. See
[`docs/JSON_SCHEMA.md`](docs/JSON_SCHEMA.md) for the exact contract.

**Server-side validation is derived, not duplicated.** `FormSchemaValidator`
turns a form's schema into Laravel validation rules at submission time, so the
public fill page can't be bypassed by tampering with client-side JS — whatever
the browser did is advisory only.

**AI never saves broken output.** `AiFormGeneratorService` validates every
Gemini response against the schema contract; on failure it feeds the specific
errors back to the model and asks for a corrected version (up to N retries)
before giving up. A schema that never validates is never persisted.

**Every schema write is versioned.** `Form::saveSchema()` is the only path that
updates a form's live schema, and it always snapshots a `form_versions` row
first — manual edits, AI generate/edit, and import commits all go through it.

---

## Database schema (ERD summary)

- **forms** — schema (JSON), status, public_key (unguessable, used in fill URL), settings
- **form_submissions** — form_id, data (JSON), meta (ip/UA); indexed on (form_id, created_at)
- **form_versions** — full schema snapshot per version, source (manual/ai_generate/ai_edit/import/rollback)
- **ai_generation_logs** — model, prompt/completion tokens, latency, status, attempt
- **import_jobs** — uploaded file → detected schema → user corrections → committed form
- **webhooks** — per-form webhook subscriptions (table scaffolded; see limitations)

A schema-only SQL dump is at [`database/schema.sql`](database/schema.sql);
migrations are the source of truth. Sample import files are in
[`storage/app/samples/`](storage/app/samples/).

---

## API endpoints

| Method | Path | Purpose |
|---|---|---|
| GET | `/forms` | Dashboard — list forms |
| POST | `/forms` | Create a draft form |
| GET | `/forms/{form}/builder` | Visual builder (Livewire) |
| POST | `/forms/ai-generate` | Generate a form from a prompt (Part B) |
| POST | `/forms/{form}/toggle-publish` | Publish / unpublish |
| DELETE | `/forms/{form}` | Delete a form |
| GET | `/imports` | Word/Excel import + review screen (Part C) |
| POST | `/imports/{importJob}/commit` | Commit a reviewed import as a form |
| GET | `/forms/{form}/submissions` | Paginated, searchable submissions |
| GET | `/forms/{form}/submissions/export` | CSV export |
| GET | `/f/{publicKey}` | Public fill page (no auth) |

---

## AI prompt strategy

- A system prompt pins Gemini to the exact JSON schema contract — valid field
  types only, unique snake_case keys, required options on choice fields.
- The request uses Gemini's JSON response mode to reduce malformed output.
- Every response is validated by `FormSchemaValidator` before persisting.
  Hallucinated field types, duplicate keys, and missing options are caught.
- On validation failure, the specific errors are sent back to the model as a
  repair prompt (up to `AI_FORM_GEN_MAX_RETRIES` attempts).
- Every attempt is logged (model, tokens, latency, status) to `ai_generation_logs`.
- Generation runs as a queued job locally (non-blocking); in production it runs
  inline via the `sync` queue (see limitations).

---

## Import strategy (Word / Excel)

Deterministic-first parsing, no AI needed for the common cases:

- **Word:** Heading styles → sections, paragraphs ending in `?`/`:` → field
  labels, bullet lists under a question → radio options. Field types are
  guessed from label wording (email, phone, date, file, etc.).
- **Excel:** Two documented layouts — a **structured** layout
  (`label | type | required | options`) and a **plain header-row** layout
  (each column header becomes a field).
- Anything the parser can't confidently place is reported in a "parser notes"
  panel rather than guessed silently. The review screen lets the user correct
  any detected field type before committing.

---

## Known limitations & honest scoping

Per the brief's priority (A → B → C → D) and "state clearly what is unfinished":

- **Part D — webhooks + public submissions API:** the database table is
  scaffolded, but the webhook-firing and read-API code is **not implemented**.
  Two of three Part D features (versioning, AI editing) are fully built. With
  more time: an HMAC-signed webhook delivery on `submission.created` plus a
  token-authed `/api/v1/forms/{id}/submissions` read endpoint with a
  `webhook_deliveries` retry queue.
- **`tenant_id`** is reserved for future multi-tenancy — the column exists but
  isolation is not enforced.
- **No authentication.** This is a single-user demo; auth was out of scope
  given the assignment's focus on form-builder functionality (see
  [`DECISIONS.md`](DECISIONS.md) for the reasoning).
- **Free-tier hosting caveats:** the web service and database both sleep when
  idle (30–50s cold start on first request). Production `sync` queue means AI
  generation/import run inline (the request waits a few seconds) rather than in
  a background worker.
- **No automated test suite yet.** Pest is installed; with more time the first
  tests would cover `FormSchemaValidator` and the submission validation path
  (the actual security boundary).
- **Tailwind via CDN** — fine for a demo; production would compile it.

See [`DECISIONS.md`](DECISIONS.md) for the reasoning behind key choices and the
engineering trade-offs made along the way.

---

## Credits

PhpWord, PhpSpreadsheet, Livewire, SortableJS (CDN), DataTables (CDN). AI calls
go directly to the Gemini REST API via Laravel's HTTP client (no SDK dependency).
