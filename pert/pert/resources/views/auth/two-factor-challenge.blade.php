@extends('layouts.guest')
@section('title', 'Verificação em duas etapas')
@section('content')
<section class="guest-card text-center"><div class="stat-icon tone-violet mx-auto mb-3"><i class="bi bi-shield-lock"></i></div><h1>Verificação em duas etapas</h1><p>Digite o código de seis dígitos do seu aplicativo autenticador.</p>
    <form method="POST" action="{{ route('two-factor.login') }}">@csrf<input class="form-control form-control-lg text-center mb-3" name="code" inputmode="numeric" maxlength="6" placeholder="000 000" autofocus><button class="btn btn-primary w-100 py-2">Verificar código</button></form><button class="btn btn-link btn-sm mt-3" type="button" data-demo-action>Usar um código de recuperação</button>
</section>
@endsection
