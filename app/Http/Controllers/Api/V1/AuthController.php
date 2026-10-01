<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Exceptions\ErrorCode;
use App\Exceptions\UpstreamUnavailableException;
use App\Models\User;
use App\Services\PlatformMail;
use App\Support\Redactor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class AuthController
{
    public static function audit(Request $r, string $event, ?int $id = null)
    {
        DB::table('audit_events')->insert(['user_id' => $id, 'event' => $event, 'ip' => $r->ip(), 'user_agent' => substr($r->userAgent() ?? '', 0, 512), 'created_at' => now()]);
    }

    public function register(Request $r)
    {
        $r->merge(['email' => strtolower(trim((string) $r->input('email')))]);
        $d = $r->validate(['name' => 'required|string|max:120', 'email' => 'required|email|max:254|unique:users', 'password' => 'required|string|min:12|max:128|confirmed']);
        $u = new User;
        $u->name = $d['name'];
        $u->email = strtolower(trim($d['email']));
        $u->password = Hash::make($d['password']);
        $u->save();
        self::audit($r, 'register', $u->id);

        return response()->json(['message' => 'Account created. Request your verification code.'], 201);
    }

    public function login(Request $r)
    {
        $d = $r->validate(['email' => 'required|email', 'password' => 'required|string']);
        $u = User::where('email', strtolower(trim($d['email'])))->first();
        if (! $u || ! Hash::check($d['password'], $u->password)) {
            self::audit($r, 'login_failed');

            throw new ApiException(ErrorCode::InvalidCredentials);
        }
        if ($u->suspended) {
            self::audit($r, 'login_blocked', $u->id);

            throw new ApiException(ErrorCode::AccountSuspended);
        }
        if (! $u->verified_at) {
            throw new ApiException(ErrorCode::EmailNotVerified);
        }
        $r->session()->regenerate();
        $r->session()->put('user_id', $u->id);
        $r->session()->put('session_version', $u->session_version);
        self::audit($r, 'login', $u->id);

        return $u;
    }

    public function code(Request $r)
    {
        $d = $r->validate(['email' => 'required|email', 'purpose' => 'required|in:verify,reset']);
        $u = User::where('email', strtolower(trim($d['email'])))->first();
        if ($u) {
            $code = (string) random_int(100000, 999999);
            $key = 'otp:'.$d['purpose'].':'.$u->id;
            Cache::put($key, ['hash' => Hash::make($code), 'attempts' => 0, 'expires' => now()->addMinutes(10)->timestamp], now()->addMinutes(10));
            try {
                app(PlatformMail::class)->send($u->email, $d['purpose'] === 'reset' ? 'Reset your CVPilot password' : 'Verify your CVPilot account', 'emails.verification', ['name' => $u->name, 'code' => $code, 'purpose' => $d['purpose']]);
            } catch (\Throwable $e) {
                Cache::forget($key);
                Log::warning('Verification email delivery failed', ['exception' => $e::class, 'message' => Redactor::scrub($e->getMessage())]);

                throw new UpstreamUnavailableException('Email delivery failed.', $e);
            }
        }

        return ['message' => 'If this account exists, a code has been sent.'];
    }

    public function verify(Request $r)
    {
        $d = $r->validate(['email' => 'required|email', 'purpose' => 'required|in:verify,reset', 'code' => 'required|digits:6', 'password' => 'required_if:purpose,reset|nullable|string|min:12|max:128|confirmed']);
        $u = User::where('email', strtolower(trim($d['email'])))->first();
        abort_unless($u, 422, 'Invalid or expired code.');
        $key = 'otp:'.$d['purpose'].':'.$u->id;

        return Cache::lock($key.':lock', 10)->block(3, function () use ($r, $d, $u, $key) {
            $v = Cache::get($key);
            abort_unless($v && $v['attempts'] < 5 && $v['expires'] > time(), 422, 'Invalid or expired code.');
            if (! Hash::check($d['code'], $v['hash'])) {
                Cache::put($key, ['hash' => $v['hash'], 'attempts' => $v['attempts'] + 1, 'expires' => $v['expires']], max(1, $v['expires'] - time()));
                abort(422, 'Invalid code.');
            }Cache::forget($key);
            if ($d['purpose'] === 'reset') {
                $u->password = Hash::make($d['password']);
                $u->session_version++;
            } else {
                $u->verified_at = now();
            }$u->save();
            self::audit($r, $d['purpose'] === 'reset' ? 'password_reset' : 'email_verified', $u->id);

            return ['message' => 'Completed. Please sign in.'];
        });
    }

    public function logout(Request $r)
    {
        self::audit($r, 'logout', $r->user()->id);
        $r->session()->invalidate();
        $r->session()->regenerateToken();

        return ['message' => 'Signed out.'];
    }

    public function profile(Request $r)
    {
        $u = $r->user();
        $u->fill($r->validate(['name' => 'sometimes|required|string|max:120', 'phone' => 'nullable|string|max:40', 'country' => 'nullable|string|size:2', 'city' => 'nullable|string|max:120', 'language' => 'sometimes|in:en,fr,es,ar', 'target_role' => 'nullable|string|max:120', 'experience_level' => 'nullable|string|max:80', 'preferred_countries' => 'nullable|array|max:20', 'preferred_countries.*' => 'string|max:80', 'work_modes' => 'nullable|array|max:5', 'work_modes.*' => 'string|max:30', 'preferences' => 'nullable|array']));
        $u->save();

        return $u;
    }

    public function password(Request $r)
    {
        $d = $r->validate(['current_password' => 'required', 'password' => 'required|string|min:12|max:128|confirmed']);
        abort_unless(Hash::check($d['current_password'], $r->user()->password), 422, 'Current password is incorrect.');
        $u = $r->user();
        $u->password = Hash::make($d['password']);
        $u->session_version++;
        $u->save();
        $r->session()->regenerate();
        $r->session()->put('session_version', $u->session_version);
        self::audit($r, 'password_changed', $u->id);

        return ['message' => 'Password changed.'];
    }
}
