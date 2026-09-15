import { useEffect, useRef, useState } from 'react';
import { CalendarDaysIcon, CheckIcon, ChevronLeftIcon, ChevronRightIcon } from '@heroicons/react/24/outline';
import { todayLocal } from '../../lib/api';

const MAX_DAYS = 93;
const WEEKDAYS = ['Lu', 'Ma', 'Mi', 'Ju', 'Vi', 'Sá', 'Do'];

const pad = (n) => String(n).padStart(2, '0');
const toKey = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
const parseKey = (s) => new Date(`${s}T12:00:00`);
const labelOf = (s) => {
    const [y, m, d] = s.split('-');
    return `${d}/${m}/${y}`;
};

const monthCells = (y, m) => {
    const lead = (new Date(y, m, 1).getDay() + 6) % 7;
    const days = new Date(y, m + 1, 0).getDate();
    const cells = [];
    for (let i = 0; i < lead; i++) cells.push(null);
    for (let d = 1; d <= days; d++) cells.push(toKey(new Date(y, m, d)));
    return cells;
};

export function RangeDatePicker({ from, to, onChange, maxDate }) {
    const max = maxDate || todayLocal();
    const [open, setOpen] = useState(false);
    const [draftFrom, setDraftFrom] = useState(from || '');
    const [draftTo, setDraftTo] = useState(to || '');
    const [view, setView] = useState(() => {
        const b = parseKey(from || max);
        return { y: b.getFullYear(), m: b.getMonth() };
    });
    const ref = useRef(null);

    useEffect(() => {
        setDraftFrom(from || '');
        setDraftTo(to || '');
    }, [from, to]);

    useEffect(() => {
        if (!open) return;
        const close = (e) => {
            if (ref.current && !ref.current.contains(e.target)) setOpen(false);
        };
        const esc = (e) => {
            if (e.key === 'Escape') setOpen(false);
        };
        document.addEventListener('mousedown', close);
        document.addEventListener('keydown', esc);
        return () => {
            document.removeEventListener('mousedown', close);
            document.removeEventListener('keydown', esc);
        };
    }, [open]);

    const openPicker = () => {
        const b = parseKey(from || max);
        setView({ y: b.getFullYear(), m: b.getMonth() });
        setDraftFrom(from || '');
        setDraftTo(to || '');
        setOpen(true);
    };

    const shiftView = (delta) => {
        setView((v) => {
            const d = new Date(v.y, v.m + delta, 1);
            const top = parseKey(max);
            if (d > new Date(top.getFullYear(), top.getMonth(), 1)) return v;
            return { y: d.getFullYear(), m: d.getMonth() };
        });
    };

    const pick = (key) => {
        if (!key || key > max) return;
        if (!draftFrom || (draftFrom && draftTo)) {
            setDraftFrom(key);
            setDraftTo('');
            return;
        }
        let f = draftFrom;
        let t = key;
        if (t < f) [f, t] = [t, f];
        const diff = Math.round((parseKey(t) - parseKey(f)) / 86400000);
        if (diff >= MAX_DAYS) t = toKey(new Date(parseKey(f).getTime() + (MAX_DAYS - 1) * 86400000));
        setDraftFrom(f);
        setDraftTo(t);
    };

    const apply = () => {
        if (!draftFrom || !draftTo) return;
        onChange?.(draftFrom, draftTo);
        setOpen(false);
    };

    const cells = monthCells(view.y, view.m);
    const monthName = new Date(view.y, view.m, 1).toLocaleDateString('es-BO', { month: 'long', year: 'numeric' });
    const ready = !!(draftFrom && draftTo);
    const shown = from ? (to ? `${labelOf(from)} – ${labelOf(to)}` : labelOf(from)) : 'Seleccionar rango';

    return (
        <div ref={ref} className="relative">
            <button
                type="button"
                onClick={() => (open ? setOpen(false) : openPicker())}
                className="flex w-full items-center gap-2 rounded-lg border border-stone-300 bg-white px-3 py-2 text-sm dark:border-white/10 dark:bg-wa-header"
            >
                <CalendarDaysIcon className="h-4 w-4 shrink-0 text-stone-500" />
                <span className={from ? '' : 'text-stone-400'}>{shown}</span>
            </button>
            {open && (
                <div className="absolute z-30 mt-1 w-72 rounded-lg border border-stone-200 bg-white p-3 shadow-lg dark:border-white/10 dark:bg-wa-panel">
                    <div className="mb-2 flex items-center justify-between">
                        <button type="button" onClick={() => shiftView(-1)} className="rounded p-1 hover:bg-stone-100 dark:hover:bg-white/5" aria-label="Mes anterior">
                            <ChevronLeftIcon className="h-4 w-4" />
                        </button>
                        <span className="text-sm font-semibold capitalize">{monthName}</span>
                        <button type="button" onClick={() => shiftView(1)} className="rounded p-1 hover:bg-stone-100 dark:hover:bg-white/5" aria-label="Mes siguiente">
                            <ChevronRightIcon className="h-4 w-4" />
                        </button>
                    </div>
                    <div className="grid grid-cols-7 justify-items-center gap-0.5">
                        {WEEKDAYS.map((w) => (
                            <span key={w} className="w-8 text-center text-[11px] font-medium text-stone-400">{w}</span>
                        ))}
                        {cells.map((key, i) => {
                            if (!key) return <span key={`e${i}`} className="h-8 w-8" />;
                            const isStart = key === draftFrom;
                            const isEnd = !!draftTo && key === draftTo;
                            const inRange = draftFrom && draftTo && key > draftFrom && key < draftTo;
                            const disabled = key > max;
                            return (
                                <button
                                    key={key}
                                    type="button"
                                    disabled={disabled}
                                    onClick={() => pick(key)}
                                    className={`h-8 w-8 rounded-full text-sm ${
                                        isStart || isEnd
                                            ? 'bg-primary-600 font-semibold text-white'
                                            : inRange
                                              ? 'bg-primary-100 font-medium text-primary-800 dark:bg-primary-900/50 dark:text-primary-200'
                                              : 'hover:bg-stone-100 dark:hover:bg-white/5'
                                    } ${disabled ? 'cursor-not-allowed opacity-30 hover:bg-transparent' : ''}`}
                                >
                                    {Number(key.slice(8))}
                                </button>
                            );
                        })}
                    </div>
                    <div className="mt-3 flex items-center justify-between gap-2">
                        <p className="text-[11px] text-stone-500 dark:text-wa-muted">Día inicial y final · Máx. 93 días</p>
                        <button
                            type="button"
                            disabled={!ready}
                            onClick={apply}
                            className={`inline-flex items-center gap-1 rounded-lg px-3 py-1.5 text-sm font-medium transition ${
                                ready
                                    ? 'bg-primary-600 text-white hover:bg-primary-700'
                                    : 'cursor-not-allowed bg-stone-200 text-stone-400 dark:bg-white/10 dark:text-wa-muted'
                            }`}
                        >
                            <CheckIcon className="h-4 w-4" />
                            Aplicar
                        </button>
                    </div>
                </div>
            )}
        </div>
    );
}