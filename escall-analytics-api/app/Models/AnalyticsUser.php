<?php

namespace App\Models;

use Database\Factories\AnalyticsUserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

final class AnalyticsUser extends Authenticatable
{
    /** @use HasFactory<AnalyticsUserFactory> */
    use HasFactory, Notifiable;

    public const ROLE_ADMINISTRADOR = 'administrador';

    public const ROLE_SUPERVISOR = 'supervisor';

    public const ROLE_PROVEEDOR = 'proveedor';

    protected $table = 'analytics_users';

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'is_active',
        'must_change_password',
        'last_login_at',
        'password_changed_at',
        'created_by',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'last_login_at' => 'datetime',
            'password_changed_at' => 'datetime',
        ];
    }

    /** @return list<string> */
    public static function roles(): array
    {
        return [self::ROLE_ADMINISTRADOR, self::ROLE_SUPERVISOR, self::ROLE_PROVEEDOR];
    }

    /** @return list<string> */
    public static function manageableRoles(): array
    {
        return [self::ROLE_SUPERVISOR, self::ROLE_PROVEEDOR];
    }
}
