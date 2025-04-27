<?php

namespace App\Http\Controllers;

use App\Models\AIInteraction;
use App\Models\CustomAgent;
use App\Models\Payment;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DashboardController extends Controller
{
    /**
     * Construtor do controlador.
     */
    public function __construct()
    {
        $this->middleware(['auth', 'verified']);
    }

    /**
     * Exibe o dashboard principal do usuário.
     */
    public function index()
    {
        $user = Auth::user();
        
        // Obter estatísticas do usuário
        $stats = $this->getUserStats($user);
        
        // Obter interações recentes
        $recentInteractions = $user->aiInteractions()
            ->with(['aiService', 'customAgent'])
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get();
        
        // Obter agentes personalizados recentes
        $recentAgents = $user->customAgents()
            ->with('aiService')
            ->orderBy('created_at', 'desc')
            ->limit(3)
            ->get();
        
        // Obter pagamentos recentes
        $recentPayments = $user->payments()
            ->orderBy('created_at', 'desc')
            ->limit(3)
            ->get();
        
        // Obter assinatura ativa
        $activeSubscription = $user->getActiveSubscription();
        $subscriptionPlan = $activeSubscription ? $activeSubscription->subscriptionPlan : null;
        
        // Verificar uso de recursos
        $resourceUsage = $this->getResourceUsage($user, $subscriptionPlan);
        
        return view('dashboard.index', compact(
            'stats', 
            'recentInteractions', 
            'recentAgents', 
            'recentPayments', 
            'activeSubscription', 
            'subscriptionPlan',
            'resourceUsage'
        ));
    }
    
    /**
     * Exibe o histórico de interações com IA do usuário.
     */
    public function interactions()
    {
        $user = Auth::user();
        
        $interactions = $user->aiInteractions()
            ->with(['aiService', 'customAgent'])
            ->orderBy('created_at', 'desc')
            ->paginate(10);
        
        return view('dashboard.interactions', compact('interactions'));
    }
    
    /**
     * Exibe o histórico de pagamentos do usuário.
     */
    public function payments()
    {
        $user = Auth::user();
        
        $payments = $user->payments()
            ->orderBy('created_at', 'desc')
            ->paginate(10);
        
        return view('dashboard.payments', compact('payments'));
    }
    
    /**
     * Exibe as informações de assinatura do usuário.
     */
    public function subscription()
    {
        $user = Auth::user();
        
        $activeSubscription = $user->getActiveSubscription();
        $subscriptionPlan = $activeSubscription ? $activeSubscription->subscriptionPlan : null;
        
        $availablePlans = SubscriptionPlan::where('is_active', true)
            ->orderBy('price', 'asc')
            ->get();
        
        $paymentHistory = $user->payments()
            ->where('type', 'subscription')
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get();
        
        return view('dashboard.subscription', compact(
            'activeSubscription', 
            'subscriptionPlan', 
            'availablePlans', 
            'paymentHistory'
        ));
    }
    
    /**
     * Obtém estatísticas do usuário.
     */
    private function getUserStats(User $user): array
    {
        // Total de interações com IA
        $totalInteractions = $user->aiInteractions()->count();
        
        // Total de tokens usados
        $totalTokens = $user->aiInteractions()->sum('tokens_used');
        
        // Total de agentes personalizados
        $totalAgents = $user->customAgents()->count();
        
        // Total de pagamentos
        $totalPayments = $user->payments()->count();
        
        // Interações do mês atual
        $currentMonthInteractions = $user->aiInteractions()
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->count();
        
        // Tokens usados no mês atual
        $currentMonthTokens = $user->aiInteractions()
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->sum('tokens_used');
        
        return [
            'total_interactions' => $totalInteractions,
            'total_tokens' => $totalTokens,
            'total_agents' => $totalAgents,
            'total_payments' => $totalPayments,
            'current_month_interactions' => $currentMonthInteractions,
            'current_month_tokens' => $currentMonthTokens,
        ];
    }
    
    /**
     * Obtém informações de uso de recursos em relação aos limites do plano.
     */
    private function getResourceUsage(User $user, ?SubscriptionPlan $plan): array
    {
        if (!$plan) {
            return [
                'ai_requests' => [
                    'used' => 0,
                    'limit' => 0,
                    'percentage' => 0,
                ],
                'custom_agents' => [
                    'used' => 0,
                    'limit' => 0,
                    'percentage' => 0,
                ],
            ];
        }
        
        // Uso de requisições de IA
        $currentMonthRequests = $user->aiInteractions()
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->count();
        
        $requestsLimit = $plan->ai_requests_limit;
        $requestsPercentage = $requestsLimit > 0 
            ? min(100, round(($currentMonthRequests / $requestsLimit) * 100)) 
            : 0;
        
        // Uso de agentes personalizados
        $currentAgentsCount = $user->customAgents()->count();
        $agentsLimit = $plan->custom_agents_limit;
        $agentsPercentage = $agentsLimit > 0 
            ? min(100, round(($currentAgentsCount / $agentsLimit) * 100)) 
            : 0;
        
        return [
            'ai_requests' => [
                'used' => $currentMonthRequests,
                'limit' => $requestsLimit,
                'percentage' => $requestsPercentage,
                'unlimited' => $requestsLimit <= 0,
            ],
            'custom_agents' => [
                'used' => $currentAgentsCount,
                'limit' => $agentsLimit,
                'percentage' => $agentsPercentage,
                'unlimited' => $agentsLimit <= 0,
            ],
        ];
    }
}
