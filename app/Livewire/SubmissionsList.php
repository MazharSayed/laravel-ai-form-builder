<?php

namespace App\Livewire;

use App\Models\Form;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Part A — the submissions list for a form: paginated, searchable, exportable.
 *
 * A Livewire full-page component. Search and pagination are done SERVER-SIDE
 * (not in the browser), so the list stays fast even with many submissions —
 * we never load them all at once. The table's columns are derived from the
 * form's own schema, so it always matches whatever fields that form has.
 */
#[Layout('layouts.app')]
class SubmissionsList extends Component
{
    use WithPagination; // gives us Livewire's built-in server-side pagination

    public Form $form;

    // #[Url] keeps the search term in the browser's query string, so a searched
    // view is shareable/bookmarkable and survives a page refresh.
    #[Url]
    public string $search = '';

    public function mount(Form $form): void
    {
        $this->form = $form;
    }

    /**
     * Livewire lifecycle hook — fires automatically whenever $search changes.
     * We reset to page 1 so the user isn't stranded on, say, page 5 of results
     * that no longer exist after filtering.
     */
    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        // Base query: this form's submissions, newest first.
        $query = $this->form->submissions()->latest();

        // Search runs against the JSON `data` column using MySQL's JSON_SEARCH,
        // so it matches on ANY submitted value (name, email, etc.) without
        // needing a separate column per field. Good enough at this scale; a
        // high-volume version would denormalise searchable fields into columns.
        if ($this->search !== '') {
            $query->whereRaw('JSON_SEARCH(data, "one", ?) IS NOT NULL', ['%' . $this->search . '%']);
        }

        // 20 per page, paginated server-side.
        $submissions = $query->paginate(20);

        // Build the table columns from the form's own fields. We also pull out
        // which fields are file uploads, so the view can render those as a
        // secure "View / Download" link instead of showing a raw storage path.
        $fields = collect($this->form->flattenedFields());

        return view('livewire.submissions-list', [
            'submissions'   => $submissions,
            'fieldKeys'     => $fields->pluck('key')->all(),
            'fileFieldKeys' => $fields->where('type', 'file')->pluck('key')->all(),
        ]);
    }
}
