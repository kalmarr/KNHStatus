<?php

namespace App\Filament\Resources\HeartbeatResource\Pages;

use App\Filament\Resources\HeartbeatResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditHeartbeat extends EditRecord
{
    protected static string $resource = HeartbeatResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
