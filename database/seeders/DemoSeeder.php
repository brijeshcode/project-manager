<?php

namespace Database\Seeders;

use App\Models\ChecklistItem;
use App\Models\Client;
use App\Models\Module;
use App\Models\Project;
use App\Models\Resource;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoSeeder extends Seeder
{
    /**
     * Sample data so the UI has something real to show. Idempotent on the
     * demo client's name — safe to run more than once.
     */
    public function run(): void
    {
        if (! Client::where('name', 'Northwind Trading')->exists()) {
            $this->seedWorkspace();
        }

        $this->seedResource();
    }

    private function seedResource(): void
    {
        $project = Project::where('name', 'Northwind Storefront')->first();
        $admin = User::where('email', 'admin@example.com')->first();
        if (! $project || ! $admin) {
            return;
        }

        $resource = Resource::firstOrCreate(
            ['project_id' => $project->id, 'title' => 'Staging access'],
            ['description' => 'Demo shared resource'],
        );
        if ($resource->items()->exists()) {
            return;
        }

        $item = $resource->items()->create([
            'type' => 'text',
            'label' => 'Staging URL',
            'value' => 'https://staging.northwind.test',
            'created_by' => $admin->id,
        ]);
        $item->assignees()->sync(User::whereIn('email', ['admin@example.com', 'client@example.com'])->pluck('id'));
    }

    private function seedWorkspace(): void
    {
        $admin = User::where('email', 'admin@example.com')->first();
        if ($admin && $admin->cost_rate_type === null) {
            $admin->update(['cost_rate_type' => 'hourly', 'cost_rate_amount' => 60]);
        }

        $client = Client::create([
            'name' => 'Northwind Trading',
            'currency' => 'USD',
            'contact_name' => 'Dana Reyes',
            'contact_email' => 'dana@northwind.test',
            'contact_phone' => '+1 555 0142',
            'address' => '220 Harbor St, Seattle, WA',
            'tax_number' => 'US-99-1234567',
            'payment_terms' => 'net-30',
        ]);

        // A client-side login for testing the portal.
        $clientUser = User::firstOrCreate(
            ['email' => 'client@example.com'],
            [
                'name' => 'Dana Reyes',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
                'client_id' => $client->id,
            ],
        );
        $clientUser->update(['client_id' => $client->id]);
        if (! $clientUser->hasRole('client')) {
            $clientUser->assignRole('client');
        }

        // Project 1 — active, with real work for live progress bars.
        $storefront = Project::create([
            'client_id' => $client->id,
            'name' => 'Northwind Storefront',
            'buffer_percent' => 15,
            'start_date' => now()->subWeeks(2)->startOfWeek(),
            'status' => 'active',
        ]);
        if ($admin) {
            $storefront->members()->attach($admin->id, ['role_on_project' => 'project_manager', 'access_scope' => 'all', 'active' => true]);
        }

        $module = Module::create(['project_id' => $storefront->id, 'name' => 'Checkout', 'position' => 1]);

        $specs = [
            ['Cart & line items', 'Done', 10, [true, true, true, true], 12],
            ['Payment integration', 'In Progress', 16, [true, true, false, false], 20],
            ['Order confirmation email', 'Backlog', 6, [false, false], 0],
        ];

        foreach ($specs as $i => [$title, $column, $estimate, $checks, $logged]) {
            $task = Task::create([
                'module_id' => $module->id,
                'creator_id' => $admin?->id ?? $clientUser->id,
                'title' => $title,
                'description' => '',
                'status' => 'published',
                'is_idea' => false,
                'priority' => 2,
                'estimate_hours' => $estimate,
                'scope' => 'in',
                'client_visible' => true,
                'board_column' => $column,
                'position' => $i + 1,
            ]);

            foreach ($checks as $j => $done) {
                ChecklistItem::create([
                    'checklistable_type' => Task::class,
                    'checklistable_id' => $task->id,
                    'title' => 'Step '.($j + 1),
                    'is_done' => $done,
                    'position' => $j + 1,
                ]);
            }

            if ($logged > 0 && $admin) {
                TimeEntry::create([
                    'timeable_type' => Task::class,
                    'timeable_id' => $task->id,
                    'user_id' => $admin->id,
                    'hours' => $logged,
                    'worked_on' => now()->subDays(2),
                    'started_at' => now()->subDays(2),
                    'completed_at' => now()->subDays(2)->addHours(4),
                ]);
            }
        }

        // Project 2 — a quiet draft, to show mixed states.
        Project::create([
            'client_id' => $client->id,
            'name' => 'Inventory API',
            'buffer_percent' => 10,
            'start_date' => now()->startOfWeek(),
            'status' => 'draft',
        ]);
    }
}
