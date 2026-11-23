<?php

namespace App\Livewire\Settings;

use App\Support\Settings;
use Illuminate\Validation\Rule;
use Livewire\Component;

/** Super-admin page: system-wide defaults (rows per page, default sidebar layout, number format). */
class System extends Component
{
    public int|string $pageSize = 15;

    public string $sidebarLayout = 'full';

    public string $numberFormat = 'international';

    public string $dashboardTab = 'financial';

    public function mount(): void
    {
        $this->authorize('manage-system');
        $this->pageSize = Settings::pageSize();
        $this->sidebarLayout = Settings::sidebarLayout();
        $this->numberFormat = Settings::numberFormat();
        $this->dashboardTab = Settings::dashboardDefaultTab();
    }

    public function save(): void
    {
        $this->authorize('manage-system');
        $data = $this->validate([
            'pageSize' => ['required', 'integer', Rule::in(Settings::PAGE_SIZES)],
            'sidebarLayout' => ['required', Rule::in(array_keys(Settings::SIDEBARS))],
            'numberFormat' => ['required', Rule::in(array_keys(Settings::NUMBER_FORMATS))],
            'dashboardTab' => ['required', Rule::in(array_keys(Settings::DASHBOARD_TABS))],
        ]);
        Settings::set('page_size', (string) $data['pageSize'], 'system');
        Settings::set('sidebar_layout', $data['sidebarLayout'], 'system');
        Settings::set('number_format', $data['numberFormat'], 'system');
        Settings::set('dashboard_default_tab', $data['dashboardTab'], 'system');
        $this->dispatch('sidebar-default', layout: $data['sidebarLayout']);
        $this->dispatch('toast', type: 'success', message: 'System settings saved.');
    }

    public function render()
    {
        return view('livewire.settings.system');
    }
}
