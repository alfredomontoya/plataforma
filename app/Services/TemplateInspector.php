<?php

namespace App\Services;

use App\Support\ApiResponse;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Inspector y editor de textos fijos de plantillas .docx.
 * Reutiliza el catálogo de macros y la normalización de ReportService.
 */
class TemplateInspector
{
    public const GROUPS = [
        'texto' => ['FECHA', 'NRO_CI', 'DIRIGIDO_A', 'PUESTO_DIRIGIDO_A', 'REMITENTE',
            'PUESTO_REMITENTE', 'NRO_SEMANA', 'FECHA_INICIO', 'FECHA_FIN'],
        'tablas' => ['TABLA_INGRESO', 'TABLA_ENTREGA',
            'ING_NOMBRE', 'ING_TOTAL', 'ENT_NOMBRE', 'ENT_TOTAL'],
        'graficos' => ['GRAFICO_INGRESO', 'GRAFICO_ENTREGA', 'GRAFICO_TENDENCIA',
            'GRAFICO_DISTRIBUCION_TRAMITE', 'GRAFICO_TOTAL_TRAMITE', 'GRAFICO_TENDENCIA_DIA'],
    ];

    /** Macros encontrados por grupo + faltantes + desconocidos. */
    public function inspect(string $templatePath): array
    {
        $zip = new \ZipArchive;
        if ($zip->open($templatePath) !== true) {
            throw new HttpResponseException(ApiResponse::error('No se pudo abrir la plantilla.', 400));
        }
        $found = [];
        $unknown = [];
        foreach (ReportService::templateParts($zip) as $part) {
            $xml = $zip->getFromName($part);
            if (! is_string($xml)) {
                continue;
            }
            $text = strip_tags(ReportService::collapseSplitMacros($xml));
            if (! preg_match_all('/\{(.*?)\}/', $text, $m)) {
                continue;
            }
            foreach ($m[1] as $inner) {
                $var = ltrim(trim($inner), '$');
                if ($var === '') {
                    continue;
                }
                if (in_array($var, ReportService::TEMPLATE_VARS, true)) {
                    $found[$var] = true;
                } else {
                    $unknown[$part][] = mb_substr($inner, 0, 80);
                }
            }
        }
        $zip->close();

        $grouped = [];
        foreach (self::GROUPS as $group => $vars) {
            $grouped[$group] = [
                'found' => array_values(array_filter($vars, fn ($v) => isset($found[$v]))),
                'missing' => array_values(array_filter($vars, fn ($v) => ! isset($found[$v]))),
            ];
        }

        return ['groups' => $grouped, 'unknown' => $unknown];
    }

    /** ¿Párrafo consumido por el motor (solo macro o ancla de tabla)? */
    private function isEngineParagraph(string $collapsedText): bool
    {
        if (preg_match('/^\$?\{\s*[A-Z_]+\s*\}$/', $collapsedText)) {
            return true;
        }

        return str_contains($collapsedText, '{TABLA_INGRESO}') || str_contains($collapsedText, '{TABLA_ENTREGA}');
    }

    /** Valida que todo {...} sea un marcador conocido. */
    private function assertKnownMacros(string $text): void
    {
        if (! preg_match_all('/\$?\{([^}]*)\}/', $text, $m)) {
            return;
        }
        foreach ($m[1] as $inner) {
            $var = ltrim(trim($inner), '$');
            if (! in_array($var, ReportService::TEMPLATE_VARS, true)) {
                throw new HttpResponseException(
                    ApiResponse::error("Marcador desconocido: '{$var}'. Usa la guía de marcadores.", 400)
                );
            }
        }
    }

