<?php

namespace App\Livewire\Concerns;

use App\Exceptions\OverBudget;
use App\Models\Module;
use App\Services\ModuleBudget;
use App\Support\Money;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/**
 * The block flow for an over-budget edit (task value/hours or a lowered module budget): the edit is refused and the
 * page offers three explicit actions, all backed by App\Services\ModuleBudget:
 * (1) raise the module budget (logged through Module::updateBudget, with a reason), (2) create an add-on task,
 * (3) rebalance the other tasks (preview, then confirm). The host calls blockFrom() when it catches OverBudget
 * and implements retryBlocked() to repeat the refused save once room has been made.
 * Money actions need `manage-money`; everyone else just sees the message.
 */
trait HandlesBudgetBlock
{
    /** @var array|null kind (task|module), module, over, needCap, newCap, taskId, newUnits, title */
    #[Locked]
    public ?array $block = null;

    public string $blockReason = '';

    public bool $blockPreview = false;

    abstract protected function retryBlocked(): void;

    protected function blockFrom(OverBudget $e, array $extra): void
    {
        $this->block = $extra + ['module' => $e->module->id, 'over' => $e->over, 'needCap' => $e->needCap, 'newCap' => $e->newCap, 'taskId' => null, 'newUnits' => 0, 'title' => ''];
        $this->blockReason = ($extra['kind'] ?? 'task') === 'task' ? 'Raised to fit '.($extra['title'] ?? 'a task') : 'Raised to cover the tasks already planned';
        $this->blockPreview = false;
    }

    private function blockedModule(): Module
    {
        abort_unless($this->block && auth()->user()->can('manage-money'), 403);
        $module = Module::with('project.client')->findOrFail($this->block['module']);
        $this->authorize('update', $module->project);

        return $module;
    }

    public function dismissBlock(): void
    {
        $this->block = null;
        $this->blockPreview = false;
    }

    public function showRebalance(): void
    {
        $this->blockedModule();
        $this->blockPreview = true;
    }

    /** Action 1: raise the module budget to what is needed, logged with the reason. */
    public function raiseBlocked(): void
    {
        $module = $this->blockedModule();
        $mb = new ModuleBudget($module);
        $this->validate(['blockReason' => 'required|string|max:1000'], ['blockReason.required' => 'A reason is required to raise a budget.']);
        $need = $this->block['needCap'];
        $module->updateBudget([$mb->hourly() ? 'budget_hours' : 'budget_amount' => $mb->hourly() ? number_format($need / 100, 2, '.', '') : Money::toInput($need)], auth()->user(), $this->blockReason);
        $kind = $this->block['kind'];
        $this->dismissBlock();
        $kind === 'task' ? $this->retryBlocked() : $this->afterModuleRaise();
    }

    /** Action 3, after the preview: shrink the other tasks, then repeat the refused save. */
    public function applyBlockedRebalance(): void
    {
        $module = $this->blockedModule();
        $b = $this->block;
        try {
            (new ModuleBudget($module))->applyRebalance($b['taskId'], $b['newUnits'], $b['newCap']);
        } catch (ValidationException $e) {
            $this->addError('block', $e->errors()['rebalance'][0]);

            return;
        }
        $this->dismissBlock();
        $this->retryBlocked();
    }

    /** What the partial needs: the message, the module, whether each action is available. */
    protected function blockView(): ?array
    {
        if (! $this->block) {
            return null;
        }
        $b = $this->block;
        $module = Module::with('project.client')->find($b['module']);
        if (! $module) {
            return null;
        }
        $mb = new ModuleBudget($module);
        $money = auth()->user()->can('manage-money') && auth()->user()->can('update', $module->project);
        $addon = ['module' => $module->id, 'addon' => 1, 'title' => 'Add-on: '.$b['title']]
            + ($mb->hourly() ? ['hours' => $b['kind'] === 'task' ? $b['newUnits'] / 100 : $b['over'] / 100] : ['amount' => Money::toInput($b['kind'] === 'task' ? $b['newUnits'] : $b['over'])]);
        $rebalance = $mb->rebalancePreview($b['taskId'], $b['newUnits'], $b['newCap']);

        return [
            'b' => $b, 'module' => $module, 'mb' => $mb, 'money' => $money, 'hourly' => $mb->hourly(),
            'message' => 'This puts '.$module->name.' '.$mb->format($b['over']).' over its budget.',
            'raiseTo' => $mb->format($b['needCap']), 'addonUrl' => route('tasks.create', $addon),
            'rebalance' => $rebalance, 'preview' => $this->blockPreview && $rebalance['ok'],
        ];
    }

    protected function afterModuleRaise(): void {}
}
