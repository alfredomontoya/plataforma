import { useEffect, useRef, useState } from 'react';
import { Cell, Pie, PieChart as RPie, ResponsiveContainer, BarChart as RBar, Bar, XAxis, YAxis, Tooltip, CartesianGrid, Legend, LabelList, AreaChart as RArea, Area } from 'recharts';

const CATEGORICAL = ['#f71f3d', '#2563eb', '#16a34a', '#d97706', '#9333ea', '#0d9488', '#ec4899', '#ea580c', '#0891b2', '#65a30d', '#4f46e5', '#78716c'];

const RADIAN = Math.PI / 180;
// Todas las porciones llevan etiqueta: las grandes (≥12%) dentro; las demás
// en columnas laterales con línea guía en codo (reparto uniforme por lado,
// imposible de solapar y sin medir el DOM).
const PIE_OUTSIDE_BELOW = 0.12;
const PIE_COL_PITCH = 22;
const PIE_SPINE_DX = 56;

export function PieChart({ data, height = null, animate = true, legendFontSize = 14, legendLineHeight = 22, pieOuterRadius = 120, outsideAll = false, legendLayout = 'vertical', legendAlign = 'right', legendVerticalAlign = 'middle', hideLegend = false }) {
    const values = data.datasets[0].data.map((v) => Number(v) || 0);
    const total = values.reduce((a, b) => a + b, 0) || 1;
    const rows = data.labels.map((label, i) => ({ name: label, value: values[i] || 0 }));
    const legend = data.legendLabels || data.labels;
    // Ángulos teóricos con la misma convención de Recharts (startAngle 0,
    // acumulación positiva) para distribuir sin depender del layout.
    let acc = 0;
    const layout = rows.map((r) => {
        const pct = r.value / total;
        const sweep = pct * Math.PI * 2;
        const mid = acc + sweep / 2;
        acc += sweep;
        return { pct, outside: outsideAll || pct < PIE_OUTSIDE_BELOW, mid, shift: 0 };
    });
    // Reparto uniforme por columna lateral (conserva el orden circular).
    const perSide = { '1': [], '-1': [] };
    layout.forEach((L, i) => {
        if (!L.outside) return;
        perSide[Math.cos(-L.mid) >= 0 ? '1' : '-1'].push(i);
    });
    const maxSide = Math.max(1, perSide['1'].length, perSide['-1'].length);
    const resolvedHeight = height ?? Math.max(360, (maxSide - 1) * 24 + 220);
    for (const side of [1, -1]) {
        const ids = perSide[String(side)]
            .map((i) => ({ i, y: Math.sin(-layout[i].mid) }))
            .sort((a, b) => a.y - b.y);
        const n = ids.length;
        ids.forEach((o, k) => {
            layout[o.i].slot = n === 1 ? 0 : -((n - 1) * PIE_COL_PITCH) / 2 + k * PIE_COL_PITCH;
            layout[o.i].side = side;
        });
    }
    const renderSliceLabel = (props) => {
        const { cx, cy, midAngle, outerRadius, x, y, index } = props;
        const L = layout[index];
        if (!L) return null;
        // Icono del color de la porción (= swatch de la leyenda).
        const color = CATEGORICAL[index % CATEGORICAL.length];
        const text = `${rows[index].name} ${(L.pct * 100).toFixed(1)}%`;
        if (!L.outside) {
            const w = text.length * 6.2;
            return (
                <g>
                    <circle cx={x - w / 2 - 5} cy={y} r={5} fill={color} />
                    <text x={x + 5} y={y} textAnchor="middle" dominantBaseline="central" fontSize={13} fill="#333333">{text}</text>
                </g>
            );
        }
        const right = (L.side ?? (Math.cos(-midAngle * RADIAN) >= 0 ? 1 : -1)) >= 0;
        const sx = cx + (right ? outerRadius + PIE_SPINE_DX : -(outerRadius + PIE_SPINE_DX));
        const ly = cy + (L.slot || 0);
        const tx = right ? sx + 10 : sx - 10;
        const ex = cx + (outerRadius + 5) * Math.cos(-midAngle * RADIAN);
        const ey = cy + (outerRadius + 5) * Math.sin(-midAngle * RADIAN);
        return (
            <g>
                <polyline
                    points={`${ex},${ey} ${sx},${ly} ${tx},${ly}`}
                    fill="none"
                    stroke="#999999"
                    strokeWidth={1}
                />
                <circle cx={sx} cy={ly} r={5} fill={color} />
                <text
                    x={tx}
                    y={ly}
                    textAnchor={right ? 'start' : 'end'}
                    dominantBaseline="central"
                    fontSize={L.pct < 0.02 ? 11 : 12}
                    className="fill-stone-700 dark:fill-wa-text"
                >
                    {text}
                </text>
            </g>
        );
    };
    return (
        <ResponsiveContainer width="100%" height={resolvedHeight}>
            <RPie>
                <Pie data={rows} dataKey="value" nameKey="name" outerRadius={pieOuterRadius} startAngle={0} endAngle={360} label={renderSliceLabel} labelLine={false} isAnimationActive={animate}>
                    {rows.map((_, i) => (
                        <Cell key={i} fill={CATEGORICAL[i % CATEGORICAL.length]} />
                    ))}
                </Pie>
                <Tooltip formatter={(v) => [v, 'Cantidad']} />
                {!hideLegend && (
                <Legend
                    layout={legendLayout}
                    align={legendAlign}
                    verticalAlign={legendVerticalAlign}
                    wrapperStyle={{ lineHeight: `${legendLineHeight}px` }}
                    formatter={(v, entry, index) => {
                        const pct = ((Number(entry?.payload?.value) || 0) / total) * 100;
                        const label = legend[typeof index === 'number' ? index : 0] || v;
                        return <span className="text-stone-700 dark:text-wa-text" style={{ fontSize: legendFontSize }}>{label} ({pct.toFixed(1)}%)</span>;
                    }}
                />
                )}
            </RPie>
        </ResponsiveContainer>
    );
}

