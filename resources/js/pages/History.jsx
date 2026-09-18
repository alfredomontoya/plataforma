import { useEffect, useRef, useState } from 'react';
import { renderAsync } from 'docx-preview';
import api, { todayLocal } from '../lib/api';
import { saveBlob } from '../lib/reportShared';
import { RoleGuard } from '../components/layout/RoleGuard';
import { Button, DatePicker, Input, Modal } from '../components/ui/controls';
import { useToast } from '../components/ui/Toast';

const MODE_LABEL = { DAY: 'Diario', WEEK: 'Semanal', RANGE: 'Rango' };

/** '2026-09-14T00:00:00...' → '14/09/2026' (parte fecha UTC, sin corrimientos). */
const fmtDay = (iso) => {
    if (!iso) return '—';
    const d = String(iso).slice(0, 10).split('-');
    return d.length === 3 ? `${d[2]}/${d[1]}/${d[0]}` : String(iso);
};

const periodLabel = (r) => {
    if (!r) return '—';
    if (r.mode === 'DAY') return fmtDay(r.periodStart);
    // periodEnd es exclusivo (día siguiente): se muestra el día previo.
    const end = new Date(new Date(r.periodEnd).getTime() - 86400000);
    return `${fmtDay(r.periodStart)} al ${fmtDay(end.toISOString())}`;
};

const generatorLabel = (r) => {
    const g = r?.generatedBy;
    if (!g) return '—';
    const name = [g.firstName, g.lastName].filter(Boolean).join(' ');
    return name ? `${name} (${g.username})` : g.username;
};

