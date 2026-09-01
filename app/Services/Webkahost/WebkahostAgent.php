<?php

namespace App\Services\Webkahost;

use App\Models\AiAgentMessage;
use App\Models\Client;
use App\Models\Service;
use App\Services\Module\ModuleRegistry;
use Modules\Servers\Coolify\CoolifyModule;

/**
 * Hostinger-style jobs, not just a chatbot.
 *
 * The agent has a small, fenced tool list: list this customer's services,
 * deploy WordPress / Node.js on their Coolify plan, inspect Git, and report
 * AI credit usage. It never sees another customer's rows.
 *
 * When no upstream LLM is configured it still does the job: it maps the
 * customer's sentence onto a tool and runs it. That is the path tests and a
 * fresh install use.
 */
class WebkahostAgent
{
    public function __construct(
        private AiCreditService $credits,
    ) {}

    /**
     * @return array{reply: string, tools: list<array{name: string, ok: bool, result: mixed}>}
     */
    public function chat(Client $client, string $message): array
    {
        $message = trim($message);
        if ($message === '') {
            return ['reply' => 'Tell me what to do — deploy WordPress, ship a Node.js Git repo, or check your AI credits.', 'tools' => []];
        }

        AiAgentMessage::create([
            'client_id' => $client->id,
            'role' => 'user',
            'content' => $message,
        ]);

        $plan = $this->plan($message);
        $tools = [];
        $parts = [];

        foreach ($plan as $call) {
            $result = $this->runTool($client, $call['name'], $call['arguments']);
            $tools[] = ['name' => $call['name'], 'ok' => (bool) ($result['ok'] ?? false), 'result' => $result];
            $parts[] = (string) ($result['summary'] ?? json_encode($result));
        }

        $reply = $parts !== []
            ? implode("\n\n", $parts)
            : $this->helpText();

        $this->credits->charge($client, 'webkahost-agent', max(8, (int) ceil(strlen($message) / 4)), max(8, (int) ceil(strlen($reply) / 4)), [
            'source' => 'agent',
            'status' => 'ok',
        ]);

        AiAgentMessage::create([
            'client_id' => $client->id,
            'role' => 'assistant',
            'content' => $reply,
            'tool_calls' => $tools,
        ]);

        return ['reply' => $reply, 'tools' => $tools];
    }

