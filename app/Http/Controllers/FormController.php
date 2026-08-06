<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateFormFromPrompt;
use App\Models\Form;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use App\Models\ImportJob;
use App\Services\FormSchemaValidator;

/**
 * Handles the "form management" actions that aren't part of the live Livewire
 * builder itself: listing forms, creating them, kicking off AI generation,
 * committing imports, and publish/delete. The interactive builder and public
 * fill pages are Livewire components; this controller covers the plain
 * request/redirect actions around them.
 */
class FormController extends Controller
{
    /** Dashboard — shows all forms, newest first, 15 per page. */
    public function index()
    {
        $forms = Form::latest()->paginate(15);
        return view('forms.index', compact('forms'));
    }

    /**
     * Create a blank draft form and jump straight into the builder.
     * We seed it with one empty section so the builder always has somewhere
     * to drop the first field into (avoids an empty-canvas edge case).
     */
    public function store(Request $request)
    {
        $data = $request->validate(['title' => 'required|string|max:150']);

        $form = Form::create([
            'title' => $data['title'],
            'schema' => ['version' => 1, 'sections' => [[
                'id' => 'sec_' . Str::random(6), 'title' => 'Section 1', 'description' => null, 'fields' => [],
            ]]],
            'status' => 'draft',
            'created_by' => auth()->id(),
        ]);

        return redirect()->route('forms.builder', $form);
    }

    /**
     * Part B — kick off AI form generation from a text prompt.
     *
     * The actual Gemini call is slow, so we DON'T do it here in the request.
     * We dispatch a queued job and hand back a tracking id. The frontend polls
     * the status endpoint (below) to know when it's done. On the live free tier
     * the queue runs 'sync' (inline), but the code is written to run async so it
     * scales properly with a real queue worker.
     *
     * Supports both JSON (for API-style callers) and a normal form-submit
     * (redirect with a flash message) from the dashboard.
     */
    public function aiGenerate(Request $request)
    {
        $data = $request->validate(['prompt' => 'required|string|max:2000']);
        $trackingId = (string) Str::uuid();

        GenerateFormFromPrompt::dispatch(
            trackingId: $trackingId,
            prompt: $data['prompt'],
            userId: auth()->id(),
        );

        if ($request->wantsJson()) {
            return response()->json(['tracking_id' => $trackingId]);
        }

        return redirect()->route('forms.index')->with('status', "AI generation queued (tracking: {$trackingId}) — refresh in a few seconds.");
    }

    /**
     * Polled by the frontend to check on a queued AI generation.
     * The job writes its progress to the cache under this tracking id;
     * we just read it back. Defaults to 'pending' if nothing's there yet.
     */
    public function aiGenerateStatus(string $trackingId)
    {
        return response()->json(Cache::get("ai_gen:{$trackingId}", ['status' => 'pending']));
    }

    /**
     * Part C — final step of the Word/Excel import: turn the reviewed schema
     * into a real form.
     *
     * The import review page posts the (possibly user-corrected) schema back as
     * JSON. We re-validate it server-side before saving — never trust that what
     * comes back is still valid, even though it was valid when we showed it.
     * Uses a plain form POST (not a Livewire action) because that proved the
     * most reliable way to commit + redirect across Livewire 4's re-render
     * behaviour.
     */
    public function commitImport(Request $request, ImportJob $importJob)
    {
        // The reviewed schema (with any type fixes) is posted as JSON from the review page.
        $schema = json_decode($request->input('schema'), true);

        // Re-validate before persisting — the schema is the source of truth and
        // a broken one must never reach the database.
        $errors = app(FormSchemaValidator::class)->validateSchema($schema ?? []);
        if (!empty($errors)) {
            return back()->with('import_error', 'Cannot commit: ' . implode('; ', $errors));
        }

        $form = Form::create([
            'title' => 'Imported form ' . now()->format('M j, H:i'),
            'schema' => $schema,
            'status' => 'draft',
            'created_by' => auth()->id(),
        ]);
        // saveSchema() records a version snapshot (source: import) so the import
        // shows up in the form's version history like any other change.
        $form->saveSchema($schema, source: 'import', userId: auth()->id());

        $importJob->update(['status' => 'committed', 'form_id' => $form->id]);

        return redirect()->route('forms.builder', $form);
    }

    /**
     * Flip a form between draft and published. Only published forms expose a
     * public fill link — this is the gate that decides whether a form is live
     * to the outside world.
     */
    public function togglePublish(Form $form)
    {
        $form->update([
            'status' => $form->status === 'published' ? 'draft' : 'published',
        ]);

        return back()->with('status', $form->status === 'published'
            ? 'Form published — public link is now live.'
            : 'Form unpublished.');
    }

    /**
     * Delete a form. This is a soft delete (the Form model uses SoftDeletes),
     * so the row is flagged deleted rather than physically removed — the data
     * and its submissions could still be recovered if needed.
     */
    public function destroy(Form $form)
    {
        $form->delete(); // soft-deletes (Form model uses SoftDeletes)
        return back()->with('status', 'Form deleted.');
    }
}
