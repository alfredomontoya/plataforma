import { useEffect, useState } from 'react';
import api from '../lib/api';
import { useAuth } from '../lib/auth';
import { RoleGuard } from '../components/layout/RoleGuard';
import { Button } from '../components/ui/controls';
import { useToast } from '../components/ui/Toast';

const ALLOWED = ['OPERATOR_INGRESO', 'OPERATOR_ENTREGA', 'ADMIN', 'JEFE'];

function formatDate(value) {
    if (!value) return '—';
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? '—' : date.toLocaleString();
}

export default function Sessions() {
    const { refreshSessions } = useAuth();
    const { toastSuccess, toastError } = useToast();
    const [rows, setRows] = useState([]);
    const [loading, setLoading] = useState(true);
    const [busy, setBusy] = useState(null);

    const load = async () => {
        setLoading(true);
        const sessions = await refreshSessions();
        if (sessions) setRows(sessions);
        else toastError('No se pudieron cargar las sesiones.');
        setLoading(false);
    };

    useEffect(() => {
        load();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const closeOne = async (session) => {
        setBusy(session.id);
        try {
            await api.delete(`/auth/sessions/${session.id}`);
            toastSuccess('Sesión cerrada.');
            await load();
        } catch (err) {
            toastError(err.response?.data?.error || 'No se pudo cerrar la sesión.');
        } finally {
            setBusy(null);
        }
    };

    const closeOthers = async () => {
        setBusy('others');
        try {
            await api.delete('/auth/sessions/others');
            toastSuccess('Se cerraron las demás sesiones.');
            await load();
        } catch (err) {
            toastError(err.response?.data?.error || 'No se pudieron cerrar las sesiones.');
        } finally {
            setBusy(null);
        }
    };

    const others = rows.filter((r) => !r.current).length;

    return (
        <RoleGuard allowed={ALLOWED}>
            <div className="card space-y-4 p-5">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-semibold">Sesiones activas</h1>
                        <p className="text-sm text-stone-500 dark:text-wa-muted">
                            Equipos y navegadores donde tu cuenta tiene una sesión iniciada. Puedes cerrar los que no reconozcas.
                        </p>
                    </div>
                    <Button variant="secondary" onClick={load} loading={loading}>
                        Actualizar
                    </Button>
                </div>

                {loading ? (
                    <div className="flex justify-center py-10">
                        <span className="h-8 w-8 animate-spin rounded-full border-4 border-stone-300 border-t-primary-600 dark:border-t-primary-500" />
                    </div>
                ) : rows.length === 0 ? (
                    <p className="py-6 text-center text-sm text-stone-500 dark:text-wa-muted">No hay sesiones registradas.</p>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="table">
                            <thead>
                                <tr>
                                    <th>Dispositivo</th>
                                    <th>Inicio</th>
                                    <th>Última actividad</th>
                                    <th>Estado</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                {rows.map((s) => (
                                    <tr key={s.id}>
                                        <td>{s.device}</td>
                                        <td>{formatDate(s.createdAt)}</td>
                                        <td>{formatDate(s.lastUsedAt)}</td>
                                        <td>
                                            <span className={`badge ${s.current ? 'badge-success' : 'badge-primary'}`}>
                                                {s.current ? 'Esta sesión' : 'Activa'}
                                            </span>
                                        </td>
                                        <td>
                                            {s.current ? (
                                                <span className="text-xs text-stone-500 dark:text-wa-muted">Sesión actual</span>
                                            ) : (
                                                <Button
                                                    size="sm"
                                                    variant="danger"
                                                    onClick={() => closeOne(s)}
                                                    loading={busy === s.id}
                                                >
                                                    Cerrar
                                                </Button>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                {others > 0 && (
                    <div className="flex justify-end">
                        <Button variant="danger" onClick={closeOthers} loading={busy === 'others'}>
                            Cerrar todas las demás
                        </Button>
                    </div>
                )}
            </div>
        </RoleGuard>
    );
}