    /**
     * @return list<array{name: string, arguments: array<string, mixed>}>
     */
    public function plan(string $message): array
    {
        $text = strtolower($message);

        if (preg_match('/\b(credit|usage|balance|wallet|token)\b/', $text)) {
            return [['name' => 'get_ai_usage', 'arguments' => []]];
        }

        if (preg_match('/\b(list|show|my)\b.+\b(app|service|site|deploy)/', $text) || preg_match('/\b(what.*(running|hosted|live))\b/', $text)) {
            return [['name' => 'list_services', 'arguments' => []]];
        }

        if (preg_match('/wordpress|wp-admin|\bwp\b/', $text)) {
            $domain = $this->extractDomain($message);

            return [['name' => 'deploy_wordpress', 'arguments' => array_filter(['domain' => $domain])]];
        }

        if (preg_match('/node(\.?js)?|next\.?js|git|repository|repo/', $text)) {
            $repo = $this->extractGitUrl($message);
            $domain = $this->extractDomain($message);

            return [['name' => 'deploy_git_app', 'arguments' => array_filter([
                'git_repository' => $repo,
                'domain' => $domain,
            ])]];
        }

        if (preg_match('/\b(help|what can you)\b/', $text)) {
            return [];
        }

        return [['name' => 'list_services', 'arguments' => []]];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function runTool(Client $client, string $name, array $arguments): array
    {
        return match ($name) {
            'list_services' => $this->listServices($client),
            'deploy_wordpress' => $this->deployOnCoolify($client, 'wordpress', $arguments),
            'deploy_git_app' => $this->deployOnCoolify($client, 'git', $arguments),
            'get_ai_usage' => $this->aiUsage($client),
            default => ['ok' => false, 'summary' => "Unknown tool {$name}."],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function listServices(Client $client): array
    {
        $services = Service::where('client_id', $client->id)
            ->whereIn('status', ['active', 'pending', 'suspended'])
            ->with('product')
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(function (Service $service) {
                $data = is_array($service->module_data) ? $service->module_data : [];

                return [
                    'id' => $service->id,
                    'name' => $service->product?->name,
                    'domain' => $service->domain,
                    'status' => $service->status,
                    'kind' => $data['coolify_kind'] ?? $service->product?->server_type,
                    'url' => $data['coolify_fqdn'] ?? null,
                    'git' => $data['coolify_git_repository'] ?? null,
                ];
            })
            ->all();

        if ($services === []) {
            return ['ok' => true, 'summary' => 'You have no active apps yet. Ask me to deploy WordPress or a Node.js Git repository.', 'services' => []];
        }

        $lines = array_map(function (array $row) {
            $label = $row['domain'] ?: ($row['name'] ?: 'Service #'.$row['id']);

            return "- {$label} ({$row['status']}".($row['kind'] ? ", {$row['kind']}" : '').')';
        }, $services);

        return [
            'ok' => true,
            'summary' => "Your apps:\n".implode("\n", $lines),
            'services' => $services,
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function deployOnCoolify(Client $client, string $kind, array $arguments): array
    {
        $service = $this->coolifyService($client);
        if (! $service) {
            return [
                'ok' => false,
                'summary' => 'No Coolify (Webkahost PaaS) plan is attached to this account. Order a WordPress or Node.js plan first, then ask me again.',
            ];
        }

        $module = app(ModuleRegistry::class)->getServerModule('coolify');
        if (! $module instanceof CoolifyModule) {
            return ['ok' => false, 'summary' => 'Coolify is not registered on this installation.'];
        }

        if ($kind === 'git') {
            $repo = trim((string) ($arguments['git_repository'] ?? ''));
            if ($repo === '') {
                return ['ok' => false, 'summary' => 'Send a public HTTPS Git URL (GitHub, GitLab, Bitbucket or Gitea) and I will deploy it.'];
            }
            if (! $module->isPublicGitUrl($repo)) {
                return ['ok' => false, 'summary' => 'That Git URL is not a public HTTPS repository I can clone.'];
            }

            if (($this->resourceUuid($service)) !== '') {
                $result = $module->updateGitSource($service, $repo, 'main');
            } else {
                $data = is_array($service->module_data) ? $service->module_data : [];
                $data['coolify_git_repository'] = $repo;
                $data['coolify_kind'] = 'nodejs';
                $service->forceFill(['module_data' => $data])->save();
                if (! empty($arguments['domain'])) {
                    $service->update(['domain' => $arguments['domain']]);
                }
                $result = $module->create($service->fresh());
            }
        } else {
            if (! empty($arguments['domain'])) {
                $service->update(['domain' => $arguments['domain']]);
            }
            $data = is_array($service->module_data) ? $service->module_data : [];
            if (($data['coolify_uuid'] ?? '') !== '') {
                $result = $module->redeploy($service);
            } else {
                $result = $module->create($service->fresh());
            }
        }

        $ok = (bool) ($result['success'] ?? false);
        $summary = $ok
            ? (($result['message'] ?? 'Deployed.').($service->fresh()->domain ? ' Site: '.$service->fresh()->domain : ''))
            : (string) ($result['message'] ?? 'Deploy failed.');

        return ['ok' => $ok, 'summary' => $summary, 'result' => $result];
    }

    /**
     * @return array<string, mixed>
     */
    private function aiUsage(Client $client): array
    {
        $balance = $this->credits->balance($client);

        return [
            'ok' => true,
            'balance' => $balance,
            'summary' => 'AI credit balance: '.number_format($balance, 2).' credits. Buy more under AI Credits in the portal.',
        ];
    }

    private function coolifyService(Client $client): ?Service
    {
        return Service::where('client_id', $client->id)
            ->whereIn('status', ['active', 'pending'])
            ->whereHas('product', fn ($q) => $q->where('server_type', 'coolify'))
            ->orderByDesc('id')
            ->first();
    }

    private function resourceUuid(Service $service): string
    {
        $data = is_array($service->module_data) ? $service->module_data : [];

        return (string) ($data['coolify_uuid'] ?? '');
    }

    private function extractGitUrl(string $message): ?string
    {
        if (preg_match('#https://[a-zA-Z0-9._-]+/[a-zA-Z0-9._/-]+(?:\.git)?#', $message, $m)) {
            return rtrim($m[0], '.');
        }

        return null;
    }

    private function extractDomain(string $message): ?string
    {
        if (preg_match('/\b([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z]{2,})+)\b/i', $message, $m)) {
            $host = strtolower($m[1]);
            if (in_array($host, ['github.com', 'gitlab.com', 'bitbucket.org', 'gitea.com'], true)) {
                return null;
            }

            return $host;
        }

        return null;
    }

    private function helpText(): string
    {
        return "I am the Webkahost Agent. I can:\n"
            ."- Deploy WordPress on your PaaS plan (\"deploy wordpress on blog.example.com\")\n"
            ."- Deploy a Node.js / Next.js app from Git (\"deploy https://github.com/me/app\")\n"
            ."- List your running apps\n"
            ."- Check AI credit balance and usage\n\n"
            .'AI Gateway keys live under AI Credits — drop the base URL into any OpenAI-compatible SDK.';
    }
}
