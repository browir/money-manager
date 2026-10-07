<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ValidatesTransactionFields;
use App\Models\Recurring;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class RecurringController extends Controller
{
    use ValidatesTransactionFields;

    public function index(Request $request)
    {
        $user = $request->user();

        // ?dari=<id transaksi>: "Jadikan berulang" dari form transaksi membuka form terisi.
        $prefill = $request->integer('dari')
            ? $user->transactions()->find($request->integer('dari'))?->toListItem()
            : null;

        return Inertia::render('recurring/Index', [
            'recurrings' => $user->recurrings()->orderBy('next_due')->orderBy('id')->get()->map->toListItem(),
            'prefill' => $prefill,
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $request->user()->recurrings()->create([...$data, 'start_on' => $data['next_due']]);

        Inertia::flash('toast', ['message' => 'Jadwal berulang dibuat']);

        return back();
    }

    public function update(Request $request, Recurring $recurring)
    {
        $this->ensureOwned($recurring);
        $data = $this->validated($request);

        // Tanggal diubah = jangkar baru (mis. gajian pindah ke tgl 28).
        if ($data['next_due'] !== $recurring->next_due->toDateString()) {
            $data['start_on'] = $data['next_due'];
        }
        $recurring->update($data);

        Inertia::flash('toast', ['message' => 'Jadwal diperbarui']);

        return back();
    }

    public function destroy(Recurring $recurring)
    {
        $this->ensureOwned($recurring);
        $recurring->delete();

        Inertia::flash('toast', ['message' => 'Jadwal dihapus']);

        return back();
    }

    /** Catat periode yang jatuh tempo apa adanya, lalu majukan jadwal. */
    public function record(Request $request, Recurring $recurring)
    {
        $this->ensureOwned($recurring);
        $transaction = $request->user()->transactions()->create($recurring->transactionAttributes());
        $recurring->advance();

        Inertia::flash('saved', $transaction->id);
        Inertia::flash('toast', ['message' => 'Tercatat · '.($recurring->note ?: 'Transaksi berulang')]);

        return back();
    }

    /** Lewati periode ini (mis. langganan sedang libur) tanpa mencatat. */
    public function skip(Recurring $recurring)
    {
        $this->ensureOwned($recurring);
        $recurring->advance();

        Inertia::flash('toast', ['message' => 'Dilewati sampai '.$recurring->next_due->locale('id')->translatedFormat('j M')]);

        return back();
    }

    private function validated(Request $request): array
    {
        return $this->validateTransactionFields($request, [
            'frequency' => ['required', Rule::in(Recurring::FREQUENCIES)],
            'next_due' => ['required', 'date_format:Y-m-d'],
        ]);
    }
}
