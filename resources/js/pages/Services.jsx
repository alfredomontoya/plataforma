import { useEffect, useState } from 'react';
import api, { isCanceled } from '../lib/api';
import { useDebouncedSearch } from '../hooks/useDebouncedSearch';
import { RoleGuard } from '../components/layout/RoleGuard';
import { Button, Input, Modal, SearchInput, Select } from '../components/ui/controls';
import { useToast } from '../components/ui/Toast';

export default function Services() {
    const { toastSuccess, toastError } = useToast();
    const [rows, setRows] = useState([]);
    const { input: searchInput, setInput: setSearchInput, query: search, pending: searchPending, clear: clearSearch } = useDebouncedSearch();
    const [modal, setModal] = useState(null);
    const [form, setForm] = useState({ type: 'INGRESO' });
    const [loading, setLoading] = useState(false);
    const [refreshKey, setRefreshKey] = useState(0);

    const reload = () => setRefreshKey((key) => key + 1);

    useEffect(() => {
        const controller = new AbortController();
        setLoading(true);

        api.get('/services/admin', {
            params: { ...(search ? { search } : {}), limit: 50 },
            signal: controller.signal,
        })
            .then((r) => setRows(r.data?.data ?? []))
            .catch((err) => {
                if (isCanceled(err)) return;
                setRows([]);
                toastError('No se pudo cargar servicios.');
            })
            .finally(() => {
                if (!controller.signal.aborted) setLoading(false);
            });

        return () => controller.abort();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search, refreshKey]);

    const save = async (e) => {
        e.preventDefault();
        try {
            if (modal === 'new') await api.post('/services/admin', form);
            else await api.patch(`/services/admin/${form.id}`, form);
            setModal(null);
            toastSuccess('Guardado.');
            reload();
        } catch (err) {
            toastError(err.response?.data?.error || 'Error.');
        }
    };

    return (
        <RoleGuard allowed={['ADMIN']}>
            <div className="card space-y-4 p-5">
                <div className="flex flex-wrap items-end gap-3">
                    <div className="min-w-[16rem] flex-1">
                        <span className="mb-1 block text-sm font-medium">Buscar</span>
                        <SearchInput
                            value={searchInput}
                            onChange={(e) => setSearchInput(e.target.value)}
                            onClear={clearSearch}
                            loading={searchPending}
                            placeholder="Nombre, código o abreviatura…"
                        />
                    </div>
                    <Button variant="secondary" onClick={() => { setForm({ type: 'INGRESO' }); setModal('new'); }}>Nuevo</Button>
                </div>
                <table className="table" aria-busy={loading}>
                    <thead>
                        <tr><th>Nombre</th><th>Código</th><th>Abrev.</th><th>Tipo</th><th>Orden</th><th>Estado</th><th>Acciones</th></tr>
                    </thead>
                    <tbody className={loading ? 'opacity-60 transition-opacity' : ''}>
                        {rows.map((s) => (
                            <tr key={s.id}>
                                <td>{s.name}</td>
                                <td className="font-mono">{s.codigo || '—'}</td>
                                <td>{s.abreviation || '—'}</td>
                                <td><span className="badge badge-primary">{s.type}</span></td>
                                <td>{s.sortOrder}</td>
                                <td><span className={`badge ${s.isActive ? 'badge-success' : 'badge-danger'}`}>{s.isActive ? 'Activo' : 'Inactivo'}</span></td>
                                <td>
                                    <Button size="sm" variant="secondary" onClick={() => { setForm(s); setModal('show'); }}>Ver</Button>{' '}
                                    <Button size="sm" variant="secondary" onClick={() => { setForm(s); setModal('edit'); }}>Editar</Button>{' '}
                                    <Button size="sm" variant="danger" onClick={() => api.delete(`/services/admin/${s.id}`).then(() => { toastSuccess('Desactivado.'); reload(); })}>Off</Button>
                                </td>
                            </tr>
                        ))}
                        {rows.length === 0 && (
                            <tr>
                                <td colSpan={7} className="text-sm text-stone-500 dark:text-wa-muted">
                                    {loading ? 'Cargando…' : search ? 'Sin resultados.' : 'Sin servicios registrados.'}
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>
            <Modal isOpen={modal === 'new' || modal === 'edit'} onClose={() => setModal(null)} title="Servicio">
                <form onSubmit={save} className="space-y-3">
                    <Input label="Nombre" value={form.name || ''} onChange={(e) => setForm({ ...form, name: e.target.value })} required />
                    <div className="grid grid-cols-2 gap-3">
                        <Input label="Código" value={form.codigo || ''} onChange={(e) => setForm({ ...form, codigo: e.target.value })} />
                        <Input label="Abreviatura" value={form.abreviation || ''} onChange={(e) => setForm({ ...form, abreviation: e.target.value })} />
                    </div>
                    <div className="grid grid-cols-2 gap-3">
                        <Select label="Tipo" value={form.type || 'INGRESO'} onChange={(e) => setForm({ ...form, type: e.target.value })} options={[{ value: 'INGRESO', label: 'INGRESO' }, { value: 'ENTREGA', label: 'ENTREGA' }]} />
                        <Input label="Orden" type="number" value={form.sortOrder ?? 0} onChange={(e) => setForm({ ...form, sortOrder: Number(e.target.value) })} />
                    </div>
                    <Button type="submit">Guardar</Button>
                </form>
            </Modal>
            <Modal isOpen={modal === 'show'} onClose={() => setModal(null)} title="Detalle del servicio">
                <dl className="space-y-2 text-sm">
                    <div><dt className="font-medium text-stone-500 dark:text-wa-muted">Nombre</dt><dd>{form.name}</dd></div>
                    <div><dt className="font-medium text-stone-500 dark:text-wa-muted">Código</dt><dd className="font-mono">{form.codigo || '—'}</dd></div>
                    <div><dt className="font-medium text-stone-500 dark:text-wa-muted">Abreviatura</dt><dd>{form.abreviation || '—'}</dd></div>
                    <div><dt className="font-medium text-stone-500 dark:text-wa-muted">Tipo</dt><dd><span className="badge badge-primary">{form.type}</span></dd></div>
                    <div><dt className="font-medium text-stone-500 dark:text-wa-muted">Orden</dt><dd>{form.sortOrder}</dd></div>
                    <div><dt className="font-medium text-stone-500 dark:text-wa-muted">Estado</dt><dd>{form.isActive ? 'Activo' : 'Inactivo'}</dd></div>
                </dl>
            </Modal>
        </RoleGuard>
    );
}
