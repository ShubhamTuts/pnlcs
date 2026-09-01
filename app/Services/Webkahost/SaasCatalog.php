<?php

namespace App\Services\Webkahost;

use App\Models\Currency;
use App\Models\Pricing;
use App\Models\Product;
use App\Models\ProductGroup;

/**
 * The SKUs Webkahost sells on day one: apps, managed databases, one-click
 * tools. Prices are starting points the operator edits in Products.
 */
class SaasCatalog
{
    /**
     * @return array{groups: int, products: int}
     */
    public function seed(bool $force = false): array
    {
        $currency = Currency::getDefault()
            ?? Currency::firstOrCreate(
                ['code' => 'USD'],
                ['prefix' => '$', 'suffix' => '', 'rate' => 1, 'is_default' => true]
            );

        $groups = 0;
        $products = 0;

        foreach ($this->blueprint() as $groupSpec) {
            $group = ProductGroup::firstOrCreate(
                ['slug' => $groupSpec['slug']],
                [
                    'name' => $groupSpec['name'],
                    'headline' => $groupSpec['headline'],
                    'tagline' => $groupSpec['tagline'],
                    'order_form_template' => 'standard_cart',
                    'hidden' => false,
                    'sort_order' => $groupSpec['sort'],
                ]
            );
            $groups++;

            foreach ($groupSpec['products'] as $i => $spec) {
                $product = Product::withTrashed()->where('slug', $spec['slug'])->first();
                if ($product && ! $force) {
                    continue;
                }

                if ($product && $product->trashed()) {
                    $product->restore();
                }

                $payload = [
                    'type' => 'hostingaccount',
                    'group_id' => $group->id,
                    'name' => $spec['name'],
                    'slug' => $spec['slug'],
                    'description' => $spec['description'],
                    'hidden' => false,
                    'show_domain_options' => ! in_array($spec['package'], ['postgresql', 'mysql', 'mariadb', 'mongodb', 'redis', 'keydb', 'dragonfly', 'clickhouse'], true),
                    'is_featured' => (bool) ($spec['featured'] ?? false),
                    'retired' => false,
                    'pay_type' => 'recurring',
                    'auto_setup' => 'payment',
                    'server_type' => 'coolify',
                    'stock_control' => false,
                    'tax' => true,
                    'sort_order' => ($i + 1) * 10,
                    'config_options' => [
                        'package_name' => $spec['package'],
                        'coolify_kind' => $spec['package'],
                    ],
                ];

                if ($product) {
                    $product->update($payload);
                } else {
                    $product = Product::create($payload);
                }

                Pricing::updateOrCreate(
                    ['type' => 'product', 'rel_id' => $product->id, 'currency_id' => $currency->id],
                    [
                        'monthly' => $spec['monthly'],
                        'annually' => round($spec['monthly'] * 10, 2),
                        'monthly_setup' => 0,
                        'annually_setup' => 0,
                        'quarterly' => -1,
                        'semiannually' => -1,
                        'biennially' => -1,
                        'triennially' => -1,
                        'quarterly_setup' => 0,
                        'semiannually_setup' => 0,
                        'biennially_setup' => 0,
                        'triennially_setup' => 0,
                    ]
                );
                $products++;
            }
        }

        return ['groups' => $groups, 'products' => $products];
    }

    public function blueprint(): array
    {
        return [
            [
                'slug' => 'webkahost-apps',
                'name' => 'Apps',
                'headline' => 'Git push to a live HTTPS URL',
                'tagline' => 'WordPress, Node.js, Next.js, static',
                'sort' => 10,
                'products' => [
                    ['slug' => 'webkahost-wordpress', 'name' => 'Managed WordPress', 'package' => 'wordpress', 'monthly' => 9.00, 'featured' => true, 'description' => 'One-click WordPress with MySQL, Traefik and Let\'s Encrypt.'],
                    ['slug' => 'webkahost-nodejs', 'name' => 'Node.js App', 'package' => 'nodejs', 'monthly' => 12.00, 'description' => 'Public Git repo built with Nixpacks. Redeploy from the portal or the Agent.'],
                    ['slug' => 'webkahost-nextjs', 'name' => 'Next.js App', 'package' => 'nextjs', 'monthly' => 14.00, 'description' => 'Next.js on Coolify with automatic TLS.'],
                    ['slug' => 'webkahost-static', 'name' => 'Static Site', 'package' => 'static', 'monthly' => 5.00, 'description' => 'Static export from Git. Good for marketing sites.'],
                ],
            ],
            [
                'slug' => 'webkahost-databases',
                'name' => 'Databases',
                'headline' => 'Managed databases on your Coolify pool',
                'tagline' => 'PostgreSQL, MySQL, Redis, MongoDB',
                'sort' => 20,
                'products' => [
                    ['slug' => 'webkahost-postgresql', 'name' => 'PostgreSQL', 'package' => 'postgresql', 'monthly' => 8.00, 'featured' => true, 'description' => 'Private PostgreSQL. Connection details in the portal after payment.'],
                    ['slug' => 'webkahost-mysql', 'name' => 'MySQL', 'package' => 'mysql', 'monthly' => 8.00, 'description' => 'Managed MySQL for WordPress-adjacent workloads.'],
                    ['slug' => 'webkahost-mariadb', 'name' => 'MariaDB', 'package' => 'mariadb', 'monthly' => 8.00, 'description' => 'MySQL-compatible MariaDB.'],
                    ['slug' => 'webkahost-mongodb', 'name' => 'MongoDB', 'package' => 'mongodb', 'monthly' => 10.00, 'description' => 'Document store with automated containers.'],
                    ['slug' => 'webkahost-redis', 'name' => 'Redis', 'package' => 'redis', 'monthly' => 6.00, 'description' => 'In-memory cache and queue.'],
                    ['slug' => 'webkahost-clickhouse', 'name' => 'ClickHouse', 'package' => 'clickhouse', 'monthly' => 16.00, 'description' => 'Column store for analytics.'],
                ],
            ],
            [
                'slug' => 'webkahost-oneclick',
                'name' => 'One-click',
                'headline' => 'Tools that ship with TLS',
                'tagline' => 'n8n, Ghost, MinIO, Umami',
                'sort' => 30,
                'products' => [
                    ['slug' => 'webkahost-n8n', 'name' => 'n8n', 'package' => 'n8n', 'monthly' => 11.00, 'description' => 'Self-hosted automation.'],
                    ['slug' => 'webkahost-ghost', 'name' => 'Ghost', 'package' => 'ghost', 'monthly' => 10.00, 'description' => 'Publishing platform with Let\'s Encrypt.'],
                    ['slug' => 'webkahost-minio', 'name' => 'MinIO', 'package' => 'minio', 'monthly' => 9.00, 'description' => 'S3-compatible object storage.'],
                    ['slug' => 'webkahost-umami', 'name' => 'Umami', 'package' => 'umami', 'monthly' => 7.00, 'description' => 'Privacy-friendly analytics.'],
                    ['slug' => 'webkahost-plausible', 'name' => 'Plausible', 'package' => 'plausible', 'monthly' => 9.00, 'description' => 'Lightweight analytics with TLS.'],
                    ['slug' => 'webkahost-nocodb', 'name' => 'NocoDB', 'package' => 'nocodb', 'monthly' => 9.00, 'description' => 'Airtable-style UI on your database.'],
                    ['slug' => 'webkahost-grafana', 'name' => 'Grafana', 'package' => 'grafana', 'monthly' => 8.00, 'description' => 'Dashboards on your own VPS pool.'],
                ],
            ],
        ];
    }
}
