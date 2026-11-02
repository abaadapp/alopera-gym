<?php

namespace App\Core\Tenancy;

/**
 * A branch's record that is also a line of the whole club's money (an employee's line in the club's payroll run):
 * whoever reaches the club's money (User::reachesClubFinance) reaches it whatever its branch; anyone else tied to a
 * branch only as BranchOwned allows.
 */
interface ClubFinanceRecord extends BranchOwned {}
