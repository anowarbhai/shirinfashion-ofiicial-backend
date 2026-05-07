<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Support\MediaUrl;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Collection;
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
        'phone',
        'address',
        'role',
        'admin_role_id',
        'status',
        'avatar_url',
        'marketing_opt_in',
        'password',
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
            'marketing_opt_in' => 'boolean',
            'password' => 'hashed',
        ];
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function adminRole(): BelongsTo
    {
        return $this->belongsTo(AdminRole::class, 'admin_role_id');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function wishlistItems(): HasMany
    {
        return $this->hasMany(WishlistItem::class);
    }

    public function isAdmin(): bool
    {
        return ($this->role === 'admin' || $this->admin_role_id !== null)
            && ($this->status ?? 'active') === 'active';
    }

    public function hasAdminPermission(string $permission): bool
    {
        if (! $this->isAdmin()) {
            return false;
        }

        if ($this->admin_role_id === null) {
            return $this->role === 'admin';
        }

        $permissions = $this->adminPermissionSlugs();

        return $permissions->contains('system.everything') || $permissions->contains($permission);
    }

    /**
     * @return Collection<int, string>
     */
    public function adminPermissionSlugs(): Collection
    {
        $role = $this->adminRole()
            ->where('is_active', true)
            ->with(['permissions' => fn ($query) => $query->where('is_active', true)->select('admin_permissions.id', 'slug')])
            ->first();

        if (! $role) {
            return collect();
        }

        return $role->permissions->pluck('slug')->values();
    }

    protected function avatarUrl(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => MediaUrl::toPublic($value),
            set: fn (?string $value) => MediaUrl::normalizeStored($value),
        );
    }
}
