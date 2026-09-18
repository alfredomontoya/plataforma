import { LockClosedIcon, PlusIcon } from '@heroicons/react/24/outline';

/**
 * Hoja estilo Word para editar la plantilla: página blanca con márgenes,
 * párrafos editables inline y marcas del motor como chips bloqueados.
 */
export default function WordSheet({ paragraphs, texts, marker, onText, onInsert, registerRef }) {
    let prevSection = null;
    return (
        <div className="mx-auto max-w-3xl rounded-sm bg-white text-black shadow-xl ring-1 ring-stone-200">
            <div className="px-10 py-8 md:px-14">
                {(paragraphs || []).map((p) => {
                    const value = texts[p.index] ?? p.text;
                    const divider = p.location !== 'documento' && p.location !== prevSection
                        ? (
                            <div key={`sec-${p.index}`} className="mb-1 mt-4 border-t border-dashed border-stone-300 pt-1 text-[11px] uppercase tracking-wide text-stone-400">
                                {p.location}
                            </div>
                        )
                        : null;
                    prevSection = p.location;
                    return (
                        <div key={p.index}>
                            {divider}
                            {p.locked ? (
                                <div className="my-1 flex justify-center">
                                    <span className="inline-flex items-center gap-1.5 rounded-full bg-amber-100 px-3 py-1 font-mono text-xs text-amber-900">
                                        <LockClosedIcon className="h-3.5 w-3.5" />
                                        {p.text || '(vacío)'}
                                    </span>
                                </div>
                            ) : (
                                <div className="group relative my-0.5">
                                    <textarea
                                        ref={(el) => registerRef && registerRef(p.index, el)}
                                        rows={Math.max(1, Math.min(6, value.split('\n').length || 1))}
                                        value={value}
                                        placeholder="(párrafo vacío)"
                                        onChange={(e) => onText(p.index, e.target.value)}
                                        className="w-full resize-y rounded border border-transparent bg-transparent px-2 py-1 text-justify text-[15px] leading-relaxed text-stone-900 outline-none hover:border-stone-300 focus:border-primary-400 focus:bg-primary-50/40"
                                    />
                                    <button
                                        type="button"
                                        title={`Insertar {${marker}} aquí`}
                                        onClick={() => onInsert(p.index)}
                                        className="absolute -right-1 top-1 hidden items-center gap-0.5 rounded-full border border-stone-300 bg-white px-2 py-0.5 font-mono text-[11px] text-stone-600 shadow-sm hover:border-primary-400 hover:text-primary-700 group-hover:inline-flex"
                                    >
                                        <PlusIcon className="h-3 w-3" />
                                        {`{${marker}}`}
                                    </button>
                                </div>
                            )}
                        </div>
                    );
                })}
                {!(paragraphs || []).length && (
                    <p className="text-sm text-stone-400">Sin párrafos.</p>
                )}
            </div>
        </div>
    );
}
