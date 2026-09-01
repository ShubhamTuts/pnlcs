@extends("client.layouts.app")
@section("title", $service->product?->name ?? 'Deploy')
@section("content")

<div class="pn-page-header">
    <div>
        <h1 class="pn-page-title">{{ $service->domain ?: ($service->product?->name ?? 'Coolify') }}</h1>
        <p class="pn-page-subtitle">Git source, one-click apps, and redeploys on your Webkahost PaaS plan.</p>
    </div>
    <a href="{{ route('client.services.show', $service) }}" class="btn btn-outline">← {{ __('client.services.back_to_services') }}</a>
</div>

<div class="pn-card mb-24">
    <div class="pn-card-header"><span class="pn-card-title">Deployment</span></div>
    <div class="pn-card-body">
        <ul class="sv-dl" style="list-style:none;padding:0;margin:0">
            <li style="display:flex;justify-content:space-between;padding:10px 0;border-bottom:1px solid var(--border)"><span>Kind</span><strong>{{ $deployment['kind'] ?? '—' }}</strong></li>
            <li style="display:flex;justify-content:space-between;padding:10px 0;border-bottom:1px solid var(--border)"><span>Status</span><strong>{{ $deployment['status'] ?? $service->status }}</strong></li>
            <li style="display:flex;justify-content:space-between;padding:10px 0;border-bottom:1px solid var(--border)"><span>URL</span><strong>@if($deployment['fqdn'])<a href="{{ $deployment['fqdn'] }}" target="_blank" rel="noopener">{{ $deployment['fqdn'] }}</a>@else — @endif</strong></li>
            <li style="display:flex;justify-content:space-between;padding:10px 0"><span>UUID</span><code>{{ $deployment['uuid'] ?? '—' }}</code></li>
        </ul>
        <form method="POST" action="{{ route('client.services.coolify.redeploy', $service) }}" style="margin-top:16px">
            @csrf
            <button class="btn btn-primary">Redeploy</button>
        </form>
    </div>
</div>

@if(($deployment['kind'] ?? '') !== 'wordpress')
<div class="pn-card">
    <div class="pn-card-header"><span class="pn-card-title">Git repository</span></div>
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
            <button class="btn btn-primary">Save and deploy</button>
        </form>
    </div>
</div>
@endif
@endsection
