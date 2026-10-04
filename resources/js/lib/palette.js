/**
 * Warna identitas kategori/akun. Urutan ini lolos validator dataviz
 * (CVD & normal-vision) di mode terang dan gelap; nilai hex ada di app.css.
 * "slate" sengaja abu-abu untuk "Lainnya".
 */
export const COLORS = ['teal', 'clay', 'ink', 'sand', 'plum', 'moss', 'rose', 'iris', 'slate'];

export const color = (key) => `var(--c-${COLORS.includes(key) ? key : 'slate'})`;

/** Latar lembut dari warna identitas. */
export const tint = (key, pct = 14) => `color-mix(in oklab, ${color(key)} ${pct}%, transparent)`;
