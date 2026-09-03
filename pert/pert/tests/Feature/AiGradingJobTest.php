<?php

namespace Tests\Feature;

use App\Application\DTOs\GradingRequest;
use App\Application\DTOs\GradingResult;
use App\Domain\Activities\Enums\ActivityStatus;
use App\Domain\Activities\Models\Activity;
use App\Domain\Activities\Models\ActivityQuestion;
use App\Domain\Classrooms\Models\Classroom;
use App\Domain\Grading\Contracts\AiGradingProvider;
use App\Domain\Grading\Enums\GradingRunStatus;
use App\Domain\Grading\Models\GradingRun;
use App\Domain\Identity\Models\User;
use App\Domain\QuestionBank\Enums\QuestionType;
use App\Domain\Submissions\Enums\SubmissionStatus;
use App\Domain\Submissions\Models\Submission;
use App\Infrastructure\AI\Exceptions\RetryableAiException;
use App\Jobs\GenerateAiSuggestion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class AiGradingJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_completed_ai_job_does_not_overwrite_a_finished_human_review(): void
    {
        [$submission, $run] = $this->scenario(SubmissionStatus::Reviewed);

        (new GenerateAiSuggestion($run->id))->handle($this->provider());

        $this->assertSame(SubmissionStatus::Reviewed, $submission->fresh()->status);
        $this->assertSame(GradingRunStatus::Succeeded, $run->fresh()->status);
        $this->assertNotNull($run->fresh()->suggestion);
    }

    public function test_completed_ai_job_returns_processing_submission_to_manual_review_queue(): void
    {
        [$submission, $run] = $this->scenario(SubmissionStatus::Processing);

        (new GenerateAiSuggestion($run->id))->handle($this->provider());

        $this->assertSame(SubmissionStatus::Submitted, $submission->fresh()->status);
        $this->assertSame(GradingRunStatus::Succeeded, $run->fresh()->status);
    }

    public function test_retryable_provider_failure_succeeds_on_the_second_job_attempt(): void
    {
        [, $run] = $this->scenario(SubmissionStatus::Processing);
        $provider = new class implements AiGradingProvider
        {
            public int $calls = 0;

            public function grade(GradingRequest $request): GradingResult
            {
                if (++$this->calls === 1) {
                    throw new RetryableAiException('Falha do provedor Gemini: HTTP 500');
                }

                return new GradingResult(8, [['criterion' => 'Qualidade geral da resposta', 'score' => 8, 'justification' => 'Boa resposta.']], [], 'Bom trabalho.', .9, []);
            }
        };
        $job = new GenerateAiSuggestion($run->id);

        try {
            $job->handle($provider);
            $this->fail('A primeira tentativa deveria falhar.');
        } catch (RetryableAiException) {
            $this->assertSame(GradingRunStatus::RetryableFailed, $run->fresh()->status);
        }
        $job->handle($provider);

        $this->assertSame(2, $provider->calls);
        $this->assertSame(2, $run->fresh()->attempts);
        $this->assertSame(GradingRunStatus::Succeeded, $run->fresh()->status);
    }

    public function test_final_retry_preserves_the_original_connection_timeout(): void
    {
        [, $run] = $this->scenario(SubmissionStatus::Processing);
        $provider = new class implements AiGradingProvider
        {
            public function grade(GradingRequest $request): GradingResult
            {
                throw new RetryableAiException('cURL error 28: Operation timed out after 30000 milliseconds with 0 bytes received.');
            }
        };
        $job = new GenerateAiSuggestion($run->id);
        $lastException = null;

        foreach (range(1, 2) as $_) {
            try {
                $job->handle($provider);
            } catch (RetryableAiException $exception) {
                $lastException = $exception;
            }
        }
        $job->failed($lastException);

        $this->assertSame(GradingRunStatus::PermanentlyFailed, $run->fresh()->status);
        $this->assertSame('RetryableAiException', $run->fresh()->error_code);
        $this->assertStringContainsString('cURL error 28', $run->fresh()->error_message);
    }

    public function test_job_for_a_deleted_grading_run_ends_without_calling_provider(): void
    {
        $provider = new class implements AiGradingProvider
        {
            public bool $called = false;

            public function grade(GradingRequest $request): GradingResult
            {
                $this->called = true;
                throw new RuntimeException('O provedor não deveria ser chamado.');
            }
        };

        (new GenerateAiSuggestion(999999))->handle($provider);

        $this->assertFalse($provider->called);
    }

    public function test_ai_job_uses_the_activity_wide_teacher_prompt(): void
    {
        [$submission, $run] = $this->scenario(SubmissionStatus::Processing);
        $submission->activity->update(['grading_instructions' => 'Considere respostas equivalentes e seja objetivo.']);
        $provider = new class implements AiGradingProvider
        {
            public ?GradingRequest $captured = null;

            public function grade(GradingRequest $request): GradingResult
            {
                $this->captured = $request;

                return new GradingResult(8, [['criterion' => 'Qualidade geral da resposta', 'score' => 8, 'justification' => 'Boa resposta.']], [], 'Bom trabalho.', .9, []);
            }
        };

        (new GenerateAiSuggestion($run->id))->handle($provider);

        $this->assertSame('Considere respostas equivalentes e seja objetivo.', $provider->captured?->teacherInstruction);
    }

    public function test_ai_job_is_cancelled_after_total_time_limit(): void
    {
        config(['formai.grading_timeout_seconds' => 30]);
        [$submission, $run] = $this->scenario(SubmissionStatus::Processing);
        $run->forceFill(['created_at' => now()->subSeconds(31)])->save();

        (new GenerateAiSuggestion($run->id))->handle($this->provider());

        $this->assertSame(GradingRunStatus::PermanentlyFailed, $run->fresh()->status);
        $this->assertSame('AiGradingTimeout', $run->fresh()->error_code);
        $this->assertNull($run->fresh()->suggestion);
        $this->assertSame(SubmissionStatus::Submitted, $submission->fresh()->status);
    }

    public function test_total_time_limit_preserves_a_previous_provider_error(): void
    {
        config(['formai.grading_timeout_seconds' => 60]);
        [, $run] = $this->scenario(SubmissionStatus::Processing);
        $run->forceFill([
            'status' => GradingRunStatus::RetryableFailed,
            'error_code' => 'ConnectionException',
            'error_message' => 'cURL error 28: Operation timed out.',
            'created_at' => now()->subSeconds(61),
        ])->save();

        (new GenerateAiSuggestion($run->id))->handle($this->provider());

        $this->assertSame(GradingRunStatus::PermanentlyFailed, $run->fresh()->status);
        $this->assertSame('ConnectionException', $run->fresh()->error_code);
        $this->assertSame('cURL error 28: Operation timed out.', $run->fresh()->error_message);
    }

    public function test_failed_ai_job_keeps_submission_processing_while_another_run_is_pending(): void
    {
        [$submission, $failedRun] = $this->scenario(SubmissionStatus::Processing);
        $firstQuestion = $failedRun->answer->activityQuestion;
        $question = ActivityQuestion::query()->create([
            'activity_id' => $firstQuestion->activity_id,
            'type' => QuestionType::Essay,
            'body' => 'Explique outro aspecto do tema.',
            'max_score' => 10,
            'rubric_snapshot' => [],
            'position' => 2,
        ]);
        $otherAnswer = $submission->answers()->create([
            'activity_question_id' => $question->id,
            'response_text' => 'Outra resposta do aluno.',
            'version' => 1,
        ]);
        $pendingRun = GradingRun::query()->create([
            'answer_id' => $otherAnswer->id,
            'idempotency_key' => hash('sha256', 'pending-run'),
            'status' => GradingRunStatus::Pending,
            'provider' => 'openai',
            'model' => 'gpt-5.6-terra',
            'prompt_version' => 1,
        ]);

        (new GenerateAiSuggestion($failedRun->id))->failed(new RuntimeException('Falha de teste.'));

        $this->assertSame(GradingRunStatus::PermanentlyFailed, $failedRun->fresh()->status);
        $this->assertSame(GradingRunStatus::Pending, $pendingRun->fresh()->status);
        $this->assertSame(SubmissionStatus::Processing, $submission->fresh()->status);
    }

    private function provider(): AiGradingProvider
    {
        return new class implements AiGradingProvider
        {
            public function grade(GradingRequest $request): GradingResult
            {
                return new GradingResult(
                    score: 8,
                    criterionScores: [['criterion' => 'Qualidade geral da resposta', 'score' => 8, 'justification' => 'Boa resposta.']],
                    evidence: ['Trecho relevante.'],
                    feedback: 'Continue desenvolvendo seus argumentos.',
                    confidence: .9,
                    warnings: [],
                    inputTokens: 100,
                    outputTokens: 40,
                );
            }
        };
    }

    private function scenario(SubmissionStatus $status): array
    {
        $teacher = User::factory()->teacher()->create();
        $student = User::factory()->student()->create();
        $classroom = Classroom::query()->create(['teacher_id' => $teacher->id, 'name' => 'Turma', 'is_active' => true]);
        $activity = Activity::query()->create([
            'teacher_id' => $teacher->id,
            'classroom_id' => $classroom->id,
            'title' => 'Redação',
            'status' => ActivityStatus::Grading,
            'deadline_at' => now()->subHour(),
            'total_score' => 10,
        ]);
        $question = ActivityQuestion::query()->create([
            'activity_id' => $activity->id,
            'type' => QuestionType::Essay,
            'body' => 'Explique o tema.',
            'max_score' => 10,
            'rubric_snapshot' => [],
            'position' => 1,
        ]);
        $submission = Submission::query()->create([
            'activity_id' => $activity->id,
            'student_id' => $student->id,
            'status' => $status,
            'submitted_at' => now(),
        ]);
        $answer = $submission->answers()->create([
            'activity_question_id' => $question->id,
            'response_text' => 'Resposta do aluno.',
            'version' => 1,
        ]);
        $run = GradingRun::query()->create([
            'answer_id' => $answer->id,
            'idempotency_key' => hash('sha256', 'run-'.$status->value),
            'status' => GradingRunStatus::Pending,
            'provider' => 'openai',
            'model' => 'gpt-5.6-terra',
            'prompt_version' => 1,
        ]);

        return [$submission, $run];
    }
}