export default function History() {
    const { toastSuccess, toastError } = useToast();
    const [rows, setRows] = useState([]);
    const [from, setFrom] = useState('');
    const [to, setTo] = useState('');
    const [search, setSearch] = useState('');
    const [selected, setSelected] = useState(null);
    const [showPreview, setShowPreview] = useState(false);
    const [previewKind, setPreviewKind] = useState(null); // 'pdf' | 'docx'
    const [pdfUrl, setPdfUrl] = useState(null);
    const [busy, setBusy] = useState(false);
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

    const revokePdf = () => {
        setPdfUrl((url) => {
            if (url) URL.revokeObjectURL(url);
            return null;
        });
    };

    const openDetail = (row) => {
        setSelected(row);
        setShowPreview(false);
        setPreviewKind(null);
        revokePdf();
        if (previewRef.current) previewRef.current.innerHTML = '';
    };

    const closeDetail = () => {
        setSelected(null);
        setShowPreview(false);
        setPreviewKind(null);
        revokePdf();
    };

    const previewDocx = async (blob) => {
        setPreviewKind('docx');
        setShowPreview(true);
        requestAnimationFrame(async () => {
            try {
                if (previewRef.current) {
                    previewRef.current.innerHTML = '';
                    await renderAsync(await blob.arrayBuffer(), previewRef.current);
                }
            } catch {
                toastError('No se pudo mostrar la vista previa.');
                setShowPreview(false);
            }
        });
    };

    const doPreview = async () => {
        if (!selected) return;
        if (showPreview) {
            setShowPreview(false);
            return;
        }
        setBusy(true);
        try {
            // Vista exacta (PDF con membrete y pies). Si no hay conversor, docx simplificado.
            try {
                const pdf = await api.get(`/reports/${selected.id}/pdf`, { responseType: 'blob' });
                if (pdf.headers['content-type']?.includes('pdf')) {
                    const blob = pdf.data instanceof Blob ? pdf.data : new Blob([pdf.data], { type: 'application/pdf' });
                    revokePdf();
                    setPdfUrl(URL.createObjectURL(blob));
                    setPreviewKind('pdf');
                    setShowPreview(true);
                    return;
                }
                throw new Error('no-pdf');
            } catch (err) {
                if (err.response?.status && err.response.status !== 501) throw err;
                const r = await api.get(`/reports/${selected.id}/download`, { responseType: 'blob' });
                await previewDocx(r.data instanceof Blob ? r.data : new Blob([r.data]));
            }
        } catch {
            toastError('No se pudo cargar el documento.');
        } finally {
            setBusy(false);
        }
    };

    const doDownload = async () => {
        if (!selected) return;
        setBusy(true);
        try {
            const r = await api.get(`/reports/${selected.id}/download`, { responseType: 'blob' });
            saveBlob(r.data, selected.fileName || `informe-${selected.nroCI}.docx`);
        } catch {
            toastError('No se pudo descargar.');
        } finally {
            setBusy(false);
        }
    };

    const doDelete = async () => {
        if (!selected) return;
        setBusy(true);
        try {
            await api.delete(`/reports/${selected.id}`);
            toastSuccess('Eliminado.');
            closeDetail();
            load();
        } catch {
            toastError('No se pudo eliminar.');
        } finally {
            setBusy(false);
        }
    };

    const fields = selected
        ? [
              ['N° informe', selected.nroCI],
              ['Dirigido a', selected.dirigidoA],
              ['Puesto', selected.puestoDirigidoA],
              ['Modo', MODE_LABEL[selected.mode] || selected.mode],
              ['Periodo', periodLabel(selected)],
              ['Total ingreso', selected.totalIngreso],
              ['Total entrega', selected.totalEntrega],
              ['Plantilla', selected.template?.name || '—'],
              ['Generado por', generatorLabel(selected)],
              ['Generado el', fmtDay(selected.created_at)],
              ['Archivo', selected.fileName],
          ]
        : [];

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
                    <thead><tr><th>N°</th><th>Dirigido a</th><th>Modo</th><th>Periodo</th><th>Totales</th></tr></thead>
                    <tbody>
                        {rows.map((r) => (
                            <tr key={r.id} className="cursor-pointer hover:bg-stone-100 dark:hover:bg-white/5" onClick={() => openDetail(r)} title="Ver detalle">
                                <td>{r.nroCI}</td>
                                <td>{r.dirigidoA}</td>
                                <td><span className="badge badge-gray">{MODE_LABEL[r.mode] || r.mode}</span></td>
                                <td>{periodLabel(r)}</td>
                                <td>I:{r.totalIngreso} / E:{r.totalEntrega}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
                {!rows.length && <p className="text-sm text-stone-500 dark:text-wa-muted">Sin resultados.</p>}
            </div>

            <Modal isOpen={!!selected} onClose={closeDetail} title={selected ? `Informe ${selected.nroCI}` : ''} size="lg">
                {selected && (
                    <div className="space-y-3">
                        <dl className="grid gap-x-4 gap-y-2 md:grid-cols-2">
                            {fields.map(([k, v]) => (
                                <div key={k} className="flex gap-2 text-sm">
                                    <dt className="w-28 shrink-0 text-stone-500 dark:text-wa-muted">{k}</dt>
                                    <dd className="font-medium break-all">{String(v ?? '—')}</dd>
                                </div>
                            ))}
                        </dl>
                        <div className="flex flex-wrap gap-2">
                            <Button size="sm" variant="secondary" loading={busy} onClick={doPreview}>
                                {showPreview ? 'Ocultar vista previa' : 'Vista previa'}
                            </Button>
                            <Button size="sm" loading={busy} onClick={doDownload}>Descargar</Button>
                            <Button size="sm" variant="danger" loading={busy} onClick={doDelete}>Eliminar</Button>
                            <Button size="sm" variant="secondary" onClick={closeDetail}>Cerrar</Button>
                        </div>
                        {showPreview && previewKind === 'pdf' && pdfUrl && (
                            <div className="space-y-1">
                                <p className="text-xs text-stone-500 dark:text-wa-muted">Vista exacta del documento (PDF).</p>
                                <iframe src={pdfUrl} title="Vista previa PDF" className="h-[500px] w-full rounded-lg border border-stone-200 bg-white dark:border-white/10" />
                            </div>
                        )}
                        {showPreview && previewKind === 'docx' && (
                            <div className="space-y-1">
                                <p className="text-xs text-stone-500 dark:text-wa-muted">Vista simplificada (sin encabezado ni pie). Descargue para ver el documento final.</p>
                                <div ref={previewRef} className="docx-preview max-h-[500px] overflow-auto rounded-lg border border-stone-200 bg-white p-4 text-black dark:border-white/10" />
                            </div>
                        )}
                    </div>
                )}
            </Modal>
        </RoleGuard>
    );
}
