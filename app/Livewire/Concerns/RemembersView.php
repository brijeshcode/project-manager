<?php

namespace App\Livewire\Concerns;

use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

/** The cards/list switch ($view) is remembered per page in a cookie: a ?view= in the URL wins, else the last choice. */
trait RemembersView
{
    public function mountRemembersView(): void
    {
        $saved = request()->cookie($this->viewCookie());
        if (! request()->has('view') && in_array($saved, ['cards', 'list'], true)) {
            $this->view = $saved;
        }
    }

    public function updatedRemembersView(string $property, mixed $value): void
    {
        if ($property === 'view' && in_array($value, ['cards', 'list'], true)) {
            Cookie::queue($this->viewCookie(), $value, 60 * 24 * 365);
        }
    }

    private function viewCookie(): string
    {
        return 'view_'.Str::snake(str_replace(['App\\Livewire\\', '\\'], ['', ''], static::class));
    }
}
