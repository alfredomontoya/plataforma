@extends('errors.layout')

@section('code', '419')
@section('title', 'Sesión expirada')
@section('message', 'Tu sesión expiró por inactividad. Vuelve a iniciar sesión para continuar.')
@section('actionUrl', url('/login'))
@section('action', 'Iniciar sesión')
