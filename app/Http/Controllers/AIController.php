<?php

namespace App\Http\Controllers;

use App\Models\AIInteraction;
use App\Models\AIService;
use App\Models\User;
use Exception;
use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class AIController extends Controller
{
    /**
     * Cliente HTTP para requisições às APIs.
     */
    protected $httpClient;

    /**
     * Construtor do controlador.
     */
    public function __construct()
    {
        $this->middleware(['auth', 'verified']);
        $this->httpClient = new Client();
    }

    /**
     * Exibe a página de interação com IA.
     */
    public function index()
    {
        $user = Auth::user();
        $services = $this->getAvailableServicesForUser($user);
        $interactions = $user->aiInteractions()
            ->with('aiService')
            ->orderBy('created_at', 'desc')
            ->paginate(10);
        
        return view('ai.index', compact('services', 'interactions'));
    }

    /**
     * Exibe o formulário para criar uma nova interação.
     */
    public function create()
    {
        $user = Auth::user();
        $services = $this->getAvailableServicesForUser($user);
        
        if ($services->isEmpty()) {
            return redirect()->route('subscription_plans.index')
                ->with('error', 'Você precisa de um plano de assinatura ativo para acessar os serviços de IA.');
        }
        
        return view('ai.create', compact('services'));
    }

    /**
     * Processa a requisição para a API de IA.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'ai_service_id' => 'required|exists:ai_services,id',
            'prompt' => 'required|string|min:5|max:4000',
        ]);
        
        $user = Auth::user();
        $service = AIService::findOrFail($validated['ai_service_id']);
        
        // Verificar se o usuário pode usar este serviço
        if (!$this->canUseService($user, $service)) {
            return redirect()->back()->with('error', 'Você não tem acesso a este serviço de IA. Atualize seu plano de assinatura.');
        }
        
        // Verificar se o usuário atingiu o limite de requisições
        if (!$this->checkRequestLimit($user)) {
            return redirect()->back()->with('error', 'Você atingiu o limite de requisições para seu plano atual.');
        }
        
        try {
            // Criar registro de interação
            $interaction = new AIInteraction([
                'user_id' => $user->id,
                'ai_service_id' => $service->id,
                'prompt' => $validated['prompt'],
                'is_successful' => false, // Será atualizado após a resposta
            ]);
            $interaction->save();
            
            // Processar a requisição para o serviço de IA apropriado
            $response = $this->processAIRequest($service, $validated['prompt']);
            
            // Atualizar o registro de interação com a resposta
            $interaction->update([
                'response' => $response['text'],
                'tokens_used' => $response['tokens_used'],
                'cost' => $response['cost'],
                'is_successful' => true,
                'metadata' => $response['metadata'],
            ]);
            
            return redirect()->route('ai.show', $interaction)
                ->with('success', 'Requisição processada com sucesso!');
            
        } catch (Exception $e) {
            Log::error('Erro ao processar requisição de IA: ' . $e->getMessage());
            
            // Se já criamos a interação, atualizar com o erro
            if (isset($interaction)) {
                $interaction->update([
                    'is_successful' => false,
                    'error_message' => $e->getMessage(),
                ]);
            }
            
            return redirect()->back()
                ->with('error', 'Ocorreu um erro ao processar sua requisição: ' . $e->getMessage());
        }
    }

    /**
     * Exibe uma interação específica.
     */
    public function show(AIInteraction $aiInteraction)
    {
        $this->authorize('view', $aiInteraction);
        
        return view('ai.show', ['interaction' => $aiInteraction]);
    }

    /**
     * Retorna os serviços de IA disponíveis para o usuário com base em seu plano.
     */
    protected function getAvailableServicesForUser(User $user): object
    {
        $activeSubscription = $user->getActiveSubscription();
        
        if (!$activeSubscription) {
            return collect();
        }
        
        $plan = $activeSubscription->subscriptionPlan;
        $query = AIService::where('is_active', true);
        
        // Filtrar por provedores disponíveis no plano
        $availableProviders = [];
        
        if ($plan->can_use_chatgpt) {
            $availableProviders[] = 'chatgpt';
        }
        
        if ($plan->can_use_gemini) {
            $availableProviders[] = 'gemini';
        }
        
        if ($plan->can_use_deepseek) {
            $availableProviders[] = 'deepseek';
        }
        
        if (empty($availableProviders)) {
            return collect();
        }
        
        return $query->whereIn('provider', $availableProviders)->get();
    }

    /**
     * Verifica se o usuário pode usar o serviço de IA específico.
     */
    protected function canUseService(User $user, AIService $service): bool
    {
        $activeSubscription = $user->getActiveSubscription();
        
        if (!$activeSubscription) {
            return false;
        }
        
        $plan = $activeSubscription->subscriptionPlan;
        
        return match ($service->provider) {
            'chatgpt' => $plan->can_use_chatgpt,
            'gemini' => $plan->can_use_gemini,
            'deepseek' => $plan->can_use_deepseek,
            default => false,
        };
    }

    /**
     * Verifica se o usuário atingiu o limite de requisições do plano.
     */
    protected function checkRequestLimit(User $user): bool
    {
        $activeSubscription = $user->getActiveSubscription();
        
        if (!$activeSubscription) {
            return false;
        }
        
        $plan = $activeSubscription->subscriptionPlan;
        
        // Se o plano tem requisições ilimitadas
        if ($plan->ai_requests_limit <= 0) {
            return true;
        }
        
        // Contar requisições do mês atual
        $currentMonthRequests = $user->aiInteractions()
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->count();
        
        return $currentMonthRequests < $plan->ai_requests_limit;
    }

    /**
     * Processa a requisição para o serviço de IA apropriado.
     */
    protected function processAIRequest(AIService $service, string $prompt): array
    {
        return match ($service->provider) {
            'chatgpt' => $this->processChatGPTRequest($service, $prompt),
            'gemini' => $this->processGeminiRequest($service, $prompt),
            'deepseek' => $this->processDeepSeekRequest($service, $prompt),
            default => throw new Exception('Provedor de IA não suportado'),
        };
    }

    /**
     * Processa uma requisição para a API do ChatGPT (OpenAI).
     */
    protected function processChatGPTRequest(AIService $service, string $prompt): array
    {
        $apiKey = $service->getApiKey();
        
        if (empty($apiKey)) {
            throw new Exception('Chave de API do ChatGPT não configurada');
        }
        
        $response = $this->httpClient->post('https://api.openai.com/v1/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'model' => $service->model,
                'messages' => [
                    ['role' => 'user', 'content' => $prompt]
                ],
                'temperature' => $service->parameters['temperature'] ?? 0.7,
                'max_tokens' => $service->parameters['max_tokens'] ?? 1000,
            ],
        ]);
        
        $result = json_decode($response->getBody()->getContents(), true);
        
        if (!isset($result['choices'][0]['message']['content'])) {
            throw new Exception('Resposta inválida da API do ChatGPT');
        }
        
        return [
            'text' => $result['choices'][0]['message']['content'],
            'tokens_used' => $result['usage']['total_tokens'] ?? 0,
            'cost' => ($result['usage']['total_tokens'] ?? 0) * ($service->cost_per_request / 1000),
            'metadata' => $result,
        ];
    }

    /**
     * Processa uma requisição para a API do Gemini (Google).
     */
    protected function processGeminiRequest(AIService $service, string $prompt): array
    {
        $apiKey = $service->getApiKey();
        
        if (empty($apiKey)) {
            throw new Exception('Chave de API do Gemini não configurada');
        }
        
        $response = $this->httpClient->post('https://generativelanguage.googleapis.com/v1/models/' . $service->model . ':generateContent', [
            'query' => [
                'key' => $apiKey,
            ],
            'json' => [
                'contents' => [
                    ['parts' => [['text' => $prompt]]],
                ],
                'generationConfig' => [
                    'temperature' => $service->parameters['temperature'] ?? 0.7,
                    'maxOutputTokens' => $service->parameters['max_tokens'] ?? 1000,
                ],
            ],
        ]);
        
        $result = json_decode($response->getBody()->getContents(), true);
        
        if (!isset($result['candidates'][0]['content']['parts'][0]['text'])) {
            throw new Exception('Resposta inválida da API do Gemini');
        }
        
        // Gemini não retorna contagem de tokens, então estimamos
        $tokensUsed = intval(str_word_count($prompt . $result['candidates'][0]['content']['parts'][0]['text']) * 1.3);
        
        return [
            'text' => $result['candidates'][0]['content']['parts'][0]['text'],
            'tokens_used' => $tokensUsed,
            'cost' => $tokensUsed * ($service->cost_per_request / 1000),
            'metadata' => $result,
        ];
    }

    /**
     * Processa uma requisição para a API do DeepSeek.
     */
    protected function processDeepSeekRequest(AIService $service, string $prompt): array
    {
        $apiKey = $service->getApiKey();
        
        if (empty($apiKey)) {
            throw new Exception('Chave de API do DeepSeek não configurada');
        }
        
        $response = $this->httpClient->post('https://api.deepseek.com/v1/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'model' => $service->model,
                'messages' => [
                    ['role' => 'user', 'content' => $prompt]
                ],
                'temperature' => $service->parameters['temperature'] ?? 0.7,
                'max_tokens' => $service->parameters['max_tokens'] ?? 1000,
            ],
        ]);
        
        $result = json_decode($response->getBody()->getContents(), true);
        
        if (!isset($result['choices'][0]['message']['content'])) {
            throw new Exception('Resposta inválida da API do DeepSeek');
        }
        
        return [
            'text' => $result['choices'][0]['message']['content'],
            'tokens_used' => $result['usage']['total_tokens'] ?? 0,
            'cost' => ($result['usage']['total_tokens'] ?? 0) * ($service->cost_per_request / 1000),
            'metadata' => $result,
        ];
    }
}
