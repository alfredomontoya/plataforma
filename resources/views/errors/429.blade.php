@extends('errors.layout')

@section('code', '429')
@section('title', 'Demasiadas solicitudes')
@section('message', 'Has realizado demasiadas solicitudes en poco tiempo. Espera unos instantes y vuelve a intentarlo.')
@section('hint', 'Si el problema persiste, contacta al administrador del sistema.')
@section('actionUrl', url()->current())
@section('action', 'Reintentar')
