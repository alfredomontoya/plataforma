import { useEffect, useRef, useState } from 'react';

/** Marcadores atómicos insertables (mismo catálogo del inspector). */
const MARCADORES = [
    'FECHA', 'NRO_CI', 'DIRIGIDO_A', 'PUESTO_DIRIGIDO_A', 'REMITENTE',
    'PUESTO_REMITENTE', 'NRO_SEMANA', 'FECHA_INICIO', 'FECHA_FIN',
    'TABLA_INGRESO', 'TABLA_ENTREGA', 'ING_NOMBRE', 'ING_TOTAL',
    'ENT_NOMBRE', 'ENT_TOTAL', 'GRAFICO_INGRESO', 'GRAFICO_ENTREGA',
    'GRAFICO_TENDENCIA', 'GRAFICO_DISTRIBUCION_TRAMITE', 'GRAFICO_TOTAL_TRAMITE',
    'GRAFICO_TENDENCIA_DIA',
];
import { renderAsync } from 'docx-preview';
import api, { todayLocal, toLocalDay } from '../lib/api';
import { saveBlob } from '../lib/reportShared';
import { RoleGuard } from '../components/layout/RoleGuard';
import { Button, Input, Modal } from '../components/ui/controls';
import WordSheet from '../components/WordSheet';
import { useToast } from '../components/ui/Toast';

/** Guía de marcadores: dónde colocar cada dato en el .docx. */
const GUIDE = [
    { group: 'Texto', vars: [
        ['FECHA', 'Fecha larga de generación'],
        ['NRO_CI', 'Número del informe'],
        ['DIRIGIDO_A', 'Destinatario'],
        ['PUESTO_DIRIGIDO_A', 'Puesto del destinatario'],
        ['REMITENTE', 'Quien genera (nombre)'],
        ['PUESTO_REMITENTE', 'Puesto de quien genera'],
        ['NRO_SEMANA', 'N° de semana (solo modo semanal)'],
        ['FECHA_INICIO', 'Inicio del periodo'],
        ['FECHA_FIN', 'Fin del periodo'],
    ] },
    { group: 'Tablas', vars: [
        ['TABLA_INGRESO', 'Párrafo ancla: tabla del total diario (N° / trámite / total)'],
        ['TABLA_ENTREGA', 'Párrafo ancla: se reemplaza por tabla de entrega'],
        ['ING_NOMBRE / ING_TOTAL', 'Fila clonable (plantilla simple): servicio / total ingreso'],
        ['ENT_NOMBRE / ENT_TOTAL', 'Fila clonable (plantilla simple): servicio / total entrega'],
    ] },
    { group: 'Gráficos', vars: [
        ['GRAFICO_INGRESO', 'Imagen: torta de ingreso'],
        ['GRAFICO_ENTREGA', 'Imagen: torta de entrega'],
        ['GRAFICO_TENDENCIA', 'Imagen: tendencia diaria'],
        ['GRAFICO_DISTRIBUCION_TRAMITE', 'Imagen: torta del total diario (dashboard)'],
        ['GRAFICO_TOTAL_TRAMITE', 'Imagen: barras del total diario (dashboard)'],
        ['GRAFICO_TENDENCIA_DIA', 'Imagen: tendencia día a día del total (dashboard)'],
    ] },
];

const mondayLocal = () => {
    const now = new Date();
    const dow = (now.getDay() + 6) % 7;
    return toLocalDay(new Date(now.getTime() - dow * 86400000));
};

