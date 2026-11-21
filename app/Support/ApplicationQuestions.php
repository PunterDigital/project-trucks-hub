<?php

namespace App\Support;

use App\Models\Company;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Application questions (#5222): up to `MAX` questions a company asks on its Apply form.
 *
 * Stored on `companies.application_questions` as a list of
 * `{id, label, type, required, options}`. The `id` is a short random string that ties an
 * answer field to its question; it never changes when the label does. An application keeps
 * `snapshot()` of the questions it was asked with the answers, so editing or deleting a
 * question later leaves old answers as they were.
 *
 * @phpstan-type Question array{id: string, label: string, type: string, required: bool, options: list<string>}
 * @phpstan-type Answer array{question: string, type: string, answer: string|null}
 */
class ApplicationQuestions
{
    public const MAX = 10;

    public const MAX_OPTIONS = 8;

    public const LABEL_MAX = 150;

    public const OPTION_MAX = 80;

    /** Answer length limits per text type. */
    public const ANSWER_MAX = ['short_text' => 200, 'long_text' => 2000];

    public const TYPES = ['short_text', 'long_text', 'yes_no', 'choice'];

    private const ID_PATTERN = '/^[a-z0-9]{8}$/';

    /**
     * The company's questions, cleaned: anything malformed in the stored JSON is skipped.
     *
     * @return list<Question>
     */
    public static function for(Company $company): array
    {
        $stored = $company->application_questions;

        if (! is_array($stored)) {
            return [];
        }

        $questions = [];

        foreach ($stored as $q) {
            if (! is_array($q) || ! is_string($q['id'] ?? null) || ! is_string($q['label'] ?? null)
                || ! in_array($q['type'] ?? null, self::TYPES, true)) {
                continue;
            }
            $options = $q['type'] === 'choice' && is_array($q['options'] ?? null)
                ? array_values(array_filter($q['options'], 'is_string'))
                : [];

            $questions[] = [
                'id' => $q['id'],
                'label' => $q['label'],
                'type' => $q['type'],
                'required' => (bool) ($q['required'] ?? false),
                'options' => $options,
            ];
        }

        return array_slice($questions, 0, self::MAX);
    }

    /**
     * Validates the edit page's list and stores it. An empty list removes every question.
     *
     * @param  array<string, mixed>  $input
     */
    public static function save(Company $company, array $input): void
    {
        $data = validator($input, [
            'questions' => ['present', 'array', 'max:'.self::MAX],
            'questions.*' => ['array'],
            'questions.*.id' => ['nullable', 'string'],
            'questions.*.label' => ['required', 'string', 'max:'.self::LABEL_MAX],
            'questions.*.type' => ['required', Rule::in(self::TYPES)],
            'questions.*.required' => ['boolean'],
            'questions.*.options' => ['nullable', 'array'],
            'questions.*.options.*' => ['nullable', 'string', 'max:'.self::OPTION_MAX],
        ], [
            'questions.max' => __('A company can ask at most :max questions.', ['max' => self::MAX]),
        ], [
            'questions.*.label' => __('question'),
            'questions.*.type' => __('answer type'),
            'questions.*.options.*' => __('option'),
        ])->validate();

        $questions = [];
        $seen = [];
        $errors = [];

        /** @var list<array<string, mixed>> $rows */
        $rows = array_values($data['questions']);

        foreach ($rows as $i => $row) {
            $type = (string) $row['type'];
            $options = [];

            if ($type === 'choice') {
                foreach ((array) ($row['options'] ?? []) as $option) {
                    $option = trim((string) $option);
                    if ($option !== '' && ! in_array(Str::lower($option), array_map(Str::lower(...), $options), true)) {
                        $options[] = $option;
                    }
                }
                if (count($options) < 2 || count($options) > self::MAX_OPTIONS) {
                    $errors["questions.$i.options"] = __('A choice needs 2 to :max different options.', ['max' => self::MAX_OPTIONS]);
                }
            }

            // Keep a question's id across edits, so the Apply form's field names stay put.
            $id = is_string($row['id'] ?? null) && preg_match(self::ID_PATTERN, $row['id']) && ! isset($seen[$row['id']])
                ? $row['id']
                : self::newId($seen);
            $seen[$id] = true;

            $questions[] = [
                'id' => $id,
                'label' => trim((string) $row['label']),
                'type' => $type,
                'required' => (bool) ($row['required'] ?? false),
                'options' => $options,
            ];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $company->application_questions = $questions === [] ? null : $questions;
        $company->save();
    }

    /**
     * Validation rules for the Apply form's `answers[<id>]` fields.
     *
     * @param  list<Question>  $questions
     * @return array{0: array<string, list<mixed>>, 1: array<string, string>}
     */
    public static function rules(array $questions): array
    {
        $rules = ['answers' => ['nullable', 'array']];
        $attributes = [];

        foreach ($questions as $q) {
            $key = 'answers.'.$q['id'];
            $base = [$q['required'] ? 'required' : 'nullable'];

            $rules[$key] = match ($q['type']) {
                'short_text', 'long_text' => [...$base, 'string', 'max:'.self::ANSWER_MAX[$q['type']]],
                'yes_no' => [...$base, Rule::in(['yes', 'no'])],
                default => [...$base, 'string', Rule::in($q['options'])],
            };
            $attributes[$key] = $q['label'];
        }

        return [$rules, $attributes];
    }

    /**
     * The questions as asked, each with its answer (null when an optional one was skipped).
     *
     * @param  list<Question>  $questions
     * @param  array<string, mixed>  $answers  validated `answers`
     * @return list<Answer>
     */
    public static function snapshot(array $questions, array $answers): array
    {
        return array_map(function (array $q) use ($answers) {
            $answer = $answers[$q['id']] ?? null;
            $answer = is_string($answer) ? trim($answer) : null;

            return [
                'question' => $q['label'],
                'type' => $q['type'],
                'answer' => $answer === '' ? null : $answer,
            ];
        }, $questions);
    }

    /** @param  array<string, true>  $taken */
    private static function newId(array $taken): string
    {
        do {
            $id = Str::lower(Str::random(8));
        } while (isset($taken[$id]) || ! preg_match(self::ID_PATTERN, $id));

        return $id;
    }
}
