<?php

declare(strict_types=1);

namespace App\Dashboard;

use App\Models\DashboardWidget;
use App\Models\Household;
use Fahoba\PluginApi\Dashboard\PlacedTile;
use Fahoba\PluginApi\Dashboard\PlacedTiles;

/**
 * The core's `PlacedTiles`: read without a household context, because a
 * scheduled command runs for all of them.
 */
final class CorePlacedTiles implements PlacedTiles
{
    public function __construct(
        private readonly WidgetRegistry $widgets,
        private readonly WidgetSettings $settings,
    ) {}

    public function of(string $type): array
    {
        $households = Household::query()->active()->get()->keyBy('id');
        $fields = $this->widgets->find($type)->settings ?? [];

        return DashboardWidget::withoutHouseholdScope()
            ->where('type', $type)
            ->whereIn('household_id', $households->keys())
            ->orderBy('id')
            ->get()
            ->map(function (DashboardWidget $tile) use ($households, $fields): PlacedTile {
                $household = $households[$tile->household_id];

                return new PlacedTile(
                    (int) $household->id,
                    (string) $household->timezone,
                    (string) ($household->locale ?? config('app.locale')),
                    $this->settings->plain($fields, $tile->settings ?? []),
                );
            })
            ->values()
            ->all();
    }
}
