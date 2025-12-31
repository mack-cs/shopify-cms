<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Filament\Resources\UserResource\RelationManagers;
use App\Enums\PermissionEnum;
use App\Enums\RolesEnum;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationGroup = 'User Management';
    protected static ?string $navigationIcon = 'heroicon-o-users';
    protected static ?int $navigationSort = 100;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('email')
                    ->email()
                    ->required()
                    ->maxLength(255),
                Forms\Components\Select::make('roles')
                    ->label('Role')
                    ->relationship(
                        'roles',
                        'name',
                        fn ($query) => $query->when(
                            !Auth::user()?->hasRole(RolesEnum::SuperAdmin->value),
                            fn ($q) => $q->where('name', '!=', RolesEnum::SuperAdmin->value)
                        )
                    )
                    ->multiple()
                    ->maxItems(1)
                    ->preload()
                    ->required(),
                Forms\Components\Toggle::make('is_active')
                    ->label('Active')
                    ->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('email')
                    ->searchable(),
                Tables\Columns\TextColumn::make('roles.name')
                    ->label('Role')
                    ->badge()
                    ->separator(', '),
                Tables\Columns\IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->trueColor('success')
                    ->falseColor('gray'),
                Tables\Columns\TextColumn::make('email_verified_at')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('two_factor_confirmed_at')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->visible(fn (User $record): bool => static::canEdit($record)),
                Tables\Actions\Action::make('toggleActive')
                    ->label(fn (User $record): string => $record->is_active ? 'Deactivate' : 'Activate')
                    ->requiresConfirmation()
                    ->visible(fn (User $record): bool => static::canEdit($record))
                    ->action(function (User $record): void {
                        $record->forceFill(['is_active' => !$record->is_active])->save();
                    }),
                Tables\Actions\Action::make('forcePasswordReset')
                    ->label('Force Password Reset')
                    ->requiresConfirmation()
                    ->visible(fn (User $record): bool => static::canEdit($record))
                    ->action(function (User $record): void {
                        $record->forceFill(['force_password_change' => true])->save();

                        $status = Password::sendResetLink(['email' => $record->email]);
                        if ($status === Password::RESET_LINK_SENT) {
                            Notification::make()
                                ->title('Reset email sent')
                                ->success()
                                ->send();
                            return;
                        }

                        Notification::make()
                            ->title('Failed to send reset email')
                            ->danger()
                            ->send();
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->visible(fn (): bool => Auth::user()?->hasRole(RolesEnum::SuperAdmin->value) ?? false),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return Auth::user()?->can(PermissionEnum::UserManage->value) ?? false;
    }

    public static function canCreate(): bool
    {
        return Auth::user()?->can(PermissionEnum::UserManage->value) ?? false;
    }

    public static function canEdit($record): bool
    {
        $user = Auth::user();
        if (!$user || !$user->can(PermissionEnum::UserManage->value)) {
            return false;
        }

        if ((int) $record->getKey() === (int) $user->getKey()) {
            return false;
        }

        if ($record->hasRole(RolesEnum::SuperAdmin->value) && !$user->hasRole(RolesEnum::SuperAdmin->value)) {
            return false;
        }

        return true;
    }

    public static function canDelete($record): bool
    {
        return static::canEdit($record);
    }

    public static function canDeleteAny(): bool
    {
        return Auth::user()?->can(PermissionEnum::UserManage->value) ?? false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereDoesntHave('roles', fn (Builder $query) => $query->where('name', RolesEnum::SuperAdmin->value));
    }
}
