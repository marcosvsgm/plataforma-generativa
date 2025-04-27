<?php

namespace App\Http\Controllers;

use App\Models\SubscriptionPlan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SubscriptionPlanController extends Controller
{
    /**
     * Construtor do controlador.
     */
    public function __construct()
    {
        $this->middleware(['auth', 'verified'])->except(['index', 'show']);
        $this->middleware(['auth:admin'])->only(['create', 'store', 'edit', 'update', 'destroy']);
    }

    /**
     * Exibe uma lista de planos de assinatura.
     */
    public function index()
    {
        $plans = SubscriptionPlan::where('is_active', true)->orderBy('price')->get();
        
        return view('subscription_plans.index', compact('plans'));
    }

    /**
     * Mostra o formulário para criar um novo plano de assinatura.
     */
    public function create()
    {
        return view('subscription_plans.create');
    }

    /**
     * Armazena um novo plano de assinatura.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'required|string',
            'price' => 'required|numeric|min:0',
            'billing_period' => 'required|string|in:monthly,quarterly,yearly',
            'ai_requests_limit' => 'required|integer|min:0',
            'custom_agents_limit' => 'required|integer|min:1',
            'can_use_chatgpt' => 'boolean',
            'can_use_gemini' => 'boolean',
            'can_use_deepseek' => 'boolean',
            'is_active' => 'boolean',
            'stripe_plan_id' => 'nullable|string|max:255',
            'mercadopago_plan_id' => 'nullable|string|max:255',
        ]);
        
        SubscriptionPlan::create($validated);
        
        return redirect()->route('subscription_plans.index')
            ->with('success', 'Plano de assinatura criado com sucesso!');
    }

    /**
     * Exibe um plano de assinatura específico.
     */
    public function show(SubscriptionPlan $subscriptionPlan)
    {
        return view('subscription_plans.show', compact('subscriptionPlan'));
    }

    /**
     * Mostra o formulário para editar um plano de assinatura.
     */
    public function edit(SubscriptionPlan $subscriptionPlan)
    {
        return view('subscription_plans.edit', compact('subscriptionPlan'));
    }

    /**
     * Atualiza um plano de assinatura específico.
     */
    public function update(Request $request, SubscriptionPlan $subscriptionPlan)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'required|string',
            'price' => 'required|numeric|min:0',
            'billing_period' => 'required|string|in:monthly,quarterly,yearly',
            'ai_requests_limit' => 'required|integer|min:0',
            'custom_agents_limit' => 'required|integer|min:1',
            'can_use_chatgpt' => 'boolean',
            'can_use_gemini' => 'boolean',
            'can_use_deepseek' => 'boolean',
            'is_active' => 'boolean',
            'stripe_plan_id' => 'nullable|string|max:255',
            'mercadopago_plan_id' => 'nullable|string|max:255',
        ]);
        
        $subscriptionPlan->update($validated);
        
        return redirect()->route('subscription_plans.index')
            ->with('success', 'Plano de assinatura atualizado com sucesso!');
    }

    /**
     * Remove um plano de assinatura específico.
     */
    public function destroy(SubscriptionPlan $subscriptionPlan)
    {
        // Verificar se há usuários usando este plano antes de excluir
        if ($subscriptionPlan->payments()->count() > 0) {
            return redirect()->route('subscription_plans.index')
                ->with('error', 'Não é possível excluir um plano que está sendo utilizado por usuários.');
        }
        
        $subscriptionPlan->delete();
        
        return redirect()->route('subscription_plans.index')
            ->with('success', 'Plano de assinatura excluído com sucesso!');
    }
    
    /**
     * Permite que um usuário assine um plano.
     */
    public function subscribe(SubscriptionPlan $subscriptionPlan)
    {
        $user = Auth::user();
        
        // Redirecionar para a página de pagamento
        return redirect()->route('payments.create', ['plan' => $subscriptionPlan->id]);
    }
}