// Tick de una sola línea: el tick por defecto de Recharts envuelve el texto
// al ancho del eje; este recorta con elipsis y muestra el completo en <title>.
const singleLineTick = (axisWidth, tickFontSize) => (props) => {
    const { x, y, payload } = props;
    const full = String(payload?.value ?? '');
    const maxChars = Math.max(4, Math.floor(axisWidth / (tickFontSize * 0.62)));
    const short = full.length > maxChars ? `${full.slice(0, Math.max(0, maxChars - 1))}…` : full;
    return (
        <text x={x} y={y} textAnchor="end" dominantBaseline="central" fontSize={tickFontSize} fill="#57534e" className="dark:fill-wa-muted">
            <title>{full}</title>
            {short}
        </text>
    );
};

export function BarChart({ labels, datasets, height, animate = true, axisWidth = 140, tickFontSize = 12, dense = false, showAllTicks = false }) {
    const rows = labels.map((label, i) => {
        const row = { name: label };
        datasets.forEach((ds) => {
            row[ds.label] = ds.data[i] || 0;
        });
        return row;
    });
    const rowH = dense ? 22 : 40;
    return (
        <ResponsiveContainer width="100%" height={height || labels.length * rowH + 60}>
            <RBar data={rows} layout="vertical" maxBarSize={dense ? 14 : 30} barCategoryGap="20%">
                <CartesianGrid strokeDasharray="3 3" />
                <XAxis type="number" tick={{ fontSize: tickFontSize }} />
                <YAxis type="category" dataKey="name" width={axisWidth} tick={singleLineTick(axisWidth, tickFontSize)} {...(showAllTicks ? { interval: 0 } : {})} />
                <Tooltip />
                {datasets.length > 1 && (
                    <Legend formatter={(v) => <span className="text-sm text-stone-700 dark:text-wa-text">{v}</span>} />
                )}
                {datasets.map((ds) => (
                    <Bar key={ds.label} dataKey={ds.label} fill={ds.color} stackId={ds.stackId} isAnimationActive={animate}>
                        {ds.stackId
                            ? <LabelList dataKey={ds.label} position="center" fill="#fff" formatter={(v) => (Number(v) > 0 ? v : '')} />
                            : (
                                <>
                                    {rows.map((_, i) => (
                                        <Cell key={i} fill={CATEGORICAL[i % CATEGORICAL.length]} />
                                    ))}
                                    <LabelList dataKey={ds.label} position="right" fill="#8696a0" />
                                </>
                            )}
                    </Bar>
                ))}
            </RBar>
        </ResponsiveContainer>
    );
}

const TREND_SERIES_DEFAULT = [
    { key: 'ingreso', name: 'Ingreso', color: '#2563eb' },
    { key: 'entrega', name: 'Entrega', color: '#16a34a' },
];

