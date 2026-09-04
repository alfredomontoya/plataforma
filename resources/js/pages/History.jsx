import { useEffect, useRef, useState } from 'react';
import { renderAsync } from 'docx-preview';
import api, { todayLocal } from '../lib/api';
import { RoleGuard } from '../components/layout/RoleGuard';
import { Button, DatePicker, Input } from '../components/ui/controls';
import { useToast } from '../components/ui/Toast';

export default function History() {
    const { toastSuccess, toastError } = useToast();
    const [rows, setRows] = useState([]);
    const [from, setFrom] = useState('');
    const [to, setTo] = useState('');
    const [search, setSearch] = useState('');
    const [openId, setOpenId] = useState(null);
    const previewRef = useRef(null);

    const load = async () => {
        try {
            const r = await api.get('/reports', { params: { from, to, search, limit: 20 } });
            setRows(r.data.data);
        } catch {
            toastError('No se pudo cargar el historial.');
        }
    };

    useEffect(() => {
        load();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const togglePreview = async (id) => {
        if (openId === id) {
            setOpenId(null);
            return;
        }
        setOpenId(id);
        try {
            const r = await api.get(`/reports/${id}/download`, { responseType: 'blob' });
            if (previewRef.current) {
                previewRef.current.innerHTML = '';
                await renderAsync(r.data, previewRef.current);
            }
        } catch {
            toastError('No se pudo previsualizar.');
        }
    };

    const download = async (id, fileName) => {
        const r = await api.get(`/reports/${id}/download`, { responseType: 'blob' });
        const url = URL.createObjectURL(new Blob([r.data]));
        const a = document.createElement('a');
        a.href = url;
        a.download = fileName;
        a.click();
        URL.revokeObjectURL(url);
    };

    return (
        <RoleGuard allowed={['JEFE', 'ADMIN']}>
            <div className="card space-y-4 p-5">
                <div className="flex flex-wrap items-end gap-3">
                    <DatePicker label="Desde" value={from || todayLocal()} onChange={setFrom} />
                    <DatePicker label="Hasta" value={to || todayLocal()} onChange={setTo} />
                    <Input label="Buscar" value={search} onChange={(e) => setSearch(e.target.value)} />
                    <Button onClick={load}>Filtrar</Button>
                </div>
                <table className="table">
                    <thead><tr><th>N°</th><th>Dirigido a</th><th>Modo</th><th>Totales</th><th>Acciones</th></tr></thead>
                    <tbody>
                        {rows.map((r) => (
                            <tr key={r.id} className="cursor-pointer" onClick={() => togglePreview(r.id)}>
                                <td>{r.nroCI}</td>
                                <td>{r.dirigidoA}</td>
                                <td><span className="badge badge-gray">{r.mode}</span></td>
                                <td>I:{r.totalIngreso} / E:{r.totalEntrega}</td>
                                <td onClick={(e) => e.stopPropagation()}>
                                    <Button size="sm" variant="secondary" onClick={() => download(r.id, r.fileName)}>Descargar</Button>{' '}
                                    <Button size="sm" variant="danger" onClick={() => api.delete(`/reports/${r.id}`).then(() => { if (openId === r.id) setOpenId(null); toastSuccess('Eliminado.'); load(); })}>Eliminar</Button>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
                {openId && <div ref={previewRef} className="max-h-[600px] overflow-auto border border-stone-200 bg-white p-4 dark:border-white/10 dark:bg-white" />}
            </div>
        </RoleGuard>
    );
}
