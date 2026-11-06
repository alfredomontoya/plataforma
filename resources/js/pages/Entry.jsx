import { useEffect, useMemo, useState } from 'react';
import { ArrowDownTrayIcon, ChevronDownIcon, PencilSquareIcon, PlusIcon, TrashIcon } from '@heroicons/react/24/outline';
import api, { todayLocal } from '../lib/api';
import { useAuth } from '../lib/auth';
import { saveBlob } from '../lib/reportShared';
import { RoleGuard } from '../components/layout/RoleGuard';
import { Combobox } from '../components/ui/Combobox';
import { Button, Input, Modal, SearchInput, Select } from '../components/ui/controls';
import { Pagination, PAGE_SIZE_OPTIONS } from '../components/ui/Pagination';
import { RangeDatePicker } from '../components/ui/RangeDatePicker';
import { useToast } from '../components/ui/Toast';

const EMPTY = { date: '', serviceId: '', placa: '', ticket: '', estado: 'procesado', observaciones: '', activo: true };

const TICKET_ROLES = ['OPERATOR_INGRESO', 'OPERATOR_ENTREGA'];

const ESTADO_OPTIONS = [
    { value: 'procesado', label: 'Procesado' },
    { value: 'recepcionado', label: 'Recepcionado' },
];

const PAGE_SIZE_KEY = 'tramites.pageSize';
const DEFAULT_PAGE_SIZE = 20;

const storedPageSize = () => {
    try {
        const stored = Number(window.localStorage.getItem(PAGE_SIZE_KEY));
        return PAGE_SIZE_OPTIONS.includes(stored) ? stored : DEFAULT_PAGE_SIZE;
    } catch {
        return DEFAULT_PAGE_SIZE;
    }
};

const PLACA_RE = /^\d{3,4}[A-Z]{3}$/;
const POLIZA_RE = /^\d{5,10}$/;
const PLACA_MESSAGE = 'Formato inválido. Placa: 3 o 4 dígitos y 3 letras (ej. 123ABC o 1234ABC). Póliza: de 5 a 10 dígitos.';

const sanitizePlaca = (value) => String(value ?? '').toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 10);

const sanitizeTicket = (value) =>
  String(value ?? '')
    .toUpperCase()
    .replace(/[^A-Z0-9-]/g, '')
    .replace(/-/g, (match, offset, string) =>
      string.indexOf('-') === offset ? match : ''
    )
    .slice(0, 8);

const placaKind = (value) => {
    if (PLACA_RE.test(value)) return 'placa';
    if (POLIZA_RE.test(value)) return 'poliza';
    return null;
};

const dayOf = (value) => String(value ?? '').slice(0, 10);

const fmtDate = (value) => {
    const day = dayOf(value);
    if (!/^\d{4}-\d{2}-\d{2}$/.test(day)) return '—';
    const [year, month, date] = day.split('-');
    return `${date}/${month}/${year}`;
};

const fmtTime = (value) => {
    if (!value) return '—';
    const d = new Date(value);
    if (Number.isNaN(d.getTime())) return '—';
    return d.toLocaleTimeString('es-BO', { hour: '2-digit', minute: '2-digit' });
};

const typeLabel = (type) => (type === 'ENTREGA' ? 'Entrega' : 'Ingreso');

const estadoLabel = (estado) => (estado === 'recepcionado' ? 'Recepcionado' : 'Procesado');

