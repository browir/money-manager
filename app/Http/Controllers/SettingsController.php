<?php

namespace App\Http\Controllers;

use App\Support\Period;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;

class SettingsController extends Controller
{
    public function edit()
    {
        return Inertia::render('settings/Index');
    }

    public function updateProfile(Request $request)
    {
        $user = $request->user();

        $user->update($request->validate([
            'name' => ['required', 'string', 'max:60'],
            'email' => ['required', 'email', 'max:120', Rule::unique('users')->ignore($user->id)],
        ]));

        Inertia::flash('toast', ['message' => 'Profil diperbarui']);

        return back();
    }

    /** Tanggal awal periode bulanan (tanggal gajian). */
    public function updatePeriod(Request $request)
    {
        $data = $request->validate([
            'period_start' => ['required', 'integer', 'min:1', 'max:'.Period::MAX_START_DAY],
        ], [
            'period_start.max' => 'Pilih tanggal 1 sampai '.Period::MAX_START_DAY.'.',
        ]);

        $request->user()->update($data);

        Inertia::flash('toast', ['message' => 'Awal periode disimpan']);

        return back();
    }

    public function updatePassword(Request $request)
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ], [
            'current_password.current_password' => 'Kata sandi saat ini salah.',
            'password.confirmed' => 'Konfirmasi kata sandi tidak cocok.',
            'password.min' => 'Minimal 8 karakter.',
        ]);

        $request->user()->update(['password' => $data['password']]);

        Inertia::flash('toast', ['message' => 'Kata sandi diganti']);

        return back();
    }
}
