import { useEffect, useRef, useState } from 'react';
import api, { todayLocal } from '../lib/api';
import { useAuth } from '../lib/auth';
import { matchOperator, parseTotalText } from '../lib/totalText';
import { RoleGuard } from '../components/layout/RoleGuard';
import { Button, Input } from '../components/ui/controls';
import { useToast } from '../components/ui/Toast';

export default function TotalEntry() {
    const { user } = useAuth();
    const { toastSuccess, toastError } = useToast();
    const [date, setDate] = useState(todayLocal());
    const [catalog, setCatalog] = useState([]);
    const [qty, setQty] = useState({});
    const [filter, setFilter] = useState('');
    const [saving, setSaving] = useState(false);
    const [registered, setRegistered] = useState([]);
    const [loading, setLoading] = useState(false);
    const [paste, setPaste] = useState('');
    const [parseInfo, setParseInfo] = useState(null);
    const [operators, setOperators] = useState([]);
    // Al pegar, el cambio de fecha NO debe recargar desde la BD (borraría lo pegado).
    const skipMeReload = useRef(false);

    useEffect(() => {
        api.get('/totals/catalog')
            .then((r) => setCatalog(r.data.data ?? []))
            .catch(() => toastError('No se pudo cargar el catálogo de trámites.'));
        api.get('/totals/operators')
            .then((r) => setOperators(r.data.data ?? []))
            .catch(() => setOperators([]));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    useEffect(() => {
        if (!user) return;
        if (skipMeReload.current) {
            skipMeReload.current = false;
            setLoading(false);
            return;
        }
        let cancelled = false;
        setLoading(true);
        api.get('/totals/me', { params: { date } })
            .then((r) => {
                if (cancelled) return;
                const rows = r.data?.data ?? [];
                setRegistered(rows);
                setQty(Object.fromEntries(rows.map((e) => [e.serviceId, e.quantity])));
            })
            .catch(() => {
                if (!cancelled) {
                    setRegistered([]);
                    setQty({});
                    toastError('No se pudo cargar el registro guardado.');
                }
            })
            .finally(() => {
                if (!cancelled) setLoading(false);
            });
        return () => { cancelled = true; };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [date, user?.id]);

    const maxDate = user?.canBackfill ? undefined : todayLocal();

    const [processing, setProcessing] = useState(false);

    const processPaste = async () => {
        if (!paste.trim()) {
            toastError('Pega primero el texto del parte.');
            return;
        }
        if (!catalog.length) {
            toastError('Aún no cargó el catálogo, intenta de nuevo.');
            return;
        }
        setProcessing(true);
        try {
            const res = parseTotalText(paste, catalog);
            if (!res.items.length && !res.unmatched.length && !res.date) {
                toastError('No se reconoció el formato del texto.');
                return;
            }
            // Lo no registrado se REGISTRA como servicio de ingreso al procesar.
            let created = [];
            let workingCatalog = catalog;
            if (res.unmatched.length) {
                try {
                    const r = await api.post('/totals/resolve', { names: res.unmatched.map((u) => u.name) });
                    const resolved = r.data.data?.resolved ?? [];
                    if (r.data.data?.catalog) {
                        workingCatalog = r.data.data.catalog;
                        setCatalog(workingCatalog);
                    }
                    const byName = Object.fromEntries(resolved.map((x) => [x.name, x]));
                    for (const u of res.unmatched) {
                        const hit = byName[u.name];
                        if (hit) {
                            res.items.push({ tramite: hit.name, quantity: u.quantity, ...(hit.created ? { nuevo: true } : {}) });
                            if (hit.created) created.push(hit);
                        }
                    }
                    res.unmatched = res.unmatched.filter((u) => !byName[u.name]);
                    res.suma = res.items.reduce((a, i) => a + i.quantity, 0);
                } catch {
                    toastError('No se pudieron registrar los servicios nuevos; revísalos abajo.');
                }
            }
            const idByName = Object.fromEntries(workingCatalog.map((c) => [c.tramite, c.serviceId]));
            if (res.date && res.date !== date) skipMeReload.current = true;
            if (res.date) setDate(res.date);
            setQty(Object.fromEntries(
                res.items.filter((i) => idByName[i.tramite]).map((i) => [idByName[i.tramite], i.quantity])
            ));
            setRegistered([]);
            const match = matchOperator(res.supervisor, operators);
            setParseInfo({ ...res, match, created });
            const bits = [`${res.items.length} filas`];
            if (created.length) bits.push(`${created.length} servicios nuevos`);
            if (res.date) bits.push(`fecha ${res.date}`);
            toastSuccess(`Texto procesado: ${bits.join(', ')}. Revisa y guarda.`);
        } finally {
            setProcessing(false);
        }
    };

    const save = async () => {
        const items = catalog
            .map((c) => ({ serviceId: c.serviceId, quantity: Number(qty[c.serviceId] ?? 0) }))
            .filter((i) => i.quantity > 0);
        if (items.length === 0) {
            toastError('Ingrese al menos un trámite con cantidad mayor a cero.');
            return;
        }
        setSaving(true);
        try {
            await api.post('/totals', { date, items });
            const r = await api.get('/totals/me', { params: { date } });
            const rows = r.data?.data ?? [];
            setRegistered(rows);
            setQty(Object.fromEntries(rows.map((e) => [e.serviceId, e.quantity])));
            const sum = items.reduce((a, i) => a + Number(i.quantity || 0), 0);
            toastSuccess({
                title: 'Registro guardado',
                description: `${items.length} trámite(s) · total ${sum} · fecha ${date}.`,
            });
        } catch (e) {
            toastError(e.response?.data?.error || 'Error al guardar.');
        } finally {
            setSaving(false);
        }
    };

    const total = registered.reduce((acc, e) => acc + Number(e.quantity ?? 0), 0);
    const needle = filter.trim().toLowerCase();
    const visible = needle
        ? catalog.filter((c) => `${c.nro} ${c.tramite}`.toLowerCase().includes(needle))
        : catalog;

    return (
        <RoleGuard allowed={['OPERATOR_INGRESO', 'ADMIN']}>
            <div className="space-y-4">
                <div className="card space-y-3 p-5">
                    <h1 className="text-lg font-semibold">Ingreso diario total</h1>
                    <label className="block text-sm font-medium">Pegar parte diario</label>
                    <textarea
                        value={paste}
                        onChange={(e) => setPaste(e.target.value)}
                        rows={7}
                        spellCheck={false}
                        placeholder={'Desde Fecha: 14/09/2026 Hasta Fecha: 14/09/2026\nNombre Supervisor: APELLIDOS NOMBRES\n\nN°\tTipo Trámite\tCantidad\n1\tTRANSFERENCIA NORMAL\t45\n…\nTOTAL INGRESADOS: 116'}
                        className="w-full rounded-md border border-stone-300 px-3 py-2 font-mono text-xs dark:border-white/10 dark:bg-wa-header"
                    />
                    <div className="flex gap-2">
                        <Button size="sm" variant="secondary" loading={processing} onClick={processPaste}>Procesar texto</Button>
                        <Button size="sm" variant="secondary" onClick={() => { setPaste(''); setParseInfo(null); }}>Limpiar</Button>
                    </div>
                    {parseInfo && (
                        <div className="space-y-1 rounded-lg bg-stone-100 p-3 text-[13px] dark:bg-white/5">
                            <div>Fecha detectada: <strong>{parseInfo.date || '—'}</strong>
                                {parseInfo.dateEnd && parseInfo.dateEnd !== parseInfo.date && (
                                    <span className="text-amber-600"> (el parte llega hasta {parseInfo.dateEnd}; se toma Desde)</span>
                                )}
                            </div>
                            <div>Supervisor: <strong>{parseInfo.supervisor || '—'}</strong>
                                {parseInfo.supervisor && (
                                    parseInfo.match
                                        ? <span> → operador <strong>{parseInfo.match.operator.username}</strong></span>
                                        : <span className="text-amber-600"> (sin coincidencia; se guarda a tu nombre)</span>
                                )}
                            </div>
                            <div>Filas aplicadas: <strong>{parseInfo.items.length}</strong>
                                {parseInfo.items.some((i) => i.aprox) && (
                                    <span> (incluye aproximadas: {parseInfo.items.filter((i) => i.aprox).map((i) => i.aprox).join(', ')})</span>
                                )}
                            </div>
                            {parseInfo.created?.length > 0 && (
                                <div className="text-green-600">
                                    <div>Se registraron {parseInfo.created.length} servicio(s) nuevo(s):</div>
                                    <ul className="list-disc pl-5">
                                        {parseInfo.created.map((s) => (
                                            <li key={s.serviceId}>
                                                {s.name}
                                                {s.codigo && <span> · código {s.codigo}</span>}
                                                {s.abreviation && <span> · abrev. {s.abreviation}</span>}
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            )}
                            {parseInfo.unmatched.length > 0 && (
                                <div className="text-amber-600">No registradas ({parseInfo.unmatched.length}): {parseInfo.unmatched.map((u) => u.raw).join(' | ')}</div>
                            )}
                            {parseInfo.totalDeclarado !== null && (
                                parseInfo.suma === parseInfo.totalDeclarado
                                    ? <div className="text-green-600">Suma {parseInfo.suma} = TOTAL INGRESADOS ✓</div>
                                    : <div className="text-red-600">Suma {parseInfo.suma} ≠ TOTAL INGRESADOS ({parseInfo.totalDeclarado}). Revisa.</div>
                            )}
                        </div>
                    )}
                </div>
                <div className="card space-y-4 p-5">
                    <h2 className="text-sm font-semibold">Detalle por trámite</h2>
                    <div className="flex flex-col gap-3 sm:flex-row sm:items-end">
                        <div className="sm:w-52 sm:shrink-0">
                            <Input type="date" label="Fecha" value={date} max={maxDate} onChange={(e) => setDate(e.target.value)} />
                        </div>
                        <div className="sm:flex-1">
                            <Input
                                type="search"
                                label="Filtrar trámites"
                                placeholder="Número o nombre…"
                                value={filter}
                                onChange={(e) => setFilter(e.target.value)}
                            />
                        </div>
                    </div>
                    <div className="overflow-auto">
                        <table className="table table-compact">
                            <thead><tr><th className="w-10">N°</th><th>Tipo trámite</th><th className="w-24 text-right">Cant.</th></tr></thead>
                            <tbody>
                                {visible.length === 0 ? (
                                    <tr><td colSpan={3} className="text-xs text-stone-500 dark:text-wa-muted">Sin coincidencias.</td></tr>
                                ) : visible.map((c) => (
                                    <tr key={c.serviceId}>
                                        <td className="tabular-nums">{c.nro}</td>
                                        <td className="leading-tight">{c.tramite}</td>
                                        <td className="text-right">
                                            <input
                                                type="number"
                                                min={0}
                                                disabled={loading}
                                                value={qty[c.serviceId] ?? 0}
                                                onChange={(e) => setQty({ ...qty, [c.serviceId]: e.target.value })}
                                                className="w-16 rounded border border-stone-300 px-1.5 py-0 text-right text-xs disabled:opacity-50 dark:border-white/10 dark:bg-wa-header"
                                            />
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <Button onClick={save} loading={saving || loading}>
                        Guardar
                    </Button>
                </div>
                <div className="card space-y-3 p-5">
                    <h2 className="text-sm font-semibold">Registrado · {date} · Total: {total}</h2>
                    {loading ? (
                        <p className="text-sm text-stone-500 dark:text-wa-muted">Cargando…</p>
                    ) : registered.length === 0 ? (
                        <p className="text-sm text-stone-500 dark:text-wa-muted">No hay registros para esta fecha.</p>
                    ) : (
                        <div className="overflow-hidden rounded-lg border border-stone-200 dark:border-white/10">
                            <div className="px-3 py-1">
                                {registered.map((e) => (
                                    <div key={e.id ?? e.serviceId} className="flex items-center justify-between gap-2 border-b border-stone-100 py-1 text-[13px] last:border-0 dark:border-white/5">
                                        <span className="truncate leading-tight">{e.service?.name ?? e.serviceId}</span>
                                        <span className="shrink-0 font-medium tabular-nums">{e.quantity}</span>
                                    </div>
                                ))}
                                <div className="flex items-center justify-between gap-2 py-1 text-[13px] font-semibold">
                                    <span>Total</span>
                                    <span className="tabular-nums">{total}</span>
                                </div>
                            </div>
                        </div>
                    )}
                </div>
            </div>
        </RoleGuard>
    );
}