const estadoClass = (estado) => (estado === 'recepcionado'
    ? 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-300'
    : 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300');

const userLabel = (u) => {
    if (!u) return '—';
    const name = [u.firstName, u.lastName, u.paternalSurname, u.maternalSurname]
        .filter(Boolean)
        .join(' ')
        .trim();
    return name || u.username || '—';
};

export default function Entry() {
    const { user } = useAuth();
    const { toastSuccess, toastError } = useToast();
    const [from, setFrom] = useState(todayLocal());
    const [to, setTo] = useState(todayLocal());
    const [searchInput, setSearchInput] = useState('');
    const [search, setSearch] = useState('');
    const [records, setRecords] = useState([]);
    const [page, setPage] = useState(1);
    const [limit, setLimit] = useState(storedPageSize);
    const [total, setTotal] = useState(0);
    const [totalPages, setTotalPages] = useState(1);
    const [activos, setActivos] = useState(0);
    const [porTipo, setPorTipo] = useState([]);
    const [refreshKey, setRefreshKey] = useState(0);
    const [catalog, setCatalog] = useState([]);
    const [loading, setLoading] = useState(false);
    const [saving, setSaving] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const [deleteTarget, setDeleteTarget] = useState(null);
    const [summaryOpen, setSummaryOpen] = useState(false);
    const [exporting, setExporting] = useState(null);
    const [modalOpen, setModalOpen] = useState(false);
    const [editing, setEditing] = useState(null);
    const [form, setForm] = useState(EMPTY);
    const [placaTouched, setPlacaTouched] = useState(false);
    const [ticketTouched, setTicketTouched] = useState(false);

    const isPrivileged = ['ADMIN', 'JEFE'].includes(user?.role);
    const ticketRequired = TICKET_ROLES.includes(user?.role);
    const today = todayLocal();

    useEffect(() => {
        api.get('/tramites/catalog')
            .then((r) => setCatalog(r.data?.data ?? []))
            .catch(() => toastError('No se pudo cargar el catálogo de trámites.'));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    // Búsqueda con retardo: al aplicarse vuelve a la primera página.
    useEffect(() => {
        const timer = setTimeout(() => {
            setSearch(searchInput.trim());
            setPage(1);
        }, 350);

        return () => clearTimeout(timer);
    }, [searchInput]);

    useEffect(() => {
        if (!user) return;

        const controller = new AbortController();
        setLoading(true);

        api.get('/tramites', {
            params: {
                from,
                to,
                page,
                limit,
                ...(search ? { search } : {}),
            },
            signal: controller.signal,
        })
            .then((r) => {
                const payload = r.data ?? {};
                const lastPage = Math.max(1, payload.totalPages ?? 1);

                // La página solicitada ya no existe (por ejemplo tras editar).
                if ((payload.page ?? page) > lastPage) {
                    setPage(lastPage);
                    return;
                }

                setRecords(payload.data ?? []);
                setTotal(payload.total ?? 0);
                setTotalPages(lastPage);
                setActivos(payload.activos ?? 0);
                setPorTipo(payload.porTipo ?? []);
            })
            .catch((e) => {
                if (e.code === 'ERR_CANCELED' || e.name === 'CanceledError') return;
                setRecords([]);
                setTotal(0);
                setTotalPages(1);
                setActivos(0);
                setPorTipo([]);
                toastError(e.response?.data?.error || 'No se pudo cargar el registro.');
            })
            .finally(() => {
                if (!controller.signal.aborted) setLoading(false);
            });

        return () => controller.abort();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [from, to, search, page, limit, refreshKey, user?.id]);

    const options = useMemo(
        () => catalog.map((s) => ({
            value: s.id,
            label: `${typeLabel(s.type)} · ${s.name}`,
            description: [s.codigo, s.abreviation].filter(Boolean).join(' · '),
            keywords: `${s.name} ${s.codigo ?? ''} ${s.abreviation ?? ''}`,
        })),
        [catalog],
    );

    const canEdit = (r) => isPrivileged || r.userId === user?.id;
    const maxTipo = porTipo.reduce((max, item) => Math.max(max, item.total), 0) || 1;

    const openCreate = () => {
        setEditing(null);
        setForm({ ...EMPTY, date: today, serviceId: catalog[0]?.id ?? '' });
        setPlacaTouched(false);
        setTicketTouched(false);
        setModalOpen(true);
    };

    const openEdit = (r) => {
        setEditing(r);
        setForm({
            date: dayOf(r.date),
            serviceId: r.serviceId,
            placa: r.placa ?? '',
            ticket: r.ticket ?? '',
            estado: r.estado ?? 'procesado',
            observaciones: r.observaciones ?? '',
            activo: !!r.activo,
        });
        setPlacaTouched(false);
        setTicketTouched(false);
        setModalOpen(true);
    };

    const close = () => {
        if (saving) return;
        setModalOpen(false);
        setEditing(null);
    };

    const rowActions = (record) => {
        if (!canEdit(record)) return null;

        return (
            <div className="flex items-center justify-end gap-3">
                <button
                    type="button"
                    onClick={() => openEdit(record)}
                    className="inline-flex items-center gap-1 text-sm text-primary-700 hover:underline dark:text-primary-400"
                >
                    <PencilSquareIcon className="h-4 w-4" /> Editar
                </button>
                {record.activo && (
                    <button
                        type="button"
                        onClick={() => setDeleteTarget(record)}
                        className="inline-flex items-center gap-1 text-sm text-red-600 hover:underline dark:text-red-400"
                        title="Eliminar trámite"
                    >
                        <TrashIcon className="h-4 w-4" /> Eliminar
                    </button>
                )}
            </div>
        );
    };

    const submit = async (e) => {
        e.preventDefault();

        const placa = sanitizePlaca(form.placa);
        const ticket = sanitizeTicket(form.ticket);
        if (!form.date || !form.serviceId) {
            toastError('Seleccione la fecha y el tipo de trámite.');
            return;
        }
        if (!placa) {
            setPlacaTouched(true);
            toastError('El nro. de placa o póliza es obligatorio.');
            return;
        }
        if (!placaKind(placa)) {
            setPlacaTouched(true);
            toastError(PLACA_MESSAGE);
            return;
        }
        if (ticketRequired && !ticket) {
            setTicketTouched(true);
            toastError('El número de ticket es obligatorio para su rol.');
            return;
        }

        const payload = {
            date: form.date,
            serviceId: form.serviceId,
            placa,
            ticket: ticket || null,
            estado: form.estado || 'procesado',
            observaciones: form.observaciones?.trim() || null,
            activo: !!form.activo,
        };

        setSaving(true);
        try {
            let response;
            if (editing) {
                response = await api.patch(`/tramites/${editing.id}`, payload);
                toastSuccess('Registro actualizado.');
            } else {
                response = await api.post('/tramites', payload);
                toastSuccess('Trámite registrado.');
            }
            if (response?.data?.data?.advertencia) {
                toastError(response.data.data.advertencia);
            }
            setModalOpen(false);
            setEditing(null);

            if (payload.date < from || payload.date > to) {
                // El registro guardado queda fuera del rango visible: saltar a su fecha.
                setFrom(payload.date);
                setTo(payload.date);
                setPage(1);
            } else {
                // Un alta nueva se muestra en la primera página (orden más reciente).
                if (!editing) setPage(1);
                setRefreshKey((key) => key + 1);
            }
        } catch (err) {
            const details = err.response?.data?.details;
            const first = details ? Object.values(details)[0]?.[0] : null;
            toastError(first || err.response?.data?.error || 'No se pudo guardar el registro.');
        } finally {
            setSaving(false);
        }
    };

    const confirmDelete = async () => {
        if (!deleteTarget || deleting) return;

        setDeleting(true);
        try {
            await api.delete(`/tramites/${deleteTarget.id}`);
            toastSuccess('Registro eliminado. Ya no se contabiliza.');
            setDeleteTarget(null);
            setRefreshKey((key) => key + 1);
        } catch (err) {
            toastError(err.response?.data?.error || 'No se pudo eliminar el registro.');
        } finally {
            setDeleting(false);
        }
    };

    const exportFile = async (format) => {
        if (exporting || total === 0) return;

        setExporting(format);
        try {
            const r = await api.get(`/tramites/export/${format}`, {
                params: { from, to, ...(search.trim() ? { search: search.trim() } : {}) },
                responseType: 'blob',
            });
            const extension = format === 'pdf' ? 'pdf' : 'xlsx';
            saveBlob(r.data, `reporte_registro_tramites_${from}_a_${to}.${extension}`);
            toastSuccess(`Reporte ${format === 'pdf' ? 'PDF' : 'Excel'} descargado.`);
        } catch (err) {
            let message = `No se pudo exportar el reporte ${format === 'pdf' ? 'PDF' : 'Excel'}.`;
            try {
                const blob = err.response?.data;
                if (blob instanceof Blob) {
                    const data = JSON.parse(await blob.text());
                    message = data.error || data.message || message;
                }
            } catch {
                /* respuesta sin JSON */
            }
            toastError(message);
        } finally {
            setExporting(null);
        }
    };

    const placaValue = form.placa.trim().toUpperCase();
    const placaError = placaTouched
        ? !placaValue
            ? 'El nro. de placa o póliza es obligatorio.'
            : !placaKind(placaValue)
              ? PLACA_MESSAGE
              : null
        : null;

    const ticketValue = sanitizeTicket(form.ticket);
    const ticketError = ticketTouched && ticketRequired && !ticketValue
        ? 'El número de ticket es obligatorio para su rol.'
        : null;

    const emptyMessage = total === 0
        ? search
            ? 'No hay registros que coincidan con la búsqueda.'
            : 'No hay registros en el rango de fechas seleccionado.'
        : 'No hay registros en esta página.';

    return (
        <RoleGuard allowed={['OPERATOR_INGRESO', 'OPERATOR_ENTREGA', 'ADMIN', 'JEFE']}>
            <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_300px] xl:items-start">
                <div className="card space-y-4 p-4 sm:p-5 xl:col-span-2">
                    <div className="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-end sm:justify-between">
                        <div className="min-w-0">
                            <h1 className="truncate text-lg font-semibold">Registro de trámites</h1>
                            <p className="text-xs text-stone-500 dark:text-wa-muted">
                                {loading
                                    ? 'Cargando…'
                                    : `${total} registro(s) · ${activos} activo(s)`}
                            </p>
                        </div>
                        <div className="grid w-full grid-cols-2 gap-2 sm:flex sm:w-auto sm:flex-wrap sm:items-center">
                            <Button
                                variant="secondary"
                                size="sm"
                                className="w-full sm:w-auto"
                                onClick={() => exportFile('pdf')}
                                disabled={loading || total === 0}
                                loading={exporting === 'pdf'}
                            >
                                {exporting !== 'pdf' && <ArrowDownTrayIcon className="mr-1 h-4 w-4" />} PDF
                            </Button>
                            <Button
                                variant="secondary"
                                size="sm"
                                className="w-full sm:w-auto"
                                onClick={() => exportFile('excel')}
                                disabled={loading || total === 0}
                                loading={exporting === 'excel'}
                            >
                                {exporting !== 'excel' && <ArrowDownTrayIcon className="mr-1 h-4 w-4" />} Excel
                            </Button>
                            <Button
                                className="col-span-2 w-full sm:col-span-1 sm:w-auto"
                                onClick={openCreate}
                                disabled={catalog.length === 0}
                            >
                                <PlusIcon className="mr-1 h-5 w-5" /> Nuevo registro
                            </Button>
                        </div>
                    </div>

                    <div className="grid gap-3 sm:grid-cols-[16rem_minmax(0,1fr)] sm:items-end">
                        <div className="w-full">
                            <span className="mb-1 block text-sm font-medium">Rango de fechas</span>
                            <RangeDatePicker
                                from={from}
                                to={to}
                                maxDate={today}
                                onChange={(start, end) => {
                                    setFrom(start);
                                    setTo(end);
                                    setPage(1);
                                }}
                            />
                        </div>
                        <div className="min-w-0">
                            <span className="mb-1 block text-sm font-medium">Buscar</span>
                            <SearchInput
                                value={searchInput}
                                onChange={(e) => setSearchInput(e.target.value)}
                                onClear={() => setSearchInput('')}
                                placeholder="Tipo de trámite, placa/póliza, ticket, estado, observaciones o usuario…"
                            />
                        </div>
                    </div>
                </div>

                <div className="card overflow-hidden p-0">
                    <div className="hidden overflow-x-auto sm:block">
                        <table className="table min-w-[1000px]" aria-busy={loading}>
                            <thead>
                                <tr>
                                    <th>Fecha</th>
                                    <th>Hora</th>
                                    <th>Tipo de trámite</th>
                                    <th>Nro. placa / póliza</th>
                                    <th>Ticket</th>
                                    <th>Observaciones</th>
                                    {isPrivileged && <th>Usuario</th>}
                                    <th>Estado</th>
                                    <th>Activo</th>
                                    <th className="sticky right-0 z-10 bg-stone-100 dark:bg-wa-header" />
                                </tr>
                            </thead>
                            <tbody className={loading ? 'opacity-60 transition-opacity' : ''}>
                                {loading && records.length === 0 ? (
                                    <tr><td colSpan={isPrivileged ? 10 : 9} className="text-sm text-stone-500 dark:text-wa-muted">Cargando…</td></tr>
                                ) : records.length === 0 ? (
                                    <tr>
                                        <td colSpan={isPrivileged ? 10 : 9} className="text-sm text-stone-500 dark:text-wa-muted">
                                            {total === 0
                                                ? search
                                                    ? 'No hay registros que coincidan con la búsqueda.'
                                                    : 'No hay registros en el rango de fechas seleccionado.'
                                                : 'No hay registros en esta página.'}
                                        </td>
                                    </tr>
                                ) : records.map((r) => (
                                    <tr key={r.id} className="group">
                                        <td className="whitespace-nowrap tabular-nums">{fmtDate(r.date)}</td>
                                        <td className="whitespace-nowrap tabular-nums">{fmtTime(r.created_at)}</td>
                                        <td>
                                            <span className="text-[13px]">{r.service?.name ?? '—'}</span>
                                            <span className={`ml-2 inline-block rounded px-1.5 py-0.5 text-[11px] font-medium ${r.service?.type === 'ENTREGA' ? 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300' : 'bg-primary-100 text-primary-800 dark:bg-primary-900/40 dark:text-primary-300'}`}>
                                                {typeLabel(r.service?.type)}
                                            </span>
                                        </td>
                                        <td className="font-mono text-[13px] uppercase">{r.placa}</td>
                                        <td className="font-mono text-[13px] uppercase">{r.ticket || '—'}</td>
                                        <td className="max-w-xs truncate text-[13px]" title={r.observaciones ?? ''}>{r.observaciones || '—'}</td>
                                        {isPrivileged && (
                                            <td className="text-[13px]">{userLabel(r.user)}</td>
                                        )}
                                        <td>
                                            <span className={`inline-block rounded px-1.5 py-0.5 text-[11px] font-medium ${estadoClass(r.estado)}`}>
                                                {estadoLabel(r.estado)}
                                            </span>
                                        </td>
                                        <td>
                                            <span className={`inline-block rounded px-1.5 py-0.5 text-[11px] font-medium ${r.activo ? 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300' : 'bg-stone-200 text-stone-600 dark:bg-white/10 dark:text-wa-muted'}`}>
                                                {r.activo ? 'Activo' : 'Inactivo'}
                                            </span>
                                        </td>
                                        <td className="sticky right-0 z-10 bg-white text-right group-hover:bg-stone-50 dark:bg-wa-panel dark:group-hover:bg-white/5">
                                            {rowActions(r)}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <div className="space-y-2 p-3 sm:hidden">
                        {loading && records.length === 0 ? (
                            <p className="p-2 text-sm text-stone-500 dark:text-wa-muted">Cargando…</p>
                        ) : records.length === 0 ? (
                            <p className="p-2 text-sm text-stone-500 dark:text-wa-muted">{emptyMessage}</p>
                        ) : records.map((r) => (
                            <article key={r.id} className="rounded-lg border border-stone-200 p-3 dark:border-white/10">
                                <div className="flex items-start justify-between gap-2">
                                    <div className="min-w-0">
                                        <p className="truncate text-[13px] font-medium" title={r.service?.name ?? ''}>
                                            {r.service?.name ?? '—'}
                                        </p>
                                        <p className="mt-0.5 text-[11px] tabular-nums text-stone-500 dark:text-wa-muted">
                                            {fmtDate(r.date)} · {fmtTime(r.created_at)}
                                        </p>
                                    </div>
                                    <span className={`shrink-0 rounded px-1.5 py-0.5 text-[11px] font-medium ${r.activo ? 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300' : 'bg-stone-200 text-stone-600 dark:bg-white/10 dark:text-wa-muted'}`}>
                                        {r.activo ? 'Activo' : 'Inactivo'}
                                    </span>
                                </div>

                                <dl className="mt-2 space-y-1 text-[13px]">
                                    <div className="flex gap-1">
                                        <dt className="text-stone-500 dark:text-wa-muted">Placa/póliza:</dt>
                                        <dd className="font-mono uppercase">{r.placa}</dd>
                                    </div>
                                    {r.ticket && (
                                        <div className="flex gap-1">
                                            <dt className="text-stone-500 dark:text-wa-muted">Ticket:</dt>
                                            <dd className="font-mono uppercase">{r.ticket}</dd>
                                        </div>
                                    )}
                                    {isPrivileged && (
                                        <div className="flex gap-1">
                                            <dt className="text-stone-500 dark:text-wa-muted">Usuario:</dt>
                                            <dd className="min-w-0 truncate">{userLabel(r.user)}</dd>
                                        </div>
                                    )}
                                    {r.observaciones && (
                                        <div className="flex gap-1">
                                            <dt className="shrink-0 text-stone-500 dark:text-wa-muted">Obs.:</dt>
                                            <dd className="line-clamp-2">{r.observaciones}</dd>
                                        </div>
                                    )}
                                </dl>

                                <div className="mt-2 flex items-center justify-between gap-2 border-t border-stone-100 pt-2 dark:border-white/10">
                                    <span className="flex flex-wrap items-center gap-1">
                                        <span className={`inline-block rounded px-1.5 py-0.5 text-[11px] font-medium ${r.service?.type === 'ENTREGA' ? 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300' : 'bg-primary-100 text-primary-800 dark:bg-primary-900/40 dark:text-primary-300'}`}>
                                            {typeLabel(r.service?.type)}
                                        </span>
                                        <span className={`inline-block rounded px-1.5 py-0.5 text-[11px] font-medium ${estadoClass(r.estado)}`}>
                                            {estadoLabel(r.estado)}
                                        </span>
                                    </span>
                                    {rowActions(r)}
                                </div>
                            </article>
                        ))}
                    </div>

                    <Pagination
                        page={page}
                        totalPages={totalPages}
                        total={total}
                        limit={limit}
                        pageSizeOptions={PAGE_SIZE_OPTIONS}
                        disabled={loading}
                        onPageChange={setPage}
                        onLimitChange={(size) => {
                            setLimit(size);
                            setPage(1);
                            try {
                                window.localStorage.setItem(PAGE_SIZE_KEY, String(size));
                            } catch {
                                /* almacenamiento no disponible */
                            }
                        }}
                    />
                </div>

                <aside className="card p-4 xl:sticky xl:top-4">
                    <button
                        type="button"
                        onClick={() => setSummaryOpen((open) => !open)}
                        aria-expanded={summaryOpen}
                        aria-controls="resumen-por-tipo"
                        className="flex w-full items-start justify-between gap-2 text-left xl:pointer-events-none"
                    >
                        <span className="min-w-0">
                            <span className="block text-sm font-semibold">Resumen por tipo</span>
                            <span className="mt-0.5 block text-[11px] text-stone-500 dark:text-wa-muted">
                                Trámites activos según el rango y filtros actuales.
                            </span>
                        </span>
                        <span className="flex shrink-0 items-center gap-2">
                            <span className="badge badge-gray tabular-nums">{activos}</span>
                            <ChevronDownIcon
                                className={`h-4 w-4 text-stone-400 transition-transform xl:hidden ${summaryOpen ? 'rotate-180' : ''}`}
                            />
                        </span>
                    </button>

                    <div id="resumen-por-tipo" className={`${summaryOpen ? 'block' : 'hidden'} xl:block`}>
                        {loading && porTipo.length === 0 ? (
                            <div className="mt-4 space-y-3">
                                {[0, 1, 2].map((i) => (
                                    <div key={i} className="h-9 animate-pulse rounded-lg bg-stone-100 dark:bg-white/5" />
                                ))}
                            </div>
                        ) : porTipo.length === 0 ? (
                            <p className="mt-4 text-xs text-stone-500 dark:text-wa-muted">
                                Sin trámites activos en el periodo.
                            </p>
                        ) : (
                            <ul className="mt-4 space-y-3">
                                {porTipo.map((item) => {
                                    const pct = Math.max(4, Math.round((item.total / maxTipo) * 100));
                                    const barColor = item.type === 'ENTREGA' ? 'bg-green-500' : 'bg-primary-500';

                                    return (
                                        <li key={item.serviceId}>
                                            <div className="flex items-baseline justify-between gap-2">
                                                <div className="min-w-0">
                                                    <p className="truncate text-[13px] font-medium" title={item.name}>
                                                        {item.name}
                                                    </p>
                                                    <p className="text-[11px] text-stone-500 dark:text-wa-muted">
                                                        {typeLabel(item.type)}
                                                        {item.codigo ? ` · ${item.codigo}` : ''}
                                                    </p>
                                                </div>
                                                <span className="shrink-0 text-sm font-semibold tabular-nums">{item.total}</span>
                                            </div>
                                            <div className="mt-1.5 h-1.5 overflow-hidden rounded-full bg-stone-100 dark:bg-white/10">
                                                <div
                                                    className={`h-full rounded-full transition-all ${barColor}`}
                                                    style={{ width: `${pct}%` }}
                                                />
                                            </div>
                                        </li>
                                    );
                                })}
                            </ul>
                        )}

                        <div className="mt-4 flex items-center justify-between border-t border-stone-200 pt-3 text-xs dark:border-white/10">
                            <span className="text-stone-500 dark:text-wa-muted">Total activo</span>
                            <span className="font-semibold tabular-nums">{activos}</span>
                        </div>
                    </div>
                </aside>
            </div>

            <Modal isOpen={modalOpen} onClose={close} title={editing ? 'Editar trámite' : 'Nuevo trámite'}>
                <form onSubmit={submit} noValidate className="space-y-4">
                    <Input
                        type="date"
                        label="Fecha"
                        value={form.date}
                        max={today}
                        required
                        autoFocus={!editing}
                        onChange={(e) => setForm({ ...form, date: e.target.value })}
                    />
                    <Combobox
                        label="Tipo de trámite"
                        value={form.serviceId}
                        placeholder="Buscar tipo de trámite…"
                        options={options}
                        emptyMessage="No hay trámites que coincidan."
                        onChange={(serviceId) => setForm({ ...form, serviceId })}
                    />
                    <Input
                        label="Nro. placa o póliza"
                        value={form.placa}
                        placeholder="Ej. 123ABC o 0123456789"
                        maxLength={10}
                        required
                        error={placaError}
                        helperText={
                            placaKind(placaValue) === 'placa'
                                ? 'Placa válida (3 o 4 dígitos y 3 letras).'
                                : placaKind(placaValue) === 'poliza'
                                  ? 'Póliza válida (5 a 10 dígitos).'
                                  : 'Placa: 3 o 4 dígitos y 3 letras (123ABC). Póliza: 5 a 10 dígitos.'
                        }
                        onBlur={() => setPlacaTouched(true)}
                        onChange={(e) => setForm({ ...form, placa: sanitizePlaca(e.target.value) })}
                    />
                    <Input
                        label="Nro. de ticket"
                        value={form.ticket}
                        placeholder="Ej. A1234567"
                        maxLength={8}
                        required={ticketRequired}
                        error={ticketError}
                        helperText={
                            ticketRequired
                                ? 'Obligatorio para su rol. Alfanumérico, máximo 8 caracteres.'
                                : 'Opcional. Alfanumérico, máximo 8 caracteres.'
                        }
                        onBlur={() => setTicketTouched(true)}
                        onChange={(e) => setForm({ ...form, ticket: sanitizeTicket(e.target.value) })}
                    />
                    <Select
                        label="Estado"
                        value={form.estado}
                        options={ESTADO_OPTIONS}
                        onChange={(e) => setForm({ ...form, estado: e.target.value })}
                    />
                    <label className="block">
                        <span className="mb-1 block text-sm font-medium">Observaciones</span>
                        <textarea
                            rows={3}
                            maxLength={500}
                            value={form.observaciones}
                            onChange={(e) => setForm({ ...form, observaciones: e.target.value })}
                            className="w-full rounded-lg border border-stone-300 bg-white px-3 py-2 text-sm dark:border-white/10 dark:bg-wa-header"
                            placeholder="Opcional"
                        />
                    </label>
                    <label className="flex items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            checked={form.activo}
                            onChange={(e) => setForm({ ...form, activo: e.target.checked })}
                        />
                        Registro activo (cuenta en dashboard y reportes)
                    </label>
                    <div className="flex justify-end gap-2 pt-2">
                        <Button type="button" variant="secondary" onClick={close} disabled={saving}>
                            Cancelar
                        </Button>
                        <Button type="submit" loading={saving}>
                            {editing ? 'Guardar cambios' : 'Registrar'}
                        </Button>
                    </div>
                </form>
            </Modal>

            <Modal
                isOpen={!!deleteTarget}
                onClose={() => {
                    if (!deleting) setDeleteTarget(null);
                }}
                title="Eliminar registro"
                size="sm"
            >
                <div className="space-y-4">
                    <p className="text-sm text-stone-600 dark:text-wa-muted">
                        ¿Desea eliminar el trámite{' '}
                        <span className="font-semibold text-stone-900 dark:text-wa-text">{deleteTarget?.placa}</span>?
                        Se marcará como inactivo y dejará de contabilizarse en totales, dashboard y reportes.
                    </p>
                    <div className="flex justify-end gap-2">
                        <Button
                            type="button"
                            variant="secondary"
                            onClick={() => setDeleteTarget(null)}
                            disabled={deleting}
                        >
                            Cancelar
                        </Button>
                        <Button type="button" variant="danger" loading={deleting} onClick={confirmDelete}>
                            Eliminar
                        </Button>
                    </div>
                </div>
            </Modal>
        </RoleGuard>
    );
}
