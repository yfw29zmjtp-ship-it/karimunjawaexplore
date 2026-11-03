# Konsep Template Website Travel (untuk dijual)

Tujuan: satu sistem travel (booking, invoice, kas, paket) dengan **beberapa tampilan website** yang berbeda
tatanan, warna, dan gaya. Fungsinya sama; yang beda hanya "kulit"-nya. Klien memilih template, mengisi
nama, logo, warna, dan foto, lalu website langsung jadi.

## Kondisi sekarang (Karimunjawa Explore = template #1)

- Halaman publik: `home.php`, `paket-wisata.php`, `galeri.php`, `tentang-kami.php`, `blog.php`, `kontak.php`.
- Kerangka bersama: `includes/website-header.php`, `website-footer.php`, `website-bootstrap.php`.
- Gaya: satu file `assets/website/style.css` (2.100 baris, 100 pemakaian warna `--we-*`).
- Isi dari database: `trip_packages`, `website_gallery`, blog, dan pengaturan `website_*` (judul hero, tentang, sosmed, favicon).
- Masalah untuk dijual: tampilan, teks "Karimunjawa", ikon 🌊, dan sebagian HTML masih tertanam di tiap halaman.

## Prinsip

1. **Logika tidak berubah**: formulir penawaran cepat, booking, kontak, anti-spam, dan data paket tetap satu kode.
2. **Template = tema + tata letak**, bukan salinan seluruh website.
3. **Klien tidak menyentuh kode**: semua pilihan lewat halaman Pengaturan Website di admin.
4. Tambah template baru = tambah satu folder, tanpa mengubah halaman lain.

## Dua tingkat perbedaan

| Tingkat | Yang berubah | Cara |
|---|---|---|
| **A. Tema (warna & gaya)** | warna utama, warna aksen, font, bentuk sudut (bulat/tajam), bayangan | variabel CSS `--we-*` per tema |
| **B. Tata letak** | susunan beranda (hero penuh / hero terbelah / slider), kartu paket (grid / daftar / besar), menu (atas / samping / transparan), footer | potongan tampilan (partial) per template |

Template = gabungan satu pilihan A + satu pilihan B, dengan nama (mis. "Pantai", "Petualang", "Elegan").

## Struktur folder yang diusulkan

```
website-templates/
  _shared/            logika & potongan umum: formulir quote, kontak, spam guard, kartu paket dasar
  ocean/              template 1 (isi sekarang, dipindah rapi)
    template.json     nama, deskripsi, font, warna bawaan, daftar bagian beranda
    theme.css         hanya variabel & gaya khas template
    layout-header.php
    layout-footer.php
    sections/         hero.php, paket.php, galeri.php, tentang.php, testimoni.php
  adventure/          template 2 (gelap, foto besar, tombol tegas)
  elegant/            template 3 (putih bersih, huruf serif, banyak ruang kosong)
  minimal/            template 4 (ringkas, cocok biro kecil)
```

Halaman publik (`home.php` dll) menjadi tipis: ambil data lalu memanggil bagian dari template aktif.
Bila bagian tidak ada di template tersebut, dipakai bagian dari `_shared`, jadi template baru bisa dibuat bertahap.

## Pengaturan di admin ("Website" → "Tampilan")

- Pilih template (kartu dengan gambar pratinjau).
- Warna utama & aksen (pemilih warna), font (3–4 pilihan), bentuk sudut.
- Logo, favicon, foto hero, teks hero.
- Atur beranda: nyalakan/matikan & urutkan bagian (Paket, Galeri, Tentang, Blog, Testimoni).
- Tombol **Pratinjau** (buka website dengan template terpilih tanpa menyimpan) lalu **Terapkan**.
- Disimpan di tabel `settings`: `web_template`, `web_color_primary`, `web_color_accent`, `web_font`, `web_radius`, `web_home_sections`.

## Rangkaian pengerjaan

1. **Rapikan template #1** (Karimunjawa Explore): ganti semua warna tertanam jadi variabel, keluarkan teks "Karimunjawa" jadi pengaturan, pindahkan ke `website-templates/ocean/`. Tampilan tidak berubah.
2. **Mesin template**: pemuat template aktif + `template.json` + fallback ke `_shared`.
3. **Pengaturan Tampilan** di admin: pilih template, warna, font, urutan bagian, pratinjau.
4. **Template #2** (Adventure) sebagai bukti bahwa mesinnya jalan: hanya tema + tata letak beranda.
5. **Template #3 & #4**, lalu data contoh (demo) supaya calon pembeli bisa melihat tiap template penuh isi.
6. **Paket jual**: halaman demo publik per template, panduan setup 10 menit, harga per template atau gratis dalam paket.

Tahap 1–3 dikerjakan lebih dulu (fondasi); tahap 4 sekaligus menguji.

## Hal yang perlu diputuskan pemilik

- Harga: template termasuk paket langganan, atau jual terpisah?
- Berapa template awal (usul: 3)?
- Klien boleh ganti template kapan saja, atau dikunci setelah live?
- Satu kode untuk semua klien (di VPS, per klien satu database) atau tetap fork per klien seperti sekarang.
  Usul: satu kode, karena memperbaiki bug cukup sekali (lihat rencana pindah ke VPS).

## Risiko

- Gaya sekarang 2.100 baris; memisahkan warna/tata letak butuh ketelitian agar tampilan template #1 tidak berubah.
- Terlalu banyak pilihan membuat klien bingung: batasi warna ke pemilih sederhana + beberapa warna siap pakai.
- Setiap template baru menambah biaya uji (HP + laptop); karena itu bagian umum dipakai bersama.
