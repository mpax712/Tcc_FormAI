<?php

namespace App\Application\Actions;

use App\Domain\Grading\Enums\GradingRunStatus;
use App\Domain\Grading\Models\GradingRun;
use App\Domain\QuestionBank\Enums\QuestionType;
use App\Domain\Submissions\Models\Answer;
use App\Jobs\GenerateAiSuggestion;
use DomainException;

class DispatchAiGradingAction
{
    public function execute(Answer $answer): GradingRun
    {
        $answer->loadMissing('activityQuestion');
        if ($answer->activityQuestion->type !== QuestionType::Essay) {
            throw new DomainException('A IA e utilizada somente em questoes dissertativas.');
        }

        $version = (int) config('formai.prompt_version');
        $key = hash('sha256', implode(':', [$answer->id, $answer->version, $version, config('services.openai.model')]));
        $run = GradingRun::query()->firstOrCreate(['idempotency_key' => $key], [
            'answer_id' => $answer->id,
            'status' => GradingRunStatus::Pending,
            'provider' => config('formai.ai_provider'),
            'model' => config('services.openai.model'),
            'prompt_version' => $version,
        ]);

        if ($run->wasRecentlyCreated) {
            GenerateAiSuggestion::dispatch($run->id)->onQueue('ai');
        }

        return $run;
    }
}
