<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class CategoryController extends Controller
{
    public function index()
    {
        return Inertia::render('categories/Index');
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['sort'] = $request->user()->categories()->where('type', $data['type'])->max('sort') + 1;
        $request->user()->categories()->create($data);

        Inertia::flash('toast', ['message' => 'Kategori ditambahkan']);

        return back();
    }

    public function update(Request $request, Category $category)
    {
        $this->ensureOwned($category);
        $data = $this->validated($request);

        // Tipe tidak diubah agar transaksi lama tetap konsisten.
        unset($data['type']);
        $category->update($data);

        Inertia::flash('toast', ['message' => 'Kategori diperbarui']);

        return back();
    }

    public function destroy(Category $category)
    {
        $this->ensureOwned($category);
        $category->delete();

        Inertia::flash('toast', ['message' => 'Kategori dihapus']);

        return back();
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:40'],
            'type' => ['required', Rule::in(Category::TYPES)],
            'icon' => ['required', 'string', 'max:32'],
            'color' => ['required', 'string', 'max:16'],
            'budget' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
        ], [
            'name.required' => 'Nama kategori wajib diisi.',
            'budget.min' => 'Anggaran tidak boleh negatif.',
        ]);

        // Anggaran hanya untuk pengeluaran; 0 dianggap "tanpa anggaran".
        if (($data['type'] ?? null) === 'income' || empty($data['budget'])) {
            $data['budget'] = null;
        }

        return $data;
    }
}
