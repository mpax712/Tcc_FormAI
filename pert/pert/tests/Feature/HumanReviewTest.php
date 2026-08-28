<?php

namespace Tests\Feature;

use App\Application\Actions\ReviewSubmissionAction;
use App\Domain\Activities\Enums\ActivityStatus;
use App\Domain\Activities\Models\Activity;
use App\Domain\Activities\Models\ActivityQuestion;
use App\Domain\Classrooms\Models\Classroom;
use App\Domain\Identity\Models\User;
use App\Domain\QuestionBank\Enums\QuestionType;
use App\Domain\Submissions\Enums\SubmissionStatus;
use App\Domain\Submissions\Models\Submission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HumanReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_human_review_becomes_published_grade(): void
    {
        $teacher = User::factory()->teacher()->create(); $student = User::factory()->student()->create();
        $classroom = Classroom::query()->create(['teacher_id' => $teacher->id, 'name' => 'Turma', 'is_active' => true]);
        $activity = Activity::query()->create(['teacher_id' => $teacher->id, 'classroom_id' => $classroom->id, 'title' => 'Redacao', 'status' => ActivityStatus::Grading, 'deadline_at' => now()->subHour(), 'total_score' => 10]);
        $question = ActivityQuestion::query()->create(['activity_id' => $activity->id, 'type' => QuestionType::Essay, 'body' => 'Argumente.', 'max_score' => 10, 'rubric_snapshot' => [['label' => 'Argumentacao', 'weight' => 1]], 'position' => 1]);
        $submission = Submission::query()->create(['activity_id' => $activity->id, 'student_id' => $student->id, 'status' => SubmissionStatus::Submitted, 'submitted_at' => now()]);
        $answer = $submission->answers()->create(['activity_question_id' => $question->id, 'response_text' => 'Minha argumentacao.', 'version' => 1]);

        $this->assertFalse(\Illuminate\Support\Facades\Route::has('teacher.grading.ai'));
        $this->actingAs($teacher)->get(route('teacher.grading.show', $submission))
            ->assertOk()
            ->assertDontSee('Gerar sugestões com IA')
            ->assertSee('Salvar revisão humana');

        $reviewed = app(ReviewSubmissionAction::class)->execute($submission, $teacher, [$answer->id => ['score' => 7.5, 'feedback' => 'Desenvolva a conclusao.']]);
        $this->assertSame(SubmissionStatus::Reviewed, $reviewed->status);
        $this->assertEquals(7.5, (float) $reviewed->final_score);
        $this->assertNull($reviewed->released_at);

        $this->actingAs($teacher)->post(route('teacher.grading.release', $reviewed))->assertRedirect();
        $this->assertSame(SubmissionStatus::Released, $reviewed->fresh()->status);
    }
}