    /**
     * Párrafos del documento: editables + los del motor (locked) para ver
     * dónde están las marcas de datos.
     *
     * @return array<int, array{index: int, part: string, location: string, text: string, locked: bool}>
     */
    public function paragraphs(string $templatePath): array
    {
        $out = [];
        foreach ($this->eachParagraph($templatePath) as $p) {
            $collapsed = trim(strip_tags(ReportService::collapseSplitMacros($p['xml'])));
            $out[] = [
                'index' => count($out),
                'part' => $p['part'],
                'location' => $p['location'],
                'text' => trim(preg_replace('/\s+/', ' ', strip_tags($p['xml'])) ?? ''),
                'locked' => $this->isEngineParagraph($collapsed),
            ];
        }

        return $out;
    }

    /**
     * Reescribe textos de párrafos editables (mapa índice→texto) conservando
     * el formato del primer run. Acepta marcadores conocidos ({VAR}).
     */
    public function updateParagraphs(string $templatePath, array $texts): void
    {
        foreach ($texts as $text) {
            $this->assertKnownMacros((string) $text);
        }

        // Mismo índice que paragraphs(): solo los no bloqueados son editables.
        $targets = [];
        $idx = 0;
        foreach ($this->eachParagraph($templatePath) as $p) {
            $collapsed = trim(strip_tags(ReportService::collapseSplitMacros($p['xml'])));
            if (! $this->isEngineParagraph($collapsed)) {
                $targets[$idx] = $p;
            }
            $idx++;
        }

        $byPart = [];
        foreach ($texts as $index => $text) {
            if (! isset($targets[(int) $index])) {
                throw new HttpResponseException(ApiResponse::error("Párrafo inexistente: {$index}.", 400));
            }
            $byPart[$targets[(int) $index]['part']][] = ['node' => $targets[(int) $index]['node'], 'text' => (string) $text];
        }
        if ($byPart === []) {
            return;
        }

        $zip = new \ZipArchive;
        if ($zip->open($templatePath) !== true) {
            throw new HttpResponseException(ApiResponse::error('No se pudo abrir la plantilla.', 400));
        }
        $ns = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
        foreach ($byPart as $part => $edits) {
            $xml = $zip->getFromName($part);
            $dom = new \DOMDocument('1.0', 'UTF-8');
            if (! is_string($xml) || ! @$dom->loadXML($xml)) {
                $zip->close();
                throw new HttpResponseException(ApiResponse::error('Plantilla corrupta.', 400));
            }
            $xp = new \DOMXPath($dom);
            $xp->registerNamespace('w', $ns);
            $paras = $xp->query('//w:p');
            foreach ($edits as $edit) {
                /** @var \DOMElement $pNode */
                $pNode = $paras->item($edit['node']);
                $this->setParagraphText($dom, $ns, $pNode, $edit['text']);
            }
            $zip->deleteName($part);
            $zip->addFromString($part, $dom->saveXML() ?: $xml);
        }
        $zip->close();
    }

    /**
     * Agrega un párrafo de texto al final del cuerpo (antes de sectPr).
     * Acepta texto fijo y/o marcadores conocidos (ej. "{GRAFICO_INGRESO}").
     *
     * @return array lista actualizada de paragraphs()
     */
    public function appendParagraph(string $templatePath, string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            throw new HttpResponseException(ApiResponse::error('El texto es obligatorio.', 400));
        }
        $this->assertKnownMacros($text);

        $zip = new \ZipArchive;
        if ($zip->open($templatePath) !== true) {
            throw new HttpResponseException(ApiResponse::error('No se pudo abrir la plantilla.', 400));
        }
        $ns = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
        $xml = $zip->getFromName('word/document.xml');
        $dom = new \DOMDocument('1.0', 'UTF-8');
        if (! is_string($xml) || ! @$dom->loadXML($xml)) {
            $zip->close();
            throw new HttpResponseException(ApiResponse::error('Plantilla corrupta.', 400));
        }
        $xp = new \DOMXPath($dom);
        $xp->registerNamespace('w', $ns);
        $bodies = $xp->query('//w:body');
        if ($bodies === false || $bodies->length === 0) {
            $zip->close();
            throw new HttpResponseException(ApiResponse::error('Plantilla corrupta.', 400));
        }
        $body = $bodies->item(0);
        $p = $dom->createElementNS($ns, 'w:p');
        $r = $dom->createElementNS($ns, 'w:r');
        $t = $dom->createElementNS($ns, 'w:t');
        $t->setAttribute('xml:space', 'preserve');
        $t->appendChild($dom->createTextNode($text));
        $r->appendChild($t);
        $p->appendChild($r);
        $sect = $xp->query('w:sectPr', $body);
        if ($sect !== false && $sect->length > 0) {
            $body->insertBefore($p, $sect->item(0));
        } else {
            $body->appendChild($p);
        }
        $zip->deleteName('word/document.xml');
        $zip->addFromString('word/document.xml', $dom->saveXML() ?: $xml);
        $zip->close();

