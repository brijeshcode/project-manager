<?php

namespace App\Models;

use App\Enums\ExpenseFrequency;
use App\Models\Concerns\HasCurrency;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Expense extends Model
{
    use HasCurrency;

    protected $fillable = ['expense_category_id', 'description', 'payee', 'currency', 'currency_id', 'amount_cents', 'exchange_rate', 'incurred_on', 'client_id', 'project_id', 'frequency', 'remind_days', 'repeated_from_id', 'notes'];

    protected function casts(): array
    {
        return ['incurred_on' => 'date', 'frequency' => ExpenseFrequency::class, 'exchange_rate' => 'decimal:6', 'remind_days' => 'integer'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(ExpensePayment::class);
    }

    /** Bills, receipts. Admin only: the download route checks manage-money. */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function paidCents(): int
    {
        return (int) $this->payments->sum('amount_cents');
    }

    public function dueCents(): int
    {
        return max(0, $this->amount_cents - $this->paidCents());
    }

    /** $cents of this expense's currency in the local currency at its own rate; null without a rate. */
    public function toLocal(int $cents): ?int
    {
        return $this->exchange_rate === null ? null : Money::convert($cents, (string) $this->exchange_rate);
    }

    /** 'paid' | 'partly' | 'unpaid', from what was paid against the amount. */
    public function status(): string
    {
        $paid = $this->paidCents();

        return $paid >= $this->amount_cents ? 'paid' : ($paid > 0 ? 'partly' : 'unpaid');
    }

    /** Not fully paid yet. */
    public function scopeDue(Builder $q): void
    {
        $q->whereRaw('expenses.amount_cents > (select coalesce(sum(amount_cents), 0) from expense_payments where expense_payments.expense_id = expenses.id)');
    }

    /** Inside its reminder window (or overdue) as of $today; pair with the due() scope. */
    public function remindable(?\Carbon\CarbonInterface $today = null): bool
    {
        return $this->incurred_on->copy()->subDays($this->remind_days)->lte($today ?? today());
    }
}
