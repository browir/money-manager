# Rencana: Pengurai Lokal LAX (cadangan saat kuota Gemini habis)

Status: Tahap 1 dikerjakan, Tahap 2–5 belum · disusun 1 Nov 2026

Tujuan: selama kuota ada, Gemini yang menjawab sambil data latih terkumpul. Saat semua model Gemini gagal, LAX tetap bisa mencatat transaksi lewat mesin lokal yang belajar dari data itu.

Keputusan: **bukan LLM dari nol**. Dengan 5–10 perintah per hari, setahun hanya ada sekitar 1.800–3.600 contoh, terlalu sedikit untuk melatih LLM, dan Vercel tidak punya GPU serta membatasi proses 60 detik. Tugas utama LAX sempit (ucapan → nominal, jenis, akun, kategori, tanggal), jadi pengurai yang belajar dari data sudah cukup. LLM kecil baru dipertimbangkan di Tahap 5, berdasarkan angka akurasi.

Prinsip yang dipertahankan:

- **Usul → tinjau → jalankan.** Mesin lokal hanya mengusulkan, dan server memvalidasi ulang seperti biasa.
- **Tidak boleh mengarang nominal.** Kalau ragu, kolomnya dikosongkan atau LAX bertanya.
- **Pencatatan tidak boleh mengganggu asisten.** Kegagalan menyimpan contoh tidak boleh menggagalkan urai atau simpan, termasuk bila kode terdeploy sebelum migrasi.

---

## Alur

```
Ucapan → Gemini (semua model) → berhasil → usulan → tinjau → simpan
            │                                                  │
            │                                    contoh latih (usulan + hasil akhir)
            └ semua gagal → mesin lokal → usulan → tinjau → simpan
```

## Kemampuan mesin lokal

| Kemampuan | Cara kerja |
|---|---|
| Nominal (`25rb`, `1,5 jt`, `goceng`) | aturan tetap, dari daftar di prompt `VoiceAssistant::SYSTEM` |
| Jenis dan tanggal (`beli`, `gaji`, `kemarin`) | aturan tetap ditambah kata yang dipelajari |
| Kategori (`bakso` → Makan) | dipelajari dari riwayat transaksi dan contoh latih |
| Akun (`BCA`, `dompet`) | dicocokkan dengan daftar akun |
| Tanya saldo atau total | dihitung langsung dari database |
| Kata penyemangat | kalimat tetap dari paket suara |
| Obrolan bebas atau saran | tidak bisa; LAX bilang sedang mode hemat |

---

## Tahap 1: Kumpulkan data (dikerjakan)

- Tabel `assistant_examples`: `text`, `model`, `default_account_id`, `proposal` (usulan AI setelah dibersihkan), `final` (yang akhirnya disimpan pengguna), `applied_at`.
- `POST /asisten` menyimpan contoh dan mengembalikan `example_id`; `POST /asisten/jalankan` menerimanya dan mencatat hasil akhir.
- `reply` AI tidak disimpan karena bisa memuat saldo, hanya penanda `answered`.
- Penyimpanan 730 hari; contoh lama dibuang saat contoh baru masuk, karena tidak ada cron di Vercel.
- Halaman Pemakaian AI menampilkan jumlah contoh dan tombol hapus semua.
- Butuh migrasi produksi. Kode tetap aman bila terdeploy lebih dulu, tetapi contoh baru tersimpan setelah migrasi berjalan.

Selisih `proposal` dan `final` adalah koreksi pengguna, yaitu label paling berharga untuk Tahap 2 dan 3.

## Tahap 2: Mesin lokal

- Kelas baru (usulan `App\Support\LocalParser`) dengan keluaran mentah sebentuk jawaban AI, supaya bisa lewat `sanitize()` yang sama.
- Nominal, jenis, tanggal, dan akun dengan aturan. Kategori dengan pengklasifikasi sederhana (Naive Bayes) atas `note` riwayat transaksi dan `final` contoh latih, jadi sudah berguna sejak hari pertama.
- Pertanyaan saldo dan total memakai templat dari data yang sama dengan konteks AI.
- Berjalan di PHP pada server, tanpa layanan tambahan.

## Tahap 3: Uji diam-diam

Selama Gemini aktif, mesin lokal ikut menjawab tanpa ditampilkan, lalu hasilnya dibandingkan dengan `final`. Tidak memakai token. Akurasi per kolom tampil di halaman Pemakaian AI.

## Tahap 4: Aktifkan cadangan

`VoiceAssistant::ask()` memakai mesin lokal saat semua model Gemini gagal, kecuali kunci API salah. Layar menampilkan penanda "mode hemat". Syarat aktif: akurasi nominal Tahap 3 cukup tinggi; ambang persisnya ditetapkan setelah ada angkanya.

## Tahap 5: Evaluasi LLM kecil (opsional)

Hanya bila contoh sudah ribuan dan akurasi mesin lokal kurang. Opsi: melatih model kecil dengan data ini lalu menjalankannya di HP. Unduhannya bisa ratusan MB dan jawabannya mungkin lambat, jadi harus diuji di HP pemilik dulu.

---

## Catatan privasi

- Ucapan disimpan di database pemilik. Isinya bisa lebih luas dari transaksi, karena semua ucapan yang berhasil diurai ikut tersimpan, termasuk yang tidak pernah dijalankan.
- Pemilik bisa menghapus semua contoh dari halaman Pemakaian AI.
- Di tier gratis, Gemini boleh memakai data yang dikirim untuk keperluan Google. Ini sudah berlaku sebelum rencana ini dan tidak berubah karenanya.
