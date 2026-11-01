/**
 * Tombol / gestur kembali (Android, Safari, browser) menutup sheet yang terbuka,
 * bukan meninggalkan halaman — perilaku yang diharapkan di aplikasi HP.
 *
 * Selama ada sheet terbuka, satu entri riwayat "penanda" ditaruh di atas halaman.
 * Kembali = entri penanda dibuang, sheet teratas ditutup. Sheet ditutup lewat tombol
 * = penanda dibuang sendiri lewat history.back().
 *
 * Pendengar popstate didaftarkan saat modul dimuat (sebelum Inertia) dengan capture,
 * sehingga Inertia tidak ikut "memulihkan" halaman dari entri lama.
 */
const stack = []; // fungsi penutup; terakhir = sheet teratas
let marked = false; // entri penanda sedang berada di atas
let leaving = false; // history.back() dari kita sendiri sedang berjalan
let saved = null; // state riwayat terbaru (Inertia bisa memperbaruinya selama sheet terbuka)

if (typeof window !== 'undefined') {
    window.addEventListener(
        'popstate',
        (e) => {
            if (leaving) {
                leaving = false;
                e.stopImmediatePropagation();
                if (saved) history.replaceState(saved, '');
                saved = null;
                sync();
                return;
            }
            if (!marked) return;
            marked = false;
            e.stopImmediatePropagation();
            stack.at(-1)?.();
            sync();
        },
        true,
    );
}

// Ditunda sedetik-nol: berpindah dari satu sheet ke sheet lain (tutup + buka di tick
// yang sama) tidak perlu membuang lalu memasang penanda lagi.
let pending = false;
function sync() {
    if (pending) return;
    pending = true;
    setTimeout(() => {
        pending = false;
        if (leaving) return;
        if (stack.length && !marked) {
            history.pushState({ ...history.state, sheet: true }, '');
            marked = true;
        } else if (!stack.length && marked) {
            marked = false;
            leaving = true;
            const { sheet, ...rest } = history.state ?? {};
            saved = rest;
            history.back();
        }
    }, 0);
}

/** Daftarkan sheet yang baru terbuka. Kembalikan fungsi untuk melepasnya saat ditutup. */
export function guardBack(close) {
    stack.push(close);
    sync();
    return () => {
        const i = stack.indexOf(close);
        if (i === -1) return;
        stack.splice(i, 1);
        sync();
    };
}
