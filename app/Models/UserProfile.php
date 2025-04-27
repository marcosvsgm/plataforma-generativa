<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserProfile extends Model
{
    use HasFactory;

    /**
     * Os atributos que são atribuíveis em massa.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'full_name',
        'phone',
        'address',
        'city',
        'state',
        'country',
        'postal_code',
        'company',
        'job_title',
        'avatar',
        'bio',
    ];

    /**
     * Obtém o usuário associado ao perfil.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