        return $this->paragraphs($templatePath);
    }

    /**
     * @return array<int, array{part: string, location: string, node: int, xml: string}>
     * @throws HttpResponseException
     */
    private function eachParagraph(string $templatePath): array
    {
        $zip = new \ZipArchive;
        if ($zip->open($templatePath) !== true) {
            throw new HttpResponseException(ApiResponse::error('No se pudo abrir la plantilla.', 400));
        }
        $ns = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
        $out = [];
        foreach (ReportService::templateParts($zip) as $part) {
            $xml = $zip->getFromName($part);
            if (! is_string($xml)) {
                continue;
            }
            $dom = new \DOMDocument('1.0', 'UTF-8');
            if (! @$dom->loadXML($xml)) {
                continue;
            }
            $xp = new \DOMXPath($dom);
            $xp->registerNamespace('w', $ns);
            $paras = $xp->query('//w:p');
            if ($paras === false) {
                continue;
            }
            for ($i = 0; $i < $paras->length; $i++) {
                $node = $paras->item($i);
                $inner = '';
                foreach ($node->childNodes as $child) {
                    $inner .= $dom->saveXML($child);
                }
                $out[] = [
                    'part' => $part,
                    'location' => $this->locationLabel($part),
                    'node' => $i,
                    'xml' => "<w:p>{$inner}</w:p>",
                ];
            }
        }
        $zip->close();

        return $out;
    }

    private function locationLabel(string $part): string
    {
        if ($part === 'word/document.xml') {
            return 'documento';
        }
        if (preg_match('#word/(header\d*)\.xml#', $part, $m)) {
            return "encabezado ({$m[1]})";
        }
        if (preg_match('#word/(footer\d*)\.xml#', $part, $m)) {
            return "pie ({$m[1]})";
        }

        return $part;
    }

    private function setParagraphText(\DOMDocument $dom, string $ns, \DOMElement $p, string $text): void
    {
        $xp = new \DOMXPath($dom);
        $xp->registerNamespace('w', $ns);
        $runs = $xp->query('w:r', $p);
        if ($runs === false || $runs->length === 0) {
            $r = $dom->createElementNS($ns, 'w:r');
            $t = $dom->createElementNS($ns, 'w:t');
            $t->setAttribute('xml:space', 'preserve');
            $t->appendChild($dom->createTextNode($text));
            $r->appendChild($t);
            $p->appendChild($r);

            return;
        }
        /** @var \DOMElement $first */
        $first = $runs->item(0);
        // Conserva rPr del primer run; elimina el resto.
        for ($i = $runs->length - 1; $i >= 1; $i--) {
            $p->removeChild($runs->item($i));
        }
        foreach (iterator_to_array($first->childNodes) as $child) {
            if ($child instanceof \DOMElement && $child->localName === 't') {
                $first->removeChild($child);
            }
        }
        // Limpia dibujos anclados al párrafo editado (no se reubican).
        foreach (iterator_to_array($first->childNodes) as $child) {
            if ($child instanceof \DOMElement && in_array($child->localName, ['drawing', 'pict'], true)) {
                $first->removeChild($child);
            }
        }
        $t = $dom->createElementNS($ns, 'w:t');
        $t->setAttribute('xml:space', 'preserve');
        $t->appendChild($dom->createTextNode($text));
        $first->appendChild($t);
    }
}
