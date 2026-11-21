<?php

namespace Tests\Feature;

use App\Enums\CompanyRole;
use App\Enums\Recruitment;
use App\Models\Company;
use App\Models\CompanyApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** Application questions (#5222). */
class ApplicationQuestionsTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Company, 1: User} */
    private function companyWith(CompanyRole $role): array
    {
        $company = Company::factory()->create(['recruitment' => Recruitment::Apply]);
        $user = User::factory()->create();
        $company->members()->create(['user_id' => $user->id, 'role' => $role]);

        return [$company, $user];
    }

    /** @return list<array<string, mixed>> */
    private function twoQuestions(): array
    {
        return [
            ['label' => 'Where do you usually drive?', 'type' => 'short_text', 'required' => true],
            ['label' => 'Which server?', 'type' => 'choice', 'required' => false, 'options' => ['Simulation 1', 'Simulation 2', ' ']],
        ];
    }

    /** @param  list<array<string, mixed>>  $questions */
    private function saveQuestions(User $manager, Company $company, array $questions): void
    {
        $this->actingAs($manager)
            ->put(route('companies.questions.update', $company), ['questions' => $questions])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('companies.edit', $company));
    }

    /** @return list<array{id: string, label: string, type: string, required: bool, options: list<string>}> */
    private function stored(Company $company): array
    {
        return $company->fresh()->application_questions ?? [];
    }

    public function test_a_manager_saves_questions_and_ids_survive_edits(): void
    {
        [$company, $manager] = $this->companyWith(CompanyRole::Manager);

        $this->saveQuestions($manager, $company, $this->twoQuestions());

        $saved = $this->stored($company);
        $this->assertCount(2, $saved);
        $this->assertMatchesRegularExpression('/^[a-z0-9]{8}$/', $saved[0]['id']);
        $this->assertNotSame($saved[0]['id'], $saved[1]['id']);
        // Blank options are dropped; a text question keeps none.
        $this->assertSame(['Simulation 1', 'Simulation 2'], $saved[1]['options']);
        $this->assertSame([], $saved[0]['options']);

        // Renaming keeps the id, so the Apply form's field stays the same.
        $this->saveQuestions($manager, $company, [
            [...$saved[0], 'label' => 'Where do you drive?'],
        ]);
        $this->assertSame($saved[0]['id'], $this->stored($company)[0]['id']);
        $this->assertSame('Where do you drive?', $this->stored($company)[0]['label']);

        // The edit page gets them; an empty list removes them all.
        $this->get(route('companies.edit', $company))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('questions', 1));
        $this->saveQuestions($manager, $company, []);
        $this->assertNull($company->fresh()->application_questions);
    }

    public function test_invalid_question_lists_are_refused(): void
    {
        [$company, $owner] = $this->companyWith(CompanyRole::Owner);
        $put = fn (array $questions) => $this->actingAs($owner)
            ->put(route('companies.questions.update', $company), ['questions' => $questions]);

        $eleven = array_fill(0, 11, ['label' => 'Q', 'type' => 'yes_no', 'required' => false]);
        $put($eleven)->assertSessionHasErrors('questions');
        $put([['label' => '', 'type' => 'short_text']])->assertSessionHasErrors('questions.0.label');
        $put([['label' => 'Q', 'type' => 'essay']])->assertSessionHasErrors('questions.0.type');
        $put([['label' => 'Q', 'type' => 'choice', 'options' => ['Only one', 'only ONE']]])
            ->assertSessionHasErrors('questions.0.options');
        $put([['label' => 'Q', 'type' => 'choice', 'options' => array_map(fn ($i) => "Option $i", range(1, 9))]])
            ->assertSessionHasErrors('questions.0.options');

        $this->assertNull($company->fresh()->application_questions);
    }

    public function test_drivers_and_outsiders_cannot_change_questions(): void
    {
        [$company, $driver] = $this->companyWith(CompanyRole::Driver);

        $this->actingAs($driver)
            ->put(route('companies.questions.update', $company), ['questions' => $this->twoQuestions()])
            ->assertForbidden();
        $this->actingAs(User::factory()->create())
            ->put(route('companies.questions.update', $company), ['questions' => $this->twoQuestions()])
            ->assertForbidden();
    }

    public function test_answers_are_validated_by_type_and_required_flag(): void
    {
        [$company, $owner] = $this->companyWith(CompanyRole::Owner);
        $this->saveQuestions($owner, $company, [
            ...$this->twoQuestions(),
            ['label' => 'Do you have a microphone?', 'type' => 'yes_no', 'required' => true],
            ['label' => 'Tell us about yourself', 'type' => 'long_text', 'required' => false],
        ]);
        [$where, $server, $mic, $about] = array_column($this->stored($company), 'id');
        $applicant = User::factory()->create();
        $apply = fn (array $answers) => $this->actingAs($applicant)
            ->post(route('companies.apply', $company), ['answers' => $answers]);

        $apply([])->assertSessionHasErrors(["answers.$where", "answers.$mic"]);
        // The error names the question.
        $this->assertStringContainsString('Where do you usually drive?', (string) session('errors')->first("answers.$where"));
        $apply([$where => 'Scandinavia', $mic => 'maybe'])->assertSessionHasErrors("answers.$mic");
        $apply([$where => 'Scandinavia', $mic => 'yes', $server => 'Arcade'])->assertSessionHasErrors("answers.$server");
        $apply([$where => str_repeat('x', 201), $mic => 'yes'])->assertSessionHasErrors("answers.$where");
        $apply([$where => 'Scandinavia', $mic => 'yes', $about => str_repeat('x', 2001)])->assertSessionHasErrors("answers.$about");
        $this->assertSame(0, CompanyApplication::query()->count());

        $apply([$where => ' Scandinavia ', $mic => 'no', $server => 'Simulation 2'])->assertSessionHasNoErrors();

        $application = CompanyApplication::query()->sole();
        $this->assertSame([
            ['question' => 'Where do you usually drive?', 'type' => 'short_text', 'answer' => 'Scandinavia'],
            ['question' => 'Which server?', 'type' => 'choice', 'answer' => 'Simulation 2'],
            ['question' => 'Do you have a microphone?', 'type' => 'yes_no', 'answer' => 'no'],
            ['question' => 'Tell us about yourself', 'type' => 'long_text', 'answer' => null],
        ], $application->answers);
    }

    public function test_answers_are_a_snapshot_that_later_edits_do_not_change(): void
    {
        [$company, $owner] = $this->companyWith(CompanyRole::Owner);
        $this->saveQuestions($owner, $company, $this->twoQuestions());
        [$where] = array_column($this->stored($company), 'id');
        $applicant = User::factory()->create();

        $this->actingAs($applicant)->post(route('companies.apply', $company), ['answers' => [$where => 'Iberia']]);

        // The owner rewrites the questions after the application came in.
        $this->saveQuestions($owner, $company, [['label' => 'Completely new question', 'type' => 'yes_no', 'required' => true]]);

        $this->actingAs($owner)->get(route('companies.show', $company))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('applications.0.answers.0.question', 'Where do you usually drive?')
                ->where('applications.0.answers.0.answer', 'Iberia')
                ->where('applications.0.answers.1.answer', null)
                ->has('applications.0.answers', 2));

        // The applicant sees their own answers; the Apply form isn't shown to them any more.
        $this->actingAs($applicant)->get(route('companies.show', $company))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('viewer.pendingAnswers.0.answer', 'Iberia')
                ->has('applications', 0));
    }

    public function test_only_staff_and_the_applicant_see_answers(): void
    {
        [$company, $owner] = $this->companyWith(CompanyRole::Owner);
        $this->saveQuestions($owner, $company, $this->twoQuestions());
        [$where] = array_column($this->stored($company), 'id');
        $applicant = User::factory()->create();
        $this->actingAs($applicant)->post(route('companies.apply', $company), ['answers' => [$where => 'Secret route']]);

        $driver = User::factory()->create();
        $company->members()->create(['user_id' => $driver->id, 'role' => CompanyRole::Driver]);
        $outsider = User::factory()->create();

        foreach ([$driver, $outsider] as $viewer) {
            $this->actingAs($viewer)->get(route('companies.show', $company))
                ->assertDontSee('Secret route')
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->has('applications', 0)
                    ->has('viewer.pendingAnswers', 0));
        }
        auth()->logout();
        $this->get(route('companies.show', $company))->assertDontSee('Secret route');
    }

    public function test_the_apply_form_gets_the_questions_and_no_questions_works_as_before(): void
    {
        [$company, $owner] = $this->companyWith(CompanyRole::Owner);
        $applicant = User::factory()->create();

        $this->actingAs($applicant)->get(route('companies.show', $company))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('questions', 0));
        $this->actingAs($applicant)->post(route('companies.apply', $company), ['message' => 'Hello'])
            ->assertSessionHasNoErrors();
        $this->assertNull(CompanyApplication::query()->sole()->answers);
        $this->assertSame('Hello', CompanyApplication::query()->sole()->message);

        $this->saveQuestions($owner, $company, $this->twoQuestions());
        $other = User::factory()->create();
        $this->actingAs($other)->get(route('companies.show', $company))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('questions', 2)
                ->where('questions.1.options', ['Simulation 1', 'Simulation 2']));
        // Members don't get the form's questions.
        $this->actingAs($owner)->get(route('companies.show', $company))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('questions', 0));
    }
}
