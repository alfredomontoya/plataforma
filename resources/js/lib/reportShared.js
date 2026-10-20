const YEAR = new Date().getFullYear();

export const parseNro = (nro) => {
    const m = /^(\d{1,5})\/(\d{4})$/.exec(String(nro || '').trim());
    return m ? { seq: Number(m[1]), year: Number(m[2]) } : null;
};

export const nextNro = (from) => {
    const p = parseNro(from);
    const seq = p && p.year === YEAR ? p.seq : 0;
    return `${String(seq + 1).padStart(3, '0')}/${YEAR}`;
};

/** Arma el shape de data usado por <PieChart> a partir de agregados por servicio. */
export const pie = (items) => ({
    labels: items.map((i) => i.codigo || i.abreviation || i.serviceName),
    legendLabels: items.map((i) => [i.codigo, i.abreviation || i.serviceName].filter(Boolean).join(' – ')),
    datasets: [{ data: items.map((i) => i.total) }],
});

/**
 * docx-preview renderiza el .docx en el DOM y copia el `Target` de las
 * relaciones externas al atributo href sin filtrar el esquema: un .docx con
 * href="javascript:..." ejecutaría código en el navegador (XSS) al pulsarlo.
 * Se eliminan los enlaces con esquemas no seguros tras cada render.
 */
export const sanitizeLinks = (root) => {
    if (!root) return;
    root.querySelectorAll('a[href]').forEach((a) => {
        const href = (a.getAttribute('href') || '').trim();
        if (!/^(https?:|mailto:|tel:|#|\/)/i.test(href)) {
            a.removeAttribute('href');
            a.removeAttribute('xlink:href');
        }
    });
};

export const saveBlob = (blob, name) => {
    const url = URL.createObjectURL(blob instanceof Blob ? blob : new Blob([blob]));
    const a = document.createElement('a');
    a.href = url;
    // Sanea el nombre: evita rutas/caracteres raros o extensiones engañosas.
    a.download = String(name || 'archivo').replace(/[\\/:*?"<>|\x00-\x1F]/g, '_').slice(0, 150);
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
};