<?php
namespace App\Http\Controllers;

use App\Models\Integration;
use App\Services\AiGateway;
use Illuminate\Http\Request;

class IntegrationController {
    public function index(AiGateway $ai) {
        return [
            'items' => Integration::orderBy('type')
                ->orderBy('priority')
                ->orderBy('id')
                ->get(['id', 'provider', 'type', 'model', 'settings', 'enabled', 'priority', 'tested_at', 'last_error', 'updated_at']),
            'catalog' => $ai->providers(),
            'job_providers' => ['jsearch', 'adzuna', 'jooble', 'arbeitnow'],
        ];
    }

    public function store(Request $r) {
        $d = $r->validate([
            'provider' => 'required|in:openai,anthropic,gemini,groq,mistral,openrouter,bazaarlink,jsearch,adzuna,jooble,arbeitnow',
            'type' => 'required|in:ai,jobs',
            'secret' => 'nullable|string|max:1000',
            'model' => 'nullable|string|max:120',
            'settings' => 'nullable|array',
            'enabled' => 'required|boolean',
            'priority' => 'nullable|integer|min:1|max:999',
        ]);

        $item = Integration::firstOrNew(['provider' => $d['provider']]);
        if (!$item->exists && empty($d['secret']) && $d['provider'] !== 'arbeitnow') {
            abort(422, 'API key is required.');
        }

        $defaultPriority = (Integration::where('type', $d['type'])->max('priority') ?? 0) + 1;
        $priority = $d['priority'] ?? ($item->priority ?: $defaultPriority);

        $item->fill([
            'type' => $d['type'],
            'model' => $d['model'] ?? null,
            'settings' => $d['settings'] ?? null,
            'enabled' => $d['enabled'],
            'priority' => $priority,
        ]);

        if (!empty($d['secret'])) {
            $item->secret = $d['secret'];
        } elseif ($d['provider'] === 'arbeitnow' && !$item->secret) {
            $item->secret = 'public';
        }

        $item->save();
        AuthController::audit($r, 'integration_saved:' . $item->provider, $r->user()->id);

        return [
            'message' => 'Integration saved with encrypted credentials.',
            'item' => $item->only(['id', 'provider', 'type', 'model', 'enabled', 'priority', 'updated_at']),
        ];
    }

    public function update(Request $r, Integration $integration) {
        $d = $r->validate([
            'secret' => 'nullable|string|max:1000',
            'model' => 'nullable|string|max:120',
            'settings' => 'nullable|array',
            'enabled' => 'nullable|boolean',
            'priority' => 'nullable|integer|min:1|max:999',
        ]);

        if (empty($d['secret'])) {
            unset($d['secret']);
        }

        $integration->fill($d);

        if (array_key_exists('secret', $d) || array_key_exists('model', $d)) {
            $integration->tested_at = null;
            $integration->last_error = null;
        }

        $integration->save();
        AuthController::audit($r, 'integration_updated:' . $integration->provider, $r->user()->id);

        return ['item' => $integration];
    }

    public function reorder(Request $r) {
        $d = $r->validate([
            'items' => 'required|array',
            'items.*.id' => 'required|integer|exists:integrations,id',
            'items.*.priority' => 'required|integer|min:1|max:999',
        ]);

        foreach ($d['items'] as $entry) {
            Integration::where('id', $entry['id'])->update(['priority' => $entry['priority']]);
        }

        AuthController::audit($r, 'integrations_reordered', $r->user()->id);
        return ['ok' => true, 'message' => 'Priority order updated successfully.'];
    }

    public function test(Request $r, Integration $integration, AiGateway $ai) {
        try {
            if ($integration->type === 'ai') {
                $result = $ai->test($integration);
            } else {
                $result = app(\App\Services\JobSearchService::class)->search([
                    'provider' => $integration->provider,
                    'q' => 'software developer',
                    'country' => 'DE',
                    'country_name' => 'Germany',
                    'page' => 1,
                ]);
                $result = ['ok' => true, 'message' => 'Connected. ' . count($result['data']) . ' sample jobs returned.'];
            }

            $integration->update(['tested_at' => now(), 'last_error' => null]);
            return $result;
        } catch (\Throwable $e) {
            $err = mb_substr($e->getMessage(), 0, 500);
            $integration->update(['last_error' => $err]);
            return response()->json([
                'ok' => false,
                'message' => 'Connection failed: ' . $err,
            ], 422);
        }
    }

    public function destroy(Request $r, Integration $integration) {
        $provider = $integration->provider;
        $type = $integration->type;
        $integration->delete();

        // Re-number remaining priorities in this category
        $remaining = Integration::where('type', $type)->orderBy('priority')->orderBy('id')->get();
        foreach ($remaining as $idx => $rem) {
            $rem->update(['priority' => $idx + 1]);
        }

        AuthController::audit($r, 'integration_deleted:' . $provider, $r->user()->id);
        return response()->noContent();
    }
}
