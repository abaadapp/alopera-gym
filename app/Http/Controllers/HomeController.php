<?php

namespace App\Http\Controllers;

use App\Core\Tenancy\HomeGym;
use App\Core\Tenancy\SiteDomains;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The address on its own (/). On the panel's address, when the website has its own domain (SiteDomains): the staff
 * sign-in (a signed-in user goes on to their dashboard). Otherwise the gym's website: the gym is the one named in
 * config gym.home_club (GYM_HOME_CLUB), or the only one on record; without either, / opens the staff sign-in.
 * On the club's own domain / never reaches here: it is the website's home page (ResolveCustomDomain).
 */
class HomeController extends Controller
{
    public function __invoke(Request $request, SiteDomains $domains): RedirectResponse
    {
        if ($domains->isAdminHost($request->getHost())) {
            return redirect()->route('login');
        }

        $gym = HomeGym::tenant();

        return $gym ? redirect()->route('site.home', ['club' => $gym->slug]) : redirect()->route('login');
    }
}
