<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Permission;
use App\Integrations\Plugins\PluginCatalog;
use App\Models\Household;
use App\Models\User;
use App\Notifications\PluginMessage;
use App\Support\Abilities;
use Fahoba\PluginApi\Messages\Message;
use Fahoba\PluginApi\Messages\MessageAudience;
use Fahoba\PluginApi\Messages\Messenger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * The core's `Messenger`: a plugin's message reaches a family only when the
 * package offered the sender and the household's parents ticked it
 * (`households.message_senders`). An installed package alone sends nothing
 * — a message on a child's phone is a door into the family, like an
 * interface.
 */
final class PluginMessenger implements Messenger
{
    public function __construct(
        private readonly PluginCatalog $plugins,
        private readonly Abilities $abilities,
    ) {}

    public function allowed(string $sender, int $householdId): bool
    {
        $household = Household::query()->active()->find($householdId);

        return $household instanceof Household && $household->allowsMessagesFrom($sender);
    }

    public function send(string $sender, int $householdId, Message $message): bool
    {
        if (! $this->allowed($sender, $householdId)) {
            return false;
        }

        $recipients = $this->recipients($householdId, $message->audience);

        if ($recipients === []) {
            return false;
        }

        // Counted only for what would have gone out.
        if (! RateLimiter::attempt("plugin-message:{$householdId}:{$sender}", self::PER_HOUR, static fn (): bool => true, 3600)) {
            return false;
        }

        Notification::send($recipients, new PluginMessage(
            $householdId,
            $sender,
            Str::limit(trim($message->title), Message::MAX_TITLE),
            $message->body === null ? null : Str::limit(trim($message->body), Message::MAX_BODY),
            $message->link,
            $message->push ? 'plugin-'.str_replace('/', '-', $sender).($message->tag === null ? '' : '-'.$message->tag) : null,
        ));

        return true;
    }

    /** @return list<User> */
    private function recipients(int $householdId, MessageAudience $audience): array
    {
        $members = User::query()
            ->whereHas('memberships', fn (Builder $membership) => $membership->accepted()->where('household_id', $householdId))
            ->get();

        if ($audience === MessageAudience::Parents) {
            $members = $members->filter(fn (User $user): bool => in_array(Permission::HouseholdManage, $this->abilities->ofIn($user, $householdId), true));
        }

        return $members->values()->all();
    }
}
