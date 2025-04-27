<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AIInteraction extends Model
{
    use HasFactory;
    
    /**
     * A tabela associada ao modelo.
     *
     * @var string
     */
    protected $table = 'ai_interactions';
    
    /**
     * Os atributos que são atribuíveis em massa.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'ai_service_id',
        'prompt',
        'response',
        'tokens_used',
        'cost',
        'is_successful',
        'error_message',
        'metadata',
    ];
    
    /**
     * Os atributos que devem ser convertidos.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'tokens_used' => 'integer',
        'cost' => 'decimal:6',
        'is_successful' => 'boolean',
        'metadata' => 'array',
    ];
    
    /**
     * Obtém o usuário associado a esta interação.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
    
    /**
     * Obtém o serviço de IA associado a esta interação.
     */
    public function aiService(): BelongsTo
    {
        return $this->belongsTo(AIService::class);
    }
    
    /**
     * Calcula o custo da interação com base nos tokens usados e no custo por requisição do serviço.
     */
    public function calculateCost(): float
    {
        if ($this->tokens_used <= 0) {
            return 0;
        }
        
        $costPerToken = $this->aiService->cost_per_request / 1000; // Custo por 1000 tokens
        return $this->tokens_used * $costPerToken;
    }
    
    /**
     * Retorna um resumo da interação para exibição.
     */
    public function getSummary(int $maxLength = 100): string
    {
        return strlen($this->prompt) > $maxLength
            ? substr($this->prompt, 0, $maxLength) . '...'
            : $this->prompt;
    }
}
