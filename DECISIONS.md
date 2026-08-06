# Decisions

## Assumptions

- The brief's focus is the form-builder functionality (Parts A–D), not a
  production auth/multi-tenant system — so this build is single-user, with no
  login and no `tenant_id` enforcement. The `tenant_id` column exists on
  relevant tables but isolation is not enforced; that's a deliberate deferral,
  not an oversight.
- Free-tier hosting (Render + Aiven + Supabase) is acceptable for the demo
  despite cold-start latency, since the brief allows any free-tier host and
  prioritizes reachability over performance.
- A single JSON `schema` column on `forms` (rather than normalized field/option
  tables) is the right source of truth, because the visual builder, AI
  generator, and importer all need to read and write the *same* structure —
  normalizing early would have meant keeping three write paths in sync instead
  of one.

## Part D choices and why

Three differentiators were planned; two are fully built, one is scaffolded only.

1. **Form versioning / rollback (built).** Every schema write — manual edit,
   AI generate, AI edit, or import commit — goes through one method,
   `Form::saveSchema()`, which snapshots a `form_versions` row before applying
   the change. Chosen first because it's low-risk (pure additive logging) and
   it protects every other feature: if AI editing or import ever corrupts a
   form, rollback is the safety net.
2. **AI editing of existing forms (built).** A natural extension of Part B's
   generation pipeline — it reuses `AiFormGeneratorService`'s validation and
   retry logic, so the marginal cost of adding "edit" on top of "generate" was
   small once generation was solid.
3. **Webhooks + public submissions API (scaffolded, not implemented).**
   Chosen as the third differentiator because it's the most "real product"
   feature (integrations), but it was the lowest priority per the brief's
   explicit A → B → C → D ordering, and time ran out after finishing A–C plus
   the first two Part D features. The `webhooks` table exists; delivery and
   the read API do not.

## Trade-offs accepted

- **Gemini over OpenAI:** switched to Google Gemini (free tier, no credit
  card) after hitting friction with OpenAI's free-tier/rate-limit terms. Traded
  some uncertainty about a newer model's output quality for the ability to
  actually run this without a paid key.
- **Tailwind via CDN instead of a compiled build:** faster iteration during
  development, at the cost of a larger unminified stylesheet in production.
  Acceptable for a demo; would compile it for a real deploy.
- **`QUEUE_CONNECTION=sync` in production:** simplest way to run on a single
  free-tier web service without a separate worker process/dyno. The trade-off
  is that AI generation and import requests block for a few seconds instead of
  returning immediately — fine at demo traffic levels, not fine at scale.
- **No automated tests yet:** time went into finishing A–C and two of three
  Part D features rather than test coverage. Pest is installed and ready;
  this was a conscious call to prioritize functional completeness over test
  coverage given the time available.
<!-- TODO: if there was a specific reason Livewire was chosen over React for
     the builder UI (e.g. tighter Laravel integration, less client-state
     management, faster to build solo), add it here — that's a natural
     "trade-off accepted" item the brief is looking for and I don't have your
     specific reasoning on record. -->

## What I'd build next with two more weeks

1. **Finish Part D's third feature:** HMAC-signed webhook delivery on
   `submission.created`, a token-authed `/api/v1/forms/{id}/submissions` read
   endpoint, and a `webhook_deliveries` table with retry/backoff for failed
   deliveries.
2. **Test suite:** start with `FormSchemaValidator` and the submission
   validation path, since that's the actual security boundary of the app —
   everything else (builder UI, AI generation) sits on top of it.
3. **Move production off `sync`:** a real background worker (queue on Render,
   or a small always-on worker service) so AI generation and imports don't
   block the request.
4. **Basic auth + real multi-tenancy:** turn the reserved `tenant_id` column
   into an enforced boundary, and add at least a lightweight login so forms
   and submissions aren't globally public.
5. **Compile Tailwind for production** instead of the CDN build.
