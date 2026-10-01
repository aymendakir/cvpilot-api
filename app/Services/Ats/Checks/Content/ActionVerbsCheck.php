<?php

namespace App\Services\Ats\Checks\Content;

use App\Services\Ats\Checks\ActionVerbs;
use App\Services\Ats\Checks\Check;
use App\Services\Ats\Checks\CheckContext;
use App\Services\Ats\Checks\CheckResult;

/** §6 action_verbs: at least 60 % of the experience bullets start with an action verb (EN/FR) or a French action noun. */
final class ActionVerbsCheck implements Check
{
    public const MIN_SHARE = 0.6;

    public function __construct(private readonly ActionVerbs $verbs = new ActionVerbs) {}

    public function id(): string
    {
        return 'action_verbs';
    }

    public function run(CheckContext $context): CheckResult
    {
        $bullets = $context->sections->bullets();
        if ($bullets === []) {
            return CheckResult::fail($this->id(), 'no_bullets');
        }
        $weak = array_values(array_filter($bullets, fn (string $b) => ! $this->verbs->startsWithAction($b)));
        $strong = count($bullets) - count($weak);
        $params = ['count' => $strong, 'total' => count($bullets)];

        return $strong / count($bullets) >= self::MIN_SHARE
            ? CheckResult::pass($this->id(), 'ok', $params)
            : CheckResult::fail($this->id(), 'weak_start', $params, evidence: $weak);
    }
}
