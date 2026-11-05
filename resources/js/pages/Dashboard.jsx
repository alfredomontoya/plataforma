
import { useEffect, useRef, useState } from 'react';
import { renderAsync } from 'docx-preview';
import api, { todayLocal, toLocalDay } from '../lib/api';
import { RoleGuard } from '../components/layout/RoleGuard';
import { Button, Input, Modal, Select } from '../components/ui/controls';
import { RangeDatePicker } from '../components/ui/RangeDatePicker';
import {
    ArrowDownTrayIcon,
    ArrowUpTrayIcon,
    DocumentChartBarIcon,
    Squares2X2Icon,
    UserGroupIcon,
} from '@heroicons/react/24/outline';
import { useToast } from '../components/ui/Toast';
import {
    BarChart,
    KpiCard,
    PieChart,
    TrendChart,
} from '../components/charts/charts';
import {
    nextNro,
    parseNro,
    pie,
    saveBlob,
    sanitizeLinks,
} from '../lib/reportShared';
import { svgChartsToPng } from '../lib/chartExport';
import { barSvg, pieSvg, trendSvg } from '../lib/chartSvg';

const YEAR = new Date().getFullYear();

// Temporal: oculta la tarjeta de tendencia de ingreso y entrega.
// Los datos siguen cargándose para el documento.
const SHOW_MAIN_TREND = false;

// Temporal: oculta las distribuciones por ingreso y entrega.
const SHOW_ENTRIES_BLOCKS = false;

const REPORT_DEFAULTS = {
    templateId: '',
    nroCI: '',
    dirigidoA: 'Director General',
    puestoDirigidoA: 'Dirección General',
};

const ROLE_LABELS = {
    ADMIN: 'Administrador',
    JEFE: 'Jefe',
    OPERATOR_INGRESO: 'Ingreso',
    OPERATOR_ENTREGA: 'Entrega',
};

const fmtCompact = (d) => {
    const pad = (n) => String(n).padStart(2, '0');

    return `${pad(d.getDate())}/${pad(d.getMonth() + 1)}/${String(
        d.getFullYear()
    ).slice(2)}`;
};

