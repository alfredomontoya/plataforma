import { useEffect, useRef, useState } from 'react';
import { ArrowsUpDownIcon, ChevronDownIcon, ChevronUpIcon, EyeIcon, KeyIcon, PencilSquareIcon, PowerIcon } from '@heroicons/react/24/outline';
import api, { todayLocal } from '../lib/api';
import { RoleGuard } from '../components/layout/RoleGuard';
import { Button, DatePicker, Input, Modal, PasswordInput, Select } from '../components/ui/controls';
import { useToast } from '../components/ui/Toast';

const COLS = [
    ['username', 'Usuario'],
    ['email', 'Correo'],
    ['role', 'Rol'],
    ['isActive', 'Estado'],
];

const ROLES = ['ADMIN', 'OPERATOR_INGRESO', 'OPERATOR_ENTREGA', 'JEFE'];

function nombreEmpleado(empleado) {
    if (!empleado) return '—';
    const tratamiento = empleado.tratamiento?.abreviatura;
    return `${tratamiento ? `${tratamiento} ` : ''}${empleado.nombre} ${empleado.apellidos}`.trim();
}

function puestoEmpleado(empleado) {
    const puesto = empleado?.puesto_actual?.puesto ?? empleado?.puestoActual?.puesto;
    if (!puesto) return '';
    return `${puesto.nombre}${puesto.area?.nombre ? ` (${puesto.area.nombre})` : ''}`;
}

/** Empleado de RRHH vinculado (relaciones en snake_case al serializar). */
function empleadoLabel(user) {
    const empleado = user.empleado;
    if (!empleado) return '—';
    const puesto = puestoEmpleado(empleado);
    return `${nombreEmpleado(empleado)}${puesto ? ` · ${puesto}` : ''}`;
}

function apiErrorMessage(err, fallback) {
    const data = err.response?.data;
    const firstValidation = Object.values(data?.errors ?? {})[0]?.[0];

    return data?.error || data?.message || firstValidation || fallback;
}

