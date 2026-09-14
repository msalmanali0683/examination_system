<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
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
        ];
    }

    public function permissionOverrides(): HasMany
    {
        return $this->hasMany(UserPermission::class);
    }

    public function isHead(): bool
    {
        return $this->role === 'head';
    }

    /**
     * A permission is granted per-user if overridden, otherwise it falls
     * back to the role's default from config/permissions.php.
     */
    public function hasPermission(string $permission): bool
    {
        $override = $this->permissionOverrides->firstWhere('permission', $permission);

        if ($override !== null) {
            return $override->granted;
        }

        return (bool) config("permissions.defaults.{$this->role}.{$permission}", false);
    }
}
