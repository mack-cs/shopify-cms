<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Filament\Panel;
use Illuminate\Notifications\Notifiable;
use Filament\Models\Contracts\FilamentUser;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Spatie\Permission\Traits\HasRoles;
use Illuminate\Notifications\Notification as LaravelNotification;

class User extends Authenticatable  implements FilamentUser
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable, HasRoles;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */

    public function canAccessPanel(Panel $panel): bool
    {
        if (!$this->is_active) {
            return false;
        }
        return str_ends_with($this->email, '@mackscs.com') || str_ends_with($this->email, '@leighavenue.co.za') ;
        // return str_ends_with($this->email, '@yourdomain.com') && $this->hasVerifiedEmail();
    }
    protected $fillable = [
        'name',
        'email',
        'password',
        'force_password_change',
        'is_active',
        'slack_user_id',
        'slack_notifications_enabled',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'force_password_change' => 'boolean',
            'is_active' => 'boolean',
            'slack_notifications_enabled' => 'boolean',
        ];
    }

    public function routeNotificationForSlack(LaravelNotification $notification): mixed
    {
        return config('services.slack.channels.assignments');
    }



}
