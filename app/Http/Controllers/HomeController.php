<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;

/**
 * The address on its own (/): the gym's website. The gym is the one named in config gym.home_club (GYM_HOME_CLUB),
 * or the only one on record; without either, / opens the staff sign-in.
 */
class HomeController extends Controller
{
    public function __invoke(): RedirectResponse
    {
        $slug = config('gym.home_club') ?: (Tenant::query()->count() === 1 ? Tenant::query()->value('slug') : null);

        return $slug && Tenant::where('slug', $slug)->exists()
            ? redirect()->route('site.home', ['club' => $slug])
            : redirect()->route('login');
    }
}
