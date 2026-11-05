<?php

namespace App\Modules\Accounting\Services;

use App\Core\Support\BusinessRuleException;
use App\Core\Support\Money;
use App\Modules\Accounting\Models\Account;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

/**
 * Money paid out of a cash box or a bank account only while that account holds it.
 *
 * One payment out of an account at a time: exclusive() holds a lock on the account across the whole operation — the
 * balance read, the save and the posting, including postings made once the transaction has committed — so two payments
 * at once cannot both spend the same money. covers() reads the account's balance from the books inside that turn.
 * Card and gateway clearing accounts are not cash in hand and are not checked; nor is a correction that moves no money
 * (cancelling a payment entered by mistake). A balance already below zero is left as it is: it pays nothing more until
 * money comes in. There is no overdraft allowance: an account would need its own limit, which is a separate decision.
 */
final class AccountFunds
{
    public function __construct(private readonly FinanceReports $reports) {}

    /** Accounts this guard applies to: cash boxes and bank accounts. */
    public static function guards(?Account $account): bool
    {
        return $account !== null && in_array($account->kind, Account::PAYING_KINDS, true);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    public function exclusive(?Account $account, callable $operation): mixed
    {
        if (! self::guards($account)) {
            return $operation();
        }

        return Cache::lock("account-funds:{$account->tenant_id}:{$account->id}", 30)->block(15, $operation);
    }

    /** The account holds the amount, or nothing is paid: said with the figures to whoever may see the books. */
    public function covers(?Account $account, string|float $amount, string $field = 'account_id'): void
    {
        if (! self::guards($account) || Money::toMinor($amount) <= 0) {
            return;
        }
        $balance = $this->reports->balance($account);
        if (Money::cmp($balance, $amount) >= 0) {
            return;
        }
        $user = Auth::guard('web')->user();
        throw new BusinessRuleException('funds.insufficient', $user?->hasPermission('accounting.view')
            ? "رصيد «{$account->name}» لا يكفي: المتاح {$balance} والمطلوب ".Money::normalize($amount).'.'
            : "رصيد «{$account->name}» لا يكفي لهذا المبلغ.", $field);
    }
}
