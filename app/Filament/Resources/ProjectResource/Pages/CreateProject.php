<?php

namespace App\Filament\Resources\ProjectResource\Pages;

use App\Filament\Resources\ProjectResource;
use Filament\Resources\Pages\CreateRecord;

/**
 * Create page for projects.
 *
 * A types mező JSON tömbként menti a kiválasztott monitor típusokat.
 * Egy projekt = több monitor típus, nincs szükség külön rekordok létrehozására.
 */
class CreateProject extends CreateRecord
{
    protected static string $resource = ProjectResource::class;

    // Mentés után vissza a listához
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
