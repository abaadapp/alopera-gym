<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The whole club's money — the books, the bank accounts, the payroll runs — is not a branch's: someone tied to a branch
 * opens it only with accounting.all_branches (User::reachesClubFinance). Their branch's figures are in the reports.
 */
class EnsureClubFinance
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->reachesClubFinance(), 403, 'مالية النادي كله لا تُفتح من حساب مرتبط بفرع إلا بصلاحية «مالية النادي كله». أرقام فرعك في التقارير.');

        return $next($request);
    }
}
