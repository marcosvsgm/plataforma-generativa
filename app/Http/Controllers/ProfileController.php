<?php

namespace App\Http\Controllers;

use App\Models\UserProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class ProfileController extends Controller
{
    /**
     * Construtor do controlador.
     */
    public function __construct()
    {
        $this->middleware(['auth', 'verified']);
    }

    /**
     * Exibe o formulário de perfil do usuário.
     */
    public function show()
    {
        $user = Auth::user();
        $profile = $user->profile ?? new UserProfile();
        
        return view('profile.show', compact('user', 'profile'));
    }

    /**
     * Atualiza o perfil do usuário.
     */
    public function update(Request $request)
    {
        $user = Auth::user();
        
        $validated = $request->validate([
            'full_name' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'country' => 'nullable|string|max:100',
            'postal_code' => 'nullable|string|max:20',
            'company' => 'nullable|string|max:255',
            'job_title' => 'nullable|string|max:255',
            'bio' => 'nullable|string|max:1000',
            'avatar' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
        ]);
        
        // Atualiza o nome do usuário
        $user->name = $request->input('full_name');
        $user->save();
        
        // Processa o upload do avatar, se fornecido
        if ($request->hasFile('avatar')) {
            $avatarPath = $request->file('avatar')->store('avatars', 'public');
            $validated['avatar'] = $avatarPath;
        }
        
        // Atualiza ou cria o perfil
        $profile = $user->profile ?? new UserProfile();
        $profile->fill($validated);
        
        if (!$user->profile) {
            $profile->user_id = $user->id;
            $profile->save();
        } else {
            $user->profile->update($validated);
        }
        
        return redirect()->route('profile.show')->with('success', 'Perfil atualizado com sucesso!');
    }
}
