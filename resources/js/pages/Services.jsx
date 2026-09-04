import { useEffect, useState } from 'react';
import api from '../lib/api';
import { RoleGuard } from '../components/layout/RoleGuard';
import { Button, Input, Modal, Select } from '../components/ui/controls';
import { useToast } from '../components/ui/Toast';

export default function Services() {
    const { toastSuccess, toastError } = useToast();
    const [rows, setRows] = useState([]);
    const [search, setSearch] = useState('');
    const [modal, setModal] = useState(null);
    const [form, setForm] = useState({ type: 'INGRESO' });

    const load = async () => {
        try {
            const r = await api.get('/services/admin', { params: { search, limit: 50 } });
            setRows(r.data.data);
        } catch {
            toastError('No se pudo cargar servicios.');
        }
    };

    useEffect(() => {
        load();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const save = async (e) => {
        e.preventDefault();
        try {
            if (modal === 'new') await api.post('/services/admin', form);
            else await api.patch(`/services/admin/${form.id}`, form);
            setModal(null);
            toastSuccess('Guardado.');
            load();
        } catch (err) {
            toastError(err.response?.data?.error || 'Error.');
        }
    };

    return (
        <RoleGuard allowed={['ADMIN']}>
            <div className="card space-y-4 p-5">
                <div className="flex items-end gap-3">
                    <Input label="Buscar" value={search} onChange={(e) => setSearch(e.target.value)} />
                    <Button onClick={load}>Filtrar</Button>
                    <Button variant="secondary" onClick={() => { setForm({ type: 'INGRESO' }); setModal('new'); }}>Nuevo</Button>
                </div>
                <table className="table">
                    <thead>
                        <tr><th>Nombre</th><th>Abrev.</th><th>Tipo</th><th>Orden</th><th>Estado</th><th>Acciones</th></tr>
                    </thead>
                    <tbody>
                        {rows.map((s) => (
                            <tr key={s.id}>
                                <td>{s.name}</td>
                                <td>{s.abreviation}</td>
                                <td><span className="badge badge-primary">{s.type}</span></td>
                                <td>{s.sortOrder}</td>
                                <td><span className={`badge ${s.isActive ? 'badge-success' : 'badge-danger'}`}>{s.isActive ? 'Activo' : 'Inactivo'}</span></td>
                                <td>
                                    <Button size="sm" variant="secondary" onClick={() => { setForm(s); setModal('edit'); }}>Editar</Button>{' '}
                                    <Button size="sm" variant="danger" onClick={() => api.delete(`/services/admin/${s.id}`).then(() => { toastSuccess('Desactivado.'); load(); })}>Off</Button>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            <Modal isOpen={modal === 'new' || modal === 'edit'} onClose={() => setModal(null)} title="Servicio">
                <form onSubmit={save} className="space-y-3">
                    <Input label="Nombre" value={form.name || ''} onChange={(e) => setForm({ ...form, name: e.target.value })} required />
                    <div className="grid grid-cols-3 gap-3">
                        <Input label="Abreviatura" value={form.abreviation || ''} onChange={(e) => setForm({ ...form, abreviation: e.target.value })} />
                        <Select label="Tipo" value={form.type || 'INGRESO'} onChange={(e) => setForm({ ...form, type: e.target.value })} options={[{ value: 'INGRESO', label: 'INGRESO' }, { value: 'ENTREGA', label: 'ENTREGA' }]} />
                        <Input label="Orden" type="number" value={form.sortOrder ?? 0} onChange={(e) => setForm({ ...form, sortOrder: Number(e.target.value) })} />
                    </div>
                    <Button type="submit">Guardar</Button>
                </form>
            </Modal>
        </RoleGuard>
    );
}
