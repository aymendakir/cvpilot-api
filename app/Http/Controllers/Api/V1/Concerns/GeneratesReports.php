<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Http\Controllers\Api\V1\AuthController;
use App\Models\CareerReport;
use App\Services\AdminReview;
use App\Services\AiGateway;
use Illuminate\Http\Request;

trait GeneratesReports
{
    private function documents(Request $r, bool $identity = false): array
    {
        $rules = ['cv_text' => 'required|string|min:30|max:30000', 'job_description' => 'required|string|min:60|max:30000'];
        if ($identity) {
            $rules += ['title' => 'required|string|max:180', 'company' => 'nullable|string|max:180'];
        }

        return $r->validate($rules);
    }

    private function report(Request $r, AiGateway $ai, string $type, string $prompt, array $input): array
    {
        $result = $ai->chat($prompt, $type, $r->user()->id);
        $row = CareerReport::create(['user_id' => $r->user()->id, 'type' => $type, 'input' => $input, 'output' => $result['answer']]);
        AdminReview::record('report', $row);
        AuthController::audit($r, $type.'_generated', $r->user()->id);

        return $result + ['report_id' => $row->id];
    }
}
