import { useEffect, useState } from 'react';
import api, { todayLocal } from '../lib/api';
import { useAuth } from '../lib/auth';
import { RoleGuard } from '../components/layout/RoleGuard';
import { Button, Input } from '../components/ui/controls';
import { useToast } from '../components/ui/Toast';

export default function Entry() {
    const { user } = useAuth();
    const { toastSuccess, toastError } = useToast();
    const [date, setDate] = useState(todayLocal());
    const [tab, setTab] = useState('INGRESO');
    const [services, setServices] = useState([]);
    const [qty, setQty] = useState({});
    const [filter, setFilter] = useState('');
    const [saving, setSaving] = useState(false);
    const [registered, setRegistered] = useState([]);
    const [loadingEntries, setLoadingEntries] = useState(false);

    const types = user?.role === 'ADMIN' ? ['INGRESO', 'ENTREGA'] : [user?.role === 'OPERATOR_ENTREGA' ? 'ENTREGA' : 'INGRESO'];
    useEffect(() => {
        setTab(types[0]);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [user?.role]);

    useEffect(() => {
        api.get(`/services/type/${tab}`).then((r) => {
            const list = [...(r.data.data ?? [])].sort((a, b) => a.name.localeCompare(b.name, 'es'));
            setServices(list);
            setFilter('');
        }).catch(() => toastError('No se pudo cargar servicios.'));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [tab]);

    useEffect(() => {
        if (!user) return;
        let cancelled = false;
        setLoadingEntries(true);
        api.get('/entries/me', { params: { date, limit: 100 } })
            .then((r) => {
                if (cancelled) return;
                setRegistered(r.data?.data ?? []);
            })
            .catch(() => {
                if (!cancelled) {
                    setRegistered([]);
                    toastError('No se pudo cargar el registro guardado.');
                }
            })
            .finally(() => {
                if (!cancelled) setLoadingEntries(false);
            });
        return () => { cancelled = true; };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [date, user?.id]);

    useEffect(() => {
        const byService = Object.fromEntries(
            registered.filter((e) => e.type === tab).map((e) => [e.serviceId, e.quantity]),
        );
        setQty(byService);
    }, [registered, tab, services]);

    const maxDate = user?.canBackfill ? undefined : todayLocal();

    const save = async () => {
        const items = services
            .map((s) => ({ serviceId: s.id, quantity: Number(qty[s.id] ?? 0) }))
            .filter((i) => i.quantity > 0);
        if (items.length === 0) {
            toastError('Ingrese al menos un servicio con cantidad mayor a cero.');
            return;
        }
        setSaving(true);
        try {
            await api.post('/entries', { date, type: tab, items });
            const r = await api.get('/entries/me', { params: { date, limit: 100 } });
            setRegistered(r.data?.data ?? []);
            toastSuccess('Registro guardado.');
        } catch (e) {
            toastError(e.response?.data?.error || 'Error al guardar.');
        } finally {
            setSaving(false);
        }
    };

    const entryName = (e) => e.service?.name ?? e.serviceName ?? e.serviceId;
    const tables = types.map((t) => {
        const rows = registered.filter((e) => e.type === t && Number(e.quantity ?? 0) > 0);
        const total = rows.reduce((acc, e) => acc + Number(e.quantity ?? 0), 0);
        return { type: t, rows, total };
    });
    const hasRegistered = tables.some(({ rows }) => rows.length > 0);
    const needle = filter.trim().toLowerCase();
    const visibleServices = needle
        ? services.filter((s) => `${s.name} ${s.abreviation ?? ''}`.toLowerCase().includes(needle))
        : services;

    return (
        <RoleGuard allowed={['OPERATOR_INGRESO', 'OPERATOR_ENTREGA', 'ADMIN']}>
            <div className="space-y-4">
            <div className="card space-y-4 p-5">
                <h1 className="text-lg font-semibold">Registro diario</h1>
                <div className="flex gap-2">
                    {types.map((t) => (
                        <Button key={t} variant={tab === t ? 'primary' : 'secondary'} size="sm" onClick={() => setTab(t)}>
                            {t === 'INGRESO' ? 'Ingreso' : 'Entrega'}
                        </Button>
                    ))}
                </div>
                <div className="flex flex-col gap-3 sm:flex-row sm:items-end">
                    <div className="sm:w-52 sm:shrink-0">
                        <Input type="date" label="Fecha" value={date} max={maxDate} onChange={(e) => setDate(e.target.value)} />
                    </div>
                    <div className="sm:flex-1">
                        <Input
                            type="search"
                            label="Filtrar servicios"
                            placeholder="Nombre o abreviatura…"
                            value={filter}
                            onChange={(e) => setFilter(e.target.value)}
                        />
                    </div>
                </div>
                <div className="grid grid-cols-1 gap-x-6 gap-y-0.5 md:grid-cols-2 xl:grid-cols-3">
                    {visibleServices.length === 0 ? (
                        <p className="text-sm text-stone-500">Sin coincidencias.</p>
                    ) : visibleServices.map((s) => (
                        <div key={s.id} className="flex items-center justify-between gap-2 border-b border-stone-100 py-1 dark:border-white/5">
                            <span className="truncate text-[13px] leading-tight">{s.name}</span>
                            <input
                                type="number"
                                min={0}
                                disabled={loadingEntries}
                                value={qty[s.id] ?? 0}
                                onChange={(e) => setQty({ ...qty, [s.id]: e.target.value })}
                                className="w-20 shrink-0 rounded-md border border-stone-300 px-2 py-0.5 text-right text-sm disabled:opacity-50 dark:border-white/10 dark:bg-white/5"
                            />
                        </div>
                    ))}
                </div>
                <Button onClick={save} loading={saving || loadingEntries}>
                    Guardar
                </Button>
            </div>
            <div className="card space-y-3 p-5">
                <h2 className="text-sm font-semibold">Registrado · {date}</h2>
                {loadingEntries ? (
                    <p className="text-sm text-stone-500">Cargando…</p>
                ) : !hasRegistered ? (
                    <p className="text-sm text-stone-500">No hay registros para esta fecha.</p>
                ) : (
                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                        {tables.map(({ type, rows, total }) => (
                            <div key={type} className="overflow-hidden rounded-lg border border-stone-200 dark:border-white/10">
                                <div className="flex items-center justify-between bg-stone-100 px-3 py-1.5 text-[13px] font-semibold dark:bg-white/5">
                                    <span>{type === 'INGRESO' ? 'Ingreso' : 'Entrega'}</span>
                                    <span>Total: {total}</span>
                                </div>
                                {rows.length === 0 ? (
                                    <p className="px-3 py-2 text-[13px] text-stone-500">No hay registros para esta fecha.</p>
                                ) : (
                                    <div className="px-3 py-1">
                                        {rows.map((e) => (
                                            <div key={e.id ?? e.serviceId} className="flex items-center justify-between gap-2 border-b border-stone-100 py-1 text-[13px] last:border-0 dark:border-white/5">
                                                <span className="truncate leading-tight">{entryName(e)}</span>
                                                <span className="shrink-0 font-medium tabular-nums">{e.quantity}</span>
                                            </div>
                                        ))}
                                        <div className="flex items-center justify-between gap-2 py-1 text-[13px] font-semibold">
                                            <span>Total</span>
                                            <span className="tabular-nums">{total}</span>
                                        </div>
                                    </div>
                                )}
                            </div>
                        ))}
                    </div>
                )}
            </div>
            </div>
        </RoleGuard>
    );
}
