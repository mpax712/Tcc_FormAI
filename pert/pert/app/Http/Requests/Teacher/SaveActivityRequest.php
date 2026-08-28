<?php

namespace App\Http\Requests\Teacher;

use App\Domain\Activities\Models\Activity;
use App\Domain\QuestionBank\Enums\QuestionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveActivityRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $questions = $this->input('questions', []);
        if (! is_array($questions)) return;
        foreach ($questions as $questionIndex => $question) {
            if (! is_array($question)) continue;
            if (($question['type'] ?? null) !== QuestionType::SingleChoice->value) continue;
            $correctOption = (string) ($question['correct_option'] ?? '');
            $options = $question['options'] ?? [];
            if (! is_array($options)) continue;
            foreach ($options as $optionIndex => $option) {
                if (! is_array($option)) continue;
                $questions[$questionIndex]['options'][$optionIndex]['is_correct'] = (string) $optionIndex === $correctOption;
            }
        }
        $this->merge(['questions' => $questions]);
    }

    public function authorize(): bool
    {
        $activity = $this->route('activity');

        return $activity
            ? $this->user()?->can('update', $activity) === true
            : $this->user()?->can('create', Activity::class) === true;
    }

    public function rules(): array
    {
        $activity = $this->route('activity');
        $currentClassroomId = $activity?->classroom_id;

        return [
            'classroom_id' => ['required', 'integer', Rule::exists('classrooms', 'id')->where(
                fn ($query) => $query->where('teacher_id', $this->user()->id)
                    ->where(function ($available) use ($currentClassroomId): void {
                        $available->where('is_active', true);
                        if ($currentClassroomId) {
                            $available->orWhere('id', $currentClassroomId);
                        }
                    })
            )],
            'title' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:5000'],
            'deadline_at' => ['required', 'date', 'after:now'],
            'intent' => ['nullable', Rule::in(['draft', 'preview', 'publish'])],

            'bank_questions' => ['nullable', 'array', 'max:50'],
            'bank_questions.*' => ['integer', 'distinct', Rule::exists('questions', 'id')->where(
                fn ($query) => $query->where('owner_id', $this->user()->id)->where('is_active', true)->whereNull('deleted_at')
            )],

            'questions' => ['nullable', 'array', 'max:50'],
            'questions.*' => ['array'],
            'questions.*.type' => ['required', Rule::enum(QuestionType::class)],
            'questions.*.body' => ['required', 'string', 'max:10000'],
            'questions.*.expected_answer' => ['nullable', 'string', 'max:10000'],
            'questions.*.teacher_instruction' => ['prohibited'],
            'questions.*.max_score' => ['required', 'numeric', 'gt:0', 'max:1000'],
            'questions.*.correct_option' => ['nullable', 'integer', 'min:0', 'max:9'],
            'questions.*.options' => ['nullable', 'array', 'max:10'],
            'questions.*.options.*' => ['array'],
            'questions.*.options.*.text' => ['nullable', 'string', 'max:2000'],
            'questions.*.options.*.is_correct' => ['sometimes', 'boolean'],
            'questions.*.rubric' => ['nullable', 'array', 'max:10'],
            'questions.*.rubric.*' => ['array'],
            'questions.*.rubric.*.label' => ['nullable', 'string', 'max:120'],
            'questions.*.rubric.*.description' => ['nullable', 'string', 'max:3000'],
            'questions.*.rubric.*.weight' => ['nullable', 'numeric', 'gt:0', 'max:1'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $directInput = $this->input('questions', []);
            $bankInput = $this->input('bank_questions', []);
            $directQuestions = is_array($directInput) ? $directInput : [];
            $bankQuestions = is_array($bankInput) ? array_filter($bankInput) : [];

            if (count($directQuestions) + count($bankQuestions) === 0) {
                $validator->errors()->add('questions', 'Adicione uma pergunta ou selecione uma do banco de questões.');
            }
            if (count($directQuestions) + count($bankQuestions) > 50) {
                $validator->errors()->add('questions', 'A atividade pode ter no máximo 50 perguntas no total.');
            }

            foreach ($directQuestions as $index => $question) {
                if (! is_array($question)) continue;
                $type = $question['type'] ?? null;

                if ($type === QuestionType::SingleChoice->value) {
                    $optionInput = $question['options'] ?? [];
                    $options = collect(is_array($optionInput) ? $optionInput : [])->filter(
                        fn ($option) => is_array($option) && filled($option['text'] ?? null)
                    );
                    if ($options->count() < 2) {
                        $validator->errors()->add("questions.$index.options", 'Preencha ao menos duas alternativas.');
                    }
                    if ($options->filter(fn ($option) => filter_var($option['is_correct'] ?? false, FILTER_VALIDATE_BOOL))->count() !== 1) {
                        $validator->errors()->add("questions.$index.options", 'Marque exatamente uma alternativa correta.');
                    }
                }
            }
        }];
    }
}
