<?php

namespace App\Livewire\Concerns;

use App\Exceptions\OverBudget;
use App\Models\Module;
use App\Services\ModuleBudget;
use App\Support\Money;
use Livewire\Attributes\Locked;

/**
 * The block flow for a manual FIXED edit that would push a module over its amount (a task's typed value, or a
 * lowered module amount): the edit is refused and the page offers ONE action, raising the module amount (logged
 * through Module::updateBudget with an editable reason). The host calls blockFrom() when it catches OverBudget and
 * implements retryBlocked() to repeat the refused save once room has been made. Needs `manage-money`; everyone
 * else just sees the message.
 */
trait HandlesBudgetBlock
{
    /** @var array|null kind (task|module), module, over, needCap, newCap, title */
    #[Locked]
    public ?array $block = null;

    public string $blockReason = '';

    abstract protected function retryBlocked(): void;

    protected function blockFrom(OverBudget $e, array $extra): void
    {
        $this->block = $extra + ['module' => $e->module->id, 'over' => $e->over, 'needCap' => $e->needCap, 'newCap' => $e->newCap, 'title' => ''];
        $this->blockReason = ($extra['kind'] ?? 'task') === 'task' ? 'Raised to fit '.($extra['title'] ?? 'a task') : 'Set to the amount the tasks already use';
    }

    public function dismissBlock(): void
    {
        $this->block = null;
    }

    /** The one action: set the module amount to what is needed, logged with the reason. */
    public function raiseBlocked(): void
    {
        abort_unless($this->block && auth()->user()->can('manage-money'), 403);
        $module = Module::with('project.client')->findOrFail($this->block['module']);
        $this->authorize('update', $module->project);
        $this->validate(['blockReason' => 'required|string|max:1000'], ['blockReason.required' => 'A reason is required to change a budget.']);
        $module->updateBudget(['budget_amount' => Money::toInput($this->block['needCap'])], auth()->user(), $this->blockReason);
        $kind = $this->block['kind'];
        $this->dismissBlock();
        $kind === 'task' ? $this->retryBlocked() : $this->afterModuleRaise();
    }

    /** What the partial needs: the message, the module, and whether the action is available. */
    protected function blockView(): ?array
    {
        if (! $this->block || ! ($module = Module::with('project.client')->find($this->block['module']))) {
            return null;
        }
        $mb = new ModuleBudget($module);

        return [
            'b' => $this->block, 'module' => $module,
            'money' => auth()->user()->can('manage-money') && auth()->user()->can('update', $module->project),
            'message' => 'This puts '.$module->name.' '.$mb->format($this->block['over']).' over its budget.',
            'raiseTo' => $mb->format($this->block['needCap']),
        ];
    }

    protected function afterModuleRaise(): void {}
}
