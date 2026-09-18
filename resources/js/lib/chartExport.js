/**
 * Convierte strings SVG (generados por lib/chartSvg) a PNG base64 para el .docx.
 *
 * Ya no se rasteriza desde un div oculto: ese método dependía de
 * ResponsiveContainer/ResizeObserver y producía PNG distorsionados
 * (rectángulos sólidos sin leyenda). El SVG aquí ya trae tamaño fijo,
 * geometría determinística y leyenda incluida.
 */

const svgStringToPng = (svg, width, height) =>
    new Promise((resolve, reject) => {
        const src = `data:image/svg+xml;base64,${btoa(unescape(encodeURIComponent(svg)))}`;
        const img = new Image();
        img.onload = () => {
            try {
                const canvas = document.createElement('canvas');
                canvas.width = width;
                canvas.height = height;
                const ctx = canvas.getContext('2d');
                ctx.fillStyle = '#ffffff';
                ctx.fillRect(0, 0, width, height);
                ctx.drawImage(img, 0, 0, width, height);
                resolve(canvas.toDataURL('image/png'));
            } catch (e) {
                reject(new Error('No se pudo rasterizar el gráfico.'));
            }
        };
        img.onerror = () => reject(new Error('No se pudo rasterizar el gráfico.'));
        img.src = src;
    });

/**
 * @param {Array<{key: string, svg: string, width: number, height: number}>} charts
 * @returns {Promise<Record<string, string>>} data-URL "data:image/png;base64,..."
 */
export const svgChartsToPng = async (charts) => {
    const results = {};
    for (const c of charts || []) {
        if (!c.svg) continue;
        results[c.key] = await svgStringToPng(c.svg, c.width, c.height);
    }
    return results;
};
