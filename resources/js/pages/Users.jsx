import { useEffect, useState } from 'react';
import { ArrowsUpDownIcon, BriefcaseIcon, ChevronDownIcon, ChevronUpIcon, KeyIcon, PencilSquareIcon, PowerIcon } from '@heroicons/react/24/outline';
import api from '../lib/api';
import { RoleGuard } from '../components/layout/RoleGuard';
import { Button, Input, Modal, PasswordInput, Select } from '../components/ui/controls';
import { useToast } from '../components/ui/Toast';

const COLS = [
    ['username', 'Usuario'],
    ['firstName', 'Nombre Completo'],
    ['role', 'Rol'],
    ['title', 'Título'],
    ['position', 'Cargo Actual'],
    ['isActive', 'Estado'],
];

export default function Users() {
    const { toastSuccess, toastError } = useToast();
    const [rows, setRows] = useState([]);
    const [total, setTotal] = useState(0);
    const [totalPages, setTotalPages] = useState(1);
    const [page, setPage] = useState(1);
    const limit = 20;
    const [search, setSearch] = useState('');
    const [role, setRole] = useState('');
    const [active, setActive] = useState('');
    const [sortBy, setSortBy] = useState('createdAt');
    const [sortOrder, setSortOrder] = useState('desc');
    const [modal, setModal] = useState(null);
    const [form, setForm] = useState({});

    const load = async () => {
        try {
            const r = await api.get('/users', {
                params: { search, role, isActive: active, page, limit, sortBy, sortOrder },
            });
            setRows(r.data.data);
            setTotal(r.data.total ?? 0);
            setTotalPages(r.data.totalPages);
        } catch {
            toastError('No se pudo cargar usuarios.');
        }
    };

    useEffect(() => {
        load();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [page, sortBy, sortOrder]);

    const toggleSort = (col) => {
        if (sortBy === col) setSortOrder(sortOrder === 'asc' ? 'desc' : 'asc');
        else {
            setSortBy(col);
            setSortOrder('asc');
        }
        setPage(1);
    };

    const openNew = () => {
        setForm({ role: 'OPERATOR_INGRESO', title: 'NONE', isActive: true });
        setModal('new');
    };

    const save = async (e) => {
        e.preventDefault();
        try {
            if (modal === 'new') await api.post('/users', form);
            else await api.patch(`/users/${form.id}`, form);
            setModal(null);
            toastSuccess('Guardado.');
            load();
        } catch (err) {
            toastError(err.response?.data?.error || 'Error al guardar.');
        }
    };

    const changePosition = async (e) => {
        e.preventDefault();
        try {
            await api.patch(`/users/${form.id}/position`, { position: form.position, department: form.department, title: form.title });
            setModal(null);
            toastSuccess('Cargo actualizado.');
            load();
        } catch (err) {
            toastError(err.response?.data?.error || 'Error.');
        }
    };

    const deactivate = async () => {
        try {
            await api.delete(`/users/${form.id}`);
            setModal(null);
            toastSuccess('Usuario desactivado.');
            load();
        } catch {
            toastError('Error al desactivar.');
        }
    };

    const resetPass = async (id) => {
        try {
            const r = await api.post(`/users/${id}/reset-password`);
            toastSuccess(`Temporal: ${r.data.data.temporaryPassword}`);
        } catch {
            toastError('Error al resetear.');
        }
    };

    const th = (key, label) => (
        <th className="cursor-pointer" onClick={() => toggleSort(key)}>
            <span className="inline-flex items-center gap-1">
                {label}
                {sortBy !== key && <ArrowsUpDownIcon className="h-3 w-3 text-stone-400" />}
                {sortBy === key &&
                    (sortOrder === 'asc' ? <ChevronUpIcon className="h-3 w-3 text-primary-700" /> : <ChevronDownIcon className="h-3 w-3 text-primary-700" />)}
            </span>
        </th>
    );

    return (
        <RoleGuard allowed={['ADMIN']}>
            <div className="card space-y-4 p-5">
                <div className="flex flex-wrap items-end gap-3">
                    <Input label="Buscar" value={search} onChange={(e) => setSearch(e.target.value)} />
                    <Select label="Rol" value={role} onChange={(e) => setRole(e.target.value)} options={[{ value: '', label: 'Todos' }, 'ADMIN', 'OPERATOR_INGRESO', 'OPERATOR_ENTREGA', 'JEFE'].map((r) => (typeof r === 'string' ? { value: r, label: r } : r))} />
                    <Select label="Estado" value={active} onChange={(e) => setActive(e.target.value)} options={[{ value: '', label: 'Todos' }, { value: 'true', label: 'Activos' }, { value: 'false', label: 'Inactivos' }]} />
                    <Button onClick={() => { setPage(1); load(); }}>Filtrar</Button>
                    <Button variant="secondary" onClick={openNew}>Nuevo</Button>
                </div>
                <div className="overflow-auto">
                    <table className="table">
                        <thead>
                            <tr>
                                {COLS.map(([k, l]) => th(k, l))}
                                <th className="text-center">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((u) => (
                                <tr key={u.id}>
                                    <td className="font-mono">{u.username}</td>
                                    <td>{`${u.firstName} ${u.lastName}`}</td>
                                    <td><span className="badge badge-primary">{u.role}</span></td>
                                    <td>{u.title}</td>
                                    <td>{u.active_position?.position || '—'}</td>
                                    <td>
                                        <span className={`badge ${u.isActive ? 'badge-success' : 'badge-danger'}`}>{u.isActive ? 'Activo' : 'Inactivo'}</span>
                                        {u.canBackfill && <span className="badge badge-warning ml-1">Retroactivo</span>}
                                    </td>
                                    <td className="whitespace-nowrap text-right">
                                        <div className="flex justify-end gap-1">
                                            <Button size="sm" variant="secondary" title="Cambiar cargo" onClick={() => { setForm({ ...u, position: u.active_position?.position || '', department: u.active_position?.department || '' }); setModal('position'); }}><BriefcaseIcon className="mr-1 h-4 w-4" />Cargo</Button>
                                            <Button size="sm" variant="secondary" title="Editar usuario" onClick={() => { setForm(u); setModal('edit'); }}><PencilSquareIcon className="mr-1 h-4 w-4" />Editar</Button>
                                            <Button size="sm" variant="secondary" title="Resetear contraseña" onClick={() => resetPass(u.id)}><KeyIcon className="mr-1 h-4 w-4" />Reset</Button>
                                            <Button size="sm" variant="danger" title="Desactivar usuario" onClick={() => { setForm(u); setModal('deactivate'); }}><PowerIcon className="mr-1 h-4 w-4" />Off</Button>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    <Button size="sm" variant="secondary" disabled={page <= 1} onClick={() => setPage(page - 1)}>‹</Button>
                    <span className="text-sm">{page} / {totalPages}</span>
                    <Button size="sm" variant="secondary" disabled={page >= totalPages} onClick={() => setPage(page + 1)}>›</Button>
                    <span className="ml-auto text-sm text-stone-500 dark:text-wa-muted">
                        Mostrando {rows.length} de {total} usuarios · {limit} por página
                    </span>
                </div>
            </div>

            <Modal isOpen={modal === 'new' || modal === 'edit'} onClose={() => setModal(null)} title={modal === 'new' ? 'Nuevo usuario' : 'Editar usuario'}>
                <form onSubmit={save} className="space-y-3">
                    {modal === 'new' && (
                        <>
                            <Input label="Usuario" value={form.username || ''} onChange={(e) => setForm({ ...form, username: e.target.value })} required />
                            <PasswordInput label="Contraseña" value={form.password || ''} onChange={(e) => setForm({ ...form, password: e.target.value })} required />
                        </>
                    )}
                    <div className="grid grid-cols-2 gap-3">
                        <Select label="Rol" value={form.role || ''} onChange={(e) => setForm({ ...form, role: e.target.value })} options={['ADMIN', 'OPERATOR_INGRESO', 'OPERATOR_ENTREGA', 'JEFE'].map((r) => ({ value: r, label: r }))} />
                        <Select label="Título" value={form.title || ''} onChange={(e) => setForm({ ...form, title: e.target.value })} options={['NONE', 'LIC', 'ABG', 'ING'].map((r) => ({ value: r, label: r }))} />
                        <Input label="Nombre" value={form.firstName || ''} onChange={(e) => setForm({ ...form, firstName: e.target.value })} />
                        <Input label="Apellido" value={form.lastName || ''} onChange={(e) => setForm({ ...form, lastName: e.target.value })} />
                        {modal === 'new' && (
                            <>
                                <Input label="Cargo" value={form.position || ''} onChange={(e) => setForm({ ...form, position: e.target.value })} required />
                                <Input label="Departamento" value={form.department || ''} onChange={(e) => setForm({ ...form, department: e.target.value })} required />
                            </>
                        )}
                    </div>
                    <label className="flex items-center gap-2 text-sm">
                        <input type="checkbox" checked={!!form.isActive} onChange={(e) => setForm({ ...form, isActive: e.target.checked })} /> Activo
                    </label>
                    <label className="flex items-center gap-2 text-sm">
                        <input type="checkbox" checked={!!form.canBackfill} onChange={(e) => setForm({ ...form, canBackfill: e.target.checked })} /> Registrar fechas anteriores
                    </label>
                    <p className="text-xs text-stone-500 dark:text-wa-muted">El permiso retroactivo expira al cambiar de día.</p>
                    <Button type="submit">Guardar</Button>
                </form>
            </Modal>

            <Modal isOpen={modal === 'position'} onClose={() => setModal(null)} title="Cambiar cargo">
                <form onSubmit={changePosition} className="space-y-3">
                    <Input label="Cargo" value={form.position || ''} onChange={(e) => setForm({ ...form, position: e.target.value })} required />
                    <Input label="Departamento" value={form.department || ''} onChange={(e) => setForm({ ...form, department: e.target.value })} />
                    <Button type="submit">Guardar</Button>
                </form>
            </Modal>

            <Modal isOpen={modal === 'deactivate'} onClose={() => setModal(null)} title="Desactivar usuario" size="sm">
                <p className="mb-4 text-sm">¿Desactivar a {form.username}? (no se borra)</p>
                <Button variant="danger" onClick={deactivate}>Desactivar</Button>
            </Modal>
        </RoleGuard>
    );
}
