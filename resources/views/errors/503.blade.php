@extends('errors.layout')

@php
    $maintenance = app()->isDownForMaintenance();
    $retryAfter = isset($exception) && $exception instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface
        ? ($exception->getHeaders()['Retry-After'] ?? null)
        : null;
    $retryMinutes = $retryAfter ? max(1, (int) ceil(((int) $retryAfter) / 60)) : null;
    $hint = $retryMinutes
        ? 'Tiempo estimado: aproximadamente '.$retryMinutes.' minuto'.($retryMinutes === 1 ? '' : 's').'.'
        : '';
@endphp

@section('code', '503')
@section('title', $maintenance ? 'En mantenimiento' : 'Servicio no disponible')
@section('message', $maintenance
    ? 'Estamos realizando mejoras en la plataforma. Volveremos a estar disponibles muy pronto.'
    : 'El servicio no está disponible en este momento. Inténtalo de nuevo más tarde.')
@section('hint', $hint)
@section('actionUrl', url()->current())
@section('action', 'Reintentar')
