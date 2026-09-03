@extends('layouts.guest')
@section('title', 'Confirme sua senha')
@section('content')
<section class="guest-card"><div class="stat-icon tone-violet mb-3"><i class="bi bi-shield-lock"></i></div><h1>Confirme sua senha</h1><p>Esta é uma área protegida. Confirme sua senha para continuar.</p><form method="POST" action="{{ route('password.confirm') }}">@csrf<div class="mb-4"><label class="form-label">Senha</label><input class="form-control" name="password" type="password" required autofocus></div><button class="btn btn-primary w-100 py-2">Confirmar</button></form></section>
@endsection
