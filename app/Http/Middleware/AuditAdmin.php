<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Api\V1\AuthController;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Uniform audit trail for the admin area (SPEC §7 item 8): every write, and every read of user
 * content, is recorded as `admin.<route name>` (failed attempts as `admin.<route name>.failed`).
 * Existing, more specific events written by controllers are kept.
 */
class AuditAdmin
{
    /** GET routes that expose user data (the others only show configuration or aggregates). */
    private const CONTENT_READS = [
        'v1.admin.users.index', 'v1.admin.users.show', 'v1.admin.applications.index',
        'v1.admin.contact-messages.index', 'v1.admin.audit-events.index',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $name = (string) $request->route()?->getName();
        $isWrite = ! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true);
        $isContentRead = $request->isMethod('GET') && (in_array($name, self::CONTENT_READS, true) || $this->legacyContentRead($name));

        if (($isWrite || $isContentRead) && $request->user()) {
            $event = 'admin.'.preg_replace('/^v1\.admin\./', '', $name).($response->getStatusCode() >= 400 ? '.failed' : '');
            AuthController::audit($request, mb_substr($event, 0, 250), $request->user()->id);
        }

        return $response;
    }

    /** Legacy aliases of the content reads (named `legacy.get.<uri>`). */
    private function legacyContentRead(string $name): bool
    {
        return in_array($name, [
            'legacy.get.admin.users', 'legacy.get.admin.users.user', 'legacy.get.admin.applications',
            'legacy.get.admin.contact-messages', 'legacy.get.admin.logs',
        ], true);
    }
}
