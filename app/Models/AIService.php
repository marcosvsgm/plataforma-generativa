<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AIService extends Model
{
    use HasFactory;
    
    /**
     * A tabela associada ao modelo.
     *
     * @var string
     */
    protected $table = 'ai_services';
    
    /**
     * Os atributos que são atribuíveis em massa.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'provider',
        'model',
        'description',
        'api_key_config_name',
        'is_active',
        'cost_per_request',
        'parameters',
    ];
    
    /**
     * Os atributos que devem ser convertidos.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_active' => 'boolean',
        'cost_per_request' => 'decimal:6',
        'parameters' => 'array',
    ];
    
    /**
     * Obtém as interações associadas a este serviço de IA.
     */
    public function interactions(): HasMany
    {
        return $this->hasMany(AIInteraction::class);
    }
    
    /**
     * Verifica se o serviço é do provedor ChatGPT.
     */
    public function isChatGPT(): bool
    {
        return $this->provider === 'chatgpt';
    }
    
    /**
     * Verifica se o serviço é do provedor Gemini.
     */
    public function isGemini(): bool
    {
        return $this->provider === 'gemini';
    }
    
    /**
     * Verifica se o serviço é do provedor DeepSeek.
     */
    public function isDeepSeek(): bool
    {
        return $this->provider === 'deepseek';
    }
    
    /**
     * Obtém a chave de API configurada para este serviço.
     */
    public function getApiKey(): ?string
    {
        return config('services.' . $this->provider . '.api_key');
    }
}
