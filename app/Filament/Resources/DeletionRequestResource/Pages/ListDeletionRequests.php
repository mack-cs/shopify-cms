<?php

namespace App\Filament\Resources\DeletionRequestResource\Pages;

use App\Filament\Resources\DeletionRequestResource;
use App\Models\DeletionRequest;
use Filament\Resources\Pages\ListRecords\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class ListDeletionRequests extends ListRecords
{
    protected static string $resource = DeletionRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function getTabs(): array
    {
        $userId = (int) Auth::id();
        $assignedCount = $userId > 0
            ? DeletionRequest::query()
                ->where('status', DeletionRequest::STATUS_PENDING)
                ->where('target_approver_id', $userId)
                ->count()
            : 0;

        return [
            'all' => Tab::make('All'),
            'assigned_to_me' => Tab::make('Assigned to me')
                ->badge((string) $assignedCount)
                ->badgeColor($assignedCount > 0 ? 'warning' : 'gray')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->where('status', DeletionRequest::STATUS_PENDING)
                    ->where('target_approver_id', $userId)),
        ];
    }
}
