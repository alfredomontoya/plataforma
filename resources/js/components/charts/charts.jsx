import { Cell, Pie, PieChart as RPie, ResponsiveContainer, BarChart as RBar, Bar, XAxis, YAxis, Tooltip, CartesianGrid } from 'recharts';

const CATEGORICAL = ['#991b1b', '#dc2626', '#b91c1c', '#7f1d1d', '#f87171', '#fca5a5', '#78350a', '#9a3412', '#b45309', '#92400e', '#451a03', '#78716c'];

export function PieChart({ data, height = 260 }) {
    const total = data.datasets[0].data.reduce((a, b) => a + b, 0) || 1;
    const rows = data.labels.map((label, i) => ({ name: label, value: data.datasets[0].data[i] || 0 }));
    return (
        <ResponsiveContainer width="100%" height={height}>
            <RPie>
                <Pie data={rows} dataKey="value" nameKey="name" outerRadius={90} label={({ name, value }) => `${name} ${((value / total) * 100).toFixed(1)}%`}>
                    {rows.map((_, i) => (
                        <Cell key={i} fill={CATEGORICAL[i % CATEGORICAL.length]} />
                    ))}
                </Pie>
                <Tooltip formatter={(v) => [v, 'Cantidad']} />
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
                {datasets.map((ds) => (
                    <Bar key={ds.label} dataKey={ds.label} fill={ds.color} />
                ))}
            </RBar>
        </ResponsiveContainer>
    );
}

const KPI_COLORS = {
    blue: 'bg-primary-700',
    green: 'bg-green-600',
    purple: 'bg-purple-600',
    orange: 'bg-orange-500',
};

export function KpiCard({ title, value, color = 'blue', trend }) {
    return (
        <div className="card p-4">
            <div className="flex items-center gap-3">
                <span className={`h-10 w-10 rounded-lg ${KPI_COLORS[color]} shrink-0`} />
                <div>
                    <div className="text-xs text-stone-500">{title}</div>
                    <div className="text-2xl font-bold">{value}</div>
                    {trend !== undefined && trend !== null && <div className="text-xs text-stone-500">{trend}% vs previo</div>}
                </div>
            </div>
        </div>
    );
}