export default function Templates() {
    const { toastSuccess, toastError } = useToast();
    const [templates, setTemplates] = useState([]);
    const [search, setSearch] = useState('');
    const [upload, setUpload] = useState({ name: '', file: null });
    const [selected, setSelected] = useState(null);
    const [inspect, setInspect] = useState(null);
    const [paragraphs, setParagraphs] = useState([]);
    const [texts, setTexts] = useState({});
    const [busy, setBusy] = useState(false);
    const [exampleOpen, setExampleOpen] = useState(false);
    const [exampleKind, setExampleKind] = useState(null);
    const [examplePdf, setExamplePdf] = useState(null);
    const [newPara, setNewPara] = useState('');
    const [marker, setMarker] = useState('FECHA');
    const exampleRef = useRef(null);
    const areaRefs = useRef({});

    const load = async () => {
        try {
            const r = await api.get('/templates', { params: { limit: 50, search } });
            const rows = r.data.data ?? [];
            setTemplates(rows);
            // Al entrar, se preselecciona la plantilla por defecto para editarla.
            setSelected((prev) => {
                if (prev) return rows.find((t) => t.id === prev.id) || null;
                return rows.find((t) => t.isDefault) || null;
            });
            if (selected) {
                const still = rows.find((t) => t.id === selected.id);
                setSelected(still || null);
                if (!still) {
                    setInspect(null);
                    setParagraphs([]);
                }
            }
        } catch {
            toastError('No se pudo cargar plantillas.');
        }
    };

    useEffect(() => {
        load();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    useEffect(() => {
        if (!selected) return;
        api.get(`/templates/${selected.id}/inspect`)
            .then((r) => setInspect(r.data.data))
            .catch(() => setInspect(null));
        api.get(`/templates/${selected.id}/paragraphs`)
            .then((r) => {
                setParagraphs(r.data.data ?? []);
                setTexts({});
            })
            .catch(() => setParagraphs([]));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [selected?.id]);

    const doUpload = async (e) => {
        e.preventDefault();
        if (!upload.file || !upload.name.trim()) {
            toastError('Nombre y archivo .docx son obligatorios.');
            return;
        }
        setBusy(true);
        try {
            await api.post('/templates', { name: upload.name.trim(), file: upload.file }, { headers: { 'Content-Type': 'multipart/form-data' } });
            setUpload({ name: '', file: null });
            toastSuccess('Plantilla subida.');
            load();
        } catch (err) {
            toastError(err.response?.data?.error || 'Error al subir.');
        } finally {
            setBusy(false);
        }
    };

    const doDownload = async (t) => {
        try {
            const r = await api.get(`/templates/${t.id}/download`, { responseType: 'blob' });
            saveBlob(r.data, t.fileName || `${t.name}.docx`);
        } catch {
            toastError('No se pudo descargar.');
        }
    };

    const doDuplicate = async (t) => {
        try {
            await api.post(`/templates/${t.id}/duplicate`);
            toastSuccess('Plantilla duplicada.');
            load();
        } catch {
            toastError('No se pudo duplicar.');
        }
    };

    const doDelete = async (t) => {
        try {
            await api.delete(`/templates/${t.id}`);
            toastSuccess('Eliminada.');
            if (selected?.id === t.id) {
                setSelected(null);
                setInspect(null);
                setParagraphs([]);
            }
            load();
        } catch (err) {
            toastError(err.response?.data?.error || 'No se pudo eliminar.');
        }
    };

    const doDefault = async (t) => {
        try {
            await api.patch(`/templates/${t.id}/default`);
            toastSuccess('Actualizada.');
            load();
        } catch {
            toastError('No se pudo actualizar.');
        }
    };

    const copyVar = async (v) => {
        try {
            await navigator.clipboard.writeText(`{${v}}`);
            toastSuccess(`Copiado: {${v}}`);
        } catch {
            toastError('No se pudo copiar.');
        }
    };

    const doExample = async () => {
        if (!selected) return;
        setBusy(true);
        try {
            const payload = {
                templateId: selected.id, mode: 'WEEK', weekStart: mondayLocal(),
                nroCI: '000/EJEMPLO', dirigidoA: 'Dirección de ejemplo', puestoDirigidoA: 'Puesto de ejemplo',
            };
            try {
                const pdf = await api.post('/reports/preview?format=pdf', payload, { responseType: 'blob' });
                if (pdf.headers['content-type']?.includes('pdf')) {
                    const blob = pdf.data instanceof Blob ? pdf.data : new Blob([pdf.data], { type: 'application/pdf' });
                    setExamplePdf((u) => {
                        if (u) URL.revokeObjectURL(u);
                        return URL.createObjectURL(blob);
                    });
                    setExampleKind('pdf');
                    setExampleOpen(true);
                    return;
                }
                throw new Error('no-pdf');
            } catch (err) {
                if (err.response?.status && err.response.status !== 501) throw err;
                const r = await api.post('/reports/preview', payload, { responseType: 'blob' });
                const blob = r.data instanceof Blob ? r.data : new Blob([r.data]);
                setExampleKind('docx');
                setExampleOpen(true);
                requestAnimationFrame(async () => {
                    try {
                        if (exampleRef.current) {
                            exampleRef.current.innerHTML = '';
                            await renderAsync(await blob.arrayBuffer(), exampleRef.current);
                        }
                    } catch {
                        toastError('No se pudo mostrar la vista previa.');
                    }
                });
            }
        } catch {
            toastError('No se pudo generar el ejemplo.');
        } finally {
            setBusy(false);
        }
    };

    const doSaveTexts = async () => {
        if (!selected) return;
        const changed = Object.fromEntries(Object.entries(texts).filter(([, v]) => v !== undefined));
        if (!Object.keys(changed).length) {
            toastError('Sin cambios.');
            return;
        }
        setBusy(true);
        try {
            await api.patch(`/templates/${selected.id}/paragraphs`, { texts: changed });
            toastSuccess('Textos actualizados. Revisa el inspector y la vista de ejemplo.');
            const r = await api.get(`/templates/${selected.id}/paragraphs`);
            setParagraphs(r.data.data ?? []);
            setTexts({});
            const ins = await api.get(`/templates/${selected.id}/inspect`);
            setInspect(ins.data.data);
        } catch (err) {
            toastError(err.response?.data?.error || 'Error al guardar.');
        } finally {
            setBusy(false);
        }
    };

    const editedCount = Object.keys(texts).length;

    const insertMarker = (index) => {
        const ta = areaRefs.current[index];
        const cur = texts[index] ?? paragraphs.find((p) => p.index === index)?.text ?? '';
        const at = ta && document.activeElement === ta ? (ta.selectionStart ?? cur.length) : cur.length;
        const next = `${cur.slice(0, at)}{${marker}}${cur.slice(at)}`;
        setTexts({ ...texts, [index]: next });
        requestAnimationFrame(() => {
            const el = areaRefs.current[index];
            if (el) {
                el.focus();
                el.setSelectionRange(at + marker.length + 2, at + marker.length + 2);
            }
        });
    };

    const doAppend = async () => {
        if (!selected || !newPara.trim()) {
            toastError('Escribe el texto del párrafo (fijo y/o marcadores).');
            return;
        }
        setBusy(true);
        try {
            const r = await api.post(`/templates/${selected.id}/paragraphs`, { text: newPara.trim() });
            setParagraphs(r.data.data ?? []);
            setTexts({});
            setNewPara('');
            const ins = await api.get(`/templates/${selected.id}/inspect`);
            setInspect(ins.data.data);
            toastSuccess('Párrafo agregado.');
        } catch (err) {
            toastError(err.response?.data?.error || 'Error al agregar.');
        } finally {
            setBusy(false);
        }
    };

    return (
        <RoleGuard allowed={['JEFE', 'ADMIN']}>
            <div className="space-y-4">
                <div className="card space-y-3 p-4">
                    <h1 className="text-base font-semibold">Plantillas</h1>
                    <div className="flex flex-wrap items-end gap-2">
                        <Input label="Buscar" value={search} onChange={(e) => setSearch(e.target.value)} />
                        <Button size="sm" onClick={load}>Filtrar</Button>
                    </div>
                    {templates.map((t) => (
                        <div
                            key={t.id}
                            onClick={() => setSelected(t)}
                            className={`flex cursor-pointer items-center justify-between rounded-lg border px-3 py-2 text-sm ${selected?.id === t.id ? 'border-primary-400 bg-primary-50 dark:bg-primary-900/20' : 'border-stone-200 dark:border-white/10'}`}
                        >
                            <span>{t.name} {t.isDefault && <span className="badge badge-primary ml-1">Por defecto</span>}</span>
                            <div className="flex gap-2" onClick={(e) => e.stopPropagation()}>
                                {!t.isDefault && <Button size="sm" variant="secondary" onClick={() => doDefault(t)}>Por defecto</Button>}
                                <Button size="sm" variant="secondary" onClick={() => doDownload(t)}>Descargar</Button>
                                <Button size="sm" variant="secondary" onClick={() => doDuplicate(t)}>Duplicar</Button>
                                <Button size="sm" variant="danger" onClick={() => doDelete(t)}>Eliminar</Button>
                            </div>
                        </div>
                    ))}
                    <form onSubmit={doUpload} className="flex flex-wrap items-end gap-2">
                        <Input label="Nombre" value={upload.name} onChange={(e) => setUpload({ ...upload, name: e.target.value })} required />
                        <input type="file" accept=".docx" onChange={(e) => setUpload({ ...upload, file: e.target.files[0] })} className="text-sm" />
                        <Button type="submit" loading={busy}>Subir .docx</Button>
                    </form>
                </div>

                {selected && (
                    <div className="card space-y-4 p-4">
                        <div className="flex flex-wrap items-center gap-2">
                            <h2 className="font-semibold">{selected.name}</h2>
                            <Button size="sm" variant="secondary" loading={busy} onClick={doExample}>Vista de ejemplo</Button>
                        </div>

                        <div>
                            <h3 className="mb-1 text-sm font-semibold">Inspector de marcadores</h3>
                            {!inspect ? (
                                <p className="text-sm text-stone-500">Cargando…</p>
                            ) : (
                                <div className="space-y-2">
                                    {Object.entries(inspect.groups || {}).map(([g, v]) => (
                                        <div key={g} className="text-sm">
                                            <span className="font-medium capitalize">{g}: </span>
                                            {(v.found || []).map((x) => (
                                                <span key={x} className="badge badge-success mr-1">{x}</span>
                                            ))}
                                            {(v.missing || []).map((x) => (
                                                <span key={x} className="badge badge-gray mr-1">{x} ✕</span>
                                            ))}
                                        </div>
                                    ))}
                                    {Object.entries(inspect.unknown || {}).map(([part, vars]) => (
                                        <p key={part} className="text-sm text-red-600">
                                            Desconocidos en {part}: {(vars || []).join(', ')}
                                        </p>
                                    ))}
                                </div>
                            )}
                        </div>

                        <div>
                            <h3 className="mb-1 text-sm font-semibold">Guía: dónde colocar cada dato</h3>
                            <p className="mb-2 text-xs text-stone-500 dark:text-wa-muted">
                                En Word escribe el marcador con llaves simples <code>{'{VAR}'}</code> (plantillas institucionales)
                                o <code>{'${VAR}'}</code> (plantilla simple del sistema). Clic para copiar.
                            </p>
                            {GUIDE.map((g) => (
                                <div key={g.group} className="mb-2">
                                    <div className="text-xs font-semibold uppercase text-stone-500">{g.group}</div>
                                    {g.vars.map(([v, desc]) => (
                                        <div key={v} className="flex items-center justify-between gap-2 border-b border-stone-100 py-1 text-sm dark:border-white/5">
                                            <span><code className="font-mono">{v}</code> <span className="text-stone-500">— {desc}</span></span>
                                            <Button size="sm" variant="secondary" onClick={() => copyVar(v.split(' ')[0])}>Copiar</Button>
                                        </div>
                                    ))}
                                </div>
                            ))}
                        </div>

                        <div>
                            <h3 className="mb-1 text-sm font-semibold">Editar contenido</h3>
                            <p className="mb-2 text-xs text-stone-500 dark:text-wa-muted">
                                Párrafos del motor (marcas <code>{'{VAR}'}</code>) se muestran bloqueados. En el resto
                                puedes cambiar el texto e <strong>insertar marcas de datos</strong> con el selector.
                                El formato se conserva del inicio del párrafo.
                            </p>
                            <div className="mb-2 flex flex-wrap items-end gap-2">
                                <label className="text-xs"> Marca
                                    <select
                                        value={marker}
                                        onChange={(e) => setMarker(e.target.value)}
                                        className="ml-1 rounded-md border border-stone-300 px-2 py-1 font-mono text-xs dark:border-white/10 dark:bg-wa-header"
                                    >
                                        {MARCADORES.map((m) => <option key={m} value={m}>{`{${m}}`}</option>)}
                                    </select>
                                </label>
                            </div>
                            <WordSheet
                                paragraphs={paragraphs}
                                texts={texts}
                                marker={marker}
                                onText={(i, v) => setTexts({ ...texts, [i]: v })}
                                onInsert={insertMarker}
                                registerRef={(i, el) => { areaRefs.current[i] = el; }}
                            />
                            <div className="mt-3 flex flex-wrap items-end gap-2">
                                <Button size="sm" loading={busy} onClick={doSaveTexts} disabled={!editedCount}>
                                    Guardar textos{editedCount ? ` (${editedCount})` : ''}
                                </Button>
                            </div>
                            <div className="mt-3">
                                <label className="mb-0.5 block text-xs text-stone-500">Agregar párrafo al final (texto y/o marcas, ej. <code>{'{GRAFICO_INGRESO}'}</code> solo en su línea)</label>
                                <div className="flex flex-wrap items-end gap-2">
                                    <input
                                        value={newPara}
                                        onChange={(e) => setNewPara(e.target.value)}
                                        placeholder="Ej. Elaborado por {REMITENTE}"
                                        className="min-w-0 flex-1 rounded-md border border-stone-300 px-2 py-1 text-sm dark:border-white/10 dark:bg-wa-header"
                                    />
                                    <Button size="sm" loading={busy} onClick={doAppend}>Agregar párrafo</Button>
                                </div>
                            </div>
                        </div>
                    </div>
                )}
            </div>

            <Modal isOpen={exampleOpen} onClose={() => setExampleOpen(false)} title="Vista de ejemplo" size="lg">
                {exampleKind === 'pdf' && examplePdf && (
                    <iframe src={examplePdf} title="Ejemplo PDF" className="h-[500px] w-full rounded-lg border border-stone-200 bg-white" />
                )}
                {exampleKind === 'docx' && (
                    <div ref={exampleRef} className="docx-preview max-h-[500px] overflow-auto rounded-lg border border-stone-200 bg-white p-4 text-black" />
                )}
                <div className="mt-3 flex justify-end">
                    <Button size="sm" variant="secondary" onClick={() => setExampleOpen(false)}>Cerrar</Button>
                </div>
            </Modal>
        </RoleGuard>
    );
}
