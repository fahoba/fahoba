<?php

declare(strict_types=1);

namespace App\Notifications;

/**
 * A message a plugin sent through `Messenger` — text as the plugin wrote it,
 * the push tag carrying the sender so its pushes replace each other and no
 * other kind.
 */
final class PluginMessage extends HouseholdMessage
{
    public function __construct(
        int $householdId,
        public readonly string $sender,
        public readonly string $heading,
        public readonly ?string $text,
        public readonly ?string $target,
        public readonly ?string $pushTag,
    ) {
        parent::__construct($householdId);
    }

    public function title(): string
    {
        return $this->heading;
    }

    public function body(): ?string
    {
        return $this->text;
    }

    public function link(): ?string
    {
        return $this->target;
    }

    public function push(): ?string
    {
        return $this->pushTag;
    }
}
