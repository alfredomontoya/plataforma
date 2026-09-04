import { useEffect, useState } from 'react';
import api, { todayLocal, toLocalDay } from '../lib/api';
import { RoleGuard } from '../components/layout/RoleGuard';
import { DatePicker, Select } from '../components/ui/controls';
import { useToast } from '../components/ui/Toast';
import { BarChart, KpiCard, PieChart } from '../components/charts/charts';

const fmtCompact = (d) => {
    const pad = (n) => String(n).padStart(2, '0');
    return `${pad(d.getDate())}/${pad(d.getMonth() + 1)}/${String(d.getFullYear()).slice(2)}`;
};

export default function Dashboard() {
    const { toastError } = useToast();
    const [period, setPeriod] = useState('today');
    const [customDate, setCustomDate] = useState(todayLocal());
    const [data, setData] = useState(null);
    const [subtitle, setSubtitle] = useState('');

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
                } else {
                    const base = period === 'week' ? now : new Date(`${customDate}T12:00:00`);
                    const dow = (base.getDay() + 6) % 7;
                    const monday = new Date(base.getTime() - dow * 86400000);
                    const sunday = new Date(monday.getTime() + 6 * 86400000);
                    const r = await api.get('/reports/dashboard/weekly', { params: { weekStart: toLocalDay(monday) } });
                    setData({ kind: 'week', ...r.data.data });
                    setSubtitle(`${fmtCompact(monday)} a ${fmtCompact(sunday)}`);
                }
            } catch {
                toastError('No se pudo cargar el dashboard.');
            }
        };
        run();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [period, customDate]);

    if (!data) return <div className="card p-5">Cargando…</div>;

    const isDay = data.kind === 'day';
    const totalIng = data.totalIngreso ?? data.ingreso?.total ?? 0;
    const totalEnt = data.totalEntrega ?? data.entrega?.total ?? 0;
    const ing = isDay ? data.ingreso : [];
    const ent = isDay ? data.entrega : [];
    const pie = (items) => ({
        labels: items.map((i) => i.abreviation || i.serviceName),
        datasets: [{ data: items.map((i) => i.total) }],
    });

    return (
        <RoleGuard allowed={['JEFE', 'ADMIN']}>
            <div className="space-y-4">
                <div className="card flex flex-wrap items-end gap-3 p-4">
                    <Select label="Periodo" value={period} onChange={(e) => setPeriod(e.target.value)} options={[{ value: 'today', label: 'Hoy' }, { value: 'yesterday', label: 'Ayer' }, { value: 'week', label: 'Esta Semana' }, { value: 'date', label: 'Fecha' }]} />
                    {period === 'date' && <DatePicker label="Fecha" value={customDate} onChange={setCustomDate} />}
                    <span className="pb-2 text-sm text-stone-500">{subtitle}</span>
                </div>
                <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                    <KpiCard title="Total Ingreso" value={totalIng} color="blue" trend={data.ingreso?.trend} />
                    <KpiCard title="Total Entrega" value={totalEnt} color="green" trend={data.entrega?.trend} />
                    <KpiCard title="Total General" value={totalIng + totalEnt} color="purple" />
                    <KpiCard title="Operadores Activos" value={data.operators?.length || 0} color="orange" />
                </div>
                {isDay && (
                    <div className="grid gap-3 lg:grid-cols-2">
                        <div className="card p-4">
                            <h3 className="mb-2 font-semibold">Distribución Ingreso</h3>
                            {ing.length ? <PieChart data={pie(ing)} /> : <p className="text-sm text-stone-500">Sin datos para este periodo.</p>}
                        </div>
                        <div className="card p-4">
                            <h3 className="mb-2 font-semibold">Distribución Entrega</h3>
                            {ent.length ? <PieChart data={pie(ent)} /> : <p className="text-sm text-stone-500">Sin datos para este periodo.</p>}
                        </div>
                    </div>
                )}
                <div className="card p-4">
                    <h3 className="mb-2 font-semibold">Total por Operador</h3>
                    <BarChart
                        labels={(data.operators || []).map((o) => o.username)}
                        datasets={[{ label: 'Total', data: (data.operators || []).map((o) => o.total), color: '#991b1b' }]}
                    />
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
