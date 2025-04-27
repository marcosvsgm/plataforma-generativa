<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use MercadoPago\Client\Preference\PreferenceClient;
use MercadoPago\MercadoPagoConfig;
use MercadoPago\Exceptions\MPApiException;

class PaymentController extends Controller
{
    /**
     * Construtor do controlador.
     */
    public function __construct()
    {
        $this->middleware(['auth', 'verified']);
    }

    /**
     * Exibe uma lista de pagamentos do usuário.
     */
    public function index()
    {
        $user = Auth::user();
        $payments = $user->payments()->with('subscriptionPlan')->orderBy('created_at', 'desc')->paginate(10);
        
        return view('payments.index', compact('payments'));
    }

    /**
     * Mostra o formulário para criar um novo pagamento.
     */
    public function create(Request $request)
    {
        $plan = null;
        
        if ($request->has('plan')) {
            $plan = SubscriptionPlan::findOrFail($request->plan);
        }
        
        $plans = SubscriptionPlan::where('is_active', true)->orderBy('price')->get();
        
        return view('payments.create', compact('plan', 'plans'));
    }

    /**
     * Processa o pagamento e cria uma preferência no Mercado Pago.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'subscription_plan_id' => 'required|exists:subscription_plans,id',
        ]);
        
        $user = Auth::user();
        $plan = SubscriptionPlan::findOrFail($validated['subscription_plan_id']);
        
        // Configurar o Mercado Pago
        MercadoPagoConfig::setAccessToken(config('services.mercadopago.access_token'));
        
        try {
            // Criar cliente de preferência
            $client = new PreferenceClient();
            
            // Determinar datas de início e fim da assinatura
            $startDate = now();
            $endDate = $this->calculateEndDate($startDate, $plan->billing_period);
            
            // Criar um pagamento pendente
            $payment = Payment::create([
                'user_id' => $user->id,
                'subscription_plan_id' => $plan->id,
                'amount' => $plan->price,
                'payment_method' => 'mercadopago',
                'status' => 'pending',
                'subscription_starts_at' => $startDate,
                'subscription_ends_at' => $endDate,
            ]);
            
            // Criar preferência de pagamento
            $preferenceRequest = [
                'items' => [
                    [
                        'title' => 'Assinatura: ' . $plan->name,
                        'description' => $plan->description,
                        'quantity' => 1,
                        'currency_id' => 'BRL',
                        'unit_price' => (float) $plan->price,
                    ]
                ],
                'payer' => [
                    'name' => $user->name,
                    'email' => $user->email,
                ],
                'back_urls' => [
                    'success' => route('payments.success'),
                    'failure' => route('payments.failure'),
                    'pending' => route('payments.pending'),
                ],
                'auto_return' => 'approved',
                'notification_url' => route('payments.webhook'),
                'external_reference' => $payment->id,
                'statement_descriptor' => 'Plataforma IA Generativa',
            ];
            
            $preference = $client->create($preferenceRequest);
            
            // Atualizar o pagamento com o ID da preferência
            $payment->update([
                'payment_data' => [
                    'preference_id' => $preference->id,
                    'init_point' => $preference->init_point,
                ],
            ]);
            
            // Redirecionar para a página de pagamento do Mercado Pago
            return redirect($preference->init_point);
            
        } catch (MPApiException $e) {
            Log::error('Erro ao criar preferência no Mercado Pago: ' . $e->getMessage());
            return redirect()->back()->with('error', 'Ocorreu um erro ao processar o pagamento. Por favor, tente novamente.');
        }
    }

    /**
     * Exibe um pagamento específico.
     */
    public function show(Payment $payment)
    {
        $this->authorize('view', $payment);
        
        return view('payments.show', compact('payment'));
    }

    /**
     * Página de sucesso após o pagamento.
     */
    public function success(Request $request)
    {
        $paymentId = $request->get('external_reference');
        $payment = Payment::findOrFail($paymentId);
        
        // Atualizar o status do pagamento
        if ($payment->status === 'pending') {
            $payment->update([
                'status' => 'approved',
                'paid_at' => now(),
                'payment_id' => $request->get('payment_id'),
                'payment_data' => array_merge($payment->payment_data ?? [], [
                    'mercadopago_data' => $request->all(),
                ]),
            ]);
        }
        
        return view('payments.success', compact('payment'));
    }

    /**
     * Página de falha após o pagamento.
     */
    public function failure(Request $request)
    {
        $paymentId = $request->get('external_reference');
        $payment = Payment::findOrFail($paymentId);
        
        // Atualizar o status do pagamento
        if ($payment->status === 'pending') {
            $payment->update([
                'status' => 'rejected',
                'payment_data' => array_merge($payment->payment_data ?? [], [
                    'mercadopago_data' => $request->all(),
                ]),
            ]);
        }
        
        return view('payments.failure', compact('payment'));
    }

    /**
     * Página de pagamento pendente.
     */
    public function pending(Request $request)
    {
        $paymentId = $request->get('external_reference');
        $payment = Payment::findOrFail($paymentId);
        
        return view('payments.pending', compact('payment'));
    }

    /**
     * Webhook para receber notificações do Mercado Pago.
     */
    public function webhook(Request $request)
    {
        Log::info('Webhook do Mercado Pago recebido', $request->all());
        
        $type = $request->get('type');
        $data = $request->get('data');
        
        if ($type === 'payment') {
            // Configurar o Mercado Pago
            MercadoPagoConfig::setAccessToken(config('services.mercadopago.access_token'));
            
            try {
                // Obter informações do pagamento
                $client = new \MercadoPago\Client\Payment\PaymentClient();
                $mpPayment = $client->get($data['id']);
                
                // Encontrar o pagamento correspondente
                $payment = Payment::findOrFail($mpPayment->external_reference);
                
                // Atualizar o status do pagamento
                switch ($mpPayment->status) {
                    case 'approved':
                        $payment->update([
                            'status' => 'approved',
                            'paid_at' => Carbon::parse($mpPayment->date_approved),
                            'payment_id' => $mpPayment->id,
                            'payer_id' => $mpPayment->payer->id,
                            'payer_email' => $mpPayment->payer->email,
                            'payment_data' => array_merge($payment->payment_data ?? [], [
                                'mercadopago_payment' => json_decode(json_encode($mpPayment), true),
                            ]),
                        ]);
                        break;
                    
                    case 'rejected':
                        $payment->update([
                            'status' => 'rejected',
                            'payment_data' => array_merge($payment->payment_data ?? [], [
                                'mercadopago_payment' => json_decode(json_encode($mpPayment), true),
                            ]),
                        ]);
                        break;
                    
                    case 'pending':
                    case 'in_process':
                        // Manter como pendente
                        break;
                }
                
                return response()->json(['status' => 'success']);
                
            } catch (\Exception $e) {
                Log::error('Erro ao processar webhook do Mercado Pago: ' . $e->getMessage());
                return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
            }
        }
        
        return response()->json(['status' => 'ignored']);
    }

    /**
     * Calcula a data de término da assinatura com base no período de cobrança.
     */
    private function calculateEndDate(Carbon $startDate, string $billingPeriod): Carbon
    {
        return match ($billingPeriod) {
            'monthly' => $startDate->copy()->addMonth(),
            'quarterly' => $startDate->copy()->addMonths(3),
            'yearly' => $startDate->copy()->addYear(),
            default => $startDate->copy()->addMonth(),
        };
    }
}
