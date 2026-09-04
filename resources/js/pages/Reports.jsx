import { useEffect, useState } from 'react';
import api, { todayLocal } from '../lib/api';
import { RoleGuard } from '../components/layout/RoleGuard';
import { Button, DatePicker, Input, Select } from '../components/ui/controls';
import { useToast } from '../components/ui/Toast';

export default function Reports() {
    const { toastSuccess, toastError } = useToast();
    const [templates, setTemplates] = useState([]);
    const [form, setForm] = useState({ templateId: '', mode: 'WEEK', weekStart: todayLocal(), date: todayLocal(), nroCI: '', dirigidoA: '', puestoDirigidoA: '' });
    const [busy, setBusy] = useState(false);
    const [upload, setUpload] = useState({ name: '', file: null });

    const loadTemplates = async () => {
        try {
            const r = await api.get('/templates');
            setTemplates(r.data.data);
            const def = r.data.data.find((t) => t.isDefault);
            if (def) setForm((f) => ({ ...f, templateId: f.templateId || def.id }));
        } catch {
            toastError('No se pudo cargar plantillas.');
        }
    };

    useEffect(() => {
        loadTemplates();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const download = async (preview) => {
        setBusy(true);
        try {
            const r = await api.post(`/reports/${preview ? 'preview' : 'generate'}`, form, { responseType: 'blob' });
            const url = URL.createObjectURL(new Blob([r.data]));
            const a = document.createElement('a');
            a.href = url;
            a.download = preview ? 'vista-previa.docx' : `informe-${form.nroCI}.docx`;
            a.click();
            URL.revokeObjectURL(url);
            if (!preview) toastSuccess('Informe generado.');
        } catch {
            toastError('Error al procesar el informe.');
        } finally {
            setBusy(false);
        }
    };

    const uploadTemplate = async (e) => {
        e.preventDefault();
        if (!upload.file) return;
        try {
            await api.post('/templates', { name: upload.name, file: upload.file }, { headers: { 'Content-Type': 'multipart/form-data' } });
            setUpload({ name: '', file: null });
            toastSuccess('Plantilla subida.');
            loadTemplates();
        } catch (err) {
            toastError(err.response?.data?.error || 'Error al subir.');
        }
    };

    return (
        <RoleGuard allowed={['JEFE', 'ADMIN']}>
            <div className="space-y-4">
                <div className="card space-y-3 p-5">
                    <h1 className="text-lg font-semibold">Generar reporte</h1>
                    <div className="grid gap-3 md:grid-cols-2">
                        <Select label="Plantilla" value={form.templateId} onChange={(e) => setForm({ ...form, templateId: e.target.value })} options={templates.map((t) => ({ value: t.id, label: `${t.name}${t.isDefault ? ' (Por defecto)' : ''}` }))} />
                        <Select label="Modo" value={form.mode} onChange={(e) => setForm({ ...form, mode: e.target.value })} options={[{ value: 'DAY', label: 'Día' }, { value: 'WEEK', label: 'Semana' }]} />
                        {form.mode === 'DAY'
                            ? <DatePicker label="Fecha" value={form.date} onChange={(v) => setForm({ ...form, date: v })} />
                            : <DatePicker label="Semana (cualquier día)" value={form.weekStart} onChange={(v) => setForm({ ...form, weekStart: v })} />}
                        <Input label="N° informe" value={form.nroCI} onChange={(e) => setForm({ ...form, nroCI: e.target.value })} />
                        <Input label="Dirigido a" value={form.dirigidoA} onChange={(e) => setForm({ ...form, dirigidoA: e.target.value })} />
                        <Input label="Puesto" value={form.puestoDirigidoA} onChange={(e) => setForm({ ...form, puestoDirigidoA: e.target.value })} />
                    </div>
                    <div className="flex gap-2">
                        <Button loading={busy} onClick={() => download(true)} variant="secondary">Vista previa</Button>
                        <Button loading={busy} onClick={() => download(false)}>Generar</Button>
                    </div>
                </div>
                <div className="card space-y-3 p-5">
                    <h2 className="font-semibold">Plantillas</h2>
                    {templates.map((t) => (
                        <div key={t.id} className="flex items-center justify-between border-b border-stone-100 py-2 text-sm dark:border-white/5">
                            <span>{t.name} {t.isDefault && <span className="badge badge-primary ml-1">Por defecto</span>}</span>
                            <div className="flex gap-2">
                                {!t.isDefault && <Button size="sm" variant="secondary" onClick={() => api.patch(`/templates/${t.id}/default`).then(() => { toastSuccess('Actualizada.'); loadTemplates(); })}>Por defecto</Button>}
                                <Button size="sm" variant="danger" onClick={() => api.delete(`/templates/${t.id}`).then(() => { toastSuccess('Eliminada.'); loadTemplates(); })}>Eliminar</Button>
                            </div>
                        </div>
                    ))}
                    <form onSubmit={uploadTemplate} className="flex flex-wrap items-end gap-2">
                        <Input label="Nombre" value={upload.name} onChange={(e) => setUpload({ ...upload, name: e.target.value })} required />
                        <input type="file" accept=".docx" onChange={(e) => setUpload({ ...upload, file: e.target.files[0] })} className="text-sm" />
                        <Button type="submit">Subir .docx</Button>
                    </form>
                </div>
            </div>
        </RoleGuard>
    );
}
