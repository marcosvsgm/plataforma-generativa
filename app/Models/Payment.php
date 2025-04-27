<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    use HasFactory;

    /**
     * Os atributos que são atribuíveis em massa.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'subscription_plan_id',
        'amount',
        'payment_method',
        'payment_id',
        'payer_id',
        'payer_email',
        'status',
        'paid_at',
        'subscription_starts_at',
        'subscription_ends_at',
        'is_recurring',
        'payment_data',
    ];

    /**
     * Os atributos que devem ser convertidos.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
        'subscription_starts_at' => 'datetime',
        'subscription_ends_at' => 'datetime',
        'is_recurring' => 'boolean',
        'payment_data' => 'array',
    ];

    /**
     * Obtém o usuário associado ao pagamento.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Obtém o plano de assinatura associado ao pagamento.
     */
    public function subscriptionPlan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class);
    }

    /**
     * Verifica se o pagamento está ativo (assinatura atual).
     */
    public function isActive(): bool
    {
        return $this->status === 'approved' && 
               $this->subscription_ends_at > now();
    }

    /**
     * Verifica se o pagamento está pendente.
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Verifica se o pagamento foi rejeitado.
     */
    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }

    /**
     * Verifica se a assinatura está prestes a expirar (7 dias antes).
     */
    public function isExpiringSoon(): bool
    {
        return $this->isActive() && 
               $this->subscription_ends_at->diffInDays(now()) <= 7;
    }
}
