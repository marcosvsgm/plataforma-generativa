<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomAgent extends Model
{
    use HasFactory;
    
    /**
     * Os atributos que são atribuíveis em massa.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'ai_service_id',
        'name',
        'description',
        'instructions',
        'knowledge_base',
        'parameters',
        'is_active',
        'is_public',
        'usage_count',
    ];
    
    /**
     * Os atributos que devem ser convertidos.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'parameters' => 'array',
        'is_active' => 'boolean',
        'is_public' => 'boolean',
        'usage_count' => 'integer',
    ];
    
    /**
     * Obtém o usuário que criou este agente personalizado.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
    
    /**
     * Obtém o serviço de IA associado a este agente personalizado.
     */
    public function aiService(): BelongsTo
    {
        return $this->belongsTo(AIService::class);
    }
    
    /**
     * Obtém as interações associadas a este agente personalizado.
     */
    public function interactions(): HasMany
    {
        return $this->hasMany(AIInteraction::class, 'custom_agent_id');
    }
    
    /**
     * Incrementa o contador de uso deste agente.
     */
    public function incrementUsageCount(): void
    {
        $this->increment('usage_count');
    }
    
    /**
     * Verifica se o usuário tem permissão para usar este agente.
     */
    public function canBeUsedBy(User $user): bool
    {
        return $this->user_id === $user->id || ($this->is_public && $this->is_active);
    }
    
    /**
     * Prepara o prompt completo para envio à API de IA.
     */
    public function preparePrompt(string $userInput): string
    {
        $prompt = "Instruções do sistema:\n{$this->instructions}\n\n";
        
        if (!empty($this->knowledge_base)) {
            $prompt .= "Conhecimento base:\n{$this->knowledge_base}\n\n";
        }
        
        $prompt .= "Entrada do usuário:\n{$userInput}";
        
        return $prompt;
    }
}
