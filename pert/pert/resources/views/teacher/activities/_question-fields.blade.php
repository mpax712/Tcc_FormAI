@php
    $type = $question['type'] ?? 'essay';
    $options = array_values($question['options'] ?? []);
    $correctOption = isset($question['correct_option'])
        ? (int) $question['correct_option']
        : collect($options)->search(fn ($option) => (bool) ($option['is_correct'] ?? false));
    if ($correctOption === false) $correctOption = 0;
    while (count($options) < 4) $options[] = [];
@endphp

<article class="activity-question-builder activity-question-glass" data-question-card>
    <div class="question-builder-heading">
        <div><span class="question-number" data-question-number>{{ is_numeric($index) ? $index + 1 : '' }}</span><div><strong data-question-kind-label>{{ $type === 'essay' ? 'Questão dissertativa' : 'Questão de alternativa' }}</strong><small>Criada somente para esta atividade</small></div></div>
        <button class="btn btn-sm btn-outline-danger" type="button" data-remove-question>Remover</button>
    </div>

    <div class="row g-3">
        <div class="col-lg-8">
            <label class="form-label" for="question-{{ $index }}-body">Enunciado</label>
            <textarea class="form-control" id="question-{{ $index }}-body" name="questions[{{ $index }}][body]" rows="3" maxlength="10000" required>{{ $question['body'] ?? '' }}</textarea>
        </div>
        <div class="col-sm-7 col-lg-2">
            <label class="form-label" for="question-{{ $index }}-type">Tipo</label>
            <select class="form-select" id="question-{{ $index }}-type" name="questions[{{ $index }}][type]" data-question-type required>
                <option value="essay" @selected($type === 'essay')>Dissertativa</option>
                <option value="single_choice" @selected($type === 'single_choice')>Escolha única</option>
            </select>
        </div>
        <div class="col-sm-5 col-lg-2">
            <label class="form-label" for="question-{{ $index }}-score">Pontos</label>
            <input class="form-control" id="question-{{ $index }}-score" name="questions[{{ $index }}][max_score]" type="number" min="0.01" max="1000" step="0.01" value="{{ $question['max_score'] ?? 1 }}" required>
        </div>
    </div>

    <div class="question-kind-panel mt-3" data-choice-fields @if($type !== 'single_choice') hidden @endif>
        <label class="form-label">Alternativas</label>
        @foreach($options as $optionIndex => $option)
            <div class="input-group mb-2">
                <div class="input-group-text"><input class="form-check-input mt-0" type="radio" name="questions[{{ $index }}][correct_option]" value="{{ $optionIndex }}" aria-label="Marcar alternativa {{ chr(65 + $optionIndex) }} como correta" @checked($correctOption === $optionIndex)></div>
                <input type="hidden" name="questions[{{ $index }}][options][{{ $optionIndex }}][is_correct]" value="0" data-correct-hidden>
                <input class="form-control" name="questions[{{ $index }}][options][{{ $optionIndex }}][text]" placeholder="Alternativa {{ chr(65 + $optionIndex) }}" value="{{ $option['text'] ?? '' }}">
            </div>
        @endforeach
        <div class="form-text">Preencha ao menos duas alternativas e marque uma correta.</div>
    </div>

</article>
