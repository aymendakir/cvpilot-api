<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Exceptions\ErrorCode;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Requires a signed-in member. Unknown or revoked sessions are 401 (unauthenticated);
 * a suspended or unverified account is 403 with its own code (SPEC §6).
 */
class AuthSession
{
    public function handle($request, \Closure $next)
    {
        $user = User::find($request->session()->get('user_id'));
        if (! $user || $request->session()->get('session_version') !== $user->session_version) {
            throw new ApiException(ErrorCode::Unauthenticated);
        }
        if ($user->suspended) {
            throw new ApiException(ErrorCode::AccountSuspended);
        }
        if (! $user->verified_at) {
            throw new ApiException(ErrorCode::EmailNotVerified);
        }
        if (! $user->last_seen_at || Carbon::parse($user->last_seen_at)->lt(now()->subMinutes(5))) {
            $user->last_seen_at = now();
            $user->save();
        }$request->setUserResolver(fn () => $user);

        return $next($request);
    }
}
