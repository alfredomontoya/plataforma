import { useEffect, useState } from 'react';
import api, { todayLocal, toLocalDay } from '../lib/api';
import { RoleGuard } from '../components/layout/RoleGuard';
import { Button, Input } from '../components/ui/controls';
import { RangeDatePicker } from '../components/ui/RangeDatePicker';
import { ArrowDownTrayIcon, ArrowUpTrayIcon, Squares2X2Icon, UserGroupIcon } from '@heroicons/react/24/outline';
import { useToast } from '../components/ui/Toast';
import { BarChart, KpiCard, PieChart, TrendChart } from '../components/charts/charts';

const fmtCompact = (d) => {
    const pad = (n) => String(n).padStart(2, '0');
    return `${pad(d.getDate())}/${pad(d.getMonth() + 1)}/${String(d.getFullYear()).slice(2)}`;
};

export default function Dashboard() {
    const { toastError } = useToast();
    const [period, setPeriod] = useState('today');
    const [customFrom, setCustomFrom] = useState(() => {
        const now = new Date();
        const monday = new Date(now.getTime() - (((now.getDay() + 6) % 7) * 86400000));
        return toLocalDay(monday);
    });
    const [customTo, setCustomTo] = useState(todayLocal());
    const [data, setData] = useState(null);
    const [subtitle, setSubtitle] = useState('');
    const [trendMonth, setTrendMonth] = useState(todayLocal().slice(0, 7));
    const [trendFrom, setTrendFrom] = useState(`${todayLocal().slice(0, 7)}-01`);
    const [trendTo, setTrendTo] = useState(todayLocal());
    const [trend, setTrend] = useState([]);

    useEffect(() => {
        const run = async () => {
            try {
                const now = new Date();
                if (period === 'today' || period === 'yesterday') {
                    const d = period === 'today' ? now : new Date(now.getTime() - 86400000);
                    const day = toLocalDay(d);
                    const r = await api.get('/reports/dashboard/summary', { params: { date: day } });
                    setData({ kind: 'day', ...r.data.data });
                    setSubtitle(fmtCompact(d));
                } else if (period === 'week') {
                    const dow = (now.getDay() + 6) % 7;
                    const monday = new Date(now.getTime() - dow * 86400000);
                    const sunday = new Date(monday.getTime() + 6 * 86400000);
                    const r = await api.get('/reports/dashboard/weekly', { params: { weekStart: toLocalDay(monday) } });
                    setData({ kind: 'week', ...r.data.data });
                    setSubtitle(`${fmtCompact(monday)} a ${fmtCompact(sunday)}`);
                } else {
                    if (!customFrom || !customTo || customFrom > customTo) return;
                    const r = await api.get('/reports/dashboard/range', { params: { from: customFrom, to: customTo } });
                    setData({ kind: 'week', ...r.data.data });
                    setSubtitle(`${fmtCompact(new Date(`${customFrom}T12:00:00`))} a ${fmtCompact(new Date(`${customTo}T12:00:00`))}`);
                }
            } catch (err) {
                toastError(err.response?.data?.error || 'No se pudo cargar el dashboard.');
            }
        };
        run();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [period, customFrom, customTo]);

    useEffect(() => {
        if (!trendFrom || !trendTo || trendFrom > trendTo) return;
        api.get('/reports/dashboard/daily', { params: { from: trendFrom, to: trendTo } })
            .then((r) => setTrend(r.data.data))
            .catch(() => toastError('No se pudo cargar la tendencia.'));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [trendFrom, trendTo]);

    const onTrendMonth = (ym) => {
        setTrendMonth(ym);
        if (!/^\d{4}-\d{2}$/.test(ym)) return;
        const [y, m] = ym.split('-').map(Number);
        const last = new Date(y, m, 0).getDate();
        const today = todayLocal();
        const endDay = today.slice(0, 7) === ym ? today.slice(8, 10) : String(last);
        setTrendFrom(`${ym}-01`);
        setTrendTo(`${ym}-${endDay}`);
    };

    if (!data) return <div className="card p-5">Cargando…</div>;

    const isDay = data.kind === 'day';
    const totalIng = data.totalIngreso ?? data.ingreso?.total ?? 0;
    const totalEnt = data.totalEntrega ?? data.entrega?.total ?? 0;
    const ing = isDay ? data.ingreso : (data.ingresoByService || []);
    const ent = isDay ? data.entrega : (data.entregaByService || []);
    const opsIng = (data.operators || []).filter((o) => o.ingreso > 0);
    const opsEnt = (data.operators || []).filter((o) => o.entrega > 0);
    const pie = (items) => ({
        labels: items.map((i) => i.codigo || i.abreviation || i.serviceName),
        legendLabels: items.map((i) => [i.codigo, i.abreviation || i.serviceName].filter(Boolean).join(' – ')),
        datasets: [{ data: items.map((i) => i.total) }],
    });

    return (
        <RoleGuard allowed={['JEFE', 'ADMIN']}>
            <div className="space-y-4">
                <div className="card flex flex-wrap items-center gap-2 p-4">
                    {[
                        { value: 'today', label: 'Hoy' },
                        { value: 'yesterday', label: 'Ayer' },
                        { value: 'week', label: 'Esta Semana' },
                        { value: 'date', label: 'Fecha' },
                    ].map((o) => (
                        <Button key={o.value} size="sm" variant={period === o.value ? 'primary' : 'secondary'} onClick={() => setPeriod(o.value)}>
                            {o.label}
                        </Button>
                    ))}
                    {period === 'date' && (
                        <RangeDatePicker
                            from={customFrom}
                            to={customTo}
                            onChange={(f, t) => { setCustomFrom(f); setCustomTo(t); }}
                        />
                    )}
                    <span className="pb-2 text-sm text-stone-500 dark:text-wa-muted">{subtitle}</span>
                </div>
                <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                    <KpiCard title="Total Ingreso" value={totalIng} color="blue" trend={data.ingreso?.trend} icon={ArrowDownTrayIcon} />
                    <KpiCard title="Total Entrega" value={totalEnt} color="green" trend={data.entrega?.trend} icon={ArrowUpTrayIcon} />
                    <KpiCard title="Total General" value={totalIng + totalEnt} color="purple" icon={Squares2X2Icon} />
                    <KpiCard title="Operadores Activos" value={data.operators?.length || 0} color="orange" icon={UserGroupIcon} />
                </div>
                <div className="grid gap-3 lg:grid-cols-2">
                    <div className="card p-4">
                        <h3 className="mb-2 font-semibold">Distribución Ingreso</h3>
                        {ing.length ? <PieChart data={pie(ing)} /> : <p className="text-sm text-stone-500 dark:text-wa-muted">Sin datos para este periodo.</p>}
                    </div>
                    <div className="card p-4">
                        <h3 className="mb-2 font-semibold">Distribución Entrega</h3>
                        {ent.length ? <PieChart data={pie(ent)} /> : <p className="text-sm text-stone-500 dark:text-wa-muted">Sin datos para este periodo.</p>}
                    </div>
                </div>
                <div className="grid gap-3 lg:grid-cols-2">
                    <div className="card p-4">
                        <h3 className="mb-2 font-semibold">Total por Operador – Ingreso</h3>
                        {opsIng.length ? (
                            <BarChart
                                labels={opsIng.map((o) => o.username)}
                                datasets={[{ label: 'Total', data: opsIng.map((o) => o.ingreso), color: '#2563eb' }]}
                            />
                        ) : <p className="text-sm text-stone-500 dark:text-wa-muted">Sin datos para este periodo.</p>}
                    </div>
                    <div className="card p-4">
                        <h3 className="mb-2 font-semibold">Total por Operador – Entrega</h3>
                        {opsEnt.length ? (
                            <BarChart
                                labels={opsEnt.map((o) => o.username)}
                                datasets={[{ label: 'Total', data: opsEnt.map((o) => o.entrega), color: '#16a34a' }]}
                            />
                        ) : <p className="text-sm text-stone-500 dark:text-wa-muted">Sin datos para este periodo.</p>}
                    </div>
                </div>
                <div className="card space-y-3 p-4">
                    <h3 className="font-semibold">Tendencia por día – Ingreso y Entrega</h3>
                    <div className="flex flex-wrap items-end gap-3">
                        <Input label="Mes" type="month" value={trendMonth} onChange={(e) => onTrendMonth(e.target.value)} />
                        <RangeDatePicker
                            from={trendFrom}
                            to={trendTo}
                            onChange={(f, t) => { setTrendFrom(f); setTrendTo(t); }}
                        />
                    </div>
                    {trend.length ? (
                        <TrendChart points={trend.map((t) => ({ ...t, day: t.date.slice(8), full: t.date.split('-').reverse().join('/') }))} />
                    ) : <p className="text-sm text-stone-500 dark:text-wa-muted">Sin datos para este periodo.</p>}
                </div>
                <div className="card overflow-auto p-4">
                    <table className="table">
                        <thead><tr><th>Operador</th><th>Rol</th><th>Ingreso</th><th>Entrega</th><th>Total</th></tr></thead>
                        <tbody>
                            {(data.operators || []).map((o) => (
                                <tr key={o.userId}>
                                    <td>{o.fullName} ({o.username})</td>
                                    <td><span className={`mr-1 inline-block h-2 w-2 rounded-full ${o.role === 'OPERATOR_INGRESO' ? 'bg-primary-700' : 'bg-green-600'}`} />{o.role}</td>
                                    <td>{o.ingreso}</td>
                                    <td>{o.entrega}</td>
                                    <td>{o.total}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </RoleGuard>
    );
}
