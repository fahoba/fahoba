<?php

declare(strict_types=1);

namespace Tests\Feature\Plugins;

use App\Models\Calendar;
use App\Models\Event;
use App\Models\Household;
use App\Models\ProviderAccount;
use App\Services\Calendar\CalendarImportService;
use App\Services\Calendar\CalendarSyncService;
use App\Services\Calendar\EventWriteService;
use App\Support\HouseholdContext;
use App\Support\PublicAddressResolver;
use Carbon\CarbonImmutable;
use Fahoba\IcsCalendar\IcsCalendarProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeResolver;
use Tests\TestCase;

/**
 * A calendar subscription: a published iCal file, read whole when its
 * content changed, series as single dates, never written back.
 */
final class IcsCalendarTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://club.example.test/calendar.ics';

    private Household $household;

    private ProviderAccount $account;

    /** @var list<string> */
    private array $events = [];

    private int $fetches = 0;

    private int $stamp = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00', 'Europe/Vienna'));
        $this->app->instance(PublicAddressResolver::class, new FakeResolver([
            'club.example.test' => ['93.184.216.34'],
            'nas.example.test' => ['192.168.1.5'],
        ]));

        $this->household = Household::factory()->create(['timezone' => 'Europe/Vienna']);
        app(HouseholdContext::class)->setId((int) $this->household->id);
        $this->account = ProviderAccount::factory()->create([
            'household_id' => $this->household->id,
            'provider' => IcsCalendarProvider::KEY,
            'access_token' => null,
            'refresh_token' => null,
            'credentials' => ['url' => 'webcal://club.example.test/calendar.ics', 'name' => ''],
        ]);

        Http::fake(function (Request $request) {
            $this->fetches++;
            // Some servers stamp every answer anew; that is no change.
            $this->stamp++;

            return $request->url() === self::URL
                ? Http::response($this->file(), 200, ['Content-Type' => 'text/calendar'])
                : Http::response('', 404);
        });
    }

    public function test_webcal_becomes_https_and_the_calendar_keeps_its_published_name(): void
    {
        app(CalendarImportService::class)->import($this->account);

        $calendar = $this->calendar();
        $this->assertSame(self::URL, $calendar->external_id);
        $this->assertSame('SV Musterdorf', $calendar->summary);
    }

    public function test_events_come_over_on_the_household_day(): void
    {
        $this->event('training', "DTSTART;TZID=Europe/Vienna:20261022T160000\r\nDTEND;TZID=Europe/Vienna:20261022T170000\r\nSUMMARY:Training");
        $this->event('fest', "DTSTART;VALUE=DATE:20261024\r\nSUMMARY:Vereinsfest");

        $this->sync();

        $training = Event::query()->where('title', 'Training')->sole();
        $this->assertTrue(CarbonImmutable::parse('2026-10-22 14:00', 'UTC')->equalTo($training->starts_at));
        $this->assertSame('training', $training->external_id);

        $fest = Event::query()->where('title', 'Vereinsfest')->sole();
        $this->assertTrue($fest->all_day);
        $this->assertSame('2026-10-24 00:00', $fest->starts_at->setTimezone('Europe/Vienna')->format('Y-m-d H:i'));
        $this->assertSame('2026-10-25 00:00', $fest->ends_at->setTimezone('Europe/Vienna')->format('Y-m-d H:i'));
    }

    public function test_a_series_becomes_its_dates(): void
    {
        $this->event('chor', "DTSTART;TZID=Europe/Vienna:20261021T190000\r\nDURATION:PT90M\r\nRRULE:FREQ=WEEKLY;COUNT=3\r\nSUMMARY:Chor");

        $this->sync();

        $dates = Event::query()->where('title', 'Chor')->orderBy('starts_at')->get();
        $this->assertCount(3, $dates);
        $this->assertSame('20:30', $dates[2]->ends_at->setTimezone('Europe/Vienna')->format('H:i'));
        $this->assertSame('chor', $dates[0]->recurring_event_id);
        $this->assertStringStartsWith('chor#', $dates[0]->external_id);
    }

    public function test_an_unchanged_file_changes_nothing(): void
    {
        $this->event('training', "DTSTART;TZID=Europe/Vienna:20261022T160000\r\nDTEND;TZID=Europe/Vienna:20261022T170000\r\nSUMMARY:Training");
        $this->sync();
        $cursor = $this->calendar()->fresh()->sync_cursor;

        $result = app(CalendarSyncService::class)->sync($this->calendar()->fresh());

        $this->assertSame(0, $result->updated + $result->created + $result->deleted);
        $this->assertSame($cursor, $this->calendar()->fresh()->sync_cursor);
    }

    public function test_removed_there_is_removed_here(): void
    {
        $this->event('training', "DTSTART;TZID=Europe/Vienna:20261022T160000\r\nDTEND;TZID=Europe/Vienna:20261022T170000\r\nSUMMARY:Training");
        $this->sync();

        $this->events = [];
        app(CalendarSyncService::class)->sync($this->calendar()->fresh());

        $this->assertSame(0, Event::query()->count());
    }

    public function test_a_new_event_stays_here_and_nothing_is_sent(): void
    {
        $this->sync();
        $this->fetches = 0;

        app(EventWriteService::class)->create($this->calendar(), [
            'title' => 'Zahnarzt',
            'starts_at' => CarbonImmutable::parse('2026-10-23 09:00', 'Europe/Vienna'),
            'ends_at' => CarbonImmutable::parse('2026-10-23 10:00', 'Europe/Vienna'),
            'all_day' => false,
        ], []);

        $this->assertSame(0, $this->fetches);
        $this->assertSame(1, Event::query()->count());
    }

    public function test_the_wizard_refuses_what_is_no_public_calendar(): void
    {
        $provider = app(IcsCalendarProvider::class);

        $this->assertNotNull($provider->verifyCredentials(['url' => 'ftp://club.example.test/x.ics']));
        $this->assertNotNull($provider->verifyCredentials(['url' => 'https://nas.example.test/calendar.ics']));
        $this->assertNotNull($provider->verifyCredentials(['url' => 'https://club.example.test/missing.ics']));
        $this->assertNull($provider->verifyCredentials(['url' => 'webcal://club.example.test/calendar.ics']));
        $this->assertSame('Fußball', $provider->accountLabel(['url' => self::URL, 'name' => 'Fußball']));
    }

    private function calendar(): Calendar
    {
        return Calendar::query()->where('provider_account_id', $this->account->id)->sole();
    }

    private function sync(): void
    {
        app(CalendarImportService::class)->import($this->account);
        app(CalendarSyncService::class)->sync($this->calendar());
    }

    private function event(string $uid, string $properties): void
    {
        $this->events[] = "UID:{$uid}\r\n{$properties}\r\n";
    }

    private function file(): string
    {
        $stamp = sprintf('DTSTAMP:20261020T1000%02dZ', $this->stamp % 60);
        $events = implode('', array_map(static fn (string $event): string => "BEGIN:VEVENT\r\n{$stamp}\r\n{$event}END:VEVENT\r\n", $this->events));

        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Test//EN\r\nX-WR-CALNAME:SV Musterdorf\r\n{$events}END:VCALENDAR\r\n";
    }
}
