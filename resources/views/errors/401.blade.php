@extends('errors.layout')

@section('code', '401')
@section('title', 'No autorizado')
@section('message', 'Necesitas iniciar sesión para acceder a este recurso.')
@section('actionUrl', url('/login'))
@section('action', 'Iniciar sesión')
