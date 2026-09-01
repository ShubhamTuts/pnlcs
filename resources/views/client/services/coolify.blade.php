@extends("client.layouts.app")
@section("title", $service->product?->name ?? 'Deploy')
@section("content")

@php
    $kind = $deployment['kind'] ?? '';
    $isDb = ($deployment['resource'] ?? '') === 'database';
    $isGit = ! $isDb && $kind !== 'wordpress' && ! in_array($kind, ['n8n','ghost','minio','umami','plausible','nocodb','grafana'], true);
@endphp

<div class="pn-page-header">
    <div>
        <h1 class="pn-page-title">{{ $service->domain ?: ($service->product?->name ?? 'Coolify') }}</h1>
        <p class="pn-page-subtitle">{{ $isDb ? __('client.coolify.db_subtitle') : __('client.coolify.app_subtitle') }}</p>
    </div>
    <a href="{{ route('client.services.show', $service) }}" class="btn btn-outline">← {{ __('client.services.back_to_services') }}</a>
</div>

<div class="pn-card mb-24">
    <div class="pn-card-header"><span class="pn-card-title">{{ __('client.coolify.deployment') }}</span></div>
    <div class="pn-card-body">
        <ul class="sv-dl" style="list-style:none;padding:0;margin:0">
            <li style="display:flex;justify-content:space-between;padding:10px 0;border-bottom:1px solid var(--border)"><span>{{ __('client.coolify.kind') }}</span><strong>{{ $kind ?: '—' }}</strong></li>
            <li style="display:flex;justify-content:space-between;padding:10px 0;border-bottom:1px solid var(--border)"><span>{{ __('client.coolify.status') }}</span><strong>{{ $deployment['status'] ?? $service->status }}</strong></li>
            <li style="display:flex;justify-content:space-between;padding:10px 0;border-bottom:1px solid var(--border)"><span>{{ __('client.coolify.url') }}</span><strong>@if($deployment['fqdn'])<a href="{{ $deployment['fqdn'] }}" target="_blank" rel="noopener">{{ $deployment['fqdn'] }}</a>@else — @endif</strong></li>
            <li style="display:flex;justify-content:space-between;padding:10px 0"><span>UUID</span><code>{{ $deployment['uuid'] ?? '—' }}</code></li>
        </ul>
        <form method="POST" action="{{ route('client.services.coolify.redeploy', $service) }}" style="margin-top:16px">
            @csrf
            <button class="btn btn-primary">{{ __('client.coolify.redeploy') }}</button>
        </form>
    </div>
</div>

@if($isDb)
<div class="pn-card mb-24">
    <div class="pn-card-header"><span class="pn-card-title">{{ __('client.coolify.connection') }}</span></div>
    <div class="pn-card-body">
        <p class="form-hint" style="margin-top:0">{{ __('client.coolify.connection_hint') }}</p>
        <ul class="sv-dl" style="list-style:none;padding:0;margin:0">
            <li style="display:flex;justify-content:space-between;padding:10px 0;border-bottom:1px solid var(--border)"><span>Host</span><code>{{ $connection['host'] ?? '—' }}</code></li>
            <li style="display:flex;justify-content:space-between;padding:10px 0;border-bottom:1px solid var(--border)"><span>Port</span><code>{{ $connection['port'] ?? '—' }}</code></li>
            <li style="display:flex;justify-content:space-between;padding:10px 0;border-bottom:1px solid var(--border)"><span>User</span><code>{{ $connection['username'] ?? '—' }}</code></li>
            <li style="display:flex;justify-content:space-between;padding:10px 0"><span>Database</span><code>{{ $connection['database'] ?? '—' }}</code></li>
        </ul>
    </div>
</div>
@endif

@if(! $isDb)
<div class="pn-card mb-24">
    <div class="pn-card-header"><span class="pn-card-title">{{ __('client.coolify.ssl') }}</span></div>
    <div class="pn-card-body">
        <p class="form-hint" style="margin-top:0">{{ __('client.coolify.ssl_hint') }}</p>
        <form method="POST" action="{{ route('client.services.coolify.domain', $service) }}">
            @csrf
            <div class="form-group">
                <label class="form-label" for="domain">{{ __('client.coolify.hostname') }}</label>
                <input id="domain" type="text" name="domain" required class="form-control" value="{{ $service->domain }}" placeholder="app.example.com">
            </div>
            <button class="btn btn-primary">{{ __('client.coolify.attach_ssl') }}</button>
        </form>
    </div>
</div>
@endif

@if($isGit)
<div class="pn-card mb-24">
    <div class="pn-card-header"><span class="pn-card-title">{{ __('client.coolify.git') }}</span></div>
    <div class="pn-card-body">
        <form method="POST" action="{{ route('client.services.coolify.git', $service) }}">
            @csrf
            <div class="form-group">
                <label class="form-label" for="git_repository">HTTPS repository</label>
                <input id="git_repository" type="url" name="git_repository" required class="form-control" value="{{ $deployment['git_repository'] }}" placeholder="https://github.com/you/app">
            </div>
            <div class="form-group">
                <label class="form-label" for="git_branch">Branch</label>
                <input id="git_branch" type="text" name="git_branch" class="form-control" value="{{ $deployment['git_branch'] ?? 'main' }}">
            </div>
            <button class="btn btn-primary">{{ __('client.coolify.save_deploy') }}</button>
        </form>
    </div>
</div>

<div class="pn-card">
    <div class="pn-card-header"><span class="pn-card-title">{{ __('client.coolify.env') }}</span></div>
    <div class="pn-card-body">
        <form method="POST" action="{{ route('client.services.coolify.env', $service) }}" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
            @csrf
            <div class="form-group" style="flex:1;min-width:160px;margin:0">
                <label class="form-label" for="env_key">KEY</label>
                <input id="env_key" type="text" name="key" required class="form-control" placeholder="DATABASE_URL" pattern="[A-Z][A-Z0-9_]*">
            </div>
            <div class="form-group" style="flex:2;min-width:200px;margin:0">
                <label class="form-label" for="env_value">value</label>
                <input id="env_value" type="text" name="value" class="form-control">
            </div>
            <button class="btn btn-outline">{{ __('client.coolify.set_env') }}</button>
        </form>
    </div>
</div>
@endif
@endsection
