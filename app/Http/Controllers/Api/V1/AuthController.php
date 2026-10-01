<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Exceptions\ErrorCode;
use App\Exceptions\UpstreamUnavailableException;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\RequestOtpRequest;
use App\Http\Requests\Auth\UpdateProfileRequest;
use App\Http\Requests\Auth\VerifyOtpRequest;
use App\Http\Resources\UserResource;
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

    /**
     * Always answers the same 201, so the response never reveals whether an email is registered
     * (SPEC decision 18.6). A new email creates the account; an existing one gets a notice email
     * after the response (no timing difference), at most one per address every 10 minutes.
     */
    public function register(RegisterRequest $r)
    {
        $d = $r->validated();
        $email = strtolower(trim($d['email']));
        $existing = User::where('email', $email)->first();

        if ($existing) {
            self::audit($r, 'register_existing_email', $existing->id);
            if (Cache::add('register-notice:'.hash('sha256', $email), true, now()->addMinutes(10))) {
                $sent = false;
                app()->terminating(function () use ($existing, &$sent) {
                    if ($sent) { // a long-lived app (tests, Octane) keeps terminating callbacks between requests (runs after the response)
                        return;
                    }
                    $sent = true;
                    try {
                        app(PlatformMail::class)->send($existing->email, 'You already have a CVPilot account', 'emails.notice', [
                            'name' => $existing->name, 'heading' => 'You already have a CVPilot account',
                            'noticeMessage' => 'Someone tried to create a CVPilot account with this email address. You already have one, so nothing was changed. Sign in, or use "Forgot password" if you need a new password. If this was not you, you can ignore this email.',
                        ]);
                    } catch (\Throwable $e) {
                        Log::warning('Account notice email failed', ['exception' => $e::class, 'message' => Redactor::scrub($e->getMessage())]);
                    }
                });
            }
        } else {
            $u = new User;
            $u->name = $d['name'];
            $u->email = $email;
            $u->password = Hash::make($d['password']);
            $u->save();
            self::audit($r, 'register', $u->id);
        }

        return response()->json(['message' => 'Account created. Request your verification code.'], 201);
    }

    public function login(LoginRequest $r)
    {
        $d = $r->validated();
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

        return UserResource::make($u);
    }

    public function code(RequestOtpRequest $r)
    {
        $d = $r->validated();
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

    public function verify(VerifyOtpRequest $r)
    {
        $d = $r->validated();
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

    /** v1: 204 No Content. */
    public function logout(Request $r)
    {
        $this->signOut($r);

        return response()->noContent();
    }

    /** Legacy response (POST logout): 200 with a message. Removed with the legacy aliases. */
    public function logoutLegacy(Request $r)
    {
        $this->signOut($r);

        return ['message' => 'Signed out.'];
    }

    private function signOut(Request $r): void
    {
        self::audit($r, 'logout', $r->user()->id);
        $r->session()->invalidate();
        $r->session()->regenerateToken();
    }

    public function profile(UpdateProfileRequest $r)
    {
        $u = $r->user();
        $u->fill($r->validated());
        $u->save();

        return UserResource::make($u);
    }

    public function password(ChangePasswordRequest $r)
    {
        $d = $r->validated();
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
