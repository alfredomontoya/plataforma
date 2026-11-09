import { useEffect, useMemo, useState } from 'react';
import { PencilSquareIcon, PlusIcon, TagIcon } from '@heroicons/react/24/outline';
import api from '../lib/api';
import { RoleGuard } from '../components/layout/RoleGuard';
import { Button, Input, Modal } from '../components/ui/controls';
import { useToast } from '../components/ui/Toast';

const EMPTY_TIPO = { name: '', codigo: '', requiereItems: false, requiereTicket: true, sortOrder: 0, isActive: true };
const EMPTY_ITEM = { name: '', codigo: '', sortOrder: 0, isActive: true };

const itemIdsOf = (service) => (service?.entrega_items ?? service?.entregaItems ?? []).map((i) => i.id);

export default function TiposEntrega() {
    const { toastSuccess, toastError } = useToast();
    const [tipos, setTipos] = useState([]);
    const [items, setItems] = useState([]);
    const [services, setServices] = useState([]);
    const [loading, setLoading] = useState(true);

    const [tipoModal, setTipoModal] = useState(false);
    const [tipoForm, setTipoForm] = useState(EMPTY_TIPO);
    const [tipoEditing, setTipoEditing] = useState(null);
    const [tipoSaving, setTipoSaving] = useState(false);

    const [itemModal, setItemModal] = useState(false);
    const [itemForm, setItemForm] = useState(EMPTY_ITEM);
    const [itemEditing, setItemEditing] = useState(null);
    const [itemSaving, setItemSaving] = useState(false);

    const [serviceTarget, setServiceTarget] = useState(null);
    const [serviceItems, setServiceItems] = useState([]);
    const [serviceSaving, setServiceSaving] = useState(false);

    const activeItems = useMemo(() => items.filter((i) => i.isActive), [items]);

    const load = async () => {
        setLoading(true);
        try {
            const [t, i, s] = await Promise.all([
                api.get('/tipos-registro/admin', { params: { limit: 100 } }),
                api.get('/entrega-items/admin', { params: { limit: 100 } }),
                api.get('/services/admin', { params: { limit: 100, isActive: true } }),
            ]);
            setTipos(t.data?.data ?? []);
            setItems(i.data?.data ?? []);
            setServices(s.data?.data ?? []);
        } catch (err) {
            toastError(err.response?.data?.error || 'No se pudieron cargar los catálogos.');
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        load();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const openNewTipo = () => {
        setTipoEditing(null);
        setTipoForm({ ...EMPTY_TIPO, sortOrder: tipos.length + 1 });
        setTipoModal(true);
    };

    const openEditTipo = (tipo) => {
        setTipoEditing(tipo);
        setTipoForm({
            name: tipo.name,
            codigo: tipo.codigo,
            requiereItems: !!tipo.requiereItems,
            requiereTicket: tipo.requiereTicket !== false,
            sortOrder: tipo.sortOrder ?? 0,
            isActive: !!tipo.isActive,
        });
        setTipoModal(true);
    };

    const saveTipo = async (e) => {
        e.preventDefault();
        setTipoSaving(true);
        try {
            const payload = {
                name: tipoForm.name.trim(),
                requiereItems: !!tipoForm.requiereItems,
                requiereTicket: !!tipoForm.requiereTicket,
                sortOrder: Number(tipoForm.sortOrder) || 0,
                isActive: !!tipoForm.isActive,
            };
            if (tipoEditing) {
                await api.patch(`/tipos-registro/admin/${tipoEditing.id}`, payload);
                toastSuccess('Tipo de registro actualizado.');
            } else {
                await api.post('/tipos-registro/admin', {
                    ...payload,
                    codigo: tipoForm.codigo.trim(),
                });
                toastSuccess('Tipo de registro creado.');
            }
            setTipoModal(false);
            load();
        } catch (err) {
            const details = err.response?.data?.details;
            const first = details ? Object.values(details)[0]?.[0] : null;
            toastError(first || err.response?.data?.error || 'No se pudo guardar el tipo de registro.');
        } finally {
            setTipoSaving(false);
        }
    };

    const toggleTipo = async (tipo) => {
        try {
            await api.patch(`/tipos-registro/admin/${tipo.id}`, { isActive: !tipo.isActive });
            toastSuccess(tipo.isActive ? 'Tipo desactivado.' : 'Tipo reactivado.');
            load();
        } catch (err) {
            toastError(err.response?.data?.error || 'No se pudo actualizar el estado.');
        }
    };

    const openNewItem = () => {
        setItemEditing(null);
        setItemForm({ ...EMPTY_ITEM, sortOrder: items.length + 1 });
        setItemModal(true);
    };

    const openEditItem = (item) => {
        setItemEditing(item);
        setItemForm({
            name: item.name,
            codigo: item.codigo,
            sortOrder: item.sortOrder ?? 0,
            isActive: !!item.isActive,
        });
        setItemModal(true);
    };

    const saveItem = async (e) => {
        e.preventDefault();
        setItemSaving(true);
        try {
            const payload = {
                name: itemForm.name.trim(),
                codigo: itemForm.codigo.trim(),
                sortOrder: Number(itemForm.sortOrder) || 0,
                isActive: !!itemForm.isActive,
            };
            if (itemEditing) {
                await api.patch(`/entrega-items/admin/${itemEditing.id}`, payload);
                toastSuccess('Ítem actualizado.');
            } else {
                await api.post('/entrega-items/admin', payload);
                toastSuccess('Ítem creado.');
            }
            setItemModal(false);
            load();
        } catch (err) {
            const details = err.response?.data?.details;
            const first = details ? Object.values(details)[0]?.[0] : null;
            toastError(first || err.response?.data?.error || 'No se pudo guardar el ítem.');
        } finally {
            setItemSaving(false);
        }
    };

    const toggleItem = async (item) => {
        try {
            await api.patch(`/entrega-items/admin/${item.id}`, { isActive: !item.isActive });
            toastSuccess(item.isActive ? 'Ítem desactivado.' : 'Ítem reactivado.');
            load();
        } catch (err) {
            toastError(err.response?.data?.error || 'No se pudo actualizar el estado.');
        }
    };

    const openServiceItems = (service) => {
        setServiceTarget(service);
        setServiceItems(itemIdsOf(service));
    };

    const toggleServiceItem = (id) => {
        setServiceItems((prev) => (prev.includes(id)
            ? prev.filter((x) => x !== id)
            : [...prev, id]));
    };

    const saveServiceItems = async () => {
        if (!serviceTarget) return;
        setServiceSaving(true);
        try {
            await api.put(`/services/admin/${serviceTarget.id}/entrega-items`, {
                entregaItems: serviceItems,
            });
            toastSuccess('Ítems del trámite actualizados.');
            setServiceTarget(null);
            load();
        } catch (err) {
            toastError(err.response?.data?.error || 'No se pudieron guardar los ítems del trámite.');
        } finally {
            setServiceSaving(false);
        }
    };

    return (
        <RoleGuard allowed={['ADMIN']}>
            <div className="space-y-4">
                <div className="card p-5">
                    <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h1 className="text-lg font-semibold">Tipos de entrega</h1>
                            <p className="text-xs text-stone-500 dark:text-wa-muted">
                                Administra los tipos de registro, los ítems de entrega y su configuración por trámite.
                            </p>
                        </div>
                        <Button onClick={openNewTipo} disabled={loading}>
                            <PlusIcon className="mr-1 h-5 w-5" /> Nuevo tipo
                        </Button>
                    </div>
                </div>

                {/* Tipos de registro */}
                <div className="card p-5">
                    <div className="mb-3 flex items-center gap-2">
                        <TagIcon className="h-5 w-5 text-primary-600" />
                        <h2 className="text-base font-semibold">Tipos de registro</h2>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="table min-w-[640px]">
                            <thead>
                                <tr>
                                    <th>Nombre</th>
                                    <th>Código</th>
                                    <th>¿Solicita ítems?</th>
                                    <th>¿Pide ticket?</th>
                                    <th>Orden</th>
                                    <th>Estado</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                {tipos.length === 0 ? (
                                    <tr><td colSpan={7} className="text-sm text-stone-500">Sin tipos registrados.</td></tr>
                                ) : tipos.map((tipo) => (
                                    <tr key={tipo.id}>
                                        <td>{tipo.name}</td>
                                        <td className="font-mono">{tipo.codigo}</td>
                                        <td>{tipo.requiereItems ? 'Sí' : 'No'}</td>
                                        <td>{tipo.requiereTicket !== false ? 'Sí' : 'No'}</td>
                                        <td>{tipo.sortOrder}</td>
                                        <td>
                                            <span className={`badge ${tipo.isActive ? 'badge-success' : 'badge-danger'}`}>
                                                {tipo.isActive ? 'Activo' : 'Inactivo'}
                                            </span>
                                        </td>
                                        <td>
                                            <div className="flex flex-wrap gap-2">
                                                <Button size="sm" variant="secondary" onClick={() => openEditTipo(tipo)}>
                                                    <PencilSquareIcon className="mr-1 h-4 w-4" /> Editar
                                                </Button>
                                                <Button size="sm" variant={tipo.isActive ? 'danger' : 'secondary'} onClick={() => toggleTipo(tipo)}>
                                                    {tipo.isActive ? 'Desactivar' : 'Reactivar'}
                                                </Button>
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>

                {/* Ítems de entrega */}
                <div className="card p-5">
                    <div className="mb-3 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <h2 className="text-base font-semibold">Ítems de entrega</h2>
                        <Button size="sm" onClick={openNewItem} disabled={loading}>
                            <PlusIcon className="mr-1 h-4 w-4" /> Nuevo ítem
                        </Button>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="table min-w-[560px]">
                            <thead>
                                <tr>
                                    <th>Nombre</th>
                                    <th>Código</th>
                                    <th>Orden</th>
                                    <th>Estado</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                {items.length === 0 ? (
                                    <tr><td colSpan={5} className="text-sm text-stone-500">Sin ítems registrados.</td></tr>
                                ) : items.map((item) => (
                                    <tr key={item.id}>
                                        <td>{item.name}</td>
                                        <td className="font-mono">{item.codigo}</td>
                                        <td>{item.sortOrder}</td>
                                        <td>
                                            <span className={`badge ${item.isActive ? 'badge-success' : 'badge-danger'}`}>
                                                {item.isActive ? 'Activo' : 'Inactivo'}
                                            </span>
                                        </td>
                                        <td>
                                            <div className="flex flex-wrap gap-2">
                                                <Button size="sm" variant="secondary" onClick={() => openEditItem(item)}>
                                                    <PencilSquareIcon className="mr-1 h-4 w-4" /> Editar
                                                </Button>
                                                <Button size="sm" variant={item.isActive ? 'danger' : 'secondary'} onClick={() => toggleItem(item)}>
                                                    {item.isActive ? 'Desactivar' : 'Reactivar'}
                                                </Button>
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>

                {/* Ítems por trámite */}
                <div className="card p-5">
                    <div className="mb-3">
                        <h2 className="text-base font-semibold">Ítems permitidos por trámite</h2>
                        <p className="text-xs text-stone-500 dark:text-wa-muted">
                            Define qué ítems se pueden marcar al registrar una entrega de cada trámite.
                        </p>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="table min-w-[640px]">
                            <thead>
                                <tr>
                                    <th>Trámite</th>
                                    <th>Código</th>
                                    <th>Ítems configurados</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                {services.length === 0 ? (
                                    <tr><td colSpan={4} className="text-sm text-stone-500">Sin trámites activos.</td></tr>
                                ) : services.map((service) => {
                                    const configured = itemIdsOf(service);
                                    const names = activeItems
                                        .filter((i) => configured.includes(i.id))
                                        .map((i) => i.codigo);
                                    return (
                                        <tr key={service.id}>
                                            <td>{service.name}</td>
                                            <td className="font-mono">{service.codigo || '—'}</td>
                                            <td>
                                                {names.length === 0 ? (
                                                    <span className="text-xs text-stone-400">Sin configurar</span>
                                                ) : (
                                                    <span className="flex flex-wrap gap-1">
                                                        {names.map((code) => (
                                                            <span key={code} className="inline-block rounded bg-stone-100 px-1.5 py-0.5 text-[11px] font-medium text-stone-700 dark:bg-white/10 dark:text-wa-text">
                                                                {code}
                                                            </span>
                                                        ))}
                                                    </span>
                                                )}
                                            </td>
                                            <td>
                                                <Button size="sm" variant="secondary" onClick={() => openServiceItems(service)}>
                                                    Configurar ítems
                                                </Button>
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            {/* Modal tipo */}
            <Modal
                isOpen={tipoModal}
                onClose={() => !tipoSaving && setTipoModal(false)}
                title={tipoEditing ? 'Editar tipo de registro' : 'Nuevo tipo de registro'}
            >
                <form onSubmit={saveTipo} className="space-y-3">
                    <Input
                        label="Nombre"
                        value={tipoForm.name}
                        onChange={(e) => setTipoForm({ ...tipoForm, name: e.target.value })}
                        required
                    />
                    <Input
                        label="Código"
                        value={tipoForm.codigo}
                        onChange={(e) => setTipoForm({ ...tipoForm, codigo: e.target.value.toUpperCase() })}
                        disabled={!!tipoEditing}
                        helperText={tipoEditing ? 'El código no se puede modificar.' : 'Solo letras, números y guion bajo.'}
                        required
                    />
                    <label className="flex items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            checked={tipoForm.requiereItems}
                            onChange={(e) => setTipoForm({ ...tipoForm, requiereItems: e.target.checked })}
                        />
                        Solicita ítems de entrega
                    </label>
                    <label className="flex items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            checked={tipoForm.requiereTicket}
                            onChange={(e) => setTipoForm({ ...tipoForm, requiereTicket: e.target.checked })}
                        />
                        Solicita ticket obligatorio
                    </label>
                    <div className="grid grid-cols-2 gap-3">
                        <Input
                            label="Orden"
                            type="number"
                            min={0}
                            value={tipoForm.sortOrder}
                            onChange={(e) => setTipoForm({ ...tipoForm, sortOrder: e.target.value })}
                        />
                        <label className="flex items-end gap-2 pb-2 text-sm">
                            <input
                                type="checkbox"
                                checked={tipoForm.isActive}
                                onChange={(e) => setTipoForm({ ...tipoForm, isActive: e.target.checked })}
                            />
                            Activo
                        </label>
                    </div>
                    <div className="flex justify-end gap-2 pt-2">
                        <Button type="button" variant="secondary" onClick={() => setTipoModal(false)} disabled={tipoSaving}>
                            Cancelar
                        </Button>
                        <Button type="submit" loading={tipoSaving}>Guardar</Button>
                    </div>
                </form>
            </Modal>

            {/* Modal ítem */}
            <Modal
                isOpen={itemModal}
                onClose={() => !itemSaving && setItemModal(false)}
                title={itemEditing ? 'Editar ítem' : 'Nuevo ítem'}
            >
                <form onSubmit={saveItem} className="space-y-3">
                    <Input
                        label="Nombre"
                        value={itemForm.name}
                        onChange={(e) => setItemForm({ ...itemForm, name: e.target.value })}
                        required
                    />
                    <Input
                        label="Código"
                        value={itemForm.codigo}
                        onChange={(e) => setItemForm({ ...itemForm, codigo: e.target.value.toUpperCase() })}
                        helperText="Solo letras, números y guion bajo."
                        required
                    />
                    <div className="grid grid-cols-2 gap-3">
                        <Input
                            label="Orden"
                            type="number"
                            min={0}
                            value={itemForm.sortOrder}
                            onChange={(e) => setItemForm({ ...itemForm, sortOrder: e.target.value })}
                        />
                        <label className="flex items-end gap-2 pb-2 text-sm">
                            <input
                                type="checkbox"
                                checked={itemForm.isActive}
                                onChange={(e) => setItemForm({ ...itemForm, isActive: e.target.checked })}
                            />
                            Activo
                        </label>
                    </div>
                    <div className="flex justify-end gap-2 pt-2">
                        <Button type="button" variant="secondary" onClick={() => setItemModal(false)} disabled={itemSaving}>
                            Cancelar
                        </Button>
                        <Button type="submit" loading={itemSaving}>Guardar</Button>
                    </div>
                </form>
            </Modal>

            {/* Modal ítems por trámite */}
            <Modal
                isOpen={!!serviceTarget}
                onClose={() => !serviceSaving && setServiceTarget(null)}
                title="Ítems permitidos por trámite"
            >
                <div className="space-y-4">
                    <p className="text-sm text-stone-600 dark:text-wa-muted">
                        Trámite: <span className="font-semibold text-stone-900 dark:text-wa-text">{serviceTarget?.name}</span>
                    </p>
                    <div className="space-y-2 rounded-lg border border-stone-200 p-3 dark:border-white/10">
                        {activeItems.length === 0 ? (
                            <p className="text-xs text-stone-500">No hay ítems activos.</p>
                        ) : activeItems.map((item) => (
                            <label key={item.id} className="flex items-center gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    checked={serviceItems.includes(item.id)}
                                    onChange={() => toggleServiceItem(item.id)}
                                />
                                <span>{item.name}</span>
                                <span className="text-[11px] text-stone-400">({item.codigo})</span>
                            </label>
                        ))}
                    </div>
                    <div className="flex justify-end gap-2">
                        <Button type="button" variant="secondary" onClick={() => setServiceTarget(null)} disabled={serviceSaving}>
                            Cancelar
                        </Button>
                        <Button type="button" loading={serviceSaving} onClick={saveServiceItems}>
                            Guardar
                        </Button>
                    </div>
                </div>
            </Modal>
        </RoleGuard>
    );
}
