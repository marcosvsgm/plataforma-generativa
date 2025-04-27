<?php

namespace App\Http\Controllers;

use App\Models\AIService;
use App\Models\CustomAgent;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class CustomAgentController extends Controller
{
    /**
     * Construtor do controlador.
     */
    public function __construct()
    {
        $this->middleware(['auth', 'verified']);
    }

    /**
     * Exibe uma lista dos agentes personalizados.
     */
    public function index()
    {
        $user = Auth::user();
        
        // Obter agentes do usuário
        $userAgents = $user->customAgents()
            ->with('aiService')
            ->orderBy('created_at', 'desc')
            ->get();
        
        // Obter agentes públicos de outros usuários
        $publicAgents = CustomAgent::where('user_id', '!=', $user->id)
            ->where('is_public', true)
            ->where('is_active', true)
            ->with(['aiService', 'user'])
            ->orderBy('usage_count', 'desc')
            ->limit(10)
            ->get();
        
        return view('custom_agents.index', compact('userAgents', 'publicAgents'));
    }

    /**
     * Exibe o formulário para criar um novo agente personalizado.
     */
    public function create()
    {
        $user = Auth::user();
        $activeSubscription = $user->getActiveSubscription();
        
        if (!$activeSubscription) {
            return redirect()->route('subscription_plans.index')
                ->with('error', 'Você precisa de um plano de assinatura ativo para criar agentes personalizados.');
        }
        
        $plan = $activeSubscription->subscriptionPlan;
        
        // Verificar se o usuário atingiu o limite de agentes
        $currentAgentsCount = $user->customAgents()->count();
        if ($plan->custom_agents_limit > 0 && $currentAgentsCount >= $plan->custom_agents_limit) {
            return redirect()->route('custom_agents.index')
                ->with('error', 'Você atingiu o limite de agentes personalizados para seu plano atual.');
        }
        
        // Obter serviços de IA disponíveis para o usuário
        $services = $this->getAvailableServicesForUser($user);
        
        if ($services->isEmpty()) {
            return redirect()->route('subscription_plans.index')
                ->with('error', 'Seu plano atual não permite acesso a nenhum serviço de IA para criar agentes personalizados.');
        }
        
        return view('custom_agents.create', compact('services'));
    }

    /**
     * Armazena um novo agente personalizado.
     */
    public function store(Request $request)
    {
        $user = Auth::user();
        $activeSubscription = $user->getActiveSubscription();
        
        if (!$activeSubscription) {
            return redirect()->route('subscription_plans.index')
                ->with('error', 'Você precisa de um plano de assinatura ativo para criar agentes personalizados.');
        }
        
        $plan = $activeSubscription->subscriptionPlan;
        
        // Verificar se o usuário atingiu o limite de agentes
        $currentAgentsCount = $user->customAgents()->count();
        if ($plan->custom_agents_limit > 0 && $currentAgentsCount >= $plan->custom_agents_limit) {
            return redirect()->route('custom_agents.index')
                ->with('error', 'Você atingiu o limite de agentes personalizados para seu plano atual.');
        }
        
        $validated = $request->validate([
            'ai_service_id' => 'required|exists:ai_services,id',
            'name' => 'required|string|max:255',
            'description' => 'required|string|max:1000',
            'instructions' => 'required|string|max:4000',
            'knowledge_base' => 'nullable|string|max:10000',
            'is_public' => 'boolean',
        ]);
        
        // Verificar se o usuário pode usar o serviço de IA selecionado
        $service = AIService::findOrFail($validated['ai_service_id']);
        if (!$this->canUseService($user, $service)) {
            return redirect()->back()
                ->with('error', 'Você não tem acesso a este serviço de IA. Atualize seu plano de assinatura.');
        }
        
        // Criar o agente personalizado
        $agent = new CustomAgent([
            'user_id' => $user->id,
            'ai_service_id' => $validated['ai_service_id'],
            'name' => $validated['name'],
            'description' => $validated['description'],
            'instructions' => $validated['instructions'],
            'knowledge_base' => $validated['knowledge_base'],
            'is_public' => $request->has('is_public'),
            'parameters' => [
                'temperature' => $request->input('temperature', 0.7),
                'max_tokens' => $request->input('max_tokens', 1000),
            ],
        ]);
        
        $agent->save();
        
        return redirect()->route('custom_agents.show', $agent)
            ->with('success', 'Agente personalizado criado com sucesso!');
    }

    /**
     * Exibe um agente personalizado específico.
     */
    public function show(CustomAgent $customAgent)
    {
        $user = Auth::user();
        
        // Verificar se o usuário tem permissão para ver este agente
        if (!$customAgent->canBeUsedBy($user)) {
            abort(403, 'Você não tem permissão para acessar este agente personalizado.');
        }
        
        // Carregar relacionamentos
        $customAgent->load(['aiService', 'user']);
        
        // Obter interações recentes com este agente
        $interactions = $customAgent->interactions()
            ->where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get();
        
        return view('custom_agents.show', compact('customAgent', 'interactions'));
    }

    /**
     * Exibe o formulário para editar um agente personalizado.
     */
    public function edit(CustomAgent $customAgent)
    {
        $user = Auth::user();
        
        // Verificar se o usuário é o proprietário do agente
        if ($customAgent->user_id !== $user->id) {
            abort(403, 'Você não tem permissão para editar este agente personalizado.');
        }
        
        // Obter serviços de IA disponíveis para o usuário
        $services = $this->getAvailableServicesForUser($user);
        
        return view('custom_agents.edit', compact('customAgent', 'services'));
    }

    /**
     * Atualiza um agente personalizado específico.
     */
    public function update(Request $request, CustomAgent $customAgent)
    {
        $user = Auth::user();
        
        // Verificar se o usuário é o proprietário do agente
        if ($customAgent->user_id !== $user->id) {
            abort(403, 'Você não tem permissão para editar este agente personalizado.');
        }
        
        $validated = $request->validate([
            'ai_service_id' => 'required|exists:ai_services,id',
            'name' => 'required|string|max:255',
            'description' => 'required|string|max:1000',
            'instructions' => 'required|string|max:4000',
            'knowledge_base' => 'nullable|string|max:10000',
            'is_active' => 'boolean',
            'is_public' => 'boolean',
        ]);
        
        // Verificar se o usuário pode usar o serviço de IA selecionado
        $service = AIService::findOrFail($validated['ai_service_id']);
        if (!$this->canUseService($user, $service)) {
            return redirect()->back()
                ->with('error', 'Você não tem acesso a este serviço de IA. Atualize seu plano de assinatura.');
        }
        
        // Atualizar o agente personalizado
        $customAgent->update([
            'ai_service_id' => $validated['ai_service_id'],
            'name' => $validated['name'],
            'description' => $validated['description'],
            'instructions' => $validated['instructions'],
            'knowledge_base' => $validated['knowledge_base'],
            'is_active' => $request->has('is_active'),
            'is_public' => $request->has('is_public'),
            'parameters' => [
                'temperature' => $request->input('temperature', 0.7),
                'max_tokens' => $request->input('max_tokens', 1000),
            ],
        ]);
        
        return redirect()->route('custom_agents.show', $customAgent)
            ->with('success', 'Agente personalizado atualizado com sucesso!');
    }

    /**
     * Remove um agente personalizado específico.
     */
    public function destroy(CustomAgent $customAgent)
    {
        $user = Auth::user();
        
        // Verificar se o usuário é o proprietário do agente
        if ($customAgent->user_id !== $user->id) {
            abort(403, 'Você não tem permissão para excluir este agente personalizado.');
        }
        
        $customAgent->delete();
        
        return redirect()->route('custom_agents.index')
            ->with('success', 'Agente personalizado excluído com sucesso!');
    }
    
    /**
     * Exibe o formulário para interagir com um agente personalizado.
     */
    public function interact(CustomAgent $customAgent)
    {
        $user = Auth::user();
        
        // Verificar se o usuário tem permissão para usar este agente
        if (!$customAgent->canBeUsedBy($user)) {
            abort(403, 'Você não tem permissão para usar este agente personalizado.');
        }
        
        // Verificar se o usuário pode usar o serviço de IA associado
        if (!$this->canUseService($user, $customAgent->aiService)) {
            return redirect()->route('subscription_plans.index')
                ->with('error', 'Seu plano atual não permite acesso ao serviço de IA deste agente. Atualize seu plano de assinatura.');
        }
        
        // Verificar se o usuário atingiu o limite de requisições
        if (!$this->checkRequestLimit($user)) {
            return redirect()->back()
                ->with('error', 'Você atingiu o limite de requisições para seu plano atual.');
        }
        
        // Carregar relacionamentos
        $customAgent->load(['aiService', 'user']);
        
        return view('custom_agents.interact', compact('customAgent'));
    }
    
    /**
     * Processa a interação com um agente personalizado.
     */
    public function processInteraction(Request $request, CustomAgent $customAgent)
    {
        $user = Auth::user();
        
        // Verificar se o usuário tem permissão para usar este agente
        if (!$customAgent->canBeUsedBy($user)) {
            abort(403, 'Você não tem permissão para usar este agente personalizado.');
        }
        
        // Verificar se o usuário pode usar o serviço de IA associado
        if (!$this->canUseService($user, $customAgent->aiService)) {
            return redirect()->route('subscription_plans.index')
                ->with('error', 'Seu plano atual não permite acesso ao serviço de IA deste agente. Atualize seu plano de assinatura.');
        }
        
        // Verificar se o usuário atingiu o limite de requisições
        if (!$this->checkRequestLimit($user)) {
            return redirect()->back()
                ->with('error', 'Você atingiu o limite de requisições para seu plano atual.');
        }
        
        $validated = $request->validate([
            'prompt' => 'required|string|min:5|max:1000',
        ]);
        
        // Preparar o prompt completo com as instruções e conhecimento base do agente
        $fullPrompt = $customAgent->preparePrompt($validated['prompt']);
        
        // Criar uma instância do AIController para processar a requisição
        $aiController = app(AIController::class);
        
        try {
            // Processar a requisição para a API de IA
            $response = $aiController->processCustomAgentRequest($customAgent, $fullPrompt, $user, $validated['prompt']);
            
            // Incrementar o contador de uso do agente
            $customAgent->incrementUsageCount();
            
            return redirect()->route('ai.show', $response['interaction'])
                ->with('success', 'Requisição processada com sucesso!');
            
        } catch (\Exception $e) {
            return redirect()->back()
                ->with('error', 'Ocorreu um erro ao processar sua requisição: ' . $e->getMessage());
        }
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
}
