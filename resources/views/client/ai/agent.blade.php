@extends("client.layouts.app")
@section("title", __("client.ai.agent"))
@section("content")

<div class="pn-page-header">
    <div>
        <h1 class="pn-page-title">{{ __('client.ai.agent') }}</h1>
        <p class="pn-page-subtitle">{{ __('client.ai.agent_subtitle') }}</p>
    </div>
    <div style="text-align:right">
        <div style="font-size:12px;color:var(--muted);text-transform:uppercase;font-weight:700">{{ __('client.ai.balance') }}</div>
        <div style="font-size:22px;font-weight:800">{{ number_format($balance, 2) }}</div>
        <a href="{{ route('client.ai.index') }}" class="link text-sm">{{ __('client.ai.buy') }} →</a>
    </div>
</div>

<div class="pn-card" style="margin-bottom:16px">
    <div class="pn-card-body" style="display:flex;flex-direction:column;gap:14px;max-height:520px;overflow:auto">
        @forelse($history as $msg)
            <div style="align-self:{{ $msg->role === 'user' ? 'flex-end' : 'flex-start' }};max-width:80%;padding:12px 14px;border-radius:14px;background:{{ $msg->role === 'user' ? 'var(--primary)' : 'var(--bg)' }};color:{{ $msg->role === 'user' ? '#fff' : 'var(--text)' }};white-space:pre-wrap;font-size:14px;line-height:1.5">{{ $msg->content }}</div>
        @empty
            <p class="text-muted" style="margin:0">{{ __('client.ai.agent_subtitle') }}</p>
        @endforelse
        @if(session('agent_reply') && $history->isEmpty())
            <div style="align-self:flex-start;max-width:80%;padding:12px 14px;border-radius:14px;background:var(--bg);white-space:pre-wrap">{{ session('agent_reply') }}</div>
        @endif
    </div>
</div>

<form method="POST" action="{{ route('client.ai.agent.message') }}" class="pn-card">
    @csrf
    <div class="pn-card-body" style="display:flex;gap:10px;align-items:flex-end">
        <div class="form-group" style="flex:1;margin:0">
            <label class="form-label" for="agent-message">{{ __('client.ai.agent') }}</label>
            <textarea id="agent-message" name="message" rows="3" required class="form-control" placeholder="{{ __('client.ai.agent_placeholder') }}"></textarea>
        </div>
        <button type="submit" class="btn btn-primary">{{ __('client.ai.send') }}</button>
    </div>
</form>
@endsection
