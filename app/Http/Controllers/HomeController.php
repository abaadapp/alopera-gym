<?php

namespace App\Http\Controllers;

use App\Core\Tenancy\HomeGym;
use Illuminate\Http\RedirectResponse;

/**
 * The address on its own (/): the gym's website. The gym is the one named in config gym.home_club (GYM_HOME_CLUB),
 * or the only one on record; without either, / opens the staff sign-in.
 */
class HomeController extends Controller
{
    public function __invoke(): RedirectResponse
    {
        $gym = HomeGym::tenant();

        return $gym ? redirect()->route('site.home', ['club' => $gym->slug]) : redirect()->route('login');
    }
}
