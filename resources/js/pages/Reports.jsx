import { useEffect, useRef, useState } from 'react';
import { renderAsync } from 'docx-preview';
import api, { todayLocal } from '../lib/api';
import { parseNro, nextNro, saveBlob } from '../lib/reportShared';
import { RoleGuard } from '../components/layout/RoleGuard';
import { Button, DatePicker, Input, Modal, Select } from '../components/ui/controls';
import { useToast } from '../components/ui/Toast';

export default function Reports() {
    const { toastSuccess, toastError } = useToast();
    const [templates, setTemplates] = useState([]);
    const [form, setForm] = useState({ templateId: '', mode: 'WEEK', weekStart: todayLocal(), date: todayLocal(), nroCI: '', dirigidoA: 'Director General', puestoDirigidoA: 'Dirección General' });
    const [busy, setBusy] = useState(false);
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

    const loadDefaultNro = async () => {
        try {
            const r = await api.get('/reports', { params: { limit: 100, search: `/${YEAR}` } });
            const rows = r.data.data || [];
            const max = rows.reduce((m, rr) => {
                const p = parseNro(rr.nroCI);
                return p && p.year === YEAR ? Math.max(m, p.seq) : m;
            }, 0);
            setForm((f) => (f.nroCI ? f : { ...f, nroCI: `${String(max + 1).padStart(3, '0')}/${YEAR}` }));
        } catch {
            setForm((f) => (f.nroCI ? f : { ...f, nroCI: nextNro() }));
        }
    };

    useEffect(() => {
        loadTemplates();
        loadDefaultNro();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const missing = () => {
        const req = [['nroCI', 'N° informe'], ['dirigidoA', 'Dirigido a'], ['puestoDirigidoA', 'Puesto']];
        return req.filter(([k]) => !String(form[k] || '').trim()).map(([, l]) => l);
    };

    const payload = () => ({ ...form, templateId: form.templateId || null });

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
            setForm((f) => ({ ...f, nroCI: nextNro(f.nroCI) }));
            toastSuccess('Informe generado.');
        } catch (err) {
            toastError(err.response?.data?.error || err.response?.data?.message || 'Error al procesar el informe.');
        } finally {
            setBusy(false);
        }
    };

    return (
        <RoleGuard allowed={['JEFE', 'ADMIN']}>
            <div className="space-y-4">
                <div className="card space-y-3 p-4">
                    <h1 className="text-base font-semibold">Generar reporte</h1>
                    <div className="grid gap-x-3 gap-y-2 md:grid-cols-2 xl:grid-cols-3">
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
                        <Button size="sm" loading={busy} onClick={doPreview} variant="secondary">Vista previa</Button>
                        <Button size="sm" loading={busy} onClick={doGenerate}>Generar</Button>
                    </div>
                </div>
                <p className="text-sm text-stone-500 dark:text-wa-muted">
                    Gestiona tus plantillas (subir, inspeccionar, editar textos) en el módulo <a href="/jefe/plantillas" className="font-medium text-primary-700 dark:text-primary-300">Plantillas</a>.
                </p>
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
