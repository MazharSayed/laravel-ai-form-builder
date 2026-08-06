<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateFormFromPrompt;
use App\Models\Form;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use App\Models\ImportJob;
use App\Services\FormSchemaValidator;

class FormController extends Controller
{
    public function index()
    {
        $forms = Form::latest()->paginate(15);
        return view('forms.index', compact('forms'));
    }

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

    public function aiGenerateStatus(string $trackingId)
    {
        return response()->json(Cache::get("ai_gen:{$trackingId}", ['status' => 'pending']));
    }

    public function commitImport(Request $request, ImportJob $importJob)
    {
        // The reviewed schema (with any type fixes) is posted as JSON from the review page.
        $schema = json_decode($request->input('schema'), true);

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
        $form->saveSchema($schema, source: 'import', userId: auth()->id());

        $importJob->update(['status' => 'committed', 'form_id' => $form->id]);

        return redirect()->route('forms.builder', $form);
    }

    public function togglePublish(Form $form)
    {
        $form->update([
            'status' => $form->status === 'published' ? 'draft' : 'published',
        ]);

        return back()->with('status', $form->status === 'published'
            ? 'Form published — public link is now live.'
            : 'Form unpublished.');
    }
}
