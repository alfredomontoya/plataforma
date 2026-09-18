/**
 * Parser del parte pegado como texto (ingreso diario total).
 *
 * Patrón esperado:
 *   Desde Fecha: 14/09/2026 Hasta Fecha: 14/09/2026
 *   Nombre Supervisor: APELLIDOS NOMBRES
 *   N°  Tipo Trámite  Cantidad
 *   1   NOMBRE TRAMITE  28
 *   ...
 *   TOTAL INGRESADOS: 116
 *
 * Hace match exacto + tolerancia a typos (distancia de edición ≤ 3).
 * Las filas no reconocidas se devuelven con nombre y cantidad para
 * REGISTRARLAS como servicios vía POST /totals/resolve. Puro y testeable.
 */

export const norm = (s) =>
    String(s ?? '')
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toUpperCase()
        .replace(/\s+/g, ' ')
        .trim();

const lev = (a, b) => {
    const m = a.length;
    const n = b.length;
    if (!m) return n;
    if (!n) return m;
    let prev = Array.from({ length: n + 1 }, (_, j) => j);
    for (let i = 1; i <= m; i++) {
        let cur = [i];
        for (let j = 1; j <= n; j++) {
            cur[j] = Math.min(prev[j] + 1, cur[j - 1] + 1, prev[j - 1] + (a[i - 1] === b[j - 1] ? 0 : 1));
        }
        prev = cur;
    }
    return prev[n];
};

const toIso = (y, m, d) => {
    const yy = Number(y);
    const mm = Number(m);
    const dd = Number(d);
    if (!yy || mm < 1 || mm > 12 || dd < 1 || dd > 31) return null;
    const dt = new Date(Date.UTC(yy, mm - 1, dd));
    if (dt.getUTCFullYear() !== yy || dt.getUTCMonth() !== mm - 1 || dt.getUTCDate() !== dd) return null;
    const pad = (v) => String(v).padStart(2, '0');
    return `${yy}-${pad(mm)}-${pad(dd)}`;
};

/**
 * @param {string} text parte pegado
 * @param {Array<{nro:number, tramite:string}>} catalog catálogo del backend
 * @returns {{date:string|null, dateEnd:string|null, supervisor:string|null,
 *   items:Array<{tramite:string, quantity:number, aprox?:string}>,
 *   unmatched:Array<{raw:string, name:string, quantity:number}>,
 *   totalDeclarado:number|null, suma:number}}
 */
export const parseTotalText = (text, catalog) => {
    const byNorm = new Map((catalog || []).map((c) => [norm(c.tramite), c.tramite]));
    const keys = [...byNorm.keys()];
    let date = null;
    let dateEnd = null;
    let supervisor = null;
    let totalDeclarado = null;
    const items = [];
    const unmatched = [];

    for (const raw of String(text ?? '').split(/\r?\n/)) {
        const line = raw.trim();
        if (!line) continue;
        let m;
        if ((m = /Desde Fecha:\s*(\d{1,2})\/(\d{1,2})\/(\d{4})/i.exec(line))) {
            date = toIso(m[3], m[2], m[1]);
            const e = /Hasta Fecha:\s*(\d{1,2})\/(\d{1,2})\/(\d{4})/i.exec(line);
            dateEnd = e ? toIso(e[3], e[2], e[1]) : date;
            continue;
        }
        if ((m = /Nombre Supervisor:\s*(.+?)\s*$/i.exec(line))) {
            supervisor = m[1].trim() || null;
            continue;
        }
        if ((m = /TOTAL INGRESADOS:\s*(\d+)/i.exec(line))) {
            totalDeclarado = Number(m[1]);
            continue;
        }
        if ((m = /^(\d+)\s+(.+?)\s+(\d+)\s*$/.exec(line))) {
            const rawName = m[2].replace(/\s+/g, ' ').trim();
            const key = norm(rawName);
            const qty = Number(m[3]);
            if (byNorm.has(key)) {
                items.push({ tramite: byNorm.get(key), quantity: qty });
            } else {
                let best = null;
                let bestD = 4;
                for (const k of keys) {
                    const d = lev(key, k);
                    if (d < bestD) {
                        bestD = d;
                        best = k;
                    }
                }
                if (best) items.push({ tramite: byNorm.get(best), quantity: qty, aprox: rawName });
                else unmatched.push({ raw: raw.trim(), name: rawName, quantity: qty });
            }
        }
    }

    return {
        date,
        dateEnd,
        supervisor,
        items,
        unmatched,
        totalDeclarado,
        suma: items.reduce((a, i) => a + i.quantity, 0),
    };
};

/** Asocia el supervisor del parte con un operador por coincidencia de tokens. */
export const matchOperator = (supervisor, operators) => {
    const toks = norm(supervisor).split(' ').filter((t) => t.length > 1);
    if (!toks.length || !operators?.length) return null;
    let best = null;
    let bestScore = 0;
    for (const o of operators) {
        const parts = new Set(
            norm([o.firstName, o.lastName, o.paternalSurname, o.maternalSurname].filter(Boolean).join(' '))
                .split(' ')
                .filter((t) => t.length > 1)
        );
        const hit = toks.filter((t) => parts.has(t)).length;
        const score = hit / toks.length;
        if (hit >= Math.min(2, toks.length) && score > bestScore) {
            bestScore = score;
            best = o;
        }
    }
    return best ? { operator: best, score: bestScore } : null;
};
