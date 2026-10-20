import { useEffect, useRef, useState } from 'react';
import { usePage } from '@inertiajs/react';
import {
    ArrowDownTrayIcon,
    ArrowUpIcon,
    ArrowUturnLeftIcon,
    CloudArrowUpIcon,
    FolderIcon,
    FolderPlusIcon,
    InformationCircleIcon,
    PlusIcon,
} from '@heroicons/react/24/outline';
import api from '../lib/api';
import { saveBlob } from '../lib/reportShared';
import { RoleGuard } from '../components/layout/RoleGuard';
import EnvBadge from '../components/layout/EnvBadge';
import { Button, Input, Modal } from '../components/ui/controls';
import { useToast } from '../components/ui/Toast';

const fmtSize = (bytes) => {
    if (!bytes) return '0 B';
    const units = ['B', 'KB', 'MB', 'GB'];
    let i = 0;
    let n = bytes;
    while (n >= 1024 && i < units.length - 1) { n /= 1024; i++; }
    return `${n.toFixed(n < 10 && i > 0 ? 1 : 0)} ${units[i]}`;
};

const actor = (a) => (a ? `${a.username} · ${a.at}` : '—');

export default function Backups() {
    const { toastSuccess, toastError } = useToast();
    const { env } = usePage().props;
    const [data, setData] = useState({ directory: '', max: 10, limits: { min: 1, max: 100 }, backups: [], uploadRestores: [] });
    const [dir, setDir] = useState('');
    const [maxCopies, setMaxCopies] = useState(10);
    const [limits, setLimits] = useState({ min: 1, max: 100 });
    const [creating, setCreating] = useState(false);
    const [savingConfig, setSavingConfig] = useState(false);
    const [modal, setModal] = useState(null); // { type: 'create' | 'restore' | 'upload', file? }
    const [pendingUpload, setPendingUpload] = useState(null);
    const [restoring, setRestoring] = useState(false);
    const [explorer, setExplorer] = useState(null); // null = cerrado; {loading:true} = cargando
    const [newFolder, setNewFolder] = useState('');
    const [creatingFolder, setCreatingFolder] = useState(false);
    const fileRef = useRef(null);

    const load = async () => {
        try {
            const r = await api.get('/backups');
            setData(r.data.data);
            setDir(r.data.data.directory);
            setMaxCopies(r.data.data.max);
            if (r.data.data.limits) setLimits(r.data.data.limits);
        } catch {
            toastError('No se pudo cargar las copias de seguridad.');
        }
    };

    useEffect(() => {
        load();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const saveConfig = async (e) => {
        e.preventDefault();
        setSavingConfig(true);
        try {
            const r = await api.post('/backups/config', { directory: dir, max: Number(maxCopies) });
            setData((d) => ({ ...d, directory: r.data.data.directory, max: r.data.data.max }));
            setMaxCopies(r.data.data.max);
            toastSuccess(`Configuración guardada: ${r.data.data.max} copias · ${r.data.data.directory}`);
            load();
        } catch (err) {
            toastError(err.response?.data?.error || 'No se pudo guardar la configuración.');
        } finally {
            setSavingConfig(false);
        }
    };

    // ---------------- Explorador de carpetas del servidor ----------------
    const navExplorer = async (path) => {
        setExplorer({ loading: true });
        try {
            const r = await api.get('/backups/directories', { params: path ? { path } : {} });
            setExplorer(r.data.data);
            setNewFolder('');
        } catch (err) {
            setExplorer(null);
            toastError(err.response?.data?.error || 'No se pudo leer la ruta.');
        }
    };

    const openExplorer = async () => {
        setExplorer({ loading: true });
        const typed = dir.trim();
        try {
            // primero intenta la ruta escrita en el campo; si no existe, el directorio configurado
            const r = await api.get('/backups/directories', { params: typed ? { path: typed } : {} });
            setExplorer(r.data.data);
        } catch (err) {
            if (typed) {
                try {
                    const r = await api.get('/backups/directories');
                    setExplorer(r.data.data);
                    return;
                } catch { /* cae al toast */ }
            }
            setExplorer(null);
            toastError(err.response?.data?.error || 'No se pudo abrir el explorador.');
        }
    };

    const createFolder = async () => {
        const name = newFolder.trim();
        if (!name || !explorer?.path) return;
        setCreatingFolder(true);
        try {
            const r = await api.post('/backups/directories', { path: explorer.path, name });
            toastSuccess('Carpeta creada.');
            await navExplorer(r.data.data);
        } catch (err) {
            toastError(err.response?.data?.error || 'No se pudo crear la carpeta.');
        } finally {
            setCreatingFolder(false);
        }
    };

    const doCreate = async () => {
        setModal(null);
        setCreating(true);
        try {
            const r = await api.post('/backups');
            toastSuccess({ title: 'Copia creada.', description: r.data.data.name });
            load();
        } catch (err) {
            toastError(err.response?.data?.error || 'No se pudo crear la copia.');
        } finally {
            setCreating(false);
        }
    };

    const requestCreate = () => {
        if (env?.isProduction) setModal({ type: 'create' });
        else doCreate();
    };

    const download = async (name) => {
        try {
            const r = await api.get('/backups/download', { params: { file: name }, responseType: 'blob' });
            saveBlob(r.data, name);
            toastSuccess('Descarga iniciada.');
            load(); // registra la descarga en la auditoría
        } catch {
            toastError('No se pudo descargar la copia.');
        }
    };

    const doRestore = async () => {
        const file = modal?.file;
        setModal(null);
        setRestoring(true);
        try {
            await api.post('/backups/restore', { file });
            toastSuccess({ title: 'Base de datos restaurada.', description: `Desde ${file}` });
            load();
        } catch (err) {
            toastError(err.response?.data?.error || 'No se pudo restaurar la copia.');
        } finally {
            setRestoring(false);
        }
    };

    const pickFile = (e) => {
        const f = e.target.files?.[0] || null;
        setPendingUpload(f);
        if (f) setModal({ type: 'upload' });
        e.target.value = '';
    };

    const doRestoreUpload = async () => {
        const file = pendingUpload;
        setModal(null);
        setRestoring(true);
        try {
            const form = new FormData();
            form.append('file', file);
            await api.post('/backups/restore-upload', form, { headers: { 'Content-Type': 'multipart/form-data' } });
            toastSuccess({ title: 'Base de datos restaurada.', description: `Desde archivo ${file.name}` });
            setPendingUpload(null);
            load();
        } catch (err) {
            toastError(err.response?.data?.error || 'No se pudo restaurar el archivo.');
        } finally {
            setRestoring(false);
        }
    };

    const closeRestoreModal = () => {
        setModal(null);
        if (modal?.type === 'upload') setPendingUpload(null);
    };

    return (
        <RoleGuard allowed={['ADMIN']}>
            <div className="space-y-4">
                {env?.isProduction && (
                    <div className="card flex items-center gap-3 border-amber-400 bg-amber-50 px-4 py-3 dark:bg-amber-500/10">
                        <EnvBadge />
                        <span className="text-sm text-amber-800 dark:text-amber-200">
                            Está trabajando en un entorno de producción. Las restauraciones reemplazarán los datos reales.
                        </span>
                    </div>
                )}

                {/* Configuración: directorio + máximo, una sola fila de controles */}
                <form onSubmit={saveConfig} className="card space-y-2.5 p-5">
                    <h2 className="text-sm font-semibold text-stone-700 dark:text-wa-text">Configuración de copias de seguridad</h2>
                    <div className="flex flex-col gap-3 sm:flex-row sm:items-end">
                        <div className="min-w-0 flex-1">
                            <Input
                                label="Directorio de destino"
                                value={dir}
                                onChange={(e) => setDir(e.target.value)}
                                placeholder="/var/backups/plataforma"
                                required
                            />
                        </div>
                        <Button type="button" variant="secondary" onClick={openExplorer} title="Explorar carpetas del servidor">
                            <span className="flex items-center gap-1.5"><FolderIcon className="h-4 w-4" /> Explorar…</span>
                        </Button>
                        <div className="sm:w-44">
                            <Input
                                label={`Máximo de copias (${limits.min}–${limits.max})`}
                                type="number"
                                min={limits.min}
                                max={limits.max}
                                value={maxCopies}
                                onChange={(e) => setMaxCopies(e.target.value)}
                                required
                            />
                        </div>
                        <Button type="submit" variant="secondary" loading={savingConfig}>Guardar</Button>
                    </div>
                    <p className="flex items-start gap-1.5 border-t border-stone-200 pt-2.5 text-xs text-stone-500 dark:border-white/10 dark:text-wa-muted">
                        <InformationCircleIcon className="mt-px h-4 w-4 shrink-0" />
                        <span>
                            Al superar el máximo se elimina la copia más antigua (también al guardar) · el directorio no puede estar dentro de <code>public/</code>.
                        </span>
                    </p>
                </form>

                {/* Lista de copias */}
                <div className="card space-y-4 p-5">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <div className="flex items-center gap-2">
                            <h2 className="text-sm font-semibold text-stone-700 dark:text-wa-text">Copias de seguridad</h2>
                            <span className="badge badge-primary">{data.backups.length} / {data.max}</span>
                        </div>
                        <Button onClick={requestCreate} loading={creating}>
                            <span className="flex items-center gap-1.5">
                                <PlusIcon className="h-4 w-4" /> Crear copia
                            </span>
                        </Button>
                    </div>

                    {data.backups.length === 0 ? (
                        <p className="py-6 text-center text-sm text-stone-500 dark:text-wa-muted">
                            No hay copias de seguridad todavía.
                        </p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="table">
                                <thead>
                                    <tr>
                                        <th>Copia</th>
                                        <th>Fecha y hora</th>
                                        <th>Tamaño</th>
                                        <th>Creado por</th>
                                        <th>Descargas</th>
                                        <th>Restauraciones</th>
                                        <th>Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {data.backups.map((b) => (
                                        <tr key={b.name}>
                                            <td className="font-mono text-xs">{b.name}</td>
                                            <td className="whitespace-nowrap font-mono text-xs">{b.date}</td>
                                            <td className="whitespace-nowrap">{fmtSize(b.size)}</td>
                                            <td className="whitespace-nowrap text-xs">{actor(b.createdBy)}</td>
                                            <td className="whitespace-nowrap text-xs" title={b.lastDownload ? `${b.lastDownload.username} · ${b.lastDownload.at}` : ''}>
                                                {b.downloadCount > 0 ? `${b.downloadCount} · última: ${actor(b.lastDownload)}` : '—'}
                                            </td>
                                            <td className="whitespace-nowrap text-xs" title={b.lastRestore ? `${b.lastRestore.username} · ${b.lastRestore.at}` : ''}>
                                                {b.restoreCount > 0 ? `${b.restoreCount} · última: ${actor(b.lastRestore)}` : '—'}
                                            </td>
                                            <td className="whitespace-nowrap">
                                                <Button size="sm" variant="secondary" onClick={() => download(b.name)}>
                                                    <span className="flex items-center gap-1"><ArrowDownTrayIcon className="h-4 w-4" /> Descargar</span>
                                                </Button>{' '}
                                                <Button size="sm" variant="danger" onClick={() => setModal({ type: 'restore', file: b.name })}>
                                                    <span className="flex items-center gap-1"><ArrowUturnLeftIcon className="h-4 w-4" /> Restaurar</span>
                                                </Button>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>

                {/* Restaurar desde archivo descargado */}
                <div className="card space-y-3 p-5">
                    <h2 className="flex items-center gap-2 text-sm font-semibold text-stone-700 dark:text-wa-text">
                        <CloudArrowUpIcon className="h-5 w-5" /> Restaurar desde archivo (.sql)
                    </h2>
                    <p className="text-xs text-stone-500 dark:text-wa-muted">
                        Seleccione un volcado descargado previamente para reestablecerlo en la base de datos.
                    </p>
                    <input ref={fileRef} type="file" accept=".sql" className="hidden" onChange={pickFile} />
                    <div className="flex items-center gap-3">
                        <Button variant="secondary" onClick={() => fileRef.current?.click()}>
                            Elegir archivo .sql
                        </Button>
                        {pendingUpload && <span className="font-mono text-xs text-stone-600 dark:text-wa-muted">{pendingUpload.name}</span>}
                    </div>
                    {data.uploadRestores.length > 0 && (
                        <div className="border-t border-stone-200 pt-3 text-xs text-stone-500 dark:border-white/10 dark:text-wa-muted">
                            <span className="font-semibold">Última restauración desde archivo:</span>{' '}
                            {actor(data.uploadRestores[data.uploadRestores.length - 1])}
                        </div>
                    )}
                </div>

                {/* Explorador de carpetas del servidor */}
                <Modal isOpen={!!explorer} onClose={() => setExplorer(null)} title="Explorar carpetas del servidor" size="lg">
                    <div className="space-y-3 text-sm">
                        {explorer?.loading ? (
                            <p className="py-8 text-center text-stone-500 dark:text-wa-muted">Cargando…</p>
                        ) : (
                            <>
                                <div className="flex items-center gap-2">
                                    <Button
                                        type="button" size="sm" variant="secondary"
                                        disabled={!explorer?.parent}
                                        onClick={() => navExplorer(explorer.parent)}
                                        title="Subir de carpeta"
                                    >
                                        <ArrowUpIcon className="h-4 w-4" />
                                    </Button>
                                    <span className="min-w-0 flex-1 truncate rounded-lg bg-stone-100 px-2 py-1.5 font-mono text-xs dark:bg-white/5">
                                        {explorer?.path}
                                    </span>
                                    {explorer?.writable === false && <span className="badge badge-warning">sin escritura</span>}
                                </div>

                                {explorer?.drives?.length > 1 && (
                                    <div className="flex flex-wrap gap-1.5">
                                        {explorer.drives.map((d) => (
                                            <button
                                                key={d}
                                                type="button"
                                                onClick={() => navExplorer(d)}
                                                className={`rounded-lg border px-2 py-0.5 font-mono text-xs transition ${
                                                    explorer.path.startsWith(d)
                                                        ? 'border-primary-500 bg-primary-50 text-primary-700 dark:bg-primary-500/10 dark:text-primary-300'
                                                        : 'border-stone-300 hover:bg-stone-100 dark:border-white/10 dark:hover:bg-white/5'
                                                }`}
                                            >
                                                {d}
                                            </button>
                                        ))}
                                    </div>
                                )}

                                <div className="max-h-64 overflow-y-auto rounded-lg border border-stone-200 dark:border-white/10">
                                    {explorer?.directories?.length ? (
                                        explorer.directories.map((sub) => (
                                            <button
                                                key={sub.path}
                                                type="button"
                                                onClick={() => navExplorer(sub.path)}
                                                className="flex w-full items-center gap-2 px-3 py-2 text-left text-sm transition hover:bg-stone-100 dark:hover:bg-white/5"
                                            >
                                                <FolderIcon className="h-4 w-4 shrink-0 text-amber-500" />
                                                <span className="truncate">{sub.name}</span>
                                            </button>
                                        ))
                                    ) : (
                                        <p className="px-3 py-4 text-center text-stone-500 dark:text-wa-muted">Sin subcarpetas.</p>
                                    )}
                                </div>
                                {explorer?.truncated && (
                                    <p className="text-xs text-stone-500 dark:text-wa-muted">
                                        Se muestran las primeras carpetas de {explorer.total} en total.
                                    </p>
                                )}

                                <div className="flex items-center gap-2">
                                    <input
                                        value={newFolder}
                                        onChange={(e) => setNewFolder(e.target.value)}
                                        onKeyDown={(e) => e.key === 'Enter' && (e.preventDefault(), createFolder())}
                                        placeholder="Nueva carpeta…"
                                        className="min-w-0 flex-1 rounded-lg border border-stone-300 bg-transparent px-3 py-1.5 text-sm dark:border-white/10"
                                    />
                                    <Button type="button" size="sm" variant="secondary" loading={creatingFolder} onClick={createFolder}>
                                        <span className="flex items-center gap-1"><FolderPlusIcon className="h-4 w-4" /> Crear</span>
                                    </Button>
                                </div>

                                <div className="flex justify-end gap-2 border-t border-stone-200 pt-3 dark:border-white/10">
                                    <Button type="button" variant="secondary" onClick={() => setExplorer(null)}>Cancelar</Button>
                                    <Button type="button" onClick={() => { setDir(explorer.path); setExplorer(null); }}>
                                        Seleccionar esta carpeta
                                    </Button>
                                </div>
                            </>
                        )}
                    </div>
                </Modal>

                {/* Confirmación: crear en producción */}
                <Modal isOpen={modal?.type === 'create'} onClose={() => setModal(null)} title="Crear copia en Producción">
                    <div className="space-y-3 text-sm">
                        <p className="text-stone-600 dark:text-wa-muted">
                            Está por crear una copia de la base de datos en <strong>Producción</strong> ({env?.db}). ¿Continuar?
                        </p>
                        <div className="flex justify-end gap-2">
                            <Button variant="secondary" onClick={() => setModal(null)}>Cancelar</Button>
                            <Button onClick={doCreate}>Crear copia</Button>
                        </div>
                    </div>
                </Modal>

                {/* Advertencia: restaurar copia */}
                <Modal isOpen={modal?.type === 'restore'} onClose={closeRestoreModal} title="⚠️ Restaurar copia de seguridad">
                    <div className="space-y-3 text-sm">
                        <p className="font-semibold text-primary-700 dark:text-primary-300">
                            Se reemplazarán todos los datos actuales de «{env?.db}» por los de la copia seleccionada.
                        </p>
                        <p className="text-stone-600 dark:text-wa-muted">
                            <strong>Posible pérdida de datos:</strong> toda la información creada después de esta copia se perderá de forma irreversible.
                        </p>
                        <p className="font-mono text-xs text-stone-500 dark:text-wa-muted">{modal?.file}</p>
                        <div className="flex justify-end gap-2">
                            <Button variant="secondary" onClick={closeRestoreModal}>Cancelar</Button>
                            <Button variant="danger" onClick={doRestore} loading={restoring}>Sí, restaurar</Button>
                        </div>
                    </div>
                </Modal>

                {/* Advertencia: restaurar archivo subido */}
                <Modal isOpen={modal?.type === 'upload'} onClose={closeRestoreModal} title="⚠️ Restaurar desde archivo">
                    <div className="space-y-3 text-sm">
                        <p className="font-semibold text-primary-700 dark:text-primary-300">
                            Se reemplazarán todos los datos actuales de «{env?.db}» por los del archivo seleccionado.
                        </p>
                        <p className="text-stone-600 dark:text-wa-muted">
                            <strong>Posible pérdida de datos:</strong> la información actual se perderá de forma irreversible.
                        </p>
                        <p className="font-mono text-xs text-stone-500 dark:text-wa-muted">{pendingUpload?.name}</p>
                        <div className="flex justify-end gap-2">
                            <Button variant="secondary" onClick={closeRestoreModal}>Cancelar</Button>
                            <Button variant="danger" onClick={doRestoreUpload} loading={restoring}>Sí, restaurar</Button>
                        </div>
                    </div>
                </Modal>
            </div>
        </RoleGuard>
    );
}
