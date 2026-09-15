import { useMemo } from 'react';

/* Doodles line-art de vehículos (coordenadas locales de cada uno).
   Se dibujan con stroke="currentColor": el color lo pone el contenedor
   (stone en light, blanco tenue en dark). */
const DOODLES = [
    {
        vb: '0 0 68 40',
        body: (
            <g>
                <path d="M4 24 L12 24 L18 14 L40 14 L48 24 L64 24 L64 30 L4 30 Z" />
                <path d="M29 14 L29 24" />
                <circle cx="20" cy="32" r="5" />
                <circle cx="50" cy="32" r="5" />
            </g>
        ),
    },
    {
        vb: '0 0 66 42',
        body: (
            <g>
                <path d="M4 26 L8 26 L12 12 L42 12 L50 26 L62 26 L62 32 L4 32 Z" />
                <path d="M31 12 L31 26" />
                <circle cx="20" cy="34" r="6" />
                <circle cx="48" cy="34" r="6" />
            </g>
        ),
    },
    {
        vb: '0 0 68 38',
        body: (
            <g>
                <path d="M4 28 L4 16 L22 16 L28 8 L40 8 L44 16 L64 16 L64 28 L4 28 Z" />
                <path d="M44 16 L44 28" />
                <circle cx="18" cy="30" r="5" />
                <circle cx="52" cy="30" r="5" />
            </g>
        ),
    },
    {
        vb: '0 0 64 42',
        body: (
            <g>
                <path d="M4 32 L4 10 L46 10 L60 24 L60 32 Z" />
                <path d="M8 16 L30 16 L30 26 L8 26 Z" />
                <circle cx="18" cy="34" r="5" />
                <circle cx="48" cy="34" r="5" />
            </g>
        ),
    },
    {
        vb: '0 0 88 40',
        body: (
            <g>
                <path d="M4 30 L4 12 L74 12 L84 22 L84 30 Z" />
                <path d="M4 24 L80 24 M18 12 L18 24 M30 12 L30 24 M42 12 L42 24 M54 12 L54 24 M66 12 L66 24" />
                <circle cx="20" cy="32" r="5" />
                <circle cx="66" cy="32" r="5" />
            </g>
        ),
    },
    {
        vb: '0 0 84 42',
        body: (
            <g>
                <path d="M4 32 L4 14 L18 14 L24 6 L36 6 L36 32 Z" />
                <path d="M40 4 L80 4 L80 32 L40 32 Z" />
                <circle cx="18" cy="34" r="5" />
                <circle cx="56" cy="34" r="5" />
                <circle cx="70" cy="34" r="5" />
            </g>
        ),
    },
    {
        vb: '0 0 66 40',
        body: (
            <g>
                <circle cx="12" cy="30" r="8" />
                <circle cx="54" cy="30" r="8" />
                <path d="M12 30 L28 14 L42 14 L54 30" />
                <path d="M42 14 L46 6 L52 6" />
                <path d="M24 14 L34 14" />
            </g>
        ),
    },
    {
        vb: '0 0 64 40',
        body: (
            <g>
                <circle cx="12" cy="32" r="6" />
                <circle cx="52" cy="32" r="6" />
                <path d="M12 32 L26 32 L32 20 L42 20 L46 32 L52 32" />
                <path d="M42 20 L46 10" />
                <path d="M28 20 L36 20" />
            </g>
        ),
    },
];

/**
 * Fondo decorativo vehicular estilo WhatsApp con aleatoriedad real:
 * el área se divide en una cuadrícula y en cada celda se sortea el tipo
 * de vehículo, la posición (con jitter dentro de la celda), el giro
 * (0-360°), el tamaño y si va volteado. Así no quedan huecos vacíos
 * y cada carga se ve distinta. Capa no interactiva detrás del <main>.
 */
export default function VehicleDoodles({ cols = 6, rows = 8 }) {
    const items = useMemo(() => {
        const out = [];
        let id = 0;
        for (let c = 0; c < cols; c++) {
            for (let r = 0; r < rows; r++) {
                out.push({
                    id: id++,
                    d: Math.floor(Math.random() * DOODLES.length),
                    left: ((c + 0.15 + Math.random() * 0.7) / cols) * 100,
                    top: ((r + 0.15 + Math.random() * 0.7) / rows) * 100,
                    rotate: Math.random() * 360,
                    size: 40 + Math.random() * 44,
                    flip: Math.random() < 0.5,
                });
            }
        }
        return out;
    }, [cols, rows]);

    return (
        <div
            aria-hidden="true"
            className="pointer-events-none absolute inset-0 overflow-hidden text-stone-300/70 dark:text-white dark:opacity-[0.13]"
        >
            {items.map((it) => {
                const D = DOODLES[it.d];
                return (
                    <svg
                        key={it.id}
                        viewBox={D.vb}
                        className="absolute"
                        style={{
                            left: `${it.left}%`,
                            top: `${it.top}%`,
                            width: it.size,
                            transform: `translate(-50%, -50%) rotate(${it.rotate}deg) scaleX(${it.flip ? -1 : 1})`,
                        }}
                        fill="none"
                        stroke="currentColor"
                        strokeWidth={2}
                        strokeLinecap="round"
                        strokeLinejoin="round"
                    >
                        {D.body}
                    </svg>
                );
            })}
        </div>
    );
}
