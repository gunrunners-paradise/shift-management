<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesShiftRecords;
use Tests\TestCase;

class WorkerTodayInformationTest extends TestCase
{
    use CreatesShiftRecords, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00:00', 'Asia/Jerusalem'));
    }

    public function test_roster_returns_actual_todays_active_workers_with_their_correct_five_periods_and_includes_the_viewer(): void
    {
        $viewer = User::factory()->create(['first_name' => 'Viewer', 'last_name' => 'Worker']);
        $expected = [];

        foreach (config('shifts.periods') as $code => $period) {
            $worker = $code === '09_13' ? $viewer : User::factory()->create();
            $this->createShiftRecord($worker, '2026-10-20', $code);
            $expected[$worker->fullName()] = $period;
        }

        $inactive = User::factory()->inactive()->create();
        $manager = User::factory()->manager()->create();
        $cancelled = User::factory()->create();
        $yesterday = User::factory()->create();
        $tomorrow = User::factory()->create();
        $this->createShiftRecord($inactive, '2026-10-20');
        $this->createShiftRecord($manager, '2026-10-20');
        $this->createShiftRecord($cancelled, '2026-10-20', 'full', [
            'cancelled_at' => now(), 'cancelled_by' => $cancelled->id, 'cancellation_reason' => 'worker_cancelled',
        ]);
        $this->createShiftRecord($yesterday, '2026-10-19');
        $this->createShiftRecord($tomorrow, '2026-10-21');

        $response = $this->actingAs($viewer)->getJson(route('worker.today', ['week' => '2026-11-22']));

        $response->assertOk()->assertJsonPath('date', '2026-10-20');
        $coworkers = $response->json('coworkers');
        $this->assertCount(5, $coworkers);

        foreach ($coworkers as $coworker) {
            $this->assertArrayHasKey($coworker['name'], $expected);
            $period = $expected[$coworker['name']];
            $this->assertSame($period['label'], $coworker['period_label']);
            $this->assertSame($period['start'], $coworker['start']);
            $this->assertSame($period['end'], $coworker['end']);
            $this->assertEqualsCanonicalizing(['name', 'avatar_url', 'period_label', 'start', 'end'], array_keys($coworker));
        }

        $this->assertContains($viewer->fullName(), array_column($coworkers, 'name'));
    }

    public function test_birthdays_include_multiple_matching_active_workers_without_year_age_or_private_fields(): void
    {
        $viewer = User::factory()->create(['birth_date' => '1989-10-20', 'username' => 'private.birthday.viewer']);
        $colleague = User::factory()->create(['birth_date' => '1995-10-20']);
        User::factory()->create(['birth_date' => '1990-11-20']);
        User::factory()->create(['birth_date' => '1990-10-21']);
        User::factory()->inactive()->create(['birth_date' => '1990-10-20']);
        User::factory()->manager()->create(['birth_date' => '1990-10-20']);

        $response = $this->actingAs($viewer)->getJson(route('worker.today'));

        $response->assertOk();
        $birthdays = $response->json('birthdays');
        $this->assertEqualsCanonicalizing([$viewer->fullName(), $colleague->fullName()], array_column($birthdays, 'name'));

        foreach ($birthdays as $birthday) {
            $this->assertEqualsCanonicalizing(['name', 'avatar_url'], array_keys($birthday));
        }

        $this->assertStringNotContainsString('1989', $response->getContent());
        $this->assertStringNotContainsString('1995', $response->getContent());
        $this->assertStringNotContainsString($viewer->username, $response->getContent());
    }

    #[DataProvider('leapBirthdayDates')]
    public function test_february_29_birthdays_follow_the_confirmed_non_leap_year_convention(string $date, bool $celebrates): void
    {
        $this->travelTo(CarbonImmutable::parse($date.' 12:00:00', 'Asia/Jerusalem'));
        $viewer = User::factory()->create();
        $leapBirthday = User::factory()->create(['birth_date' => '2000-02-29']);

        $response = $this->actingAs($viewer)->getJson(route('worker.today'));

        $response->assertOk();
        $names = array_column($response->json('birthdays'), 'name');
        $this->assertSame($celebrates, in_array($leapBirthday->fullName(), $names, true));
    }

    public static function leapBirthdayDates(): array
    {
        return [
            'non leap year February 28' => ['2027-02-28', true],
            'not March 1' => ['2027-03-01', false],
            'leap year not February 28' => ['2028-02-28', false],
            'leap year February 29' => ['2028-02-29', true],
        ];
    }

    public function test_israeli_midnight_changes_both_birthdays_and_roster_when_utc_is_still_yesterday(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-17 21:30:00', 'UTC')->setTimezone('Asia/Jerusalem'));
        $viewer = User::factory()->create(['birth_date' => '2000-10-18']);
        User::factory()->create(['birth_date' => '2000-10-17']);
        $this->createShiftRecord($viewer, '2026-10-18', 'full');

        $response = $this->actingAs($viewer)->getJson(route('worker.today'));

        $response->assertOk()->assertJsonPath('date', '2026-10-18');
        $this->assertSame([$viewer->fullName()], array_column($response->json('birthdays'), 'name'));
        $this->assertSame([$viewer->fullName()], array_column($response->json('coworkers'), 'name'));
    }

    public function test_json_refresh_reflects_selection_replacement_and_cancellation(): void
    {
        $viewer = User::factory()->create();
        $this->actingAs($viewer);
        $this->getJson(route('worker.today'))->assertOk()->assertJsonCount(0, 'coworkers');

        $this->post(route('worker.shifts.store'), ['shift_date' => '2026-10-20', 'period' => '09_13'])
            ->assertSessionHasNoErrors();
        $this->getJson(route('worker.today'))->assertOk()->assertJsonCount(1, 'coworkers')
            ->assertJsonPath('coworkers.0.period_label', config('shifts.periods.09_13.label'));

        $this->post(route('worker.shifts.store'), ['shift_date' => '2026-10-20', 'period' => 'full'])
            ->assertSessionHasNoErrors();
        $this->getJson(route('worker.today'))->assertOk()->assertJsonCount(1, 'coworkers')
            ->assertJsonPath('coworkers.0.period_label', config('shifts.periods.full.label'));

        $this->delete(route('worker.shifts.destroy', ['date' => '2026-10-20']))->assertSessionHasNoErrors();
        $this->getJson(route('worker.today'))->assertOk()->assertJsonCount(0, 'coworkers');
    }

    public function test_birthdays_and_coworkers_are_rendered_below_the_calendar_for_a_different_viewed_week(): void
    {
        $viewer = User::factory()->create(['birth_date' => '1989-10-20']);
        $colleague = User::factory()->create(['first_name' => 'עמית', 'last_name' => 'היום']);
        $this->createShiftRecord($colleague, '2026-10-20', '11_15');

        $response = $this->actingAs($viewer)->get(route('worker.home', ['week' => '2026-11-22']));

        $response->assertOk();
        $document = $this->document($response->getContent());
        $this->assertCount(1, $document->query('//table[contains(@class,"shift-calendar")]/following::*[@id="birthdays"]'));
        $this->assertCount(1, $document->query('//table[contains(@class,"shift-calendar")]/following::*[@id="coworkers"]'));
        $birthdays = $document->query('//*[@id="birthdays"]')->item(0);
        $coworkers = $document->query('//*[@id="coworkers"]')->item(0);
        $this->assertFalse($birthdays->hasAttribute('hidden'));
        $this->assertStringContainsString($viewer->fullName(), $birthdays->textContent);
        $this->assertStringNotContainsString('1989', $birthdays->textContent);
        $this->assertStringContainsString($colleague->fullName(), $coworkers->textContent);
        $this->assertStringContainsString('11:00–15:00', $coworkers->textContent);
    }

    public function test_an_empty_birthday_list_has_no_always_visible_sample_greeting(): void
    {
        $viewer = User::factory()->create(['birth_date' => null]);

        $response = $this->actingAs($viewer)->get(route('worker.home'));

        $response->assertOk();
        $document = $this->document($response->getContent());
        $this->assertTrue($document->query('//*[@id="birthdays"]')->item(0)->hasAttribute('hidden'));
        $this->getJson(route('worker.today'))->assertOk()->assertJsonCount(0, 'birthdays');
    }

    public function test_names_are_escaped_in_the_initial_birthday_and_coworker_panels(): void
    {
        $viewer = User::factory()->create();
        $colleague = User::factory()->create(['first_name' => '<script>alert(1)</script>', 'birth_date' => '2000-10-20']);
        $this->createShiftRecord($colleague, '2026-10-20');

        $this->actingAs($viewer)->get(route('worker.home'))->assertOk()
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_today_refresh_is_worker_only_and_deactivation_revokes_an_existing_session(): void
    {
        $worker = User::factory()->create();
        $manager = User::factory()->manager()->create();
        $this->get(route('worker.today'))->assertRedirect(route('login'));
        $this->actingAs($manager)->getJson(route('worker.today'))->assertForbidden();
        $this->actingAs($worker);
        User::query()->whereKey($worker->id)->update(['is_active' => false]);

        $this->get(route('worker.today'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    private function document(string $html): DOMXPath
    {
        $document = new DOMDocument;
        $previousErrorMode = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrorMode);

        return new DOMXPath($document);
    }
}
