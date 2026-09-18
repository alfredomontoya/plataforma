/**
 * Gráficos SVG determinísticos para incrustar en el .docx.
 *
 * El flujo anterior rasterizaba los Recharts del dashboard desde un div oculto
 * (ResponsiveContainer + ResizeObserver + querySelector('svg')). En ese contexto
 * la geometría colapsaba y el PNG salía como un rectángulo sólido (p. ej. rosa)
 * sin leyenda —además la leyenda de Recharts es HTML y nunca llegaba al SVG—.
 *
 * Estos builders generan el SVG como string con geometría fija y leyenda
 * incluida (todo SVG nativo), así `svgChartsToPng` los convierte a PNG
 * sin depender del DOM ni del layout. Misma paleta que el dashboard.
 */

const CATEGORICAL = ['#f71f3d', '#2563eb', '#16a34a', '#d97706', '#9333ea', '#0d9488', '#ec4899', '#ea580c', '#0891b2', '#65a30d', '#4f46e5', '#78716c'];

const esc = (s) =>
    String(s ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');

const clip = (s, max) => {
    const t = String(s ?? '');
    return t.length <= max ? t : `${t.slice(0, Math.max(0, max - 3))}...`;
};

const polar = (cx, cy, r, angle) => [cx + r * Math.cos(angle), cy + r * Math.sin(angle)];

/**
 * Torta SVG: sectores + etiqueta por sector + leyenda derecha con %.
 * @param {{labels: string[], legendLabels?: string[], values: number[]}} data
 */
export const pieSvg = (data, width = 800, height = 500) => {
    const labels = data.labels || [];
    const legendLabels = data.legendLabels || labels;
    const values = (data.values || []).map((v) => Number(v) || 0);
    const total = values.reduce((a, b) => a + b, 0) || 1;

    const cx = Math.round(width * 0.3);
    const cy = Math.round(height / 2);
    const r = Math.round(Math.min(width * 0.23, height * 0.35));
    const legendX = Math.round(width * 0.64);
    let legendY = 30;
    const rowH = Math.max(22, Math.min(30, Math.floor((height - 60) / Math.max(1, values.length))));

    let slices = '';
    let sliceLabels = '';
    let legend = '';
    let angle = -Math.PI / 2;
    // Etiquetas externas: se distribuyen por lado con separación mínima
    // vertical para que nunca se sobrepongan.
    const outsiders = [];

    values.forEach((value, i) => {
        const color = CATEGORICAL[i % CATEGORICAL.length];
        const sweep = (value / total) * Math.PI * 2;
        const pct = (value / total) * 100;
        const pctTxt = pct.toFixed(1);
        const mid = angle + sweep / 2;

        if (sweep > 0.0001) {
            if (sweep >= Math.PI * 2 - 0.001) {
                slices += `<circle cx="${cx}" cy="${cy}" r="${r}" fill="${color}"/>`;
            } else {
                const [x1, y1] = polar(cx, cy, r, angle);
                const [x2, y2] = polar(cx, cy, r, angle + sweep);
                const large = sweep > Math.PI ? 1 : 0;
                slices += `<path d="M ${cx} ${cy} L ${x1.toFixed(1)} ${y1.toFixed(1)} A ${r} ${r} 0 ${large} 1 ${x2.toFixed(1)} ${y2.toFixed(1)} Z" fill="${color}" stroke="#ffffff" stroke-width="2"/>`;
            }
            // Todas las porciones llevan etiqueta completa con icono del color
            // de la porción (= swatch de la leyenda): grandes (≥12%) dentro,
            // demás fuera con línea guía y distribución anti-solape.
            const tag = `${labels[i] || ''} ${pctTxt}%`;
            if (pct >= 12) {
                const [tx, ty] = polar(cx, cy, r * 0.6, mid);
                const w = tag.length * 7;
                sliceLabels += `<rect x="${(tx - w / 2 - 15).toFixed(1)}" y="${(ty - 5).toFixed(1)}" width="10" height="10" fill="${color}"/>` +
                    `<text x="${(tx + 5).toFixed(1)}" y="${ty.toFixed(1)}" text-anchor="middle" dominant-baseline="middle" font-family="sans-serif" font-size="14" fill="#333333">${esc(tag)}</text>`;
            } else {
                outsiders.push({ mid, tag, color, tiny: pct < 2 });
            }
        }

        legend += `<rect x="${legendX}" y="${legendY}" width="14" height="14" fill="${color}"/>` +
            `<text x="${legendX + 20}" y="${legendY + 12}" font-family="sans-serif" font-size="14" fill="#444444">${esc(clip(`${legendLabels[i] || labels[i] || ''} (${pctTxt}%)`, 30))}</text>`;
        legendY += rowH;
        angle += sweep;
    });

    // Distribución: relajación angular acotada + separación vertical mínima.
    const labelR = r + 26;
    const minAng = 15 / labelR;
    const maxAngShift = 0.21;
    for (const side of [1, -1]) {
        const list = outsiders
            .filter((o) => (Math.cos(o.mid) >= 0 ? 1 : -1) === side)
            .sort((a, b) => Math.sin(a.mid) - Math.sin(b.mid));
        list.forEach((o) => {
            o.o0 = o.mid;
        });
        for (let pass = 0; pass < 3; pass++) {
            for (let k = 1; k < list.length; k++) {
                if (list[k].mid - list[k - 1].mid < minAng) {
                    list[k].mid = Math.min(list[k].o0 + maxAngShift, list[k - 1].mid + minAng);
                }
            }
            for (let k = list.length - 2; k >= 0; k--) {
                if (list[k + 1].mid - list[k].mid < minAng) {
                    list[k].mid = Math.max(list[k].o0 - maxAngShift, list[k + 1].mid - minAng);
                }
            }
        }
        list.forEach((o) => {
            o.x = cx + labelR * Math.cos(o.mid);
            o.y = cy + labelR * Math.sin(o.mid);
        });
        for (let pass = 0; pass < 3; pass++) {
            for (let k = 1; k < list.length; k++) {
                if (list[k].y - list[k - 1].y < 15) list[k].y = list[k - 1].y + 15;
            }
        }
        const overflow = list.length ? list[list.length - 1].y - (height - 12) : 0;
        if (overflow > 0) list.forEach((o) => {
            o.y -= overflow;
        });
        const anchor = side >= 0 ? 'start' : 'end';
        for (const o of list) {
            const [ex, ey] = polar(cx, cy, r + 3, o.o0);
            const fs = o.tiny ? 12 : 13;
            const tx = side >= 0 ? o.x + 16 : o.x - 16;
            sliceLabels += `<line x1="${ex.toFixed(1)}" y1="${ey.toFixed(1)}" x2="${o.x.toFixed(1)}" y2="${o.y.toFixed(1)}" stroke="#999999" stroke-width="1"/>` +
                `<rect x="${(o.x - 5).toFixed(1)}" y="${(o.y - 5).toFixed(1)}" width="10" height="10" fill="${o.color}"/>` +
                `<text x="${tx.toFixed(1)}" y="${(o.y + 4).toFixed(1)}" text-anchor="${anchor}" font-family="sans-serif" font-size="${fs}" fill="#333333">${esc(o.tag)}</text>`;
        }
    }

    return `<svg xmlns="http://www.w3.org/2000/svg" width="${width}" height="${height}" viewBox="0 0 ${width} ${height}">` +
        `<rect x="0" y="0" width="${width}" height="${height}" fill="#ffffff"/>${slices}${sliceLabels}${legend}</svg>`;
};

/** Eje Y "bonito" 1/2/5×10^n como el GD del backend. */
const niceTop = (maxV) => {
    const m = Math.max(1, maxV);
    const scale = 10 ** Math.floor(Math.log10(m));
    for (const k of [1, 2, 5, 10]) if (k * scale >= m) return k * scale;
    return 10 * scale;
};

/** Techo cercano para barras (282 → 300, no 500): valores chicos visibles. */
const niceCeil = (maxV) => {
    const m = Math.max(1, maxV);
    const base = 10 ** Math.floor(Math.log10(m));
    for (const k of [1, 1.2, 1.5, 2, 2.5, 3, 4, 5, 6, 8, 10]) if (k * base >= m) return Math.round(k * base);
    return 10 * base;
};

/** Paso de grilla que divide el techo en ≤6 intervalos. */
const gridStep = (top) => {
    const base = 10 ** Math.floor(Math.log10(Math.max(1, top / 6)));
    for (const k of [1, 2, 2.5, 5, 10]) if (top / (k * base) <= 6) return Math.round(k * base);
    return base;
};

const TREND_SERIES_DEFAULT = [
    { key: 'ingreso', name: 'Ingreso', color: '#2563eb' },
    { key: 'entrega', name: 'Entrega', color: '#16a34a' },
];

/**
 * Tendencia SVG: grilla + series con área, puntos, valores y leyenda.
 * @param {Array<Object>} points puntos con day + claves de serie
 * @param {Array<{key:string,name:string,color:string}>} [series] default Ingreso/Entrega
 */
export const trendSvg = (points, width = 1200, height = 573, series = TREND_SERIES_DEFAULT) => {
    const pts = (points || []).map((p) => {
        const o = { day: String(p.day ?? '') };
        series.forEach((s) => {
            o[s.key] = Number(p[s.key]) || 0;
        });
        return o;
    });
    const pl = 55;
    const pr = 25;
    const pt = 60;
    const pb = 45;
    const pw = width - pl - pr;
    const ph = height - pt - pb;
    const n = pts.length;

    const maxV = pts.reduce((m, p) => Math.max(m, ...series.map((s) => p[s.key])), 0);
    const top = niceTop(maxV);
    const xAt = (i) => (n === 1 ? pl + pw / 2 : pl + (i / (n - 1)) * pw);
    const yAt = (v) => pt + ph - (v / top) * ph;

    let grid = '';
    const ticks = 5;
    for (let i = 0; i <= ticks; i++) {
        const val = (top * i) / ticks;
        const y = yAt(val).toFixed(1);
        grid += `<line x1="${pl}" y1="${y}" x2="${width - pr}" y2="${y}" stroke="#e2e2e2" stroke-dasharray="4 3"/>` +
            `<text x="8" y="${Number(y) + 4}" font-family="sans-serif" font-size="12" fill="#555555">${Math.round(val)}</text>`;
    }

    let xLabels = '';
    if (n > 0) {
        const step = Math.max(1, Math.ceil(n / 16));
        pts.forEach((p, i) => {
            if (i % step === 0 || i === n - 1) {
                xLabels += `<text x="${xAt(i).toFixed(1)}" y="${height - pb + 20}" text-anchor="middle" font-family="sans-serif" font-size="12" fill="#555555">${esc(p.day)}</text>`;
            }
        });
    } else {
        xLabels = `<text x="${pl + pw / 2}" y="${pt + ph / 2}" text-anchor="middle" font-family="sans-serif" font-size="16" fill="#888888">Sin datos</text>`;
    }

    const drawSeries = (key, color) => {
        if (n === 0) return '';
        const line = pts.map((p, i) => `${xAt(i).toFixed(1)},${yAt(p[key]).toFixed(1)}`).join(' ');
        const area = `${pl},${(pt + ph).toFixed(1)} ${line} ${xAt(n - 1).toFixed(1)},${(pt + ph).toFixed(1)}`;
        let dots = '';
        pts.forEach((p, i) => {
            const x = xAt(i).toFixed(1);
            const y = yAt(p[key]).toFixed(1);
            dots += `<circle cx="${x}" cy="${y}" r="3.5" fill="${color}" stroke="#ffffff" stroke-width="1.5"/>`;
            if (p[key] > 0) {
                dots += `<text x="${x}" y="${(yAt(p[key]) - 9).toFixed(1)}" text-anchor="middle" font-family="sans-serif" font-size="11" fill="#666666">${p[key]}</text>`;
            }
        });
        return `<polygon points="${area}" fill="${color}" fill-opacity="0.15"/>` +
            `<polyline points="${line}" fill="none" stroke="${color}" stroke-width="2.5" stroke-linejoin="round"/>${dots}`;
    };

    let legend = '';
    {
        const gap = 36;
        const widths = series.map((s) => 30 + s.name.length * 8 + gap);
        let lx = (width - (widths.reduce((a, b) => a + b, 0) - gap)) / 2;
        series.forEach((s, i) => {
            legend += `<rect x="${lx.toFixed(1)}" y="18" width="13" height="13" fill="${s.color}"/>` +
                `<text x="${(lx + 17).toFixed(1)}" y="29" font-family="sans-serif" font-size="14" fill="#444444">${esc(s.name)}</text>`;
            lx += widths[i];
        });
    }

    return `<svg xmlns="http://www.w3.org/2000/svg" width="${width}" height="${height}" viewBox="0 0 ${width} ${height}">` +
        `<rect x="0" y="0" width="${width}" height="${height}" fill="#ffffff"/>${legend}${grid}${xLabels}` +
        series.map((s) => drawSeries(s.key, s.color)).join('') + `</svg>`;
};

/**
 * Barras horizontales SVG: misma data del circular en barras (morado),
 * etiquetas a la izquierda y valores al final. Alto según filas.
 * @param {Array<{label:string, value:number}>} items
 */
export const barSvg = (items, width = 1200) => {
    const rows = (items || []).filter((i) => Number(i.value) > 0);
    const pl = 300;
    const pr = 80;
    const pt = 24;
    const rowH = 36;
    const pb = 34;
    const height = pt + Math.max(1, rows.length) * rowH + pb;
    const pw = width - pl - pr;
    const ph = height - pt - pb;
    const maxV = Math.max(1, ...rows.map((i) => Number(i.value) || 0));
    const top = niceCeil(maxV);

    const step = gridStep(top);
    let grid = '';
    for (let val = 0; val <= top; val += step) {
        const x = pl + (val / top) * pw;
        grid += `<line x1="${x.toFixed(1)}" y1="${pt}" x2="${x.toFixed(1)}" y2="${pt + ph}" stroke="#e2e2e2" stroke-dasharray="4 3"/>` +
            `<text x="${(x - 4).toFixed(1)}" y="${height - pb + 20}" text-anchor="middle" font-family="sans-serif" font-size="12" fill="#555555">${val}</text>`;
    }

    let bars = '';
    rows.forEach((item, i) => {
        const y = pt + i * rowH + rowH / 2;
        const bw = (Number(item.value) / top) * pw;
        // Mismo color categórico que la porción i del circular.
        const bar = CATEGORICAL[i % CATEGORICAL.length];
        const label = String(item.label ?? '').slice(0, 42);
        bars += `<text x="${pl - 10}" y="${(y + 4).toFixed(1)}" text-anchor="end" font-family="sans-serif" font-size="13" fill="#444444">${esc(label)}</text>`;
        if (bw > 0) {
            bars += `<rect x="${pl}" y="${(y - 10).toFixed(1)}" width="${bw.toFixed(1)}" height="20" fill="${bar}"/>`;
        }
        bars += `<text x="${(pl + bw + 8).toFixed(1)}" y="${(y + 4).toFixed(1)}" font-family="sans-serif" font-size="13" fill="#666666">${Number(item.value)}</text>`;
    });

    return `<svg xmlns="http://www.w3.org/2000/svg" width="${width}" height="${height}" viewBox="0 0 ${width} ${height}">` +
        `<rect x="0" y="0" width="${width}" height="${height}" fill="#ffffff"/>${grid}${bars}</svg>`;
};