export default function Users() {
    const { toastSuccess, toastError } = useToast();
    const [rows, setRows] = useState([]);
    const [total, setTotal] = useState(0);
    const [totalPages, setTotalPages] = useState(1);
    const [page, setPage] = useState(1);
    const limit = 20;
    const [searchInput, setSearchInput] = useState('');
    const [search, setSearch] = useState('');
    const [role, setRole] = useState('');
    const [active, setActive] = useState('');
    const [sortBy, setSortBy] = useState('createdAt');
    const [sortOrder, setSortOrder] = useState('desc');
    const [modal, setModal] = useState(null);
    const [form, setForm] = useState({});
    const [viewUser, setViewUser] = useState(null);

    // Búsqueda de empleado + sugerencia de credenciales.
    const [empleadoSearch, setEmpleadoSearch] = useState('');
    const [empleadoResultados, setEmpleadoResultados] = useState([]);
    const [empleadoSeleccionado, setEmpleadoSeleccionado] = useState(null);
    const [mostrarBuscadorEmpleado, setMostrarBuscadorEmpleado] = useState(false);
    const [buscandoResultados, setBuscandoResultados] = useState(false);
    const [sugiriendo, setSugiriendo] = useState(false);
    const sugerenciaController = useRef(null);

    // Datos para el modal de alta de empleado.
    const [tratamientos, setTratamientos] = useState([]);
    const [puestos, setPuestos] = useState([]);
    const [supervisores, setSupervisores] = useState([]);
    const [empleadoForm, setEmpleadoForm] = useState({});
    const [guardandoEmpleado, setGuardandoEmpleado] = useState(false);

    const load = async (signal) => {
        try {
            const r = await api.get('/users', {
                params: { search, role, isActive: active, page, limit, sortBy, sortOrder },
                signal,
            });
            setRows(r.data.data);
            setTotal(r.data.total ?? 0);
            setTotalPages(r.data.totalPages);
        } catch (err) {
            if (err.code === 'ERR_CANCELED') return;
            toastError('No se pudo cargar usuarios.');
        }
    };

    useEffect(() => {
        const controller = new AbortController();
        load(controller.signal);

        return () => controller.abort();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search, role, active, page, sortBy, sortOrder]);

    // Filtrado automático: aplica el texto 350 ms después de la última tecla.
    useEffect(() => {
        const timeout = setTimeout(() => {
            setSearch(searchInput.trim());
            setPage(1);
        }, 350);

        return () => clearTimeout(timeout);
    }, [searchInput]);

    const loadEmpleados = async (searchText = '', signal) => {
        setBuscandoResultados(true);
        try {
            const r = await api.get('/rrhh/empleados', {
                params: { search: searchText || undefined, limit: 50 },
                signal,
            });
            setEmpleadoResultados(r.data.data ?? []);
        } catch (err) {
            if (err.code === 'ERR_CANCELED') return;
            toastError('No se pudieron cargar los empleados.');
        } finally {
            if (!signal?.aborted) setBuscandoResultados(false);
        }
    };

    const loadCatalogosEmpleado = async () => {
        try {
            const [tratamientosRes, puestosRes, supervisoresRes] = await Promise.all([
                api.get('/rrhh/tratamientos', { params: { activo: true } }),
                api.get('/rrhh/puestos'),
                api.get('/rrhh/empleados', { params: { estado: 'activo', limit: 100 } }),
            ]);
            setTratamientos(tratamientosRes.data.data ?? []);
            setPuestos(puestosRes.data.data ?? []);
            setSupervisores(supervisoresRes.data.data ?? []);
        } catch {
            toastError('No se pudieron cargar los catálogos de RRHH.');
        }
    };

    // Búsqueda automática de empleados por CI, nombres, apellidos o combinación.
    useEffect(() => {
        if (modal !== 'new' || !mostrarBuscadorEmpleado) return;

        const controller = new AbortController();
        setBuscandoResultados(true);
        const timeout = setTimeout(() => {
            loadEmpleados(empleadoSearch.trim(), controller.signal);
        }, 300);

        return () => {
            clearTimeout(timeout);
            controller.abort();
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [empleadoSearch, modal, mostrarBuscadorEmpleado]);

    const toggleSort = (col) => {
        if (sortBy === col) setSortOrder(sortOrder === 'asc' ? 'desc' : 'asc');
        else {
            setSortBy(col);
            setSortOrder('asc');
        }
        setPage(1);
    };

    const sugerirCredenciales = async (empleadoId) => {
        sugerenciaController.current?.abort();
        const controller = new AbortController();
        sugerenciaController.current = controller;
        setSugiriendo(true);

        try {
            const r = await api.get('/users/suggest-username', {
                params: { empleadoId },
                signal: controller.signal,
            });
            const { username, email } = r.data.data;
            setForm((prev) => (prev.empleadoId === empleadoId
                ? { ...prev, username: prev.username || username, email: prev.email || email }
                : prev));
        } catch (err) {
            if (err.code === 'ERR_CANCELED') return;
            toastError(apiErrorMessage(err, 'No se pudo sugerir usuario y correo.'));
        } finally {
            if (sugerenciaController.current === controller) setSugiriendo(false);
        }
    };

    const openNew = () => {
        setForm({ role: 'OPERATOR_INGRESO', isActive: true, empleadoId: '', username: '', email: '' });
        setEmpleadoSeleccionado(null);
        setEmpleadoSearch('');
        setEmpleadoResultados([]);
        setMostrarBuscadorEmpleado(true);
        setModal('new');
        loadCatalogosEmpleado();
    };

    const openEdit = (user) => {
        setForm(user);
        setEmpleadoSeleccionado(user.empleado ?? null);
        setMostrarBuscadorEmpleado(false);
        setModal('edit');
    };

    const selectEmpleado = (empleado) => {
        if (empleado.estado !== 'activo' || empleado.usuario) return;

        setEmpleadoSeleccionado(empleado);
        setForm((prev) => ({ ...prev, empleadoId: empleado.id, username: '', email: '' }));
        setMostrarBuscadorEmpleado(false);
        setEmpleadoSearch('');
        sugerirCredenciales(empleado.id);
    };

    const cambiarEmpleado = () => {
        setEmpleadoSeleccionado(null);
        setEmpleadoResultados([]);
        setEmpleadoSearch('');
        setMostrarBuscadorEmpleado(true);
        setForm((prev) => ({ ...prev, empleadoId: '', username: '', email: '' }));
    };

    const openEmpleadoNew = () => {
        setEmpleadoForm({
            tratamientoId: '',
            ci: '',
            nombre: '',
            apellidos: '',
            fechaNacimiento: '',
            telefono: '',
            direccion: '',
            profesion: '',
            estado: 'activo',
            puestoId: '',
            fechaInicio: todayLocal(),
            supervisorId: '',
        });
        setModal('empleado-new');
        loadCatalogosEmpleado();
    };

    const volverAlUsuario = () => setModal('new');

    const saveEmpleado = async (e) => {
        e.preventDefault();
        setGuardandoEmpleado(true);

        try {
            const r = await api.post('/rrhh/empleados', empleadoForm);
            const creado = r.data.data;

            setEmpleadoSeleccionado(creado);
            setEmpleadoResultados((prev) => [creado, ...prev.filter((empleado) => empleado.id !== creado.id)]);
            setForm((prev) => ({ ...prev, empleadoId: creado.id, username: '', email: '' }));
            setMostrarBuscadorEmpleado(false);
            setModal('new');
            toastSuccess('Empleado creado y seleccionado.');
            sugerirCredenciales(creado.id);
        } catch (err) {
            toastError(apiErrorMessage(err, 'No se pudo crear el empleado.'));
        } finally {
            setGuardandoEmpleado(false);
        }
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
            toastError(apiErrorMessage(err, 'Error al guardar.'));
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

    const openView = async (id) => {
        try {
            const r = await api.get(`/users/${id}`);
            setViewUser(r.data.data);
            setModal('view');
        } catch {
            toastError('No se pudo cargar el usuario.');
        }
    };

    const clearFilters = () => {
        setSearchInput('');
        setSearch('');
        setRole('');
        setActive('');
        setPage(1);
    };

    const th = (key, label) => (
        <th key={key} className="cursor-pointer" onClick={() => toggleSort(key)}>
            <span className="inline-flex items-center gap-1">
                {label}
                {sortBy !== key && <ArrowsUpDownIcon className="h-3 w-3 text-stone-400" />}
                {sortBy === key &&
                    (sortOrder === 'asc' ? <ChevronUpIcon className="h-3 w-3 text-primary-700" /> : <ChevronDownIcon className="h-3 w-3 text-primary-700" />)}
            </span>
        </th>
    );

    const tratamientoOptions = tratamientos.map((t) => ({ value: t.id, label: `${t.abreviatura} — ${t.nombre}` }));
    const puestoOptions = puestos.map((p) => ({ value: p.id, label: `${p.area?.nombre || '—'} · ${p.nombre}` }));
    const supervisorOptions = supervisores.map((s) => ({ value: s.id, label: `${nombreEmpleado(s)} · CI ${s.ci}` }));

    return (
        <RoleGuard allowed={['ADMIN']}>
            <div className="card space-y-4 p-5">
                <div className="flex flex-wrap items-end gap-3">
                    <Input label="Buscar" value={searchInput} onChange={(e) => setSearchInput(e.target.value)} placeholder="Usuario, correo o empleado" />
                    <Select label="Rol" value={role} onChange={(e) => { setRole(e.target.value); setPage(1); }} options={[{ value: '', label: 'Todos' }, ...ROLES.map((r) => ({ value: r, label: r }))]} />
                    <Select label="Estado" value={active} onChange={(e) => { setActive(e.target.value); setPage(1); }} options={[{ value: '', label: 'Todos' }, { value: 'true', label: 'Activos' }, { value: 'false', label: 'Inactivos' }]} />
                    <Button variant="secondary" onClick={clearFilters}>Limpiar</Button>
                    <Button variant="secondary" onClick={openNew}>Nuevo</Button>
                </div>
                <div className="overflow-auto">
                    <table className="table">
                        <thead>
                            <tr>
                                {COLS.map(([k, l]) => th(k, l))}
                                <th>Empleado</th>
                                <th className="text-center">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((u) => (
                                <tr key={u.id}>
                                    <td className="font-mono">{u.username}</td>
                                    <td>{u.email || '—'}</td>
                                    <td><span className="badge badge-primary">{u.role}</span></td>
                                    <td>
                                        <span className={`badge ${u.isActive ? 'badge-success' : 'badge-danger'}`}>{u.isActive ? 'Activo' : 'Inactivo'}</span>
                                        {u.canBackfill && <span className="badge badge-warning ml-1">Retroactivo</span>}
                                    </td>
                                    <td className="max-w-[22rem] truncate" title={empleadoLabel(u)}>{empleadoLabel(u)}</td>
                                    <td className="whitespace-nowrap text-right">
                                        <div className="flex justify-end gap-1">
                                            <Button size="sm" variant="secondary" title="Ver registro" onClick={() => openView(u.id)}><EyeIcon className="mr-1 h-4 w-4" />Ver</Button>
                                            <Button size="sm" variant="secondary" title="Editar usuario" onClick={() => openEdit(u)}><PencilSquareIcon className="mr-1 h-4 w-4" />Editar</Button>
                                            <Button size="sm" variant="secondary" title="Resetear contraseña" onClick={() => resetPass(u.id)}><KeyIcon className="mr-1 h-4 w-4" />Reset</Button>
                                            <Button size="sm" variant="danger" title="Desactivar usuario" onClick={() => { setForm(u); setModal('deactivate'); }}><PowerIcon className="mr-1 h-4 w-4" />Off</Button>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                            {rows.length === 0 && (
                                <tr>
                                    <td colSpan={COLS.length + 2} className="py-6 text-center text-sm text-stone-500 dark:text-wa-muted">
                                        Sin resultados.
                                    </td>
                                </tr>
                            )}
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
                        <div className="space-y-2">
                            {mostrarBuscadorEmpleado ? (
                                <>
                                    <Input
                                        label="Buscar empleado"
                                        value={empleadoSearch}
                                        onChange={(e) => setEmpleadoSearch(e.target.value)}
                                        placeholder="CI, nombres, apellidos o combinación"
                                        helperText="Solo se pueden seleccionar empleados activos sin cuenta."
                                        autoFocus
                                    />
                                    <div className="max-h-56 divide-y divide-stone-200 overflow-auto rounded-lg border border-stone-300 dark:divide-white/10 dark:border-white/10">
                                        {buscandoResultados && (
                                            <p className="px-3 py-3 text-sm text-stone-500 dark:text-wa-muted">Buscando…</p>
                                        )}
                                        {!buscandoResultados && empleadoResultados.length === 0 && (
                                            <p className="px-3 py-3 text-sm text-stone-500 dark:text-wa-muted">Sin coincidencias.</p>
                                        )}
                                        {empleadoResultados.map((empleado) => {
                                            const bloqueado = empleado.estado !== 'activo' || !!empleado.usuario;

                                            return (
                                                <button
                                                    type="button"
                                                    key={empleado.id}
                                                    disabled={bloqueado}
                                                    onClick={() => selectEmpleado(empleado)}
                                                    className="flex w-full items-start justify-between gap-3 px-3 py-2 text-left text-sm hover:bg-stone-100 disabled:cursor-not-allowed disabled:opacity-60 disabled:hover:bg-transparent dark:hover:bg-white/5"
                                                >
                                                    <span>
                                                        <span className="block font-medium">{nombreEmpleado(empleado)}</span>
                                                        <span className="block text-xs text-stone-500 dark:text-wa-muted">
                                                            CI {empleado.ci}{puestoEmpleado(empleado) ? ` · ${puestoEmpleado(empleado)}` : ''}
                                                        </span>
                                                    </span>
                                                    <span className="flex shrink-0 gap-1">
                                                        {empleado.estado !== 'activo' && <span className="badge badge-danger">Inactivo</span>}
                                                        {empleado.usuario && <span className="badge badge-warning">Ya tiene cuenta</span>}
                                                    </span>
                                                </button>
                                            );
                                        })}
                                    </div>
                                    <Button type="button" variant="secondary" onClick={openEmpleadoNew}>
                                        Nuevo empleado
                                    </Button>
                                </>
                            ) : (
                                empleadoSeleccionado && (
                                    <div className="flex items-start justify-between gap-3 rounded-lg border border-stone-300 p-3 dark:border-white/10">
                                        <div className="text-sm">
                                            <p className="font-medium">{nombreEmpleado(empleadoSeleccionado)}</p>
                                            <p className="text-stone-500 dark:text-wa-muted">
                                                CI {empleadoSeleccionado.ci}{puestoEmpleado(empleadoSeleccionado) ? ` · ${puestoEmpleado(empleadoSeleccionado)}` : ''}
                                            </p>
                                        </div>
                                        <div className="flex shrink-0 gap-2">
                                            <Button type="button" size="sm" variant="secondary" onClick={cambiarEmpleado}>Cambiar</Button>
                                            <Button type="button" size="sm" variant="secondary" onClick={openEmpleadoNew}>Nuevo empleado</Button>
                                        </div>
                                    </div>
                                )
                            )}
                        </div>
                    )}

                    {modal === 'edit' && empleadoSeleccionado && (
                        <div className="rounded-lg border border-stone-300 p-3 text-sm dark:border-white/10">
                            <p className="font-medium">{nombreEmpleado(empleadoSeleccionado)}</p>
                            <p className="text-stone-500 dark:text-wa-muted">
                                CI {empleadoSeleccionado.ci}{puestoEmpleado(empleadoSeleccionado) ? ` · ${puestoEmpleado(empleadoSeleccionado)}` : ''}
                            </p>
                        </div>
                    )}

                    {modal === 'new' && (
                        <>
                            <Input
                                label="Usuario"
                                value={form.username || ''}
                                onChange={(e) => setForm({ ...form, username: e.target.value })}
                                required
                                helperText={sugiriendo ? 'Sugiriendo usuario y correo…' : 'Sugerido automáticamente; puedes editarlo.'}
                            />
                            <PasswordInput label="Contraseña" value={form.password || ''} onChange={(e) => setForm({ ...form, password: e.target.value })} required />
                        </>
                    )}
                    <div className="grid grid-cols-2 gap-3">
                        <Select label="Rol" value={form.role || ''} onChange={(e) => setForm({ ...form, role: e.target.value })} options={ROLES.map((r) => ({ value: r, label: r }))} />
                        <Input label="Correo" type="email" value={form.email || ''} onChange={(e) => setForm({ ...form, email: e.target.value })} required={modal === 'new'} />
                    </div>
                    <p className="text-xs text-stone-500 dark:text-wa-muted">
                        Cada cuenta depende de un empleado de RRHH. El vínculo se define al crear el usuario y no se puede cambiar.
                    </p>
                    <label className="flex items-center gap-2 text-sm">
                        <input type="checkbox" checked={!!form.isActive} onChange={(e) => setForm({ ...form, isActive: e.target.checked })} /> Activo
                    </label>
                    <label className="flex items-center gap-2 text-sm">
                        <input type="checkbox" checked={!!form.canBackfill} onChange={(e) => setForm({ ...form, canBackfill: e.target.checked })} /> Registrar fechas anteriores
                    </label>
                    <p className="text-xs text-stone-500 dark:text-wa-muted">El permiso retroactivo expira al cambiar de día.</p>
                    <Button type="submit" disabled={modal === 'new' && !form.empleadoId}>Guardar</Button>
                </form>
            </Modal>

            <Modal isOpen={modal === 'empleado-new'} onClose={volverAlUsuario} title="Nuevo empleado" size="lg">
                <form onSubmit={saveEmpleado} className="space-y-3">
                    <div className="grid grid-cols-2 gap-3">
                        <Select
                            label="Tratamiento"
                            value={empleadoForm.tratamientoId || ''}
                            onChange={(e) => setEmpleadoForm({ ...empleadoForm, tratamientoId: e.target.value || null })}
                            options={[{ value: '', label: 'Sin tratamiento' }, ...tratamientoOptions]}
                        />
                        <Input label="Cédula de identidad" value={empleadoForm.ci || ''} onChange={(e) => setEmpleadoForm({ ...empleadoForm, ci: e.target.value })} required />
                        <Input label="Nombre" value={empleadoForm.nombre || ''} onChange={(e) => setEmpleadoForm({ ...empleadoForm, nombre: e.target.value })} required />
                        <Input label="Apellidos" value={empleadoForm.apellidos || ''} onChange={(e) => setEmpleadoForm({ ...empleadoForm, apellidos: e.target.value })} required />
                        <DatePicker label="Fecha de nacimiento" value={empleadoForm.fechaNacimiento || ''} onChange={(v) => setEmpleadoForm({ ...empleadoForm, fechaNacimiento: v })} />
                        <Input label="Teléfono" value={empleadoForm.telefono || ''} onChange={(e) => setEmpleadoForm({ ...empleadoForm, telefono: e.target.value })} />
                        <Input label="Profesión" value={empleadoForm.profesion || ''} onChange={(e) => setEmpleadoForm({ ...empleadoForm, profesion: e.target.value })} />
                        <Select
                            label="Estado"
                            value={empleadoForm.estado || 'activo'}
                            onChange={(e) => setEmpleadoForm({ ...empleadoForm, estado: e.target.value })}
                            options={[{ value: 'activo', label: 'Activo' }, { value: 'inactivo', label: 'Inactivo' }]}
                        />
                    </div>
                    <label className="block">
                        <span className="mb-1 block text-sm font-medium">Dirección</span>
                        <textarea
                            className="w-full rounded-lg border border-stone-300 bg-white px-3 py-2 text-sm dark:border-white/10 dark:bg-wa-header"
                            rows={2}
                            value={empleadoForm.direccion || ''}
                            onChange={(e) => setEmpleadoForm({ ...empleadoForm, direccion: e.target.value })}
                        />
                    </label>

                    <div className="space-y-3 rounded-lg border border-stone-300 p-3 dark:border-white/10">
                        <p className="text-sm font-medium">Asignación de puesto</p>
                        <div className="grid grid-cols-2 gap-3">
                            <Select
                                label="Puesto"
                                value={empleadoForm.puestoId || ''}
                                onChange={(e) => setEmpleadoForm({ ...empleadoForm, puestoId: e.target.value })}
                                options={puestoOptions}
                                placeholder="Seleccione un puesto"
                                required
                            />
                            <DatePicker label="Fecha de inicio" value={empleadoForm.fechaInicio || ''} onChange={(v) => setEmpleadoForm({ ...empleadoForm, fechaInicio: v })} required />
                            <Select
                                label="Supervisor (opcional)"
                                value={empleadoForm.supervisorId || ''}
                                onChange={(e) => setEmpleadoForm({ ...empleadoForm, supervisorId: e.target.value })}
                                options={[{ value: '', label: 'Sin supervisor' }, ...supervisorOptions]}
                            />
                        </div>
                        <p className="text-xs text-stone-500 dark:text-wa-muted">
                            El empleado debe estar activo para asignarle un puesto.
                        </p>
                    </div>

                    <div className="flex flex-wrap gap-2">
                        <Button type="submit" loading={guardandoEmpleado} disabled={empleadoForm.estado !== 'activo'}>
                            Crear y seleccionar
                        </Button>
                        <Button type="button" variant="secondary" onClick={volverAlUsuario}>Cancelar</Button>
                    </div>
                </form>
            </Modal>

            <Modal isOpen={modal === 'view'} onClose={() => setModal(null)} title="Detalle del usuario">
                {viewUser && (
                    <dl className="grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
                        <div>
                            <dt className="text-stone-500 dark:text-wa-muted">Usuario</dt>
                            <dd className="font-mono">{viewUser.username}</dd>
                        </div>
                        <div>
                            <dt className="text-stone-500 dark:text-wa-muted">Estado</dt>
                            <dd>
                                <span className={`badge ${viewUser.isActive ? 'badge-success' : 'badge-danger'}`}>{viewUser.isActive ? 'Activo' : 'Inactivo'}</span>
                                {viewUser.canBackfill && <span className="badge badge-warning ml-1">Retroactivo</span>}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-stone-500 dark:text-wa-muted">Correo</dt>
                            <dd>{viewUser.email || '—'}</dd>
                        </div>
                        <div>
                            <dt className="text-stone-500 dark:text-wa-muted">Rol</dt>
                            <dd><span className="badge badge-primary">{viewUser.role}</span></dd>
                        </div>
                        <div className="col-span-2">
                            <dt className="text-stone-500 dark:text-wa-muted">Empleado / Puesto / Área</dt>
                            <dd>{empleadoLabel(viewUser)}</dd>
                        </div>
                        <div>
                            <dt className="text-stone-500 dark:text-wa-muted">Registro Retroactivo</dt>
                            <dd>{viewUser.canBackfill ? 'Habilitado' : 'No habilitado'}</dd>
                        </div>
                        <div>
                            <dt className="text-stone-500 dark:text-wa-muted">Creado</dt>
                            <dd>{viewUser.created_at ? new Date(viewUser.created_at).toLocaleString() : '—'}</dd>
                        </div>
                    </dl>
                )}
            </Modal>

            <Modal isOpen={modal === 'deactivate'} onClose={() => setModal(null)} title="Desactivar usuario" size="sm">
                <p className="mb-4 text-sm">¿Desactivar a {form.username}? (no se borra)</p>
                <Button variant="danger" onClick={deactivate}>Desactivar</Button>
            </Modal>
        </RoleGuard>
    );
}