export default function Dashboard() {
    const { toastSuccess, toastError } = useToast();

    // --------------------------------------------------
    // ESTADOS GENERALES
    // --------------------------------------------------

    const [period, setPeriod] = useState('week');

    const [customFrom, setCustomFrom] = useState(() => {
        const now = new Date();
        const monday = new Date(
            now.getTime() -
            (((now.getDay() + 6) % 7) * 86400000)
        );

        return toLocalDay(monday);
    });

    const [customTo, setCustomTo] = useState(todayLocal());

    const [data, setData] = useState(null);
    const [subtitle, setSubtitle] = useState('');

    const [trendMonth, setTrendMonth] = useState(
        todayLocal().slice(0, 7)
    );

    const [trendFrom, setTrendFrom] = useState(
        `${todayLocal().slice(0, 7)}-01`
    );

    const [trendTo, setTrendTo] = useState(todayLocal());
    const [trend, setTrend] = useState([]);

    // --------------------------------------------------
    // ESTADOS DE REPORTES
    // --------------------------------------------------

    const [reportOpen, setReportOpen] = useState(false);
    const [templates, setTemplates] = useState([]);
    const [reportForm, setReportForm] = useState(REPORT_DEFAULTS);
    const [busy, setBusy] = useState(false);

    const [previewOpen, setPreviewOpen] = useState(false);
    const [previewKind, setPreviewKind] = useState(null);
    const [previewPdfUrl, setPreviewPdfUrl] = useState(null);

    const previewRef = useRef(null);

    // --------------------------------------------------
    // CARGAR DASHBOARD
    // --------------------------------------------------

    useEffect(() => {
        const run = async () => {
            try {
                const now = new Date();

                if (period === 'today' || period === 'yesterday') {
                    const d = period === 'today'
                        ? now
                        : new Date(now.getTime() - 86400000);

                    const day = toLocalDay(d);

                    const r = await api.get(
                        '/reports/dashboard/summary',
                        { params: { date: day } }
                    );

                    setData({
                        kind: 'day',
                        ...r.data.data,
                    });

                    setSubtitle(fmtCompact(d));

                } else if (period === 'week') {
                    const dow = (now.getDay() + 6) % 7;

                    const monday = new Date(
                        now.getTime() - dow * 86400000
                    );

                    const sunday = new Date(
                        monday.getTime() + 6 * 86400000
                    );

                    const r = await api.get(
                        '/reports/dashboard/weekly',
                        {
                            params: {
                                weekStart: toLocalDay(monday),
                            },
                        }
                    );

                    setData({
                        kind: 'week',
                        ...r.data.data,
                    });

                    setSubtitle(
                        `${fmtCompact(monday)} a ${fmtCompact(sunday)}`
                    );

                } else {
                    if (
                        !customFrom ||
                        !customTo ||
                        customFrom > customTo
                    ) {
                        return;
                    }

                    const r = await api.get(
                        '/reports/dashboard/range',
                        {
                            params: {
                                from: customFrom,
                                to: customTo,
                            },
                        }
                    );

                    setData({
                        kind: 'week',
                        ...r.data.data,
                    });

                    setSubtitle(
                        `${fmtCompact(new Date(`${customFrom}T12:00:00`))} a ${
                            fmtCompact(new Date(`${customTo}T12:00:00`))
                        }`
                    );
                }

            } catch (err) {
                toastError(
                    err.response?.data?.error ||
                    'No se pudo cargar el dashboard.'
                );
            }
        };

        run();

        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [period, customFrom, customTo]);

    // --------------------------------------------------
    // TENDENCIA DE INGRESO Y ENTREGA
    // --------------------------------------------------

    useEffect(() => {
        if (
            !trendFrom ||
            !trendTo ||
            trendFrom > trendTo
        ) {
            return;
        }

        api.get('/reports/dashboard/daily', {
            params: {
                from: trendFrom,
                to: trendTo,
            },
        })
            .then((r) => setTrend(r.data.data))
            .catch(() => toastError('No se pudo cargar la tendencia.'));

        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [trendFrom, trendTo]);

    // --------------------------------------------------
    // INGRESO TOTAL DIARIO
    // --------------------------------------------------

    const [totals, setTotals] = useState(null);
    const [totalsTrend, setTotalsTrend] = useState([]);

    const [tSort, setTSort] = useState({
        key: 'total',
        dir: -1,
    });

    const [oSort, setOSort] = useState({
        key: 'total',
        dir: -1,
    });

    useEffect(() => {
        const run = async () => {
            try {
                const now = new Date();

                let sum;
                let tFrom;
                let tTo;

                if (
                    period === 'today' ||
                    period === 'yesterday'
                ) {
                    const d = period === 'today'
                        ? now
                        : new Date(now.getTime() - 86400000);

                    const day = toLocalDay(d);

                    sum = (
                        await api.get('/totals/summary/day', {
                            params: { date: day },
                        })
                    ).data.data;

                    tTo = day;

                    tFrom = toLocalDay(
                        new Date(d.getTime() - 6 * 86400000)
                    );

                } else if (period === 'week') {
                    const dow = (now.getDay() + 6) % 7;

                    const monday = new Date(
                        now.getTime() - dow * 86400000
                    );

                    sum = (
                        await api.get('/totals/summary/week', {
                            params: {
                                weekStart: toLocalDay(monday),
                            },
                        })
                    ).data.data;

                    tFrom = toLocalDay(monday);

                    tTo = toLocalDay(
                        new Date(monday.getTime() + 6 * 86400000)
                    );

                } else {
                    if (
                        !customFrom ||
                        !customTo ||
                        customFrom > customTo
                    ) {
                        return;
                    }

                    sum = (
                        await api.get('/totals/summary/range', {
                            params: {
                                from: customFrom,
                                to: customTo,
                            },
                        })
                    ).data.data;

                    tFrom = customFrom;
                    tTo = customTo;
                }

                setTotals(sum);

                const tr = await api.get(
                    '/totals/summary/daily',
                    {
                        params: {
                            from: tFrom,
                            to: tTo,
                        },
                    }
                );

                setTotalsTrend(tr.data.data);

            } catch {
                toastError('No se pudo cargar el total diario.');
            }
        };

        run();

        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [period, customFrom, customTo]);

    // --------------------------------------------------
    // CAMBIAR MES DE TENDENCIA
    // --------------------------------------------------

    const onTrendMonth = (ym) => {
        setTrendMonth(ym);

        if (!/^\d{4}-\d{2}$/.test(ym)) {
            return;
        }

        const [y, m] = ym.split('-').map(Number);
        const last = new Date(y, m, 0).getDate();

        const today = todayLocal();

        const endDay = today.slice(0, 7) === ym
            ? today.slice(8, 10)
            : String(last);

        setTrendFrom(`${ym}-01`);
        setTrendTo(`${ym}-${endDay}`);
    };

    // --------------------------------------------------
    // GENERACIÓN DE REPORTES
    // --------------------------------------------------

    const recordPayload = () => {
        const base = {
            templateId: reportForm.templateId || null,
            nroCI: reportForm.nroCI,
            dirigidoA: reportForm.dirigidoA,
            puestoDirigidoA: reportForm.puestoDirigidoA,
        };

        const now = new Date();

        if (
            period === 'today' ||
            period === 'yesterday'
        ) {
            const d = period === 'today'
                ? now
                : new Date(now.getTime() - 86400000);

            return {
                ...base,
                mode: 'DAY',
                date: toLocalDay(d),
            };
        }

        if (period === 'week') {
            const dow = (now.getDay() + 6) % 7;

            return {
                ...base,
                mode: 'WEEK',
                weekStart: toLocalDay(
                    new Date(now.getTime() - dow * 86400000)
                ),
            };
        }

        return {
            ...base,
            mode: 'RANGE',
            from: customFrom,
            to: customTo,
        };
    };

    // --------------------------------------------------
    // GRÁFICOS PARA EL DOCUMENTO
    // --------------------------------------------------

    const reportCharts = () => {
        const isDay = data?.kind === 'day';

        const ing = isDay
            ? data?.ingreso
            : (data?.ingresoByService || []);

        const ent = isDay
            ? data?.entrega
            : (data?.entregaByService || []);

        const trendPoints = trend.map((t) => ({
            ...t,
            day: t.date.slice(8),
            full: t.date.split('-').reverse().join('/'),
        }));

        const hasTotal = (items) =>
            (items || []).some((i) => Number(i.total) > 0);

        const hasTrend = trendPoints.some(
            (t) =>
                Number(t.ingreso) > 0 ||
                Number(t.entrega) > 0
        );

        const toPieSvg = (items, w, h) => {
            const p = pie(items);

            return pieSvg({
                labels: p.labels,
                legendLabels: p.legendLabels,
                values: p.datasets[0].data,
            }, w, h);
        };

        const out = [];

        if (hasTotal(ing)) {
            out.push({
                key: 'grafico_ingreso',
                width: 800,
                height: 500,
                svg: toPieSvg(ing, 800, 500),
            });
        }

        if (hasTotal(ent)) {
            out.push({
                key: 'grafico_entrega',
                width: 800,
                height: 500,
                svg: toPieSvg(ent, 800, 500),
            });
        }

        if (hasTrend) {
            out.push({
                key: 'grafico_tendencia',
                width: 1200,
                height: 573,
                svg: trendSvg(
                    trendPoints.map((t) => ({
                        day: t.day,
                        ingreso: Number(t.ingreso) || 0,
                        entrega: Number(t.entrega) || 0,
                    })),
                    1200,
                    573
                ),
            });
        }

        // Gráficos del total diario.
        const tRows = tItems.filter(
            (i) => Number(i.total) > 0
        );

        if (tRows.length) {
            const tp = {
                labels: tRows.map(
                    (i) => `${i.nro}. ${i.codigo || i.abreviation || `N° ${i.nro}`}`
                ),

                legendLabels: tRows.map(
                    (i) => `${i.nro} – ${String(i.abreviation || i.tramite).toUpperCase()}`
                ),

                values: tRows.map((i) => i.total),
            };

            out.push({
                key: 'grafico_distribucion',
                width: 1200,
                height: 750,
                svg: pieSvg(tp, 1200, 750),
            });

            const bars = tRows.map((i) => ({
                label: `${i.nro}. ${String(i.abreviation || i.tramite).toUpperCase()}`,
                value: i.total,
            }));

            const barsH = 24 + bars.length * 36 + 34;

            out.push({
                key: 'grafico_barras',
                width: 1200,
                height: barsH,
                svg: barSvg(bars, 1200),
            });
        }

        const tTrend = (totalsTrend || []).map((t) => ({
            day: t.date.slice(8),
            full: t.date.split('-').reverse().join('/'),
            total: Number(t.total) || 0,
        }));

        if (tTrend.some((t) => t.total > 0)) {
            out.push({
                key: 'grafico_tendencia_dia',
                width: 1500,
                height: 700,
                svg: trendSvg(
                    tTrend,
                    1500,
                    700,
                    [{
                        key: 'total',
                        name: 'Total',
                        color: '#9333ea',
                    }]
                ),
            });
        }

        return out;
    };

    // --------------------------------------------------
    // VALIDAR DATOS DEL REPORTE
    // --------------------------------------------------

    const reportMissing = () => {
        const req = [
            ['nroCI', 'N° informe'],
            ['dirigidoA', 'Dirigido a'],
            ['puestoDirigidoA', 'Puesto'],
        ];

        const names = req
            .filter(([k]) => !String(reportForm[k] || '').trim())
            .map(([, l]) => l);

        if (
            period === 'date' &&
            (!customFrom || !customTo || customFrom > customTo)
        ) {
            names.push('rango de fechas');
        }

        return names;
    };

    // --------------------------------------------------
    // ABRIR FORMULARIO DE REPORTE
    // --------------------------------------------------

    const openReport = async () => {
        setReportOpen(true);
        setPreviewOpen(false);
        setPreviewKind(null);

        setPreviewPdfUrl((url) => {
            if (url) {
                URL.revokeObjectURL(url);
            }

            return null;
        });

        try {
            if (!templates.length) {
                const r = await api.get('/templates');

                setTemplates(r.data.data);

                const def = r.data.data.find(
                    (t) => t.isDefault
                );

                if (def) {
                    setReportForm((f) => ({
                        ...f,
                        templateId: f.templateId || def.id,
                    }));
                }
            }
        } catch {
            toastError('No se pudo cargar plantillas.');
        }

        if (!reportForm.nroCI) {
            try {
                const r = await api.get('/reports', {
                    params: {
                        limit: 100,
                        search: `/${YEAR}`,
                    },
                });

                const rows = r.data.data || [];

                const max = rows.reduce((m, rr) => {
                    const p = parseNro(rr.nroCI);

                    return p && p.year === YEAR
                        ? Math.max(m, p.seq)
                        : m;
                }, 0);

                setReportForm((f) => ({
                    ...f,
                    nroCI: `${String(max + 1).padStart(3, '0')}/${YEAR}`,
                }));

            } catch {
                setReportForm((f) => (
                    f.nroCI
                        ? f
                        : {
                            ...f,
                            nroCI: nextNro(),
                        }
                ));
            }
        }
    };

    // --------------------------------------------------
    // LEER ERRORES DE VISTA PREVIA
    // --------------------------------------------------

    const blobError = async (err) => {
        try {
            const blob = err.response?.data;

            const text = blob instanceof Blob
                ? await blob.text()
                : null;

            if (text) {
                const j = JSON.parse(text);

                if (j.error) return j.error;
                if (j.message) return j.message;
            }

        } catch {
            // No es JSON.
        }

        return 'Error al procesar el informe.';
    };

    // --------------------------------------------------
    // VISTA PREVIA
    // --------------------------------------------------

    const doPreview = async () => {
        const m = reportMissing();

        if (m.length) {
            toastError(`Completa antes: ${m.join(', ')}.`);
            return;
        }

        setBusy(true);

        try {
            const charts = await svgChartsToPng(reportCharts());

            const payload = {
                ...recordPayload(),
                charts,
            };

            try {
                const pdf = await api.post(
                    '/reports/preview?format=pdf',
                    payload,
                    { responseType: 'blob' }
                );

                if (
                    pdf.headers['content-type']?.includes('pdf')
                ) {
                    const blob = pdf.data instanceof Blob
                        ? pdf.data
                        : new Blob(
                            [pdf.data],
                            { type: 'application/pdf' }
                        );

                    setPreviewPdfUrl((url) => {
                        if (url) {
                            URL.revokeObjectURL(url);
                        }

                        return URL.createObjectURL(blob);
                    });

                    setPreviewKind('pdf');
                    setPreviewOpen(true);

                    return;
                }

                throw new Error('no-pdf');

            } catch (err) {
                if (
                    err.response?.status &&
                    err.response.status !== 501
                ) {
                    throw err;
                }

                const r = await api.post(
                    '/reports/preview',
                    payload,
                    { responseType: 'blob' }
                );

                const blob = r.data instanceof Blob
                    ? r.data
                    : new Blob([r.data]);

                setPreviewKind('docx');
                setPreviewOpen(true);

                requestAnimationFrame(async () => {
                    try {
                        if (previewRef.current) {
                            previewRef.current.innerHTML = '';

                            await renderAsync(
                                await blob.arrayBuffer(),
                                previewRef.current
                            );

                            sanitizeLinks(previewRef.current);
                        }
                    } catch {
                        toastError(
                            'No se pudo mostrar la vista previa.'
                        );
                    }
                });
            }

        } catch (err) {
            toastError(await blobError(err));

        } finally {
            setBusy(false);
        }
    };

    // --------------------------------------------------
    // GENERAR Y DESCARGAR REPORTE
    // --------------------------------------------------

    const doGenerate = async () => {
        const m = reportMissing();

        if (m.length) {
            toastError(`Completa antes: ${m.join(', ')}.`);
            return;
        }

        setBusy(true);

        try {
            const charts = await svgChartsToPng(reportCharts());

            const r = await api.post('/reports/generate', {
                ...recordPayload(),
                charts,
            });

            const { id, fileName } = r.data.data;

            const d = await api.get(
                `/reports/${id}/download`,
                { responseType: 'blob' }
            );

            saveBlob(
                d.data,
                fileName || `informe-${reportForm.nroCI}.docx`
            );

            setReportForm((f) => ({
                ...f,
                nroCI: nextNro(f.nroCI),
            }));

            toastSuccess('Informe generado.');

        } catch (err) {
            toastError(
                err.response?.data?.error ||
                err.response?.data?.message ||
                err.message ||
                'Error al procesar el informe.'
            );

        } finally {
            setBusy(false);
        }
    };

    // --------------------------------------------------
    // CARGANDO
    // --------------------------------------------------

    if (!data) {
        return (
            <div className="card p-5">
                Cargando…
            </div>
        );
    }

    // --------------------------------------------------
    // DATOS GENERALES DEL DASHBOARD
    // --------------------------------------------------

    const isDay = data.kind === 'day';

    const totalIng =
        data.totalIngreso ??
        data.ingreso?.total ??
        0;

    const totalEnt =
        data.totalEntrega ??
        data.entrega?.total ??
        0;

    const ing = isDay
        ? data.ingreso
        : (data.ingresoByService || []);

    const ent = isDay
        ? data.entrega
        : (data.entregaByService || []);

    const periodLabel = period === 'week'
        ? 'Semana'
        : period === 'date'
            ? 'Rango'
            : 'Día';

    // --------------------------------------------------
    // TRÁMITES POR OPERADOR
    // --------------------------------------------------

    const roleLabel = (role) =>
        ROLE_LABELS[role] || role;

    const operatorRows = [
        ...(data.operators || []),
    ];

    const toggleOSort = (key) => {
        setOSort((s) => (
            s.key === key
                ? { key, dir: -s.dir }
                : {
                    key,
                    dir:
                        key === 'username' || key === 'role'
                            ? 1
                            : -1,
                }
        ));
    };

    const operatorSortChange = (key) => {
        setOSort({
            key,
            dir:
                key === 'username' || key === 'role'
                    ? 1
                    : -1,
        });
    };

    const sortedOperators = [...operatorRows].sort((a, b) => {
        if (oSort.key === 'username') {
            return String(
                a.fullName || a.username || ''
            ).localeCompare(
                String(b.fullName || b.username || ''),
                'es'
            ) * oSort.dir;
        }

        if (oSort.key === 'role') {
            return roleLabel(a.role).localeCompare(
                roleLabel(b.role),
                'es'
            ) * oSort.dir;
        }

        return (
            (Number(a[oSort.key]) || 0) -
            (Number(b[oSort.key]) || 0)
        ) * oSort.dir;
    });

    const oSortArrow = (key) => (
        oSort.key === key
            ? (oSort.dir === 1 ? ' ▲' : ' ▼')
            : ''
    );

    const operatorTotals = sortedOperators.reduce(
        (acc, o) => ({
            ingreso:
                acc.ingreso + (Number(o.ingreso) || 0),

            entrega:
                acc.entrega + (Number(o.entrega) || 0),

            total:
                acc.total + (Number(o.total) || 0),
        }),
        {
            ingreso: 0,
            entrega: 0,
            total: 0,
        }
    );

    const operatorName = (o) =>
        o.fullName?.trim() || o.username;

    // --------------------------------------------------
    // INGRESO TOTAL DIARIO
    // --------------------------------------------------

    const tItems = totals?.byTramite || [];

    const toggleTSort = (key) => {
        setTSort((s) => (
            s.key === key
                ? { key, dir: -s.dir }
                : {
                    key,
                    dir: key === 'tramite' ? 1 : -1,
                }
        ));
    };

    const sortedTItems = [...tItems].sort((a, b) => {
        const va = a[tSort.key];
        const vb = b[tSort.key];

        const cmp =
            typeof va === 'number' ||
            typeof vb === 'number'
                ? (Number(va) || 0) - (Number(vb) || 0)
                : String(va ?? '').localeCompare(
                    String(vb ?? ''),
                    'es'
                );

        return cmp * tSort.dir;
    });

    const sortArrow = (key) => (
        tSort.key === key
            ? (tSort.dir === 1 ? ' ▲' : ' ▼')
            : ''
    );

    const tName = (i) =>
        String(i.abreviation || i.tramite).toUpperCase();

    const tTag = (i) =>
        `${i.nro}. ${i.codigo || i.abreviation || `N° ${i.nro}`}`;

    const tPie = {
        labels: tItems.map(tTag),
        legendLabels: tItems.map(
            (i) => `${i.nro} – ${tName(i)}`
        ),
        datasets: [{
            data: tItems.map((i) => i.total),
        }],
    };

    const tBarLabels = tItems.map(
        (i) => `${i.nro}. ${tName(i)}`
    );

    const tTrendPoints = totalsTrend.map((t) => ({
        ...t,
        day: t.date.slice(8),
        full: t.date.split('-').reverse().join('/'),
    }));

    const fmtLong = (iso) => {
        const d = String(iso || '')
            .slice(0, 10)
            .split('-');

        return d.length === 3
            ? `${d[2]}/${d[1]}/${d[0]}`
            : '';
    };

    const totalsRange = totals
        ? (
            totals.date
                ? fmtLong(totals.date)
                : `${fmtLong(totals.periodStart)} al ${fmtLong(totals.periodEnd)}`
        )
        : '';

    const totalsTrendRange = tTrendPoints.length > 1
        ? `${fmtLong(totalsTrend[0].date)} al ${fmtLong(totalsTrend[totalsTrend.length - 1].date)}`
        : (
            tTrendPoints.length
                ? fmtLong(totalsTrend[0].date)
                : ''
        );

    // ==================================================
    // RENDER
    // ==================================================

    return (
        <RoleGuard allowed={['JEFE', 'ADMIN']}>
            <div className="space-y-4">

                {/* ======================================
                    1. FILTROS DE PERIODO
                ====================================== */}

                <div className="card flex flex-wrap items-center gap-2 p-4">
                    {[
                        { value: 'today', label: 'Hoy' },
                        { value: 'yesterday', label: 'Ayer' },
                        { value: 'week', label: 'Esta Semana' },
                        { value: 'date', label: 'Fecha' },
                    ].map((o) => (
                        <Button
                            key={o.value}
                            size="sm"
                            variant={
                                period === o.value
                                    ? 'primary'
                                    : 'secondary'
                            }
                            onClick={() => setPeriod(o.value)}
                        >
                            {o.label}
                        </Button>
                    ))}

                    {period === 'date' && (
                        <RangeDatePicker
                            from={customFrom}
                            to={customTo}
                            onChange={(f, t) => {
                                setCustomFrom(f);
                                setCustomTo(t);
                            }}
                        />
                    )}

                    <span className="pb-2 text-sm text-stone-500 dark:text-wa-muted">
                        {subtitle}
                    </span>

                    <Button
                        size="sm"
                        variant="secondary"
                        className="ml-auto"
                        onClick={openReport}
                    >
                        <DocumentChartBarIcon className="mr-1.5 h-4 w-4" />
                        Generar reporte
                    </Button>
                </div>

                {/* ======================================
                    2. INDICADORES KPI
                ====================================== */}

                <div className="grid grid-cols-2 gap-3 lg:grid-cols-3">
                    <KpiCard
                        title="Total Ingreso"
                        value={totals?.total ?? 0}
                        color="blue"
                        icon={ArrowDownTrayIcon}
                    />

                    <KpiCard
                        title="Trámites"
                        value={tItems.length}
                        color="green"
                        icon={DocumentChartBarIcon}
                    />

                    <KpiCard
                        title="Operadores Activos"
                        value={totals?.operators ?? 0}
                        color="orange"
                        icon={UserGroupIcon}
                    />
                </div>

                {/* ======================================
                    3. TOTAL DE TRÁMITES POR OPERADOR

                    Escritorio:
                    Gráfico | Tabla

                    Móvil:
                    Gráfico
                    Tarjetas
                ====================================== */}

                <div className="card space-y-4 p-4">

                    {/* Encabezado */}
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <h3 className="font-semibold">
                                Total de trámites por operador
                            </h3>

                            <p className="text-xs text-stone-500 dark:text-wa-muted">
                                {subtitle} · {periodLabel}
                            </p>
                        </div>

                        <span className="rounded-lg bg-purple-600 px-3 py-1 text-sm font-bold text-white">
                            Total: {operatorTotals.total}
                        </span>
                    </div>

                    {sortedOperators.length > 0 ? (
                        <div className="grid grid-cols-1 gap-4 xl:grid-cols-2 xl:items-start">

                            {/* GRÁFICO DE OPERADORES */}

                            <div className="min-w-0 rounded-lg border border-stone-200 p-3 dark:border-white/10">
                                <h4 className="mb-2 text-sm font-medium">
                                    Distribución por operador
                                </h4>

                                <BarChart
                                    labels={sortedOperators.map(operatorName)}
                                    datasets={[
                                        {
                                            label: 'Ingreso',
                                            data: sortedOperators.map(
                                                (o) => o.ingreso
                                            ),
                                            color: '#2563eb',
                                            stackId: 'total',
                                        },
                                        {
                                            label: 'Entrega',
                                            data: sortedOperators.map(
                                                (o) => o.entrega
                                            ),
                                            color: '#16a34a',
                                            stackId: 'total',
                                        },
                                    ]}
                                    height={Math.max(
                                        220,
                                        sortedOperators.length *
                                            (sortedOperators.length > 8 ? 24 : 34) + 70
                                    )}
                                    axisWidth={150}
                                    dense={sortedOperators.length > 8}
                                />
                            </div>

                            {/* TABLA DE OPERADORES */}

                            <div className="hidden min-w-0 sm:block">
                                <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
                                    <h4 className="text-sm font-medium">
                                        Detalle de operadores
                                    </h4>

                                    <span className="text-xs text-stone-500 dark:text-wa-muted">
                                        {sortedOperators.length} operadores
                                    </span>
                                </div>

                                <div className="max-h-[450px] overflow-auto rounded-lg border border-stone-200 dark:border-white/10">
                                    <table className="table table-compact w-full">
                                        <thead>
                                            <tr>
                                                <th
                                                    className="sticky top-0 z-10 cursor-pointer select-none bg-stone-100 dark:bg-wa-header"
                                                    onClick={() => toggleOSort('username')}
                                                    title="Ordenar"
                                                >
                                                    Operador{oSortArrow('username')}
                                                </th>

                                                <th
                                                    className="sticky top-0 z-10 cursor-pointer select-none bg-stone-100 dark:bg-wa-header"
                                                    onClick={() => toggleOSort('role')}
                                                    title="Ordenar"
                                                >
                                                    Rol{oSortArrow('role')}
                                                </th>

                                                <th
                                                    className="sticky top-0 z-10 cursor-pointer select-none bg-stone-100 text-right dark:bg-wa-header"
                                                    onClick={() => toggleOSort('ingreso')}
                                                    title="Ordenar"
                                                >
                                                    Ing.{oSortArrow('ingreso')}
                                                </th>

                                                <th
                                                    className="sticky top-0 z-10 cursor-pointer select-none bg-stone-100 text-right dark:bg-wa-header"
                                                    onClick={() => toggleOSort('entrega')}
                                                    title="Ordenar"
                                                >
                                                    Ent.{oSortArrow('entrega')}
                                                </th>

                                                <th
                                                    className="sticky top-0 z-10 cursor-pointer select-none bg-stone-100 text-right dark:bg-wa-header"
                                                    onClick={() => toggleOSort('total')}
                                                    title="Ordenar"
                                                >
                                                    Total{oSortArrow('total')}
                                                </th>
                                            </tr>
                                        </thead>

                                        <tbody>
                                            {sortedOperators.map((o) => (
                                                <tr key={o.userId}>
                                                    <td className="leading-tight">
                                                        <span className="font-medium">
                                                            {operatorName(o)}
                                                        </span>

                                                        <span className="block text-[11px] text-stone-500 dark:text-wa-muted">
                                                            {o.username}
                                                        </span>
                                                    </td>

                                                    <td className="text-xs">
                                                        {roleLabel(o.role)}
                                                    </td>

                                                    <td className="text-right tabular-nums">
                                                        {o.ingreso}
                                                    </td>

                                                    <td className="text-right tabular-nums">
                                                        {o.entrega}
                                                    </td>

                                                    <td className="text-right font-semibold tabular-nums">
                                                        {o.total}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>

                                        <tfoot>
                                            <tr className="bg-stone-100 font-semibold dark:bg-wa-header">
                                                <td colSpan={2}>
                                                    Total
                                                </td>

                                                <td className="text-right tabular-nums">
                                                    {operatorTotals.ingreso}
                                                </td>

                                                <td className="text-right tabular-nums">
                                                    {operatorTotals.entrega}
                                                </td>

                                                <td className="text-right tabular-nums">
                                                    {operatorTotals.total}
                                                </td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            </div>

                            {/* TARJETAS PARA MÓVILES */}

                            <div className="space-y-2 sm:hidden">
                                <div className="flex items-center justify-between gap-2 text-xs">
                                    <span className="font-medium text-stone-500 dark:text-wa-muted">
                                        Detalle de operadores
                                    </span>

                                    <div className="flex items-center gap-1">
                                        <select
                                            value={oSort.key}
                                            onChange={(e) =>
                                                operatorSortChange(e.target.value)
                                            }
                                            className="rounded-lg border border-stone-300 bg-white px-2 py-1 text-xs dark:border-white/20 dark:bg-wa-panel"
                                            aria-label="Ordenar operadores"
                                        >
                                            <option value="total">Total</option>
                                            <option value="ingreso">Ingreso</option>
                                            <option value="entrega">Entrega</option>
                                            <option value="username">Operador</option>
                                            <option value="role">Rol</option>
                                        </select>

                                        <button
                                            type="button"
                                            onClick={() =>
                                                setOSort((s) => ({
                                                    ...s,
                                                    dir: -s.dir,
                                                }))
                                            }
                                            className="rounded-lg border border-stone-300 px-2 py-1 dark:border-white/20"
                                            aria-label={
                                                oSort.dir === 1
                                                    ? 'Orden ascendente'
                                                    : 'Orden descendente'
                                            }
                                            title={
                                                oSort.dir === 1
                                                    ? 'Ascendente'
                                                    : 'Descendente'
                                            }
                                        >
                                            {oSort.dir === 1 ? '▲' : '▼'}
                                        </button>
                                    </div>
                                </div>

                                {sortedOperators.map((o) => (
                                    <article
                                        key={o.userId}
                                        className="rounded-lg border border-stone-200 p-3 dark:border-white/10"
                                    >
                                        <div className="flex items-start justify-between gap-2">
                                            <div className="min-w-0">
                                                <p className="truncate text-[13px] font-medium">
                                                    {operatorName(o)}
                                                </p>

                                                <p className="text-[11px] text-stone-500 dark:text-wa-muted">
                                                    {o.username} · {roleLabel(o.role)}
                                                </p>
                                            </div>

                                            <span className="shrink-0 rounded bg-primary-100 px-2 py-0.5 text-xs font-semibold tabular-nums text-primary-800 dark:bg-primary-900/40 dark:text-primary-300">
                                                {o.total}
                                            </span>
                                        </div>

                                        <dl className="mt-3 grid grid-cols-2 gap-2 text-xs">
                                            <div className="flex justify-between gap-2">
                                                <dt className="text-stone-500 dark:text-wa-muted">
                                                    Ingreso
                                                </dt>

                                                <dd className="font-medium tabular-nums">
                                                    {o.ingreso}
                                                </dd>
                                            </div>

                                            <div className="flex justify-between gap-2">
                                                <dt className="text-stone-500 dark:text-wa-muted">
                                                    Entrega
                                                </dt>

                                                <dd className="font-medium tabular-nums">
                                                    {o.entrega}
                                                </dd>
                                            </div>
                                        </dl>
                                    </article>
                                ))}

                                <div className="flex items-center justify-between rounded-lg bg-stone-100 px-3 py-2 text-xs font-semibold dark:bg-wa-header">
                                    <span>Total</span>
                                    <span className="tabular-nums">
                                        {operatorTotals.total}
                                    </span>
                                </div>
                            </div>
                        </div>
                    ) : (
                        <p className="py-8 text-center text-sm text-stone-500 dark:text-wa-muted">
                            Sin trámites de operadores en este periodo.
                        </p>
                    )}
                </div>

                {/* ======================================
                    4. DISTRIBUCIONES INGRESO Y ENTREGA
                ====================================== */}

                {SHOW_ENTRIES_BLOCKS && (
                    <div className="grid grid-cols-1 gap-3 lg:grid-cols-2">
                        <div className="card min-w-0 p-4">
                            <h3 className="mb-2 font-semibold">
                                Distribución Ingreso
                            </h3>

                            {ing.length ? (
                                <PieChart data={pie(ing)} />
                            ) : (
                                <p className="text-sm text-stone-500 dark:text-wa-muted">
                                    Sin datos para este periodo.
                                </p>
                            )}
                        </div>

                        <div className="card min-w-0 p-4">
                            <h3 className="mb-2 font-semibold">
                                Distribución Entrega
                            </h3>

                            {ent.length ? (
                                <PieChart data={pie(ent)} />
                            ) : (
                                <p className="text-sm text-stone-500 dark:text-wa-muted">
                                    Sin datos para este periodo.
                                </p>
                            )}
                        </div>
                    </div>
                )}

                {/* ======================================
                    5. TENDENCIA INGRESO Y ENTREGA
                ====================================== */}

                {SHOW_MAIN_TREND && (
                    <div className="card space-y-3 p-4">
                        <h3 className="font-semibold">
                            Tendencia por día – Ingreso y Entrega
                        </h3>

                        <div className="flex flex-wrap items-end gap-3">
                            <Input
                                label="Mes"
                                type="month"
                                value={trendMonth}
                                onChange={(e) =>
                                    onTrendMonth(e.target.value)
                                }
                            />

                            <RangeDatePicker
                                from={trendFrom}
                                to={trendTo}
                                onChange={(f, t) => {
                                    setTrendFrom(f);
                                    setTrendTo(t);
                                }}
                            />
                        </div>

                        {trend.length ? (
                            <TrendChart
                                points={trend.map((t) => ({
                                    ...t,
                                    day: t.date.slice(8),
                                    full: t.date.split('-').reverse().join('/'),
                                }))}
                            />
                        ) : (
                            <p className="text-sm text-stone-500 dark:text-wa-muted">
                                Sin datos para este periodo.
                            </p>
                        )}
                    </div>
                )}

                {/* ======================================
                    6. INGRESO TOTAL DIARIO
                ====================================== */}

                <div className="card space-y-5 p-4">

                    {/* Encabezado */}

                    <div className="flex flex-wrap items-center gap-3">
                        <div>
                            <h3 className="font-semibold">
                                Ingreso total diario
                            </h3>

                            <p className="text-sm text-stone-500 dark:text-wa-muted">
                                {subtitle} · {periodLabel}
                            </p>
                        </div>

                        <span className="ml-auto rounded-lg bg-purple-600 px-3 py-1 text-sm font-bold text-white">
                            Total: {totals?.total ?? 0}
                        </span>
                    </div>

                    {/* GRÁFICOS: DISTRIBUCIÓN Y BARRAS */}

                    <div className="grid grid-cols-1 gap-3 lg:grid-cols-2">

                        <div className="card min-w-0 p-4">
                            <h4 className="mb-2 text-sm font-semibold">
                                Distribución por trámite
                                {totalsRange ? ` · ${totalsRange}` : ''}
                            </h4>

                            {tItems.length ? (
                                <PieChart
                                    data={tPie}
                                    pieOuterRadius={140}
                                    outsideAll
                                    hideLegend
                                />
                            ) : (
                                <p className="text-sm text-stone-500 dark:text-wa-muted">
                                    Sin datos para este periodo.
                                </p>
                            )}
                        </div>

                        <div className="card min-w-0 p-4">
                            <h4 className="mb-2 text-sm font-semibold">
                                Total por trámite
                                {totalsRange ? ` · ${totalsRange}` : ''}
                            </h4>

                            {tItems.length ? (
                                <BarChart
                                    labels={tBarLabels}
                                    datasets={[
                                        {
                                            label: 'Total',
                                            data: tItems.map((i) => i.total),
                                            color: '#9333ea',
                                        },
                                    ]}
                                    axisWidth={Math.min(
                                        340,
                                        Math.max(
                                            110,
                                            Math.max(
                                                ...tBarLabels.map(
                                                    (l) => l.length
                                                )
                                            ) * 11 * 0.62 + 20
                                        )
                                    )}
                                    tickFontSize={11}
                                    dense
                                    showAllTicks
                                />
                            ) : (
                                <p className="text-sm text-stone-500 dark:text-wa-muted">
                                    Sin datos para este periodo.
                                </p>
                            )}
                        </div>
                    </div>

                    {/* TENDENCIA Y TABLA */}

                    <div>
                        <h4 className="mb-2 text-sm font-semibold">
                            Tendencia día a día – Total
                            {totalsTrendRange
                                ? ` · ${totalsTrendRange}`
                                : ''}
                        </h4>

                        <div className="grid grid-cols-1 gap-3 xl:grid-cols-5">

                            {/* TENDENCIA */}

                            <div className="min-w-0 xl:col-span-3">
                                {tTrendPoints.length ? (
                                    <TrendChart
                                        points={tTrendPoints}
                                        height={Math.max(
                                            320,
                                            sortedTItems.length * 24 + 110
                                        )}
                                        series={[
                                            {
                                                key: 'total',
                                                name: 'Total',
                                                color: '#9333ea',
                                            },
                                        ]}
                                        xLabel="fecha"
                                        yLabel="cantidad"
                                    />
                                ) : (
                                    <p className="text-sm text-stone-500 dark:text-wa-muted">
                                        Sin datos para este periodo.
                                    </p>
                                )}
                            </div>

                            {/* TABLA DE TRÁMITES */}

                            <div className="min-w-0 xl:col-span-2">
                                {tItems.length ? (
                                    <div className="max-h-[450px] overflow-auto rounded-lg border border-stone-200 dark:border-white/10">
                                        <table className="table table-compact w-full">
                                            <thead>
                                                <tr>
                                                    <th
                                                        className="sticky top-0 z-10 cursor-pointer select-none bg-stone-100 dark:bg-wa-header"
                                                        onClick={() => toggleTSort('nro')}
                                                        title="Ordenar"
                                                    >
                                                        N°{sortArrow('nro')}
                                                    </th>

                                                    <th
                                                        className="sticky top-0 z-10 cursor-pointer select-none bg-stone-100 dark:bg-wa-header"
                                                        onClick={() => toggleTSort('codigo')}
                                                        title="Ordenar"
                                                    >
                                                        Código{sortArrow('codigo')}
                                                    </th>

                                                    <th
                                                        className="sticky top-0 z-10 cursor-pointer select-none bg-stone-100 dark:bg-wa-header"
                                                        onClick={() => toggleTSort('tramite')}
                                                        title="Ordenar"
                                                    >
                                                        Nombre{sortArrow('tramite')}
                                                    </th>

                                                    <th
                                                        className="sticky top-0 z-10 cursor-pointer select-none bg-stone-100 dark:bg-wa-header"
                                                        onClick={() => toggleTSort('abreviation')}
                                                        title="Ordenar"
                                                    >
                                                        Abrev.{sortArrow('abreviation')}
                                                    </th>

                                                    <th
                                                        className="sticky top-0 z-10 cursor-pointer select-none bg-stone-100 text-right dark:bg-wa-header"
                                                        onClick={() => toggleTSort('total')}
                                                        title="Ordenar"
                                                    >
                                                        Total{sortArrow('total')}
                                                    </th>
                                                </tr>
                                            </thead>

                                            <tbody>
                                                {sortedTItems.map((i) => (
                                                    <tr
                                                        key={i.serviceId}
                                                        title={i.tramite}
                                                    >
                                                        <td className="tabular-nums">
                                                            {i.nro}
                                                        </td>

                                                        <td className="font-mono">
                                                            {String(
                                                                i.codigo || '—'
                                                            ).toUpperCase()}
                                                        </td>

                                                        <td className="leading-tight">
                                                            {String(
                                                                i.tramite || ''
                                                            ).toUpperCase()}
                                                        </td>

                                                        <td className="leading-tight">
                                                            {String(
                                                                i.abreviation || '—'
                                                            ).toUpperCase()}
                                                        </td>

                                                        <td className="text-right font-medium tabular-nums">
                                                            {i.total}
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>

                                            <tfoot>
                                                <tr className="bg-stone-100 font-semibold dark:bg-wa-header">
                                                    <td colSpan={4}>
                                                        Total
                                                    </td>

                                                    <td className="text-right tabular-nums">
                                                        {totals?.total ?? 0}
                                                    </td>
                                                </tr>
                                            </tfoot>
                                        </table>
                                    </div>
                                ) : (
                                    <p className="text-sm text-stone-500 dark:text-wa-muted">
                                        Sin datos para este periodo.
                                    </p>
                                )}
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {/* ======================================
                7. MODAL DE GENERACIÓN DE REPORTES
            ====================================== */}

            <Modal
                isOpen={reportOpen}
                onClose={() => setReportOpen(false)}
                title="Generar reporte"
                size="lg"
            >
                <div className="grid gap-x-3 gap-y-2 md:grid-cols-2">

                    <Select
                        label="Plantilla"
                        value={reportForm.templateId}
                        onChange={(e) =>
                            setReportForm({
                                ...reportForm,
                                templateId: e.target.value,
                            })
                        }
                        options={templates.map((t) => ({
                            value: t.id,
                            label: `${t.name}${t.isDefault ? ' (Por defecto)' : ''}`,
                        }))}
                    />

                    <Input
                        label="N° informe"
                        value={reportForm.nroCI}
                        onChange={(e) =>
                            setReportForm({
                                ...reportForm,
                                nroCI: e.target.value,
                            })
                        }
                    />

                    <Input
                        label="Dirigido a"
                        value={reportForm.dirigidoA}
                        onChange={(e) =>
                            setReportForm({
                                ...reportForm,
                                dirigidoA: e.target.value,
                            })
                        }
                    />

                    <Input
                        label="Puesto"
                        value={reportForm.puestoDirigidoA}
                        onChange={(e) =>
                            setReportForm({
                                ...reportForm,
                                puestoDirigidoA: e.target.value,
                            })
                        }
                    />
                </div>

                <p className="mt-2 text-sm text-stone-500 dark:text-wa-muted">
                    Periodo: {subtitle} · {periodLabel}
                </p>

                <div className="mt-3 flex flex-wrap gap-2">
                    <Button
                        size="sm"
                        variant="secondary"
                        loading={busy}
                        onClick={doPreview}
                    >
                        Vista previa
                    </Button>

                    <Button
                        size="sm"
                        loading={busy}
                        onClick={doGenerate}
                    >
                        Generar
                    </Button>

                    <Button
                        size="sm"
                        variant="secondary"
                        onClick={() => setReportOpen(false)}
                    >
                        Cerrar
                    </Button>
                </div>

                {/* VISTA PREVIA PDF */}

                {previewOpen &&
                    previewKind === 'pdf' &&
                    previewPdfUrl && (
                        <div className="mt-4 space-y-1">
                            <p className="text-xs text-stone-500 dark:text-wa-muted">
                                Vista exacta del documento (PDF).
                            </p>

                            <iframe
                                src={previewPdfUrl}
                                title="Vista previa PDF"
                                className="h-[500px] w-full rounded-lg border border-stone-200 bg-white dark:border-white/10"
                            />
                        </div>
                    )}

                {/* VISTA PREVIA DOCX */}

                {previewOpen && previewKind !== 'pdf' && (
                    <div className="mt-4 space-y-1">
                        <p className="text-xs text-stone-500 dark:text-wa-muted">
                            Vista simplificada (sin encabezado ni pie).
                            Genere para ver el documento final.
                        </p>

                        <div
                            ref={previewRef}
                            className="docx-preview max-h-[500px] overflow-auto rounded-lg border border-stone-200 bg-white p-4 text-black dark:border-white/10"
                        />
                    </div>
                )}
            </Modal>
        </RoleGuard>
    );
}
