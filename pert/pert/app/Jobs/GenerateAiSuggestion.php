<?php

namespace App\Jobs;

use App\Application\DTOs\GradingRequest;
use App\Domain\Grading\Contracts\AiGradingProvider;
use App\Domain\Grading\Enums\GradingRunStatus;
use App\Domain\Grading\Models\GradingRun;
use App\Domain\Submissions\Enums\SubmissionStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class GenerateAiSuggestion implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 60;
    public array $backoff = [30, 120, 300];

    public function __construct(public readonly int $gradingRunId) {}

    public function handle(AiGradingProvider $provider): void
    {
        $run = GradingRun::query()->with('answer.submission.student', 'answer.activityQuestion')->findOrFail($this->gradingRunId);
        if ($run->status === GradingRunStatus::Succeeded) {
            return;
        }
        $run->update(['status' => GradingRunStatus::Processing, 'started_at' => now(), 'attempts' => $run->attempts + 1, 'error_code' => null, 'error_message' => null]);
        $run->answer->submission->update(['status' => SubmissionStatus::Processing]);
        $question = $run->answer->activityQuestion;
        $student = $run->answer->submission->student;

        try {
            $result = $provider->grade(new GradingRequest(
                question: $question->body,
                expectedAnswer: (string) $question->expected_answer,
                rubric: $question->rubric_snapshot ?? [],
                studentAnswer: (string) $run->answer->response_text,
                teacherInstruction: (string) $question->teacher_instruction,
                maximumScore: (float) $question->max_score,
                promptVersion: $run->prompt_version,
                locale: 'pt-BR',
                idempotencyKey: $run->idempotency_key,
                safetyIdentifier: substr(hash_hmac('sha256', 'student:'.$student->id, (string) config('app.key')), 0, 64),
            ));

            $run->suggestion()->create([
                'score' => $result->score, 'criterion_scores' => $result->criterionScores,
                'evidence' => $result->evidence, 'feedback' => $result->feedback,
                'confidence' => $result->confidence, 'warnings' => $result->warnings, 'created_at' => now(),
            ]);
            $estimatedCost = (($result->inputTokens ?? 0) / 1_000_000 * config('services.openai.input_usd_per_mtok'))
                + (($result->outputTokens ?? 0) / 1_000_000 * config('services.openai.output_usd_per_mtok'));
            $run->update(['status' => GradingRunStatus::Succeeded, 'finished_at' => now(), 'input_tokens' => $result->inputTokens, 'output_tokens' => $result->outputTokens, 'estimated_cost' => $estimatedCost]);
            $this->markReviewReadyWhenComplete($run);
        } catch (Throwable $exception) {
            $run->update(['status' => GradingRunStatus::RetryableFailed, 'error_code' => class_basename($exception), 'error_message' => mb_substr($exception->getMessage(), 0, 1000)]);
            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        $run = GradingRun::query()->with('answer.submission')->find($this->gradingRunId);
        $run?->update([
            'status' => GradingRunStatus::PermanentlyFailed,
            'finished_at' => now(),
            'error_code' => $exception ? class_basename($exception) : 'UnknownFailure',
            'error_message' => $exception ? mb_substr($exception->getMessage(), 0, 1000) : 'Falha desconhecida.',
        ]);
        $run?->answer?->submission?->update(['status' => SubmissionStatus::Submitted]);
    }

    private function markReviewReadyWhenComplete(GradingRun $run): void
    {
        $submission = $run->answer->submission;
        $pending = GradingRun::query()->whereHas('answer', fn ($query) => $query->where('submission_id', $submission->id))
            ->whereIn('status', [GradingRunStatus::Pending, GradingRunStatus::Processing, GradingRunStatus::RetryableFailed])->exists();
        if (! $pending) {
            $submission->update(['status' => SubmissionStatus::Submitted]);
        }
    }
}
