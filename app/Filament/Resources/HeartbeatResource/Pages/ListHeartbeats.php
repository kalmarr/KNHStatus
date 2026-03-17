<?php

namespace App\Filament\Resources\HeartbeatResource\Pages;

use App\Filament\Resources\HeartbeatResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListHeartbeats extends ListRecords
{
    protected static string $resource = HeartbeatResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
