<?php

namespace App\Models;

use App\States\LoanApplicationState;
use Illuminate\Database\Eloquent\Model;
use Spatie\ModelStates\HasStates;

class LoanApplication extends Model
{
    use HasStates;

    protected $fillable = ['customer_id', 'amount', 'currency', 'term_months'];

    protected function casts(): array
    {
        return [
            'status' => LoanApplicationState::class,
            'amount' => 'decimal:2',
            'term_months' => 'integer',
        ];
    }

    public function getRouteKey(): string
    {
        return 'LA'.($this->id + 1000);
    }

    public function resolveRouteBinding($value, $field = null)
    {
        if (! preg_match('/^LA([1-9][0-9]*)$/', $value, $matches)) {
            return null;
        }

        return $this->whereKey((int) $matches[1] - 1000)->first();
    }
}
