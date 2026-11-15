<?php

declare(strict_types=1);

namespace Tests\Feature\Plugins;

use App\Filament\Resources\Households\Pages\EditHousehold;
use App\Models\Household;
use App\Models\Person;
use App\Models\User;
use App\Notifications\Channels\PushChannel;
use App\Notifications\PluginMessage;
use App\Support\HouseholdContext;
use Fahoba\PluginApi\Messages\Message;
use Fahoba\PluginApi\Messages\MessageAudience;
use Fahoba\PluginApi\Messages\MessageSender;
use Fahoba\PluginApi\Messages\Messenger;
use Fahoba\PluginApi\Plugins\Catalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A plugin's message reaches a family only after the parents allowed that
 * plugin — installing it is not enough.
 */
final class PluginMessagesTest extends TestCase
{
    use RefreshDatabase;

    private Household $household;

    private User $parent;

    private User $child;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        app(Catalog::class)->offer('messages', 'acme/bins', BinReminder::class);
        $this->household = Household::factory()->create();
        $this->parent = User::factory()->create(['household_id' => $this->household->id]);
        $person = Person::factory()->child()->create(['household_id' => $this->household->id]);
        $this->child = User::factory()->create(['household_id' => $this->household->id, 'person_id' => $person->id]);
    }

    public function test_an_installed_plugin_sends_nothing_until_allowed(): void
    {
        $this->assertFalse($this->messenger()->allowed('acme/bins', (int) $this->household->id));
        $this->assertFalse($this->messenger()->send('acme/bins', (int) $this->household->id, new Message('Biotonne morgen')));

        Notification::assertNothingSent();
    }

    public function test_allowed_it_reaches_the_parents_bell_and_phones(): void
    {
        $this->allow('acme/bins');

        $this->assertTrue($this->messenger()->send('acme/bins', (int) $this->household->id, new Message('Biotonne morgen', 'Heute Abend rausstellen', '/?area=dashboard', tag: 'bio')));

        Notification::assertSentTo($this->parent, PluginMessage::class, function (PluginMessage $message, array $channels): bool {
            return $message->title() === 'Biotonne morgen'
                && $message->link() === '/?area=dashboard'
                && $message->push() === 'plugin-acme-bins-bio'
                && in_array(PushChannel::class, $channels, true);
        });
        Notification::assertNotSentTo($this->child, PluginMessage::class);
    }

    public function test_the_family_audience_reaches_the_children_too(): void
    {
        $this->allow('acme/bins');

        $this->messenger()->send('acme/bins', (int) $this->household->id, new Message('Bin day', audience: MessageAudience::Family, push: false));

        Notification::assertSentTo($this->child, PluginMessage::class, fn (PluginMessage $message, array $channels): bool => ! in_array(PushChannel::class, $channels, true));
    }

    public function test_a_key_nobody_offers_sends_nothing_even_when_ticked(): void
    {
        $this->allow('acme/bins', 'acme/gone');

        $this->assertFalse($this->messenger()->send('acme/gone', (int) $this->household->id, new Message('Hallo')));
    }

    public function test_a_suspended_household_hears_nothing(): void
    {
        $this->allow('acme/bins');
        $this->household->forceFill(['suspended_at' => now()])->save();

        $this->assertFalse($this->messenger()->send('acme/bins', (int) $this->household->id, new Message('Hallo')));
    }

    public function test_a_looping_plugin_is_cut_off_after_the_hourly_limit(): void
    {
        $this->allow('acme/bins');

        $sent = 0;
        for ($i = 0; $i < Messenger::PER_HOUR + 3; $i++) {
            $sent += (int) $this->messenger()->send('acme/bins', (int) $this->household->id, new Message("Nr. {$i}"));
        }

        $this->assertSame(Messenger::PER_HOUR, $sent);
    }

    public function test_a_message_links_only_to_https_or_here(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Message('Hallo', link: 'javascript:alert(1)');
    }

    public function test_parents_tick_the_sender_in_the_household_form(): void
    {
        app(HouseholdContext::class)->setId((int) $this->household->id);
        $this->actingAs($this->parent);

        Livewire::test(EditHousehold::class, ['record' => $this->household->getRouteKey()])
            ->assertSee('Bin reminder')
            ->fillForm(['message_senders' => ['acme/bins']])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($this->household->refresh()->allowsMessagesFrom('acme/bins'));
    }

    private function allow(string ...$senders): void
    {
        $this->household->forceFill(['message_senders' => $senders])->save();
    }

    private function messenger(): Messenger
    {
        return app(Messenger::class);
    }
}

final class BinReminder implements MessageSender
{
    public function label(): string
    {
        return 'Bin reminder';
    }

    public function description(): string
    {
        return 'The evening before each collection.';
    }
}
