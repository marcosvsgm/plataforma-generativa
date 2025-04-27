<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AIInteraction;
use App\Models\AIService;
use App\Models\CustomAgent;
use App\Models\Payment;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminController extends Controller
{
    /**
     * Construtor do controlador.
     */
    public function __construct()
    {
        $this->middleware(['auth', 'admin']);
    }

    /**
     * Exibe o dashboard principal do administrador.
     */
    public function dashboard()
    {
        // Estatísticas gerais
        $stats = [
            'total_users' => User::count(),
            'active_subscriptions' => User::whereHas('subscriptions', function($query) {
                $query->where('is_active', true);
            })->count(),
            'total_interactions' => AIInteraction::count(),
            'total_custom_agents' => CustomAgent::count(),
            'total_revenue' => Payment::where('status', 'approved')->sum('amount'),
        ];
        
        // Usuários recentes
        $recentUsers = User::orderBy('created_at', 'desc')
            ->limit(5)
            ->get();
        
        // Pagamentos recentes
        $recentPayments = Payment::with('user')
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get();
        
        // Interações recentes
        $recentInteractions = AIInteraction::with(['user', 'aiService', 'customAgent'])
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get();
        
        // Gráfico de crescimento de usuários (últimos 30 dias)
        $userGrowth = DB::table('users')
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('count(*) as count'))
            ->where('created_at', '>=', now()->subDays(30))
            ->groupBy('date')
            ->orderBy('date')
            ->get();
        
        // Gráfico de receita (últimos 30 dias)
        $revenueData = DB::table('payments')
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('sum(amount) as total'))
            ->where('created_at', '>=', now()->subDays(30))
            ->where('status', 'approved')
            ->groupBy('date')
            ->orderBy('date')
            ->get();
        
        return view('admin.dashboard', compact(
            'stats', 
            'recentUsers', 
            'recentPayments', 
            'recentInteractions',
            'userGrowth',
            'revenueData'
        ));
    }
    
    /**
     * Exibe a lista de usuários.
     */
    public function users()
    {
        $users = User::withCount(['aiInteractions', 'customAgents', 'payments'])
            ->orderBy('created_at', 'desc')
            ->paginate(15);
        
        return view('admin.users.index', compact('users'));
    }
    
    /**
     * Exibe os detalhes de um usuário específico.
     */
    public function showUser(User $user)
    {
        $user->load(['subscriptions.subscriptionPlan', 'aiInteractions', 'customAgents', 'payments']);
        
        $stats = [
            'total_interactions' => $user->aiInteractions->count(),
            'total_tokens' => $user->aiInteractions->sum('tokens_used'),
            'total_agents' => $user->customAgents->count(),
            'total_payments' => $user->payments->count(),
            'total_spent' => $user->payments->where('status', 'approved')->sum('amount'),
        ];
        
        return view('admin.users.show', compact('user', 'stats'));
    }
    
    /**
     * Exibe a lista de planos de assinatura.
     */
    public function subscriptionPlans()
    {
        $plans = SubscriptionPlan::withCount(['activeSubscriptions'])
            ->orderBy('price')
            ->get();
        
        return view('admin.subscription_plans.index', compact('plans'));
    }
    
    /**
     * Exibe o formulário para criar um novo plano de assinatura.
     */
    public function createSubscriptionPlan()
    {
        return view('admin.subscription_plans.create');
    }
    
    /**
     * Armazena um novo plano de assinatura.
     */
    public function storeSubscriptionPlan(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'required|string',
            'price' => 'required|numeric|min:0',
            'billing_period' => 'required|string|in:monthly,yearly',
            'ai_requests_limit' => 'required|integer|min:-1',
            'custom_agents_limit' => 'required|integer|min:-1',
            'can_use_chatgpt' => 'boolean',
            'can_use_gemini' => 'boolean',
            'can_use_deepseek' => 'boolean',
            'is_active' => 'boolean',
        ]);
        
        $plan = SubscriptionPlan::create($validated);
        
        return redirect()->route('admin.subscription_plans.index')
            ->with('status', 'Plano de assinatura criado com sucesso!');
    }
    
    /**
     * Exibe o formulário para editar um plano de assinatura.
     */
    public function editSubscriptionPlan(SubscriptionPlan $subscriptionPlan)
    {
        return view('admin.subscription_plans.edit', compact('subscriptionPlan'));
    }
    
    /**
     * Atualiza um plano de assinatura.
     */
    public function updateSubscriptionPlan(Request $request, SubscriptionPlan $subscriptionPlan)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'required|string',
            'price' => 'required|numeric|min:0',
            'billing_period' => 'required|string|in:monthly,yearly',
            'ai_requests_limit' => 'required|integer|min:-1',
            'custom_agents_limit' => 'required|integer|min:-1',
            'can_use_chatgpt' => 'boolean',
            'can_use_gemini' => 'boolean',
            'can_use_deepseek' => 'boolean',
            'is_active' => 'boolean',
        ]);
        
        $subscriptionPlan->update($validated);
        
        return redirect()->route('admin.subscription_plans.index')
            ->with('status', 'Plano de assinatura atualizado com sucesso!');
    }
    
    /**
     * Exibe a lista de pagamentos.
     */
    public function payments()
    {
        $payments = Payment::with('user')
            ->orderBy('created_at', 'desc')
            ->paginate(15);
        
        return view('admin.payments.index', compact('payments'));
    }
    
    /**
     * Exibe os detalhes de um pagamento específico.
     */
    public function showPayment(Payment $payment)
    {
        $payment->load('user');
        
        return view('admin.payments.show', compact('payment'));
    }
    
    /**
     * Exibe a lista de serviços de IA.
     */
    public function aiServices()
    {
        $services = AIService::withCount(['interactions'])
            ->get();
        
        return view('admin.ai_services.index', compact('services'));
    }
    
    /**
     * Exibe o formulário para criar um novo serviço de IA.
     */
    public function createAIService()
    {
        return view('admin.ai_services.create');
    }
    
    /**
     * Armazena um novo serviço de IA.
     */
    public function storeAIService(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'required|string',
            'api_key_env' => 'required|string|max:255',
            'base_url' => 'required|string|max:255',
            'model_name' => 'required|string|max:255',
            'is_active' => 'boolean',
            'token_cost_per_1k' => 'required|numeric|min:0',
        ]);
        
        $service = AIService::create($validated);
        
        return redirect()->route('admin.ai_services.index')
            ->with('status', 'Serviço de IA criado com sucesso!');
    }
    
    /**
     * Exibe o formulário para editar um serviço de IA.
     */
    public function editAIService(AIService $aiService)
    {
        return view('admin.ai_services.edit', compact('aiService'));
    }
    
    /**
     * Atualiza um serviço de IA.
     */
    public function updateAIService(Request $request, AIService $aiService)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'required|string',
            'api_key_env' => 'required|string|max:255',
            'base_url' => 'required|string|max:255',
            'model_name' => 'required|string|max:255',
            'is_active' => 'boolean',
            'token_cost_per_1k' => 'required|numeric|min:0',
        ]);
        
        $aiService->update($validated);
        
        return redirect()->route('admin.ai_services.index')
            ->with('status', 'Serviço de IA atualizado com sucesso!');
    }
    
    /**
     * Exibe a lista de interações com IA.
     */
    public function aiInteractions()
    {
        $interactions = AIInteraction::with(['user', 'aiService', 'customAgent'])
            ->orderBy('created_at', 'desc')
            ->paginate(15);
        
        return view('admin.ai_interactions.index', compact('interactions'));
    }
    
    /**
     * Exibe os detalhes de uma interação específica.
     */
    public function showAIInteraction(AIInteraction $aiInteraction)
    {
        $aiInteraction->load(['user', 'aiService', 'customAgent']);
        
        return view('admin.ai_interactions.show', compact('aiInteraction'));
    }
    
    /**
     * Exibe a lista de agentes personalizados.
     */
    public function customAgents()
    {
        $agents = CustomAgent::with(['user', 'aiService'])
            ->withCount(['interactions'])
            ->orderBy('created_at', 'desc')
            ->paginate(15);
        
        return view('admin.custom_agents.index', compact('agents'));
    }
    
    /**
     * Exibe os detalhes de um agente personalizado específico.
     */
    public function showCustomAgent(CustomAgent $customAgent)
    {
        $customAgent->load(['user', 'aiService', 'interactions']);
        
        return view('admin.custom_agents.show', compact('customAgent'));
    }
}
