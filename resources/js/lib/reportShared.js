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

export const saveBlob = (blob, name) => {
    const url = URL.createObjectURL(blob instanceof Blob ? blob : new Blob([blob]));
    const a = document.createElement('a');
    a.href = url;
    a.download = name;
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
};