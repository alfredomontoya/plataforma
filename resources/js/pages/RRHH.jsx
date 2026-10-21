import { useEffect, useMemo, useState } from 'react';
import {
    PlusIcon,
    TrashIcon,
    PencilSquareIcon,
    ArrowPathIcon,
    ChevronDownIcon,
    ChevronRightIcon,
} from '@heroicons/react/24/outline';
import api from '../lib/api';
import { RoleGuard } from '../components/layout/RoleGuard';
import { Button, DatePicker, Input, Modal, Select } from '../components/ui/controls';
import { useToast } from '../components/ui/Toast';

const TABS = [
    ['areas', 'Áreas'],
    ['puestos', 'Puestos'],
    ['empleados', 'Empleados'],
    ['tratamientos', 'Tratamientos'],
];

const NIVELES = [
    { value: 1, label: '1 · Operativo' },
    { value: 2, label: '2 · Auxiliar' },
    { value: 3, label: '3 · Técnico' },
    { value: 4, label: '4 · Responsable' },
    { value: 5, label: '5 · Supervisor' },
    { value: 6, label: '6 · Encargado' },
    { value: 7, label: '7 · Jefe de Área' },
];

const nombreEmpleado = (e) =>
    [e?.tratamiento?.abreviatura, e?.nombre, e?.apellidos].filter(Boolean).join(' ').trim();

export default function Rrhh() {
    const { toastSuccess, toastError } = useToast();
    const [tab, setTab] = useState('areas');
    const [areas, setAreas] = useState([]);
    const [areasColapsadas, setAreasColapsadas] = useState(() => new Set());
    const [puestos, setPuestos] = useState([]);
    const [tratamientos, setTratamientos] = useState([]);
    const [empleados, setEmpleados] = useState([]);
    const [empTotal, setEmpTotal] = useState(0);
    const [empPage, setEmpPage] = useState(1);
    const [empTotalPages, setEmpTotalPages] = useState(1);
    const [filtros, setFiltros] = useState({ search: '', areaId: '', estado: 'activo' });
    const [loading, setLoading] = useState(false);
    const [modal, setModal] = useState(null);
    const [form, setForm] = useState({});
    const [detalle, setDetalle] = useState(null);
    const [supervisores, setSupervisores] = useState([]);

    const errorMsg = (err) => err.response?.data?.error || 'No se pudo completar la operación.';

    const cargarAreas = async () => {
        const r = await api.get('/rrhh/areas');
        setAreas(r.data.data);
    };
    const cargarPuestos = async () => {
        const r = await api.get('/rrhh/puestos');
        setPuestos(r.data.data);
    };
    const cargarTratamientos = async () => {
        const r = await api.get('/rrhh/tratamientos');
        setTratamientos(r.data.data);
    };
    const cargarEmpleados = async (page = empPage, filtrosActuales = filtros) => {
        setLoading(true);
        try {
            const r = await api.get('/rrhh/empleados', {
                params: { ...filtrosActuales, page, limit: 20 },
            });
            setEmpleados(r.data.data);
            setEmpTotal(r.data.total ?? 0);
            setEmpTotalPages(r.data.totalPages ?? 1);
        } catch (err) {
            toastError(errorMsg(err));
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        Promise.all([cargarAreas(), cargarPuestos(), cargarTratamientos(), cargarEmpleados(1)])
            .catch((err) => toastError(errorMsg(err)));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const recargar = async () => {
        await Promise.all([cargarAreas(), cargarPuestos(), cargarTratamientos()]);
        await cargarEmpleados(empPage);
    };

    const guardar = async (e, recurso) => {
        e.preventDefault();
        try {
            const base = `/rrhh/${recurso}`;
            if (modal === 'new') await api.post(base, form);
            else await api.patch(`${base}/${form.id}`, form);
            setModal(null);
            toastSuccess('Guardado.');
            await recargar();
        } catch (err) {
            toastError(errorMsg(err));
        }
    };

    const eliminar = async (recurso, id, mensaje) => {
        if (!window.confirm(mensaje || '¿Confirma la operación?')) return;
        try {
            await api.delete(`/rrhh/${recurso}/${id}`);
            toastSuccess('Operación realizada.');
            await recargar();
        } catch (err) {
            toastError(errorMsg(err));
        }
    };

    // ---- Empleados -------------------------------------------------------
    const abrirNuevoEmpleado = () => {
        setForm({ estado: 'activo', fechaNacimiento: '', tratamientoId: '' });
        setModal('empleado-new');
    };
    const abrirEditarEmpleado = (e) => {
        setForm({
            id: e.id,
            ci: e.ci,
            nombre: e.nombre,
            apellidos: e.apellidos,
            fechaNacimiento: e.fechaNacimiento?.slice(0, 10) || '',
            telefono: e.telefono || '',
            direccion: e.direccion || '',
            profesion: e.profesion || '',
            tratamientoId: e.tratamientoId || '',
            estado: e.estado,
        });
        setModal('empleado-edit');
    };

    const guardarEmpleado = async (ev) => {
        ev.preventDefault();
        try {
            if (modal === 'empleado-new') await api.post('/rrhh/empleados', form);
            else await api.patch(`/rrhh/empleados/${form.id}`, form);
            setModal(null);
            toastSuccess('Empleado guardado.');
            await recargar();
        } catch (err) {
            toastError(errorMsg(err));
        }
    };

    const abrirAsignar = async (e) => {
        setForm({
            empleadoId: e.id,
            empleadoNombre: nombreEmpleado(e),
            puestoId: '',
            fechaInicio: new Date().toISOString().slice(0, 10),
            supervisorId: '',
        });
        try {
            const r = await api.get('/rrhh/empleados', { params: { estado: 'activo', limit: 100 } });
            setSupervisores(r.data.data.filter((s) => s.id !== e.id));
        } catch {
            setSupervisores([]);
        }
        setModal('asignar');
    };

    const asignarPuesto = async (ev) => {
        ev.preventDefault();
        try {
            await api.post(`/rrhh/empleados/${form.empleadoId}/puestos`, {
                puestoId: form.puestoId,
                fechaInicio: form.fechaInicio || null,
                supervisorId: form.supervisorId || null,
            });
            setModal(null);
            toastSuccess('Puesto asignado. La asignación anterior quedó cerrada.');
            await cargarEmpleados(empPage);
        } catch (err) {
            toastError(errorMsg(err));
        }
    };

    const verEmpleado = async (id) => {
        try {
            const r = await api.get(`/rrhh/empleados/${id}`);
            setDetalle(r.data.data);
            setModal('empleado-ver');
        } catch (err) {
            toastError(errorMsg(err));
        }
    };

    const cerrarAsignacion = async (empleadoId, asignacionId) => {
        if (!window.confirm('¿Cerrar esta asignación con fecha de hoy?')) return;
        try {
            await api.patch(`/rrhh/empleados/${empleadoId}/puestos/${asignacionId}`);
            toastSuccess('Asignación cerrada.');
            await verEmpleado(empleadoId);
            await cargarEmpleados(empPage);
        } catch (err) {
            toastError(errorMsg(err));
        }
    };

    const areaOptions = useMemo(
        () => areas.map((a) => ({ value: a.id, label: a.codigo ? `${a.codigo} · ${a.nombre}` : a.nombre })),
        [areas]
    );

    // ---- Árbol de áreas ------------------------------------------------
    const areaChildren = useMemo(() => {
        const mapa = new Map();
        areas.forEach((a) => {
            const clave = a.padreId || '';
            if (!mapa.has(clave)) mapa.set(clave, []);
            mapa.get(clave).push(a);
        });
        const orden = (a, b) =>
            (a.codigo || '').localeCompare(b.codigo || '') || a.nombre.localeCompare(b.nombre);
        mapa.forEach((lista) => lista.sort(orden));
        return mapa;
    }, [areas]);

    const areaIds = useMemo(() => new Set(areas.map((a) => a.id)), [areas]);

    const areaRows = useMemo(() => {
        const filas = [];
        const agregarHijos = (padreId, depth) => {
            (areaChildren.get(padreId) || []).forEach((a) => {
                const tieneHijos = (areaChildren.get(a.id) || []).length > 0;
                filas.push({ ...a, depth, tieneHijos });
                if (!areasColapsadas.has(a.id)) agregarHijos(a.id, depth + 1);
            });
        };
        agregarHijos('', 0);
        // Áreas cuyo padre ya no existe: se muestran como raíz para no ocultarlas.
        areas
            .filter((a) => a.padreId && !areaIds.has(a.padreId))
            .sort((a, b) => (a.codigo || '').localeCompare(b.codigo || '') || a.nombre.localeCompare(b.nombre))
            .forEach((a) => {
                const tieneHijos = (areaChildren.get(a.id) || []).length > 0;
                filas.push({ ...a, depth: 0, tieneHijos });
                if (!areasColapsadas.has(a.id)) agregarHijos(a.id, 1);
            });
        return filas;
    }, [areas, areaChildren, areaIds, areasColapsadas]);

    const alternarArea = (id) => {
        setAreasColapsadas((prev) => {
            const next = new Set(prev);
            if (next.has(id)) next.delete(id);
            else next.add(id);
            return next;
        });
    };
    const puestoOptions = useMemo(
        () => puestos.map((p) => ({ value: p.id, label: `${p.area?.nombre || '—'} · ${p.nombre}` })),
        [puestos]
    );
    const tratamientoOptions = useMemo(
        () => tratamientos.filter((t) => t.activo).map((t) => ({ value: t.id, label: `${t.abreviatura} — ${t.nombre}` })),
        [tratamientos]
    );

    const input = (key, label, extra = {}) => (
        <Input
            label={label}
            value={form[key] ?? ''}
            onChange={(e) => setForm({ ...form, [key]: e.target.value })}
            {...extra}
        />
    );

    return (
        <RoleGuard allowed={['ADMIN']}>
            <div className="space-y-4">
                <div className="flex flex-wrap items-center gap-2">
                    {TABS.map(([key, label]) => (
                        <Button
                            key={key}
                            size="sm"
                            variant={tab === key ? 'primary' : 'secondary'}
                            onClick={() => setTab(key)}
                        >
                            {label}
                        </Button>
                    ))}
                    <Button size="sm" variant="secondary" className="ml-auto" onClick={() => recargar()}>
                        <ArrowPathIcon className="mr-1 h-4 w-4" />Actualizar
                    </Button>
                </div>

                {tab === 'areas' && (
                    <div className="card space-y-4 p-5">
                        <div className="flex flex-wrap items-end gap-3">
                            <h2 className="text-lg font-semibold">Áreas</h2>
                            <div className="ml-auto flex flex-wrap gap-2">
                                <Button size="sm" variant="secondary" onClick={() => setAreasColapsadas(new Set())}>
                                    Expandir todo
                                </Button>
                                <Button
                                    size="sm"
                                    variant="secondary"
                                    onClick={() => setAreasColapsadas(new Set(areas.filter((a) => (areaChildren.get(a.id) || []).length > 0).map((a) => a.id)))}
                                >
                                    Contraer todo
                                </Button>
                                <Button
                                    size="sm"
                                    onClick={() => {
                                        setForm({ nombre: '', codigo: '', descripcion: '', padreId: '' });
                                        setModal('area-new');
                                    }}
                                >
                                    <PlusIcon className="mr-1 h-4 w-4" />Nueva área
                                </Button>
                            </div>
                        </div>
                        <table className="table">
                            <thead>
                                <tr>
                                    <th>Código</th>
                                    <th>Área</th>
                                    <th>Padre</th>
                                    <th>Descripción</th>
                                    <th>Puestos</th>
                                    <th className="text-right">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                {areaRows.map((a) => {
                                    const colapsada = areasColapsadas.has(a.id);
                                    const hijas = areaChildren.get(a.id)?.length ?? 0;
                                    return (
                                        <tr key={a.id}>
                                            <td className="font-mono">{a.codigo || '—'}</td>
                                            <td>
                                                <div className="flex items-center" style={{ paddingLeft: `${a.depth * 1.25}rem` }}>
                                                    {a.tieneHijos ? (
                                                        <button
                                                            type="button"
                                                            onClick={() => alternarArea(a.id)}
                                                            aria-expanded={!colapsada}
                                                            aria-label={colapsada ? `Expandir ${a.nombre}` : `Contraer ${a.nombre}`}
                                                            className="mr-1 rounded p-0.5 text-stone-500 hover:bg-stone-100 dark:text-wa-muted dark:hover:bg-white/10"
                                                        >
                                                            {colapsada
                                                                ? <ChevronRightIcon className="h-4 w-4" />
                                                                : <ChevronDownIcon className="h-4 w-4" />}
                                                        </button>
                                                    ) : (
                                                        <span className="mr-1 inline-block h-4 w-4" />
                                                    )}
                                                    <span className="font-medium">{a.nombre}</span>
                                                    {hijas > 0 && (
                                                        <span className="ml-2 text-xs text-stone-400" title={`${hijas} subárea(s)`}>
                                                            ({hijas})
                                                        </span>
                                                    )}
                                                </div>
                                            </td>
                                            <td>{a.padre?.nombre || '—'}</td>
                                            <td className="max-w-md truncate" title={a.descripcion}>{a.descripcion || '—'}</td>
                                            <td>{a.puestos_count}</td>
                                            <td className="whitespace-nowrap text-right">
                                                <Button size="sm" variant="secondary" onClick={() => { setForm(a); setModal('area-edit'); }}>
                                                    <PencilSquareIcon className="mr-1 h-4 w-4" />Editar
                                                </Button>{' '}
                                                <Button size="sm" variant="danger" onClick={() => eliminar('areas', a.id, `¿Eliminar el área "${a.nombre}"?`)}>
                                                    <TrashIcon className="mr-1 h-4 w-4" />Eliminar
                                                </Button>
                                            </td>
                                        </tr>
                                    );
                                })}
                                {!areaRows.length && (
                                    <tr><td colSpan={6} className="py-6 text-center text-sm text-stone-500">Sin áreas registradas.</td></tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                )}

                {tab === 'puestos' && (
                    <div className="card space-y-4 p-5">
                        <div className="flex items-end gap-3">
                            <h2 className="text-lg font-semibold">Puestos</h2>
                            <Button
                                size="sm"
                                className="ml-auto"
                                disabled={!areas.length}
                                onClick={() => {
                                    setForm({ areaId: areas[0]?.id || '', nombre: '', sigla: '', nivel: 1, esSupervisor: false });
                                    setModal('puesto-new');
                                }}
                            >
                                <PlusIcon className="mr-1 h-4 w-4" />Nuevo puesto
                            </Button>
                        </div>
                        {!areas.length && <p className="text-sm text-stone-500">Cree primero un área.</p>}
                        <table className="table">
                            <thead>
                                <tr>
                                    <th>Puesto</th>
                                    <th>Sigla</th>
                                    <th>Área</th>
                                    <th>Nivel</th>
                                    <th>Supervisor</th>
                                    <th>Asignaciones</th>
                                    <th className="text-right">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                {puestos.map((p) => (
                                    <tr key={p.id}>
                                        <td className="font-medium">{p.nombre}</td>
                                        <td className="font-mono">{p.sigla || '—'}</td>
                                        <td>{p.area?.nombre || '—'}</td>
                                        <td>{p.nivel}</td>
                                        <td>{p.esSupervisor ? 'Sí' : 'No'}</td>
                                        <td>{p.asignaciones_count}</td>
                                        <td className="whitespace-nowrap text-right">
                                            <Button size="sm" variant="secondary" onClick={() => { setForm({ ...p, areaId: p.areaId }); setModal('puesto-edit'); }}>
                                                <PencilSquareIcon className="mr-1 h-4 w-4" />Editar
                                            </Button>{' '}
                                            <Button size="sm" variant="danger" onClick={() => eliminar('puestos', p.id, `¿Eliminar el puesto "${p.nombre}"?`)}>
                                                <TrashIcon className="mr-1 h-4 w-4" />Eliminar
                                            </Button>
                                        </td>
                                    </tr>
                                ))}
                                {!puestos.length && (
                                    <tr><td colSpan={7} className="py-6 text-center text-sm text-stone-500">Sin puestos registrados.</td></tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                )}

                {tab === 'empleados' && (
                    <div className="card space-y-4 p-5">
                        <div className="flex flex-wrap items-end gap-3">
                            <Input
                                label="Buscar (CI, nombre o apellido)"
                                value={filtros.search}
                                onChange={(e) => setFiltros({ ...filtros, search: e.target.value })}
                            />
                            <Select
                                label="Área"
                                value={filtros.areaId}
                                onChange={(e) => setFiltros({ ...filtros, areaId: e.target.value })}
                                options={[{ value: '', label: 'Todas' }, ...areaOptions]}
                            />
                            <Select
                                label="Estado"
                                value={filtros.estado}
                                onChange={(e) => setFiltros({ ...filtros, estado: e.target.value })}
                                options={[
                                    { value: '', label: 'Todos' },
                                    { value: 'activo', label: 'Activos' },
                                    { value: 'inactivo', label: 'Inactivos' },
                                ]}
                            />
                            <Button
                                onClick={() => {
                                    setEmpPage(1);
                                    cargarEmpleados(1);
                                }}
                            >
                                Filtrar
                            </Button>
                            <Button size="sm" className="ml-auto" onClick={abrirNuevoEmpleado}>
                                <PlusIcon className="mr-1 h-4 w-4" />Nuevo empleado
                            </Button>
                        </div>

                        <table className="table">
                            <thead>
                                <tr>
                                    <th>CI</th>
                                    <th>Empleado</th>
                                    <th>Puesto actual</th>
                                    <th>Área</th>
                                    <th>Usuario</th>
                                    <th>Estado</th>
                                    <th className="text-right">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                {empleados.map((e) => {
                                    const actual = e.puesto_actual;
                                    return (
                                        <tr key={e.id}>
                                            <td className="font-mono">{e.ci}</td>
                                            <td className="font-medium">{nombreEmpleado(e)}</td>
                                            <td>{actual?.puesto?.nombre || '—'}</td>
                                            <td>{actual?.puesto?.area?.nombre || '—'}</td>
                                            <td className="font-mono">{e.usuario?.username || '—'}</td>
                                            <td>
                                                <span className={`badge ${e.estado === 'activo' ? 'badge-success' : 'badge-danger'}`}>
                                                    {e.estado}
                                                </span>
                                            </td>
                                            <td className="whitespace-nowrap text-right">
                                                <Button size="sm" variant="secondary" onClick={() => verEmpleado(e.id)}>Ver</Button>{' '}
                                                <Button size="sm" variant="secondary" onClick={() => abrirEditarEmpleado(e)}>Editar</Button>{' '}
                                                <Button size="sm" variant="secondary" disabled={e.estado !== 'activo'} onClick={() => abrirAsignar(e)}>Puesto</Button>{' '}
                                                <Button
                                                    size="sm"
                                                    variant="danger"
                                                    disabled={e.estado !== 'activo'}
                                                    onClick={() => eliminar('empleados', e.id, `¿Dar de baja a ${nombreEmpleado(e)}?`)}
                                                >
                                                    Baja
                                                </Button>
                                            </td>
                                        </tr>
                                    );
                                })}
                                {!empleados.length && (
                                    <tr><td colSpan={7} className="py-6 text-center text-sm text-stone-500">{loading ? 'Cargando…' : 'Sin empleados.'}</td></tr>
                                )}
                            </tbody>
                        </table>

                        <div className="flex items-center gap-2">
                            <Button size="sm" variant="secondary" disabled={empPage <= 1} onClick={() => { const p = empPage - 1; setEmpPage(p); cargarEmpleados(p); }}>‹</Button>
                            <span className="text-sm">{empPage} / {empTotalPages}</span>
                            <Button size="sm" variant="secondary" disabled={empPage >= empTotalPages} onClick={() => { const p = empPage + 1; setEmpPage(p); cargarEmpleados(p); }}>›</Button>
                            <span className="ml-auto text-sm text-stone-500 dark:text-wa-muted">{empTotal} empleado(s)</span>
                        </div>
                    </div>
                )}

                {tab === 'tratamientos' && (
                    <div className="card space-y-4 p-5">
                        <div className="flex items-end gap-3">
                            <h2 className="text-lg font-semibold">Tratamientos</h2>
                            <Button
                                size="sm"
                                className="ml-auto"
                                onClick={() => {
                                    setForm({ nombre: '', abreviatura: '', activo: true });
                                    setModal('tratamiento-new');
                                }}
                            >
                                <PlusIcon className="mr-1 h-4 w-4" />Nuevo
                            </Button>
                        </div>
                        <table className="table">
                            <thead>
                                <tr><th>Tratamiento</th><th>Abreviatura</th><th>Estado</th><th className="text-right">Acciones</th></tr>
                            </thead>
                            <tbody>
                                {tratamientos.map((t) => (
                                    <tr key={t.id}>
                                        <td className="font-medium">{t.nombre}</td>
                                        <td>{t.abreviatura}</td>
                                        <td><span className={`badge ${t.activo ? 'badge-success' : 'badge-danger'}`}>{t.activo ? 'Activo' : 'Inactivo'}</span></td>
                                        <td className="whitespace-nowrap text-right">
                                            <Button size="sm" variant="secondary" onClick={() => { setForm(t); setModal('tratamiento-edit'); }}>Editar</Button>{' '}
                                            <Button size="sm" variant="danger" onClick={() => eliminar('tratamientos', t.id, `¿Eliminar el tratamiento "${t.nombre}"?`)}>Eliminar</Button>
                                        </td>
                                    </tr>
                                ))}
                                {!tratamientos.length && (
                                    <tr><td colSpan={4} className="py-6 text-center text-sm text-stone-500">Sin tratamientos.</td></tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>

            {/* Área */}
            <Modal isOpen={modal === 'area-new' || modal === 'area-edit'} onClose={() => setModal(null)} title="Área">
                <form onSubmit={(e) => guardar(e, 'areas')} className="space-y-3">
                    <div className="grid grid-cols-2 gap-3">
                        {input('codigo', 'Código', { maxLength: 30 })}
                        {input('nombre', 'Nombre', { required: true })}
                    </div>
                    <Select
                        label="Área padre"
                        value={form.padreId || ''}
                        onChange={(e) => setForm({ ...form, padreId: e.target.value || null })}
                        options={[
                            { value: '', label: 'Sin padre' },
                            ...areaOptions.filter((o) => o.value !== form.id),
                        ]}
                    />
                    <label className="block">
                        <span className="mb-1 block text-sm font-medium">Descripción</span>
                        <textarea
                            className="w-full rounded-lg border border-stone-300 bg-white px-3 py-2 text-sm dark:border-white/10 dark:bg-wa-header"
                            rows={3}
                            value={form.descripcion || ''}
                            onChange={(e) => setForm({ ...form, descripcion: e.target.value })}
                        />
                    </label>
                    <Button type="submit">Guardar</Button>
                </form>
            </Modal>

            {/* Puesto */}
            <Modal isOpen={modal === 'puesto-new' || modal === 'puesto-edit'} onClose={() => setModal(null)} title="Puesto">
                <form onSubmit={(e) => guardar(e, 'puestos')} className="space-y-3">
                    <Select label="Área" value={form.areaId || ''} onChange={(e) => setForm({ ...form, areaId: e.target.value })} options={areaOptions} required />
                    <div className="grid grid-cols-2 gap-3">
                        {input('sigla', 'Sigla', { maxLength: 10 })}
                        {input('nombre', 'Nombre', { required: true })}
                    </div>
                    <Select
                        label="Nivel"
                        value={form.nivel ?? 1}
                        onChange={(e) => setForm({ ...form, nivel: Number(e.target.value) })}
                        options={NIVELES}
                    />
                    <label className="flex items-center gap-2 text-sm">
                        <input type="checkbox" checked={!!form.esSupervisor} onChange={(e) => setForm({ ...form, esSupervisor: e.target.checked })} />
                        Es supervisor
                    </label>
                    <Button type="submit">Guardar</Button>
                </form>
            </Modal>

            {/* Empleado */}
            <Modal
                isOpen={modal === 'empleado-new' || modal === 'empleado-edit'}
                onClose={() => setModal(null)}
                title={modal === 'empleado-new' ? 'Nuevo empleado' : 'Editar empleado'}
                size="lg"
            >
                <form onSubmit={guardarEmpleado} className="space-y-3">
                    <div className="grid grid-cols-2 gap-3">
                        <Select
                            label="Tratamiento"
                            value={form.tratamientoId || ''}
                            onChange={(e) => setForm({ ...form, tratamientoId: e.target.value || null })}
                            options={[{ value: '', label: 'Sin tratamiento' }, ...tratamientoOptions]}
                        />
                        {input('ci', 'Cédula de identidad', { required: true })}
                        {input('nombre', 'Nombre', { required: true })}
                        {input('apellidos', 'Apellidos', { required: true })}
                        <DatePicker label="Fecha de nacimiento" value={form.fechaNacimiento || ''} onChange={(v) => setForm({ ...form, fechaNacimiento: v })} />
                        {input('telefono', 'Teléfono')}
                        {input('profesion', 'Profesión')}
                        <Select
                            label="Estado"
                            value={form.estado || 'activo'}
                            onChange={(e) => setForm({ ...form, estado: e.target.value })}
                            options={[{ value: 'activo', label: 'Activo' }, { value: 'inactivo', label: 'Inactivo' }]}
                        />
                    </div>
                    <label className="block">
                        <span className="mb-1 block text-sm font-medium">Dirección</span>
                        <textarea
                            className="w-full rounded-lg border border-stone-300 bg-white px-3 py-2 text-sm dark:border-white/10 dark:bg-wa-header"
                            rows={2}
                            value={form.direccion || ''}
                            onChange={(e) => setForm({ ...form, direccion: e.target.value })}
                        />
                    </label>
                    <Button type="submit">Guardar</Button>
                </form>
            </Modal>

            {/* Asignar puesto */}
            <Modal isOpen={modal === 'asignar'} onClose={() => setModal(null)} title={`Asignar puesto — ${form.empleadoNombre || ''}`}>
                <form onSubmit={asignarPuesto} className="space-y-3">
                    <Select
                        label="Puesto"
                        value={form.puestoId || ''}
                        onChange={(e) => setForm({ ...form, puestoId: e.target.value })}
                        options={puestoOptions}
                        placeholder="Seleccione un puesto"
                        required
                    />
                    <DatePicker label="Fecha de inicio" value={form.fechaInicio || ''} onChange={(v) => setForm({ ...form, fechaInicio: v })} />
                    <Select
                        label="Supervisor (opcional)"
                        value={form.supervisorId || ''}
                        onChange={(e) => setForm({ ...form, supervisorId: e.target.value })}
                        options={[{ value: '', label: 'Sin supervisor' }, ...supervisores.map((s) => ({ value: s.id, label: nombreEmpleado(s) }))]}
                    />
                    <p className="text-xs text-stone-500 dark:text-wa-muted">
                        Al asignar un puesto, la asignación vigente se cierra automáticamente el día anterior al inicio.
                    </p>
                    <Button type="submit">Asignar</Button>
                </form>
            </Modal>

            {/* Ver empleado */}
            <Modal isOpen={modal === 'empleado-ver'} onClose={() => setModal(null)} title="Ficha del empleado" size="lg">
                {detalle && (
                    <div className="space-y-4 text-sm">
                        <dl className="grid grid-cols-2 gap-x-4 gap-y-2">
                            <div><dt className="text-stone-500 dark:text-wa-muted">Empleado</dt><dd className="font-medium">{nombreEmpleado(detalle)}</dd></div>
                            <div><dt className="text-stone-500 dark:text-wa-muted">CI</dt><dd className="font-mono">{detalle.ci}</dd></div>
                            <div><dt className="text-stone-500 dark:text-wa-muted">Estado</dt><dd>{detalle.estado}</dd></div>
                            <div><dt className="text-stone-500 dark:text-wa-muted">Cuenta de sistema</dt><dd>{detalle.usuario?.username || '—'}</dd></div>
                            <div><dt className="text-stone-500 dark:text-wa-muted">Teléfono</dt><dd>{detalle.telefono || '—'}</dd></div>
                            <div><dt className="text-stone-500 dark:text-wa-muted">Profesión</dt><dd>{detalle.profesion || '—'}</dd></div>
                            <div className="col-span-2"><dt className="text-stone-500 dark:text-wa-muted">Dirección</dt><dd>{detalle.direccion || '—'}</dd></div>
                        </dl>

                        <div>
                            <h3 className="mb-2 font-semibold">Historial de puestos</h3>
                            <table className="table">
                                <thead><tr><th>Puesto</th><th>Área</th><th>Supervisor</th><th>Inicio</th><th>Fin</th><th /></tr></thead>
                                <tbody>
                                    {(detalle.asignaciones || []).map((a) => (
                                        <tr key={a.id}>
                                            <td>{a.puesto?.nombre || '—'}</td>
                                            <td>{a.puesto?.area?.nombre || '—'}</td>
                                            <td>{a.supervisor ? nombreEmpleado(a.supervisor) : '—'}</td>
                                            <td>{a.fechaInicio?.slice(0, 10)}</td>
                                            <td>{a.fechaFin?.slice(0, 10) || 'Vigente'}</td>
                                            <td className="text-right">
                                                {!a.fechaFin && (
                                                    <Button size="sm" variant="secondary" onClick={() => cerrarAsignacion(detalle.id, a.id)}>Cerrar</Button>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                    {!detalle.asignaciones?.length && (
                                        <tr><td colSpan={6} className="py-4 text-center text-stone-500">Sin asignaciones.</td></tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </div>
                )}
            </Modal>

            {/* Tratamiento */}
            <Modal isOpen={modal === 'tratamiento-new' || modal === 'tratamiento-edit'} onClose={() => setModal(null)} title="Tratamiento">
                <form onSubmit={(e) => guardar(e, 'tratamientos')} className="space-y-3">
                    {input('nombre', 'Nombre', { required: true })}
                    {input('abreviatura', 'Abreviatura', { required: true, maxLength: 10 })}
                    <label className="flex items-center gap-2 text-sm">
                        <input type="checkbox" checked={!!form.activo} onChange={(e) => setForm({ ...form, activo: e.target.checked })} />
                        Activo
                    </label>
                    <Button type="submit">Guardar</Button>
                </form>
            </Modal>
        </RoleGuard>
    );
}
