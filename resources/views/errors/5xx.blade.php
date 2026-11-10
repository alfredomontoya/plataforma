@extends('errors.layout')

@php
    $status = isset($exception) && $exception instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface
        ? $exception->getStatusCode()
        : 500;
@endphp

@section('code', (string) $status)
@section('title', 'Error del servidor')
@section('message', 'Ocurrió un problema inesperado. Inténtalo de nuevo en unos minutos.')
@section('hint', 'Si el problema persiste, contacta al administrador del sistema.')
