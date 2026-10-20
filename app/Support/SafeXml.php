<?php

namespace App\Support;

/**
 * Carga de XML de partes .docx de forma blindada (A6):
 * sin red (LIBXML_NONET), sin resolución de entidades externas (anti-XXE) y
 * sin expansión de entidades (anti billion-laughs, nunca LIBXML_NOENT).
 */
final class SafeXml
{
    /**
     * Devuelve el DOMDocument cargado o null si el XML no es válido.
     * No lanza excepciones por contenido malicioso/corrupto.
     */
    public static function load(string $xml): ?\DOMDocument
    {
        // Corta cualquier resolución de entidades externas (XXE). Disponible en
        // PHP 8; libxml_disable_entity_loader fue deprecado/eliminado, así que
        // se usa el setter moderno con fallback defensivo.
        if (function_exists('libxml_set_external_entity_loader')) {
            libxml_set_external_entity_loader(static fn () => null);
        }

        $previous = libxml_use_internal_errors(true);
        try {
            $dom = new \DOMDocument('1.0', 'UTF-8');
            // LIBXML_NONET: sin red. NO se pasa LIBXML_NOENT (bloquea billion-laughs).
            $ok = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            if (function_exists('libxml_set_external_entity_loader')) {
                libxml_set_external_entity_loader(null);
            }
        }

        return $ok ? $dom : null;
    }
}
