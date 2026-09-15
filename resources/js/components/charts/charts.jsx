import { Cell, Pie, PieChart as RPie, ResponsiveContainer, BarChart as RBar, Bar, XAxis, YAxis, Tooltip, CartesianGrid, Legend, LabelList, AreaChart as RArea, Area } from 'recharts';

const CATEGORICAL = ['#f71f3d', '#2563eb', '#16a34a', '#d97706', '#9333ea', '#0d9488', '#ec4899', '#ea580c', '#0891b2', '#65a30d', '#4f46e5', '#78716c'];

export function PieChart({ data, height = 340 }) {
    const values = data.datasets[0].data.map((v) => Number(v) || 0);
    const total = values.reduce((a, b) => a + b, 0) || 1;
    const rows = data.labels.map((label, i) => ({ name: label, value: values[i] || 0 }));
    const legend = data.legendLabels || data.labels;
    const pctLabel = (p) => `${p.name} ${((p.value / total) * 100).toFixed(1)}%`;
    return (
        <ResponsiveContainer width="100%" height={height}>
            <RPie>
                <Pie data={rows} dataKey="value" nameKey="name" outerRadius={120} label={pctLabel}>
                    {rows.map((_, i) => (
                        <Cell key={i} fill={CATEGORICAL[i % CATEGORICAL.length]} />
                    ))}
                </Pie>
                <Tooltip formatter={(v) => [v, 'Cantidad']} />
                <Legend
                    layout="vertical"
                    align="right"
                    verticalAlign="middle"
                    formatter={(v, entry, index) => {
                        const pct = ((Number(entry?.payload?.value) || 0) / total) * 100;
                        const label = legend[typeof index === 'number' ? index : 0] || v;
                        return <span className="text-sm text-stone-700 dark:text-wa-text">{label} ({pct.toFixed(1)}%)</span>;
                    }}
                />
            </RPie>
        </ResponsiveContainer>
    );
}

export function BarChart({ labels, datasets, height }) {
    const rows = labels.map((label, i) => {
        const row = { name: label };
        datasets.forEach((ds) => {
            row[ds.label] = ds.data[i] || 0;
        });
        return row;
    });
    return (
        <ResponsiveContainer width="100%" height={height || labels.length * 40 + 60}>
            <RBar data={rows} layout="vertical" maxBarSize={30}>
                <CartesianGrid strokeDasharray="3 3" />
                <XAxis type="number" />
                <YAxis type="category" dataKey="name" width={140} />
                <Tooltip />
                {datasets.length > 1 && (
                    <Legend formatter={(v) => <span className="text-sm text-stone-700 dark:text-wa-text">{v}</span>} />
                )}
                {datasets.map((ds) => (
                    <Bar key={ds.label} dataKey={ds.label} fill={ds.color} stackId={ds.stackId}>
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

export function TrendChart({ points, height = 300 }) {
    return (
        <ResponsiveContainer width="100%" height={height}>
            <RArea data={points} margin={{ top: 8, right: 8, bottom: 0, left: -12 }}>
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
                <XAxis dataKey="day" tick={{ fontSize: 12 }} interval={Math.max(0, Math.floor(points.length / 16))} />
                <YAxis allowDecimals={false} tick={{ fontSize: 12 }} />
                <Tooltip
                    formatter={(v, name) => [v, name]}
                    labelFormatter={(_, payload) => payload?.[0]?.payload?.full || ''}
                />
                <Legend formatter={(v) => <span className="text-sm text-stone-700 dark:text-wa-text">{v}</span>} />
                <Area type="linear" dataKey="ingreso" name="Ingreso" stroke="#2563eb" strokeWidth={2.5} fill="url(#trendIng)" dot={{ r: 3, fill: '#2563eb', strokeWidth: 1, stroke: '#fff' }} activeDot={{ r: 5 }}>
                    <LabelList dataKey="ingreso" position="top" fontSize={11} fill="#8696a0" formatter={(v) => (Number(v) > 0 ? v : '')} />
                </Area>
                <Area type="linear" dataKey="entrega" name="Entrega" stroke="#16a34a" strokeWidth={2.5} fill="url(#trendEnt)" dot={{ r: 3, fill: '#16a34a', strokeWidth: 1, stroke: '#fff' }} activeDot={{ r: 5 }}>
                    <LabelList dataKey="entrega" position="top" fontSize={11} fill="#8696a0" formatter={(v) => (Number(v) > 0 ? v : '')} />
                </Area>
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
    return (
        <div className="card p-4">
            <div className="flex items-center gap-3">
                <span className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-lg ${KPI_COLORS[color]}`}>
                    {Icon && <Icon className="h-6 w-6 text-white" />}
                </span>
                <div>
                    <div className="text-xs text-stone-500 dark:text-wa-muted">{title}</div>
                    <div className="text-2xl font-bold">{value}</div>
                    {trend !== undefined && trend !== null && <div className="text-xs text-stone-500 dark:text-wa-muted">{trend}% vs previo</div>}
                </div>
            </div>
        </div>
    );
}
