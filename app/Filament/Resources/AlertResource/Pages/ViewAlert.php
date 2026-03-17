<?php

namespace App\Filament\Resources\AlertResource\Pages;

use App\Filament\Resources\AlertResource;
use Filament\Resources\Pages\ViewRecord;

/**
 * Read-only view page for a single alert record.
 *
 * A riasztások csak megtekinthetők, nem szerkeszthetők az adminisztrációs panelen.
 */
class ViewAlert extends ViewRecord
{
    protected static string $resource = AlertResource::class;

    /**
     * No header actions – edit is intentionally disabled for alerts.
     */
    protected function getHeaderActions(): array
    {
        // Szerkesztés és törlés gomb szándékosan ki van hagyva
        return [];
    }
}
