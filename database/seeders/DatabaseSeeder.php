<?php

namespace Database\Seeders;

use App\Models\Form;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * A seeder is just a script that fills the database with starting data
     * automatically, so you don't have to click "Insert row" in phpMyAdmin
     * by hand every time. This matters a lot during development because
     * commands like `migrate:fresh` wipe every table clean — running
     * `php artisan db:seed` right after instantly gives you a working
     * demo user and a working example form again, with zero manual typing.
     *
     * It also matters for this specific assignment: the brief asks for
     * "migrations and seeders" as a deliverable, because whoever reviews
     * this project wants to run it and immediately see something real,
     * not an empty database with no forms and no login to test with.
     */
    public function run(): void
    {
        // Creates the demo login (demo@example.com / password) — see
        // UserSeeder.php. Kept as its own seeder file (rather than inline
        // here) just to keep "create a user" and "create a form" as two
        // separate, readable steps.
        $this->call(UserSeeder::class);

        // Creates one ready-to-use form so there's something to click into
        // on the dashboard, fill out on its public page, and see show up
        // in the submissions list — without building one through the UI first.
        Form::create([
            'title' => 'Internship Application',
            'description' => 'Seeded example form',
            'status' => 'published',
            'schema' => [
                'version' => 1,
                'sections' => [[
                    'id' => 'sec_intro',
                    'title' => 'Applicant Details',
                    'description' => null,
                    'fields' => [
                        [
                            'id' => 'fld_name', 'key' => 'full_name', 'type' => 'text',
                            'label' => 'Full name', 'required' => true,
                            'placeholder' => null, 'help_text' => null, 'default' => null,
                            'options' => [], 'validation' => ['min_length' => 2, 'max_length' => 100], 'visible_if' => null,
                        ],
                        [
                            'id' => 'fld_email', 'key' => 'email', 'type' => 'email',
                            'label' => 'Email', 'required' => true,
                            'placeholder' => null, 'help_text' => null, 'default' => null,
                            'options' => [], 'validation' => [], 'visible_if' => null,
                        ],
                        [
                            'id' => 'fld_resume', 'key' => 'resume', 'type' => 'file',
                            'label' => 'Resume', 'required' => true,
                            'placeholder' => null, 'help_text' => null, 'default' => null,
                            'options' => [], 'validation' => ['allowed_types' => ['pdf','doc','docx'], 'max_size_kb' => 5120], 'visible_if' => null,
                        ],
                    ],
                ]],
            ],
        ]);
    }
}
