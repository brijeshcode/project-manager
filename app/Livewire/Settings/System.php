<?php

namespace App\Livewire\Settings;

use App\Support\Settings;
use Illuminate\Validation\Rule;
use Livewire\Component;

/** Super-admin page: system-wide defaults (rows per page, default sidebar layout). */
class System extends Component
{
    public int|string $pageSize = 15;

    public string $sidebarLayout = 'full';

    public function mount(): void
    {
        $this->authorize('manage-system');
        $this->pageSize = Settings::pageSize();
        $this->sidebarLayout = Settings::sidebarLayout();
    }

    public function save(): void
    {
        $this->authorize('manage-system');
        $data = $this->validate([
            'pageSize' => ['required', 'integer', Rule::in(Settings::PAGE_SIZES)],
            'sidebarLayout' => ['required', Rule::in(array_keys(Settings::SIDEBARS))],
        ]);
        Settings::set('page_size', (string) $data['pageSize'], 'system');
        Settings::set('sidebar_layout', $data['sidebarLayout'], 'system');
        $this->dispatch('toast', type: 'success', message: 'System settings saved.');
    }

    public function render()
    {
        return view('livewire.settings.system');
    }
}
