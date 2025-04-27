<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Os atributos que são atribuíveis em massa.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * Os atributos que devem ser ocultados para serialização.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Os atributos que devem ser convertidos.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];

    /**
     * Obtém o perfil associado ao usuário.
     */
    public function profile(): HasOne
    {
        return $this->hasOne(UserProfile::class);
    }

    /**
     * Obtém os agentes personalizados do usuário.
     */
    public function customAgents(): HasMany
    {
        return $this->hasMany(CustomAgent::class);
    }

    /**
     * Obtém as interações com IA do usuário.
     */
    public function aiInteractions(): HasMany
    {
        return $this->hasMany(AiInteraction::class);
    }

    /**
     * Obtém os pagamentos do usuário.
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
