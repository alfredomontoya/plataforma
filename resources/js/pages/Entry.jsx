import { useEffect, useMemo, useState } from 'react';
import { PencilSquareIcon, PlusIcon } from '@heroicons/react/24/outline';
import api, { todayLocal } from '../lib/api';
import { useAuth } from '../lib/auth';
import { RoleGuard } from '../components/layout/RoleGuard';
import { Button, Input, Modal, Select } from '../components/ui/controls';
import { useToast } from '../components/ui/Toast';

const EMPTY = { date: '', serviceId: '', placa: '', observaciones: '', activo: true };

const dayOf = (value) => String(value ?? '').slice(0, 10);

const fmtTime = (value) => {
    if (!value) return '—';
    const d = new Date(value);
    if (Number.isNaN(d.getTime())) return '—';
    return d.toLocaleTimeString('es-BO', { hour: '2-digit', minute: '2-digit' });
};

const typeLabel = (type) => (type === 'ENTREGA' ? 'Entrega' : 'Ingreso');

export default function Entry() {
    const { user } = useAuth();
    const { toastSuccess, toastError } = useToast();
    const [date, setDate] = useState(todayLocal());
    const [records, setRecords] = useState([]);
    const [catalog, setCatalog] = useState([]);
    const [loading, setLoading] = useState(false);
    const [saving, setSaving] = useState(false);
    const [modalOpen, setModalOpen] = useState(false);
    const [editing, setEditing] = useState(null);
    const [form, setForm] = useState(EMPTY);

    const isAdmin = user?.role === 'ADMIN';
    const today = todayLocal();

    useEffect(() => {
        api.get('/tramites/catalog')
            .then((r) => setCatalog(r.data?.data ?? []))
            .catch(() => toastError('No se pudo cargar el catálogo de trámites.'));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const load = async (day = date) => {
        if (!user) return;
        setLoading(true);
        try {
            const r = await api.get('/tramites', { params: { date: day } });
            setRecords(r.data?.data ?? []);
        } catch (e) {
            setRecords([]);
            toastError(e.response?.data?.error || 'No se pudo cargar el registro.');
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        load(date);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [date, user?.id]);

    const options = useMemo(
        () => catalog.map((s) => ({ value: s.id, label: `${typeLabel(s.type)} · ${s.name}` })),
        [catalog],
    );

    const canEdit = (r) => isAdmin || r.userId === user?.id;

    const activeCount = records.filter((r) => r.activo).length;

    const openCreate = () => {
        setEditing(null);
        setForm({ ...EMPTY, date, serviceId: catalog[0]?.id ?? '' });
        setModalOpen(true);
    };

    const openEdit = (r) => {
        setEditing(r);
        setForm({
            date: dayOf(r.date),
            serviceId: r.serviceId,
            placa: r.placa ?? '',
            observaciones: r.observaciones ?? '',
            activo: !!r.activo,
        });
        setModalOpen(true);
    };

    const close = () => {
        if (saving) return;
        setModalOpen(false);
        setEditing(null);
    };

    const submit = async (e) => {
        e.preventDefault();

        const placa = form.placa.trim().toUpperCase();
        if (!/^[A-Z0-9]+$/.test(placa)) {
            toastError('El nro. de placa o póliza debe ser alfanumérico, sin espacios.');
            return;
        }
        if (!form.date || !form.serviceId) {
            toastError('Seleccione la fecha y el tipo de trámite.');
            return;
        }

        const payload = {
            date: form.date,
            serviceId: form.serviceId,
            placa,
            observaciones: form.observaciones?.trim() || null,
            activo: !!form.activo,
        };

        setSaving(true);
        try {
            if (editing) {
                await api.patch(`/tramites/${editing.id}`, payload);
                toastSuccess('Registro actualizado.');
            } else {
                await api.post('/tramites', payload);
                toastSuccess('Trámite registrado.');
            }
            setModalOpen(false);
            setEditing(null);
            await load(payload.date);
            if (payload.date !== date) setDate(payload.date);
        } catch (err) {
            const details = err.response?.data?.details;
            const first = details ? Object.values(details)[0]?.[0] : null;
            toastError(first || err.response?.data?.error || 'No se pudo guardar el registro.');
        } finally {
            setSaving(false);
        }
    };

    return (
        <RoleGuard allowed={['OPERATOR_INGRESO', 'OPERATOR_ENTREGA', 'ADMIN']}>
            <div className="space-y-4">
                <div className="card space-y-4 p-5">
                    <div className="flex flex-wrap items-end justify-between gap-3">
                        <div>
                            <h1 className="text-lg font-semibold">Registro de trámites</h1>
                            <p className="text-xs text-stone-500 dark:text-wa-muted">
                                {loading ? 'Cargando…' : `${records.length} registro(s) · ${activeCount} activo(s)`}
                            </p>
                        </div>
                        <Button onClick={openCreate} disabled={catalog.length === 0}>
                            <PlusIcon className="mr-1 h-5 w-5" /> Nuevo registro
                        </Button>
                    </div>

                    <div className="flex flex-wrap items-end gap-2">
                        <div className="w-52">
                            <Input
                                type="date"
                                label="Fecha"
                                value={date}
                                max={today}
                                onChange={(e) => setDate(e.target.value || today)}
                            />
                        </div>
                        <Button variant="secondary" size="sm" onClick={() => setDate(today)}>
                            Hoy
                        </Button>
                    </div>
                </div>

                <div className="card overflow-auto p-0">
                    <table className="table">
                        <thead>
                            <tr>
                                <th>Hora</th>
                                <th>Tipo de trámite</th>
                                <th>Nro. placa / póliza</th>
                                <th>Observaciones</th>
                                {isAdmin && <th>Usuario</th>}
                                <th>Estado</th>
                                <th />
                            </tr>
                        </thead>
                        <tbody>
                            {loading ? (
                                <tr><td colSpan={isAdmin ? 7 : 6} className="text-sm text-stone-500 dark:text-wa-muted">Cargando…</td></tr>
                            ) : records.length === 0 ? (
                                <tr><td colSpan={isAdmin ? 7 : 6} className="text-sm text-stone-500 dark:text-wa-muted">No hay registros para esta fecha.</td></tr>
                            ) : records.map((r) => (
                                <tr key={r.id}>
                                    <td className="whitespace-nowrap tabular-nums">{fmtTime(r.created_at)}</td>
                                    <td>
                                        <span className="text-[13px]">{r.service?.name ?? '—'}</span>
                                        <span className={`ml-2 inline-block rounded px-1.5 py-0.5 text-[11px] font-medium ${r.service?.type === 'ENTREGA' ? 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300' : 'bg-primary-100 text-primary-800 dark:bg-primary-900/40 dark:text-primary-300'}`}>
                                            {typeLabel(r.service?.type)}
                                        </span>
                                    </td>
                                    <td className="font-mono text-[13px] uppercase">{r.placa}</td>
                                    <td className="max-w-xs truncate text-[13px]" title={r.observaciones ?? ''}>{r.observaciones || '—'}</td>
                                    {isAdmin && (
                                        <td className="text-[13px]">
                                            {r.user?.fullName || r.user?.username || '—'}
                                        </td>
                                    )}
                                    <td>
                                        <span className={`inline-block rounded px-1.5 py-0.5 text-[11px] font-medium ${r.activo ? 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300' : 'bg-stone-200 text-stone-600 dark:bg-white/10 dark:text-wa-muted'}`}>
                                            {r.activo ? 'Activo' : 'Inactivo'}
                                        </span>
                                    </td>
                                    <td className="text-right">
                                        {canEdit(r) && (
                                            <button
                                                type="button"
                                                onClick={() => openEdit(r)}
                                                className="inline-flex items-center gap-1 text-sm text-primary-700 hover:underline dark:text-primary-400"
                                            >
                                                <PencilSquareIcon className="h-4 w-4" /> Editar
                                            </button>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>

            <Modal isOpen={modalOpen} onClose={close} title={editing ? 'Editar trámite' : 'Nuevo trámite'}>
                <form onSubmit={submit} className="space-y-4">
                    <Input
                        type="date"
                        label="Fecha"
                        value={form.date}
                        max={today}
                        required
                        onChange={(e) => setForm({ ...form, date: e.target.value })}
                    />
                    <Select
                        label="Tipo de trámite"
                        value={form.serviceId}
                        placeholder="Seleccione…"
                        options={options}
                        required
                        onChange={(e) => setForm({ ...form, serviceId: e.target.value })}
                    />
                    <Input
                        label="Nro. placa o póliza"
                        value={form.placa}
                        placeholder="Ej. ABC123"
                        maxLength={30}
                        required
                        onChange={(e) => setForm({ ...form, placa: e.target.value.toUpperCase() })}
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
        </RoleGuard>
    );
}
