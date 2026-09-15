import { useEffect, useRef, useState } from 'react';
import { renderAsync } from 'docx-preview';
import api, { todayLocal } from '../lib/api';
import { RoleGuard } from '../components/layout/RoleGuard';
import { Button, DatePicker, Input, Modal, Select } from '../components/ui/controls';
import { useToast } from '../components/ui/Toast';

export default function Reports() {
    const { toastSuccess, toastError } = useToast();
    const [templates, setTemplates] = useState([]);
    const [form, setForm] = useState({ templateId: '', mode: 'WEEK', weekStart: todayLocal(), date: todayLocal(), nroCI: '', dirigidoA: '', puestoDirigidoA: '' });
    const [busy, setBusy] = useState(false);
    const [upload, setUpload] = useState({ name: '', file: null });
    const [previewOpen, setPreviewOpen] = useState(false);
    const [previewBlob, setPreviewBlob] = useState(null);
    const previewRef = useRef(null);

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

    const missing = () => {
        const req = [['nroCI', 'N° informe'], ['dirigidoA', 'Dirigido a'], ['puestoDirigidoA', 'Puesto']];
        return req.filter(([k]) => !String(form[k] || '').trim()).map(([, l]) => l);
    };

    const payload = () => ({ ...form, templateId: form.templateId || null });

    const saveBlob = (blob, name) => {
        const url = URL.createObjectURL(blob instanceof Blob ? blob : new Blob([blob]));
        const a = document.createElement('a');
        a.href = url;
        a.download = name;
        document.body.appendChild(a);
        a.click();
        a.remove();
        URL.revokeObjectURL(url);
    };

    // Los errores llegan como blob: se leen como texto para mostrar el mensaje real.
    const blobError = async (err) => {
        try {
            const blob = err.response?.data;
            const text = blob instanceof Blob ? await blob.text() : null;
            if (text) {
                const j = JSON.parse(text);
                if (j.error) return j.error;
                if (j.message) return j.message;
            }
        } catch { /* no es JSON */ }
        return 'Error al procesar el informe.';
    };

    const doPreview = async () => {
        const m = missing();
        if (m.length) {
            toastError(`Completa antes: ${m.join(', ')}.`);
            return;
        }
        setBusy(true);
        try {
            const r = await api.post('/reports/preview', payload(), { responseType: 'blob' });
            const blob = r.data instanceof Blob ? r.data : new Blob([r.data]);
            if (previewRef.current) previewRef.current.innerHTML = '';
            setPreviewBlob(blob);
            setPreviewOpen(true);
            // Se renderiza tras abrir el modal (el contenedor debe existir en el DOM).
            requestAnimationFrame(async () => {
                try {
                    if (previewRef.current) await renderAsync(await blob.arrayBuffer(), previewRef.current);
                } catch {
                    toastError('No se pudo mostrar la vista previa.');
                }
            });
        } catch (err) {
            toastError(await blobError(err));
        } finally {
            setBusy(false);
        }
    };

    const doGenerate = async () => {
        const m = missing();
        if (m.length) {
            toastError(`Completa antes: ${m.join(', ')}.`);
            return;
        }
        setBusy(true);
        try {
            // generate devuelve JSON con el id; el .docx real se descarga aparte.
            const r = await api.post('/reports/generate', payload());
            const { id, fileName } = r.data.data;
            const d = await api.get(`/reports/${id}/download`, { responseType: 'blob' });
            saveBlob(d.data, fileName || `informe-${form.nroCI}.docx`);
            toastSuccess('Informe generado.');
        } catch (err) {
            toastError(err.response?.data?.error || err.response?.data?.message || 'Error al procesar el informe.');
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
                        <Button loading={busy} onClick={doPreview} variant="secondary">Vista previa</Button>
                        <Button loading={busy} onClick={doGenerate}>Generar</Button>
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

            <Modal isOpen={previewOpen} onClose={() => setPreviewOpen(false)} title="Vista previa del informe" size="lg">
                <div ref={previewRef} className="rounded-lg bg-white p-4 text-black docx-preview" />
                <div className="mt-4 flex justify-end gap-2">
                    <Button variant="secondary" onClick={() => setPreviewOpen(false)}>Cerrar</Button>
                    <Button onClick={() => previewBlob && saveBlob(previewBlob, 'vista-previa.docx')}>Descargar .docx</Button>
                </div>
            </Modal>
        </RoleGuard>
    );
}
