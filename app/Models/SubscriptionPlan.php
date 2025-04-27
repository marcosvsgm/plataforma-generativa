<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SubscriptionPlan extends Model
{
    use HasFactory;

    /**
     * Os atributos que são atribuíveis em massa.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'description',
        'price',
        'billing_period',
        'ai_requests_limit',
        'custom_agents_limit',
        'can_use_chatgpt',
        'can_use_gemini',
        'can_use_deepseek',
        'is_active',
        'stripe_plan_id',
        'mercadopago_plan_id',
    ];

    /**
     * Os atributos que devem ser convertidos.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'price' => 'decimal:2',
        'ai_requests_limit' => 'integer',
        'custom_agents_limit' => 'integer',
        'can_use_chatgpt' => 'boolean',
        'can_use_gemini' => 'boolean',
        'can_use_deepseek' => 'boolean',
        'is_active' => 'boolean',
    ];

    /**
     * Obtém os pagamentos associados a este plano.
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Verifica se o plano permite o uso de um determinado provedor de IA.
     *
     * @param string $provider
     * @return bool
     */
    public function canUseProvider(string $provider): bool
    {
        return match ($provider) {
            'chatgpt' => $this->can_use_chatgpt,
            'gemini' => $this->can_use_gemini,
            'deepseek' => $this->can_use_deepseek,
            default => false,
        };
    }
}
