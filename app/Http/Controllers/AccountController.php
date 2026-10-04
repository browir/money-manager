<?php

namespace App\Http\Controllers;

use App\Models\Account;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class AccountController extends Controller
{
    public function index()
    {
        return Inertia::render('accounts/Index');
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['sort'] = $request->user()->accounts()->max('sort') + 1;
        $request->user()->accounts()->create($data);

        Inertia::flash('toast', ['message' => 'Akun ditambahkan']);

        return back();
    }

    public function update(Request $request, Account $account)
    {
        $this->ensureOwned($account);
        $data = $this->validated($request);
        $data['archived_at'] = $request->boolean('archived') ? ($account->archived_at ?? now()) : null;
        $account->update($data);

        Inertia::flash('toast', ['message' => 'Akun diperbarui']);

        return back();
    }

    public function destroy(Account $account)
    {
        $this->ensureOwned($account);

        $used = $account->transactions()->withTrashed()->exists()
            || $account->user->transactions()->withTrashed()->where('to_account_id', $account->id)->exists();

        if ($used) {
            Inertia::flash('toast', ['message' => 'Akun punya riwayat transaksi, arsipkan saja', 'tone' => 'warn']);

            return back();
        }

        $account->delete();
        Inertia::flash('toast', ['message' => 'Akun dihapus']);

        return back();
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'type' => ['required', Rule::in(Account::TYPES)],
            'color' => ['required', 'string', 'max:16'],
            'initial_balance' => ['required', 'integer', 'between:-999999999999,999999999999'],
        ], [
            'name.required' => 'Nama akun wajib diisi.',
        ]);
    }
}
