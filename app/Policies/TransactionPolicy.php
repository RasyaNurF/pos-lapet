<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Transaction;
use App\Models\User;

class TransactionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('transactions.view');
    }

    public function view(User $user, Transaction $transaction): bool
    {
        return $user->can('transactions.view');
    }

    public function print(User $user, Transaction $transaction): bool
    {
        return $user->can('transactions.print');
    }

    public function void(User $user, Transaction $transaction): bool
    {
        return $user->can('pos.void');
    }
}
