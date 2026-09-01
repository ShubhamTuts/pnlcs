@extends("client.layouts.app")
@section("title", __("client.ai.title"))
@section("content")

<div class="pn-page-header">
    <div>
        <h1 class="pn-page-title">{{ __('client.ai.title') }}</h1>
        <p class="pn-page-subtitle">{{ __('client.ai.subtitle') }}</p>
    </div>
    <a href="{{ route('client.ai.agent') }}" class="btn btn-primary">{{ __('client.ai.agent') }} →</a>
</div>

<div class="pn-card mb-24" style="max-width:100%;background:linear-gradient(135deg,#0f766e,#042f2e);border:none">
    <div class="pn-card-body" style="text-align:center;padding:28px">
        <div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.7px;color:rgba(255,255,255,0.65);margin-bottom:8px">{{ __('client.ai.balance') }}</div>
        <div style="font-size:42px;font-weight:900;color:#fff;letter-spacing:-1px">{{ number_format($balance, 2) }}</div>
        <div style="font-size:13px;color:rgba(255,255,255,0.55);margin-top:6px">{{ __('client.ai.credits_unit') }}</div>
        @if($byok?->enabled)
        <div style="margin-top:10px;font-size:13px;color:#99f6e4">{{ __('client.ai.byok_active') }}</div>
        @endif
    </div>
</div>

<div class="pn-card mb-24">
    <div class="pn-card-header"><span class="pn-card-title">{{ __('client.ai.byok') }}</span></div>
    <div class="pn-card-body">
        <p class="form-hint" style="margin-top:0">{{ __('client.ai.byok_hint') }}</p>
        @if($byok)
            <p>{{ __('client.ai.byok_saved_as', ['provider' => $byok->provider, 'last4' => $byok->lastFour()]) }}
                @if($byok->enabled)<strong> — {{ __('client.ai.byok_unlimited') }}</strong>@endif
            </p>
            @if($byok->enabled)
            <form method="POST" action="{{ route('client.ai.byok.disable') }}" style="margin-bottom:18px">
                @csrf
                <button class="btn btn-outline">{{ __('client.ai.byok_disable') }}</button>
            </form>
            @endif
        @endif
        <form method="POST" action="{{ route('client.ai.byok') }}">
            @csrf
            <div class="form-group">
                <label class="form-label" for="provider">{{ __('client.ai.byok_provider') }}</label>
                <select id="provider" name="provider" class="form-control" required>
                    @foreach($byokProviders as $id => $url)
                        <option value="{{ $id }}" @selected(($byok->provider ?? 'openai') === $id)>{{ strtoupper($id) }}@if($url) — {{ $url }}@endif</option>
                    @endforeach
                </select>
            </div>
            <div class="form-group">
                <label class="form-label" for="api_key">{{ __('client.ai.byok_key') }}</label>
                <input id="api_key" type="password" name="api_key" required class="form-control" autocomplete="off" placeholder="sk-…">
            </div>
            <div class="form-group">
                <label class="form-label" for="base_url">{{ __('client.ai.byok_url') }}</label>
                <input id="base_url" type="url" name="base_url" class="form-control" value="{{ $byok->base_url ?? '' }}" placeholder="https://api.openai.com/v1">
            </div>
            <button type="submit" class="btn btn-primary">{{ __('client.ai.byok_save') }}</button>
        </form>
    </div>
</div>

<div class="pn-card mb-24">
    <div class="pn-card-header"><span class="pn-card-title">{{ __('client.ai.buy') }}</span></div>
    <div class="pn-card-body">
        <form method="POST" action="{{ route('client.ai.purchase') }}">
            @csrf
            <div class="pn-amount-grid" style="margin-bottom:16px">
                @foreach($packs as $pack)
                <label class="pn-amount-btn" style="cursor:pointer;{{ $pack->featured ? 'border-color:var(--primary)' : '' }}">
                    <input type="radio" name="pack" value="{{ $pack->slug }}" {{ $loop->first ? 'checked' : '' }} style="margin-right:8px">
                    <strong>{{ $pack->name }}</strong><br>
                    <span style="font-size:12px;color:var(--muted)">{{ number_format($pack->credits) }} {{ __('client.ai.credits_unit') }} · {{ money_fmt($pack->price) }}</span>
                </label>
                @endforeach
            </div>
            <div class="form-group">
                <label class="form-label" for="payment_method">{{ __('client.ai.select_payment') }}</label>
                <select id="payment_method" name="payment_method" required class="form-control">
                    <option value="">-- {{ __('client.funds.select_payment_method') }} --</option>
                    @forelse($gateways as $gateway)
                        <option value="{{ $gateway }}">{{ payment_method_label((string) $gateway) }}</option>
                    @empty
                        <option value="" disabled>{{ __('client.invoices.no_payment_methods') }}</option>
                    @endforelse
                </select>
            </div>
            @if(in_array('razorpay', $gateways->all(), true))
            <div class="form-group">
                <label class="form-label" style="display:flex;gap:8px;align-items:center">
                    <input type="checkbox" name="subscribe" value="1">
                    {{ __('client.ai.subscribe_razorpay') }}
                </label>
                <p class="form-hint">{{ __('client.ai.subscribe_razorpay_hint') }}</p>
            </div>
            @endif
            <button type="submit" class="btn btn-primary">{{ __('client.ai.buy') }}</button>
        </form>
    </div>
