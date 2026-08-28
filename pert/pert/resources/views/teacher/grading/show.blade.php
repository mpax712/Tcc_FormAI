@extends('layouts.app')

@section('title', 'Corrigir entrega · FormAI')

@section('content')
<div class="d-flex flex-wrap justify-content-between gap-2 mb-4">
    <div>
        <h1>Correção de {{ $submission->student->name }}</h1>
        <p class="text-secondary">{{ $submission->activity->title }} · enviada {{ $submission->submitted_at?->format('d/m/Y H:i') }}</p>
    </div>
</div>

<form method="post" action="{{ route('teacher.grading.review', $submission) }}">
    @csrf
    @method('PUT')

    @foreach($submission->answers as $answer)
        <article class="card question-card p-4 mb-4">
            <h2 class="h5">{{ $answer->activityQuestion->body }}</h2>
            <div class="bg-body-tertiary p-3 rounded mb-3">
                <strong>Resposta:</strong>
                <div class="mt-2">{{ $answer->response_text ?: $answer->selected_option_key }}</div>
            </div>

            @if($answer->activityQuestion->type === App\Domain\QuestionBank\Enums\QuestionType::SingleChoice)
                <div class="alert alert-info">Correção automática: incluída no subtotal objetivo de {{ $submission->objective_score }} pontos.</div>
            @else
                <div class="row">
                    <div class="col-md-3 mb-3">
                        <label class="form-label" for="score-{{ $answer->id }}">Nota (máx. {{ $answer->activityQuestion->max_score }})</label>
                        <input class="form-control" id="score-{{ $answer->id }}" name="grades[{{ $answer->id }}][score]" type="number" min="0" max="{{ $answer->activityQuestion->max_score }}" step="0.01" value="{{ old("grades.{$answer->id}.score", $answer->gradingDecision?->score) }}" required>
                    </div>
                    <div class="col-md-9 mb-3">
                        <label class="form-label" for="feedback-{{ $answer->id }}">Feedback ao aluno</label>
                        <textarea class="form-control" id="feedback-{{ $answer->id }}" name="grades[{{ $answer->id }}][feedback]" rows="3">{{ old("grades.{$answer->id}.feedback", $answer->gradingDecision?->feedback) }}</textarea>
                    </div>
                </div>
            @endif
        </article>
    @endforeach

    <button class="btn btn-primary" type="submit">Salvar revisão humana</button>
</form>

<div class="d-flex gap-2 mt-3">
    @if($submission->status === App\Domain\Submissions\Enums\SubmissionStatus::Reviewed)
        <form method="post" action="{{ route('teacher.grading.release', $submission) }}" data-confirm="Publicar nota e feedback para o aluno?">
            @csrf
            <button class="btn btn-success" type="submit">Publicar resultado</button>
        </form>
    @endif
    @if($submission->status !== App\Domain\Submissions\Enums\SubmissionStatus::Released)
        <form method="post" action="{{ route('teacher.grading.reopen', $submission) }}">
            @csrf
            <button class="btn btn-outline-secondary" type="submit">Reabrir entrega</button>
        </form>
    @endif
</div>
@endsection
