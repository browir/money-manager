# Rencana: Jurnal Harian di Sisih

Status: rencana, belum dikerjakan · disusun 1 Nov 2026

Tujuan: selain buku kas, Sisih bisa mencatat **kegiatan sehari-hari** (apa yang dilakukan, perasaan, catatan penting), dan LAX menjadi asisten untuk keduanya: mencatat lewat suara, merangkum hari, dan menjawab pertanyaan tentang kegiatan.

Prinsip yang dipertahankan dari fitur yang sudah ada:

- **Usul → tinjau → jalankan.** LAX tidak pernah menyimpan sendiri, semua divalidasi ulang di server.
- **HP dulu.** Semua layar dirancang untuk 390 px, mode gelap ikut.
- **Privasi.** Jurnal lebih pribadi daripada saldo, jadi kunci aplikasi dan pilihan penyedia AI ikut dipertimbangkan sejak awal.
- **Migrasi produksi manual** (Supabase), dan data jurnal **tidak** masuk props bersama, supaya halaman lain tidak rusak bila kode terdeploy sebelum migrasi.

---

## 1. Keputusan yang perlu diambil dulu

| # | Pertanyaan | Usulan |
|---|---|---|
| K1 | Bentuk jurnal: satu tulisan per hari, atau banyak catatan kecil per hari? | **Catatan kecil berjam** (timeline), plus ringkasan harian opsional. Paling cocok dengan input suara ("tadi pagi lari 5 km"). |
| K2 | Catat suasana hati (mood)? | Ya, opsional, 5 tingkat (😞 🙁 😐 🙂 😄) per catatan, dan rata-rata per hari. |
| K3 | Tag/jenis kegiatan? | Ya, tag bebas + saran bawaan (Kerja, Olahraga, Keluarga, Belajar, Ibadah, Hiburan, Kesehatan, Sosial). |
| K4 | Foto di jurnal? | Fase akhir (butuh Supabase Storage). |
| K5 | Navigasi bawah: di mana "Jurnal"? | **Ganti tab "Akun" dengan "Jurnal"**. Akun tetap bisa dibuka dari kartu akun di Beranda dan menu Lainnya. |
| K6 | Penyedia AI untuk jurnal | Jurnal sangat pribadi. Tier **gratis** Gemini boleh dipakai Google untuk pengembangan produk. Usul: tier berbayar Gemini atau Claude API untuk jurnal, atau sakelar "jangan kirim jurnal ke AI". |
| K7 | Kunci aplikasi (PIN/biometrik) | Kerjakan sebelum/bersamaan Fase 1 (backlog #9), karena jurnal tidak boleh terbuka bebas bila HP dipinjam. |

---

## 2. Model data

Hari jurnal mengikuti **tanggal kalender WITA**, bukan periode gajian. Periode tetap khusus keuangan.

### `journal_entries` (migrasi baru)

| Kolom | Tipe | Catatan |
|---|---|---|
| `id` | bigint | |
| `user_id` | FK users, cascade | |
| `occurred_at` | datetime | waktu kegiatan (WITA), bisa diubah |
| `entry_date` | date, index (`user_id`, `entry_date`) | untuk kelompok per hari & filter cepat |
| `body` | text | isi catatan |
| `mood` | tinyint null (1–5) | |
| `tags` | json (array string) | sederhana dulu; tabel tag terpisah bila perlu statistik berat |
| `transaction_id` | FK transactions null, nullOnDelete | tautan ke transaksi terkait ("makan siang bareng tim" ↔ pengeluaran 45 rb) |
| `source` | string(8) `manual` / `voice` | |
| `client_id` | uuid null, unique per user | untuk antrean offline (pola sama dengan transaksi) |
| timestamps + `deleted_at` | | soft delete + urungkan, seperti transaksi |

### `journal_days` (Fase 3)

`user_id`, `date` (unik per user), `summary` (text, ringkasan AI/manual), `mood` (rata-rata/manual), `highlights` (json), timestamps. Diisi saat pengguna meminta ringkasan, bukan otomatis setiap hari (hemat kuota).

---

## 3. LAX: dari "asisten keuangan" ke "asisten pribadi"

### 3.1 Refaktor dulu: kemampuan (skills)

Sekarang `VoiceAssistant.php` (≈650 baris) dan `VoiceSheet.vue` (≈700 baris) memuat semua logika keuangan. Sebelum jurnal masuk, pecah jadi modul:

```
app/Support/Lax/
  Assistant.php          // orkestrasi: konteks, panggil AI, gabung skema, sanitasi per skill
  Providers/Gemini.php, Claude.php, Tts.php
  Skills/Skill.php       // interface: promptSection(), context(user), schema(), sanitize(), apply()
  Skills/FinanceSkill.php   // items + account_actions (kode yang sudah ada)
  Skills/JournalSkill.php   // journal_entries (baru)
resources/js/components/lax/
  LaxSheet.vue           // kerangka percakapan (orb, input, gelembung)
  proposals/TransactionCard.vue, AccountActionCard.vue, JournalCard.vue
```

Setiap skill menyumbang: bagian prompt, potongan konteks (data yang perlu AI ketahui), field skema, pembersihan keluaran, dan cara menjalankan di `AssistantController@apply`. Menambah kemampuan baru nanti (mis. kebiasaan/pengingat) cukup menambah satu skill.

### 3.2 Kemampuan jurnal untuk LAX

| Ucapan | Hasil |
|---|---|
| "Tadi pagi lari 5 km, seger banget" | usulan catatan jurnal 06.30 · tag Olahraga · mood 😄 |
| "Siang makan bareng tim di warung Padang habis 45 ribu" | **dua usulan tertaut**: pengeluaran 45 rb (Makan & Minum) + catatan jurnal "Makan siang bareng tim di warung Padang" |
| "Hei LAX, rangkum hari ini" | ringkasan diucapkan (tanpa nominal, kecuali diminta), disimpan ke `journal_days` |
| "Minggu ini aku olahraga berapa kali?" | jawaban dari data jurnal |
| "Bagaimana mood-ku seminggu ini?" | ringkasan suasana hati + saran suportif |

Detail teknis:

- Skema baru `journal_entries: [{time (HH:MM|null), date, body, mood|null, tags[], link_item (indeks transaksi di items|null)}]`. Di `apply`, transaksi dibuat dulu, lalu catatan jurnal dibuat dan ditautkan lewat `link_item`.
- **Konteks jurnal untuk tanya-jawab**: jangan kirim semua jurnal. Ambil rentang yang relevan (bawaan 7 hari terakhir, ringkas: jam + 1 baris per catatan). Untuk pertanyaan rentang lain ("bulan lalu"), AI mengembalikan `need_range` → server mengambil data rentang itu → panggilan kedua. Batas token per panggilan dicatat di Pemakaian AI (fitur baru `journal`).
- Sapaan LAX boleh menyinggung jurnal ("Selamat malam, Tuan. Mau ceritakan hari ini?"), tetap aturan: tanpa nominal kecuali diminta, ucapan tidak ditulis ulang di layar.

---

## 4. Tampilan (HP dulu)

### Navigasi
Beranda · **Jurnal** · ( + ) · Transaksi · Lainnya (Akun pindah ke Lainnya + kartu akun di Beranda). Tombol + tetap untuk transaksi. Catat jurnal lewat tombol di halaman Jurnal atau lewat LAX.

### Halaman Jurnal `/jurnal`
- **Strip minggu** di atas (7 hari, titik berwarna = mood, angka = jumlah catatan), geser untuk minggu lain, ketuk tanggal untuk pindah hari.
- **Timeline hari itu**: jam · isi · chip tag · emoji mood · chip transaksi tertaut (nominal ikut mode privasi). Geser untuk hapus + urungkan (pola sama dengan transaksi).
- **Kartu "Ringkasan hari"** (Fase 3): tombol "Minta LAX merangkum" → teks ringkasan + mood harian.
- Kosong: ajakan ramah + tombol "Ceritakan ke LAX".
- Desktop (≥1024 px): dua kolom, timeline kiri dan panel kanan (kalender bulan, statistik mood, tag teratas), pola sama dengan halaman Transaksi.

### Sheet "Catatan baru"
Area teks besar (tumbuh otomatis), chip waktu (Sekarang / pilih jam), pemilih mood (5 wajah), tag (saran yang sering dipakai di depan), "Tautkan transaksi" (daftar transaksi hari itu), tombol mikrofon (dikte). Simpan dengan Ctrl+Enter di desktop.

### Beranda
Kartu kecil "Hari ini": jumlah catatan + mood. Bila kosong, tampil ajakan menulis. Di HP letaknya di bawah arus kas.

### Refleksi (Fase 3)
Grafik mood 30 hari, rentetan hari menulis (streak, dibuat lembut dan tidak memaksa), tag teratas, "setahun lalu hari ini".

---

## 5. Hal teknis lain

- **Pencarian**: `ilike` di `body` + filter tag/mood/rentang. Bila data banyak, tambah indeks trigram Postgres (`pg_trgm`) di migrasi terpisah.
- **Offline**: `useOutbox` sekarang khusus transaksi. Umumkan jadi antrean bertipe (`{kind: 'transaction'|'journal', payload}`) dengan `client_id` dedupe yang sama.
- **Privasi**: mode privasi sekarang hanya menyamarkan nominal. Tambah opsi "sembunyikan isi jurnal" (isi diburamkan sampai diketuk) + kunci aplikasi (K7).
- **Ekspor**: jurnal ke Markdown/JSON per bulan (backlog #11), karena data pribadi sebaiknya mudah dibawa pergi.
- **Kuota AI**: catatan jurnal lebih panjang daripada transaksi, jadi pakai model ringan untuk ekstraksi dan catat di Pemakaian AI sebagai fitur `journal`.
- **Tes**: CRUD + kepemilikan, validasi jam/tanggal (tidak di masa depan), tautan transaksi (milik sendiri, tetap aman saat transaksi dihapus), sanitasi usulan jurnal dari AI, rentang konteks Q&A.
- **Deploy**: setiap fase yang menambah tabel, jalankan `php artisan migrate --env=supabase` sebelum push.

---

## 6. Tahapan

| Fase | Isi | Hasil yang bisa dipakai | Perkiraan |
|---|---|---|---|
| **0. Persiapan** | Putuskan K1–K7 · refaktor LAX jadi skills (tanpa perubahan perilaku, tes lama tetap lolos) · kunci aplikasi bila dipilih | LAX siap ditambah kemampuan | 1 hari |
| **1. Jurnal manual (MVP)** | Migrasi `journal_entries` · model + controller + validasi · halaman Jurnal (strip minggu, timeline, sheet catatan, hapus/urungkan) · tab navigasi · kartu Beranda · tes | Bisa menulis & membaca jurnal harian | 1–2 hari |
| **2. LAX mencatat jurnal** | `JournalSkill` (prompt, skema, sanitasi, apply) · usulan jurnal + transaksi tertaut di sheet LAX · konfirmasi suara · pencatatan pemakaian fitur `journal` · antrean offline bertipe | "Hei LAX, tadi pagi lari 5 km" langsung jadi catatan | 1–2 hari |
| **3. Ringkasan & refleksi** | `journal_days` · ringkasan harian diucapkan · tanya-jawab rentang (`need_range`) · grafik mood, streak, pencarian & filter, "setahun lalu" | LAX bisa merangkum & menjawab tentang kegiatan | 2 hari |
| **4. Kebiasaan & pengingat** | Pengingat malam lewat Web Push (VAPID + service worker + Vercel Cron) · foto (Supabase Storage) · ekspor | Sisih mengingatkan menulis jurnal | 2–3 hari |

Urutan ini membuat setiap fase bisa dipakai sendiri. Fase 1 sudah berguna tanpa AI, dan fase berikutnya tidak memblokir pemakaian.

---

## 7. Risiko & cara mengatasi

| Risiko | Mitigasi |
|---|---|
| Isi jurnal terkirim ke AI gratis (dipakai Google) | K6: tier berbayar / Claude / sakelar "jangan kirim jurnal" |
| Token membengkak untuk tanya-jawab | Konteks ringkas 7 hari, `need_range`, model ringan, dipantau di Pemakaian AI |
| Navigasi berubah (Akun pindah) membingungkan | Kartu akun di Beranda tetap, Lainnya berisi Akun, dan pengumuman kecil sekali tampil |
| Kode LAX makin besar dan rapuh | Refaktor skills di Fase 0 sebelum fitur baru |
| Deploy sebelum migrasi | Jurnal tidak di props bersama; halaman jurnal cek tabel; jalankan migrasi dulu |