</div>

<div class="pn-card mb-24">
    <div class="pn-card-header"><span class="pn-card-title">{{ __('client.ai.keys') }}</span></div>
    <div class="pn-card-body">
        <p class="form-hint" style="margin-top:0">{{ __('client.ai.gateway_base') }}: <code>{{ url('/api/ai/v1') }}</code></p>
        @if(session('ai_plaintext_key'))
        <div class="pn-alert pn-alert-success">
            {{ __('client.ai.plaintext_warning') }}
            <div style="margin-top:8px;font-family:ui-monospace,monospace;word-break:break-all">{{ session('ai_plaintext_key') }}</div>
        </div>
        @endif
        <form method="POST" action="{{ route('client.ai.keys.store') }}" style="display:flex;gap:10px;align-items:flex-end;margin-bottom:18px">
            @csrf
            <div class="form-group" style="flex:1;margin:0">
                <label class="form-label" for="key_name">{{ __('client.ai.key_name') }}</label>
                <input id="key_name" type="text" name="name" required class="form-control" value="Production" maxlength="120">
            </div>
            <button type="submit" class="btn btn-outline">{{ __('client.ai.create_key') }}</button>
        </form>
        @if($keys->isEmpty())
            <p class="text-muted">{{ __('client.ai.no_keys') }}</p>
        @else
        <table class="pn-table">
            <thead><tr><th>{{ __('client.ai.key_name') }}</th><th>{{ __('client.ai.prefix') }}</th><th>{{ __('client.ai.last_used') }}</th><th></th></tr></thead>
            <tbody>
            @foreach($keys as $key)
                <tr>
                    <td>{{ $key->name }} @if($key->revoked_at)<span class="badge">{{ __('client.ai.revoked') }}</span>@endif</td>
                    <td><code>{{ $key->prefix }}…</code></td>
                    <td>{{ $key->last_used_at?->diffForHumans() ?? __('client.ai.never') }}</td>
                    <td style="text-align:right">
                        @if(! $key->revoked_at)
                        <form method="POST" action="{{ route('client.ai.keys.revoke', $key) }}" onsubmit="return confirm('Revoke this key?')">
                            @csrf
                            <button class="btn btn-danger btn-sm">{{ __('client.ai.revoke') }}</button>
                        </form>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
        @endif
    </div>
</div>

<div class="pn-card mb-24">
    <div class="pn-card-header"><span class="pn-card-title">{{ __('client.ai.models') }}</span></div>
    <div class="pn-card-body">
        <table class="pn-table">
            <thead><tr><th>Model</th><th>Provider</th><th>{{ __('client.ai.input') }} / 1k</th><th>{{ __('client.ai.output') }} / 1k</th></tr></thead>
            <tbody>
            @foreach($models as $id => $rates)
                <tr>
                    <td><code>{{ $id }}</code></td>
                    <td>{{ $rates['provider'] }}</td>
                    <td>{{ $rates['input'] }}</td>
                    <td>{{ $rates['output'] }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>

<div class="pn-card">
    <div class="pn-card-header"><span class="pn-card-title">{{ __('client.ai.usage') }}</span></div>
    <div class="pn-card-body">
        @if($usage->isEmpty())
            <p class="text-muted">{{ __('client.ai.no_usage') }}</p>
        @else
        <table class="pn-table">
            <thead><tr><th>When</th><th>Model</th><th>Tokens</th><th>{{ __('client.ai.credits_unit') }}</th></tr></thead>
            <tbody>
            @foreach($usage as $row)
                <tr>
                    <td>{{ $row->created_at?->diffForHumans() }}</td>
                    <td>{{ $row->model }}</td>
                    <td>{{ $row->input_tokens }} / {{ $row->output_tokens }}</td>
                    <td>{{ number_format((float) $row->credits_charged, 4) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
        @endif
    </div>
</div>
@endsection
