const MINUS = '−';
const grouped = new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 });
const oneDecimal = new Intl.NumberFormat('id-ID', { maximumFractionDigits: 1 });

/** 1250000 -> "Rp 1.250.000". `sign` menambahkan +/− di depan. */
export function rupiah(value, { sign = false } = {}) {
    const n = Math.round(Number(value) || 0);
    const body = 'Rp ' + grouped.format(Math.abs(n));
    if (n < 0) return MINUS + body;
    if (sign && n > 0) return '+' + body;
    return body;
}

/** Angka tanpa "Rp": 1250000 -> "1.250.000". */
export function digits(value) {
    return grouped.format(Math.round(Number(value) || 0));
}

/** Ringkas untuk ruang sempit: 1250000 -> "1,3 jt", 85000 -> "85 rb". */
export function compact(value) {
    const n = Number(value) || 0;
    const abs = Math.abs(n);
    const sign = n < 0 ? MINUS : '';
    if (abs >= 1e9) return sign + oneDecimal.format(abs / 1e9) + ' M';
    if (abs >= 1e6) return sign + oneDecimal.format(abs / 1e6) + ' jt';
    if (abs >= 1e3) return sign + grouped.format(Math.round(abs / 1e3)) + ' rb';
    return sign + grouped.format(abs);
}

/* ---------------------------------------------------------------
   Ekspresi nominal di keypad: hanya angka, "+" dan "−".
   Disimpan sebagai string mentah, misalnya "25000+12000".
   --------------------------------------------------------------- */
const MAX_DIGITS = 12;

export function evaluate(expr) {
    if (!expr) return 0;
    let total = 0;
    for (const match of expr.matchAll(/([+-]?)(\d+)/g)) {
        const value = Number(match[2]);
        total += match[1] === '-' ? -value : value;
    }
    return total;
}

export function hasOperator(expr) {
    return /\d[+-]/.test(expr);
}

/** "25000+12000" -> "25.000 + 12.000" */
export function formatExpr(expr) {
    if (!expr) return '0';
    return expr
        .replace(/\d+/g, (d) => grouped.format(Number(d)))
        .replace(/\+/g, ' + ')
        .replace(/-/g, ` ${MINUS} `);
}

/** Terapkan satu tombol keypad ke ekspresi. */
export function pressKey(expr, key) {
    const last = expr.slice(-1);
    const isOp = (c) => c === '+' || c === '-';
    const currentNumber = expr.split(/[+-]/).pop() ?? '';

    if (key === 'back') return expr.slice(0, -1);
    if (key === 'clear') return '';

    if (isOp(key)) {
        if (!expr) return '';
        if (isOp(last)) return expr.slice(0, -1) + key;
        return expr + key;
    }

    if (key === '000') {
        if (!currentNumber || currentNumber === '0') return expr;
        return pressKey(pressKey(pressKey(expr, '0'), '0'), '0');
    }

    if (/^\d$/.test(key)) {
        if (currentNumber.length >= MAX_DIGITS) return expr;
        if (currentNumber === '0') return expr.slice(0, -1) + key;
        if (key === '0' && !currentNumber && !expr) return expr;
        return expr + key;
    }

    return expr;
}
