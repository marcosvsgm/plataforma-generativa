<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PaymentPolicy
{
    /**
     * Determine if the given payment can be viewed by the user.
     */
    public function view(User $user, Payment $payment)
    {
        return $user->id === $payment->user_id || $user->hasRole('admin');
    }
}