const trendFill = (key, color) => {
    if (key === 'ingreso') return 'url(#trendIng)';
    if (key === 'entrega') return 'url(#trendEnt)';
    return color;
};

export function TrendChart({ points, height = 300, animate = true, series = TREND_SERIES_DEFAULT, xLabel, yLabel }) {
    return (
        <ResponsiveContainer width="100%" height={height}>
            <RArea data={points} margin={{ top: 8, right: 8, bottom: xLabel ? 24 : 0, left: yLabel ? 10 : -12 }}>
                <defs>
                    <linearGradient id="trendIng" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stopColor="#2563eb" stopOpacity={0.35} />
                        <stop offset="100%" stopColor="#2563eb" stopOpacity={0.03} />
                    </linearGradient>
                    <linearGradient id="trendEnt" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stopColor="#16a34a" stopOpacity={0.35} />
                        <stop offset="100%" stopColor="#16a34a" stopOpacity={0.03} />
                    </linearGradient>
                </defs>
                <CartesianGrid strokeDasharray="3 3" />
                <XAxis
                    dataKey="day"
                    tick={{ fontSize: 12 }}
                    interval={Math.max(0, Math.floor(points.length / 16))}
                    {...(xLabel ? { label: { value: xLabel, position: 'insideBottomRight', offset: 0, fontSize: 12, fill: '#78716c' } } : {})}
                />
                <YAxis
                    allowDecimals={false}
                    tick={{ fontSize: 12 }}
                    {...(yLabel ? { label: { value: yLabel, angle: -90, position: 'insideLeft', fontSize: 12, fill: '#78716c' } } : {})}
                />
                <Tooltip
                    formatter={(v, name) => [v, name]}
                    labelFormatter={(_, payload) => payload?.[0]?.payload?.full || ''}
                />
                <Legend formatter={(v) => <span className="text-sm text-stone-700 dark:text-wa-text">{v}</span>} />
                {series.map((s) => (
                    <Area
                        key={s.key}
                        type="linear"
                        dataKey={s.key}
                        name={s.name}
                        stroke={s.color}
                        strokeWidth={2.5}
                        fill={trendFill(s.key, s.color)}
                        fillOpacity={s.key === 'ingreso' || s.key === 'entrega' ? 1 : 0.15}
                        dot={{ r: 3, fill: s.color, strokeWidth: 1, stroke: '#fff' }}
                        activeDot={{ r: 5 }}
                        isAnimationActive={animate}
                    >
                        <LabelList dataKey={s.key} position="top" fontSize={11} fill="#8696a0" formatter={(v) => (Number(v) > 0 ? v : '')} />
                    </Area>
                ))}
            </RArea>
        </ResponsiveContainer>
    );
}

const KPI_COLORS = {    blue: 'bg-primary-700',
    green: 'bg-green-600',
    purple: 'bg-purple-600',
    orange: 'bg-orange-500',
};

export function KpiCard({ title, value, color = 'blue', trend, icon: Icon }) {
    // Contador animado: interpola desde el valor anterior al nuevo.
    const [display, setDisplay] = useState(0);
    const displayRef = useRef(0);
    useEffect(() => {
        const to = Number(value) || 0;
        const from = displayRef.current;
        if (from === to) {
            setDisplay(to);
            return;
        }
        if (window.matchMedia?.('(prefers-reduced-motion: reduce)').matches) {
            displayRef.current = to;
            setDisplay(to);
            return;
        }
        let raf;
        const t0 = performance.now();
        const dur = 900;
        const tick = (t) => {
            const p = Math.min(1, (t - t0) / dur);
            const v = Math.round(from + (to - from) * (1 - Math.pow(1 - p, 3)));
            displayRef.current = v;
            setDisplay(v);
            if (p < 1) raf = requestAnimationFrame(tick);
        };
        raf = requestAnimationFrame(tick);
        return () => cancelAnimationFrame(raf);
    }, [value]);
    return (
        <div className="card p-4">
            <div className="flex items-center gap-3">
                <span className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-lg ${KPI_COLORS[color]}`}>
                    {Icon && <Icon className="h-6 w-6 text-white" />}
                </span>
                <div>
                    <div className="text-xs text-stone-500 dark:text-wa-muted">{title}</div>
                    <div className="text-2xl font-bold tabular-nums">{display}</div>
                    {trend !== undefined && trend !== null && <div className="text-xs text-stone-500 dark:text-wa-muted">{trend}% vs previo</div>}
                </div>
            </div>
        </div>
    );
}
