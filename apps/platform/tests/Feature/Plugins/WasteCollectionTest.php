<?php

declare(strict_types=1);

namespace Tests\Feature\Plugins;

use App\Models\DashboardWidget;
use App\Models\Household;
use App\Models\Person;
use App\Models\User;
use App\Notifications\PluginMessage;
use App\Services\DevicePairingService;
use App\Support\HouseholdContext;
use App\Support\PublicAddressResolver;
use Carbon\CarbonImmutable;
use Fahoba\Waste\WasteCollectionWidget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Support\FakeResolver;
use Tests\TestCase;

/**
 * The waste tile: the next bins from the municipality's iCal file, tomorrow
 * said as the evening to put it out, the file fetched once for many asks.
 */
final class WasteCollectionTest extends TestCase
{
    use RefreshDatabase;

    private const ICS = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Gemeinde//DE\r\n"
        ."BEGIN:VEVENT\r\nUID:bio\r\nDTSTART;VALUE=DATE:20261021\r\nRRULE:FREQ=WEEKLY;INTERVAL=2\r\nSUMMARY:Biotonne\r\nEND:VEVENT\r\n"
        ."BEGIN:VEVENT\r\nUID:rest\r\nDTSTART;VALUE=DATE:20261023\r\nSUMMARY:Restmüll\r\nEND:VEVENT\r\n"
        ."BEGIN:VEVENT\r\nUID:old\r\nDTSTART;VALUE=DATE:20261001\r\nSUMMARY:Altpapier\r\nEND:VEVENT\r\n"
        ."END:VCALENDAR\r\n";

    private string $token;

    private int $deviceId;

    private Household $household;

    private int $fetches = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-20 18:00', 'Europe/Vienna'));
        $this->app->instance(PublicAddressResolver::class, new FakeResolver(['gemeinde.example.test' => ['93.184.216.34']]));
        Http::fake(function () {
            $this->fetches++;

            return Http::response(self::ICS, 200, ['Content-Type' => 'text/calendar']);
        });

        $this->household = Household::factory()->create(['timezone' => 'Europe/Vienna']);
        app(HouseholdContext::class)->setId((int) $this->household->id);
        $code = app(DevicePairingService::class)->createCode($this->household, 'Flur');
        ['device' => $device, 'token' => $this->token] = app(DevicePairingService::class)->redeem($code->pairing_code);
        $this->deviceId = (int) $device->id;
    }

    public function test_it_lists_the_next_bins_and_says_tomorrow(): void
    {
        $tile = $this->tile(['url' => 'webcal://gemeinde.example.test/abfall.ics', 'days' => '14']);

        $rows = $this->withToken($this->token)->getJson("/api/wall/dashboard/{$tile->id}/content")
            ->assertOk()
            ->json('content.rows');

        $this->assertSame([
            ['text' => '🟤 Biotonne', 'detail' => 'Morgen – heute Abend rausstellen'],
            ['text' => '⚫ Restmüll', 'detail' => 'Fr., 23. Okt.'],
        ], $rows, 'The next Biotonne (4 November) lies beyond the two weeks.');
    }

    public function test_it_narrows_to_the_kinds_asked_for_and_fetches_once(): void
    {
        $widget = app(WasteCollectionWidget::class);
        $today = CarbonImmutable::parse('2026-10-20', 'Europe/Vienna');

        $only = $widget->collections(self::ICS, 'Europe/Vienna', $today, $today->addDays(31), WasteCollectionWidget::kinds(' rest , '));

        $this->assertSame(['Restmüll'], array_column($only, 'title'));
        $this->assertTrue($only[0]['day']->equalTo(CarbonImmutable::parse('2026-10-23', 'Europe/Vienna')));

        $tile = $this->tile(['url' => 'https://gemeinde.example.test/abfall.ics']);
        $this->withToken($this->token)->getJson("/api/wall/dashboard/{$tile->id}/content")->assertOk();
        $other = $this->tile(['url' => 'https://gemeinde.example.test/abfall.ics', 'only' => 'bio']);
        $this->withToken($this->token)->getJson("/api/wall/dashboard/{$other->id}/content")->assertOk();

        $this->assertSame(1, $this->fetches);
    }

    public function test_without_an_address_it_says_where_to_enter_one(): void
    {
        $tile = $this->tile([]);

        $this->withToken($this->token)->getJson("/api/wall/dashboard/{$tile->id}/content")
            ->assertOk()
            ->assertJsonPath('content.value', '🗑️')
            ->assertJsonPath('content.caption', 'Trag die Adresse des Abfallkalenders in den Einstellungen der Kachel ein.');
    }

    public function test_the_evening_before_the_parents_hear_once_when_allowed(): void
    {
        Notification::fake();
        $parent = User::factory()->create(['household_id' => $this->household->id]);
        $this->tile(['url' => 'https://gemeinde.example.test/abfall.ics', 'remind' => 'parents']);

        $this->artisan('fahoba:waste-reminders')->assertSuccessful();
        Notification::assertNothingSent();

        $this->household->forceFill(['message_senders' => [WasteCollectionWidget::KEY]])->save();
        $this->artisan('fahoba:waste-reminders')->assertSuccessful();
        $this->artisan('fahoba:waste-reminders')->assertSuccessful();

        Notification::assertSentToTimes($parent, PluginMessage::class, 1);
        Notification::assertSentTo($parent, PluginMessage::class, fn (PluginMessage $message): bool => $message->title() === 'Morgen: Biotonne'
            && $message->body() === 'Heute Abend die Tonne rausstellen.');
    }

    public function test_not_before_the_chosen_time_and_one_message_for_the_same_calendar(): void
    {
        Notification::fake();
        $this->household->forceFill(['message_senders' => [WasteCollectionWidget::KEY]])->save();
        $person = Person::factory()->child()->create(['household_id' => $this->household->id]);
        $child = User::factory()->create(['household_id' => $this->household->id, 'person_id' => $person->id]);
        $this->tile(['url' => 'https://gemeinde.example.test/abfall.ics', 'remind' => 'parents', 'remind_at' => '19:00']);
        $this->tile(['url' => 'webcal://gemeinde.example.test/abfall.ics', 'remind' => 'family', 'remind_at' => '20:00']);

        $this->artisan('fahoba:waste-reminders')->assertSuccessful();
        Notification::assertNothingSent();

        $this->travelTo(CarbonImmutable::parse('2026-10-20 19:05', 'Europe/Vienna'));
        $this->artisan('fahoba:waste-reminders')->assertSuccessful();

        Notification::assertSentToTimes($child, PluginMessage::class, 1);
    }

    /** @param  array<string, string>  $settings */
    private function tile(array $settings): DashboardWidget
    {
        static $y = 0;

        return DashboardWidget::factory()->create([
            'household_id' => $this->household->id, 'device_id' => $this->deviceId, 'type' => WasteCollectionWidget::KEY,
            'x' => 0, 'y' => 3 * $y++, 'w' => 4, 'h' => 3, 'settings' => $settings,
        ]);
    }
}
