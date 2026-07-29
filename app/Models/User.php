<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['shop_id','name', 'username', 'email', 'password', 'role','staff_role','shop_permissions', 'phone', 'business_name', 'address', 'is_active', 'can_view_locations', 'notes', 'last_login_at'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    public function devices()
    {
        return $this->hasMany(Device::class, 'admin_id');
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === 'super_admin';
    }
    public function isShopOwner(): bool { return in_array($this->role,['admin','shop_owner'],true); }
    public function shop(){return $this->belongsTo(Shop::class);}
    public function canShop(string $permission): bool
    {
        $aliases = [
            'devices' => 'devices.create',
            'lock_unlock' => 'devices.lock',
        ];

        return $this->isShopOwner()
            || $this->isSuperAdmin()
            || in_array($permission, $this->shop_permissions ?? [], true)
            || isset($aliases[$permission]) && in_array($aliases[$permission], $this->shop_permissions ?? [], true);
    }

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
            'is_active' => 'boolean',
            'can_view_locations' => 'boolean',
            'last_login_at' => 'datetime',
            'shop_permissions' => 'array',
        ];
    }
}
