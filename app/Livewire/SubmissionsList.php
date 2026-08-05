<?php

namespace App\Livewire;

use App\Models\Form;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class SubmissionsList extends Component
{
    use WithPagination;

    public Form $form;

    #[Url]
    public string $search = '';

    public function mount(Form $form): void
    {
        $this->form = $form;
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $query = $this->form->submissions()->latest();

        if ($this->search !== '') {
            $query->whereRaw('JSON_SEARCH(data, "one", ?) IS NOT NULL', ['%' . $this->search . '%']);
        }

        $submissions = $query->paginate(20);

        return view('livewire.submissions-list', [
            'submissions' => $submissions,
            'fieldKeys' => collect($this->form->flattenedFields())->pluck('key')->all(),
        ]);
    }
}
