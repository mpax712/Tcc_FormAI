<?php

namespace App\Http\Controllers\Teacher;

use App\Application\Actions\ReviewSubmissionAction;
use App\Domain\Submissions\Enums\SubmissionStatus;
use App\Domain\Submissions\Models\Submission;
use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use App\Domain\Activities\Enums\ActivityStatus;

class GradingController extends Controller
{
    public function show(Submission $submission): View
    {
        $this->authorize('grade', $submission);
        $submission->load(['student', 'activity', 'answers.activityQuestion', 'answers.gradingDecision']);
        return view('teacher.grading.show', compact('submission'));
    }
    public function review(Request $request, Submission $submission, ReviewSubmissionAction $action): RedirectResponse
    {
        $this->authorize('grade', $submission);
        $data = $request->validate(['grades' => ['nullable', 'array'], 'grades.*.score' => ['required', 'numeric', 'min:0'], 'grades.*.feedback' => ['nullable', 'string', 'max:5000']]);
        try { $action->execute($submission, $request->user(), $data['grades'] ?? []); } catch (DomainException $e) { return back()->withErrors(['grades' => $e->getMessage()]); }
        return back()->with('status', 'Correcao salva. O resultado ainda nao foi publicado.');
    }
    public function release(Submission $submission): RedirectResponse
    {
        $this->authorize('grade', $submission);
        if ($submission->status !== SubmissionStatus::Reviewed) return back()->withErrors(['release' => 'Revise todas as respostas antes de publicar.']);
        DB::transaction(function () use ($submission) {
            $locked = Submission::query()->lockForUpdate()->findOrFail($submission->id);
            $locked->update(['status' => SubmissionStatus::Released, 'released_at' => now()]);
            $locked->activity()->update(['status' => ActivityStatus::Released, 'released_at' => now()]);
        });
        return back()->with('status', 'Nota e feedback publicados para o aluno.');
    }
    public function reopen(Submission $submission): RedirectResponse
    {
        $this->authorize('grade', $submission);
        abort_if($submission->status === SubmissionStatus::Released, 422, 'Resultados publicados nao podem ser reabertos.');
        $submission->update(['status' => SubmissionStatus::Draft, 'submitted_at' => null, 'reopened_until' => now()->addDay(), 'version' => $submission->version + 1]);
        return back()->with('status', 'Entrega reaberta por 24 horas para o aluno.');
    }
}
