@extends('errors.layout')

@php
    $status = isset($exception) && $exception instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface
        ? $exception->getStatusCode()
        : 400;
@endphp

@section('code', (string) $status)
@section('title', 'Solicitud no válida')
@section('message', 'La solicitud no pudo completarse. Revisa los datos e inténtalo de nuevo.')
