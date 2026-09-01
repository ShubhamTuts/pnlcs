<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ $textDirection ?? 'ltr' }}" data-theme="{{ request()->cookie('pnlcs_theme') === 'dark' ? 'dark' : 'light' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $brandName ?? 'Oneploy' }}.dev — Next-level cloud hosting</title>
    <meta name="description" content="Oneploy.dev — Git deploys, WordPress, domains, billing and an AI agent on Coolify. Client portal at client.oneploy.dev, billing at billing.oneploy.dev.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/remixicon@4.1.0/fonts/remixicon.css" rel="stylesheet">
    @if(!empty($customFavicon))
    <link rel="icon" href="{{ $customFavicon }}" type="image/png">
    @endif
    @if(!empty($themeCssVars))
    <style id="theme-vars">{!! $themeCssVars !!}</style>
    @endif
    @include('sections.styles')
    @if(!empty($activeThemeAssets))
    <link rel="stylesheet" href="{{ $activeThemeAssets }}/css/theme.css">
    @endif
</head>
<body class="op-page" x-data="{ mobileMenu: false, yearly: false }">
@php
    $brand = $brandName ?? 'Oneploy';
    $clientHome = oneploy_url('client', '/client/home');
    $clientLogin = oneploy_url('client', '/client/login');
    $clientRegister = oneploy_url('client', '/client/register');
    $clientStore = oneploy_url('client', '/client/store');
    $domainSearch = oneploy_url('client', '/client/domain-search');
    $billingHome = oneploy_url('billing', '/client/invoices');
    $agentUrl = oneploy_url('client', '/client/ai/agent');
@endphp

    <div class="op-topbar">
        <div class="op-topbar__inner">
            <span>client.oneploy.dev · billing.oneploy.dev · Coolify one-click deploy</span>
            <a href="{{ oneploy_url('client', '/client/contact') }}">Get in touch</a>
        </div>
    </div>

    <nav class="op-nav">
        <div class="op-nav__inner">
            <a class="op-logo" href="{{ oneploy_url('marketing', '/') }}">{{ $brand }}<span>.dev</span></a>
            <div class="op-nav__links">
                <a href="#hosting">Hosting</a>
                <a href="#domains">Domains</a>
                <a href="#pricing">Pricing</a>
                <a href="{{ $agentUrl }}">AI Agent</a>
            </div>
            <div class="op-nav__actions">
                <a class="op-link" href="{{ $clientLogin }}">Log In</a>
                <a class="op-btn" href="{{ $clientRegister }}">Get Started</a>
                <button class="op-hamburger" type="button" @click="mobileMenu = !mobileMenu" aria-label="Menu"><i class="ri-menu-line"></i></button>
            </div>
        </div>
        <div class="op-container" x-show="mobileMenu" x-cloak style="display:none;padding-bottom:16px">
            <a class="op-link" href="#hosting">Hosting</a>
            <a class="op-link" href="#domains">Domains</a>
            <a class="op-link" href="#pricing">Pricing</a>
            <a class="op-link" href="{{ $clientLogin }}">Log In</a>
        </div>
    </nav>

    <section class="op-hero">
        <div class="op-container op-hero__grid">
            <div>
                <div class="op-kicker">Git · WordPress · Domains · Coolify</div>
                <h1>Crush Limits with Next-Level <em>Domain Hosting</em>.</h1>
                <p class="op-lead">Ship on Coolify with one-click deploys, buy domains and credits in the client portal, and let the Oneploy Agent run your panel. Fast, green, billed like a host.</p>
                <form id="domains" class="op-search" action="{{ $domainSearch }}" method="GET">
                    <input type="text" name="domain" placeholder="Find your perfect domain…" required>
                    <button class="op-btn op-btn--dark" type="submit">Search Domain</button>
                </form>
                <div class="op-tlds">
                    <span><strong>.com</strong> from $9.99/yr</span>
                    <span><strong>.dev</strong> from $12.99/yr</span>
                    <span><strong>.io</strong> from $29.99/yr</span>
                </div>
            </div>
            <div class="op-panel">
                <div class="op-panel__badge"><i class="ri-checkbox-circle-fill"></i> 99% Uptime</div>
                <h3>Dominate the Web with Powerful Hosting</h3>
                <p class="op-lead" style="margin-bottom:0">Automatic Coolify deploys from GitHub. WordPress, Node.js, Postgres and n8n in one click. Pay on billing.oneploy.dev.</p>
                <div class="op-graph"></div>
            </div>
        </div>
    </section>

    <section id="hosting" class="op-section op-section--muted">
        <div class="op-container">
            <div class="op-section__head">
                <h2>Tailored hosting solutions for every stage</h2>
                <p>Mapped to Coolify packages you buy in the client portal — not a brochure grid that goes nowhere.</p>
            </div>
            <div class="op-cards">
                <article class="op-card">
                    <div class="op-icon op-icon--violet"><i class="ri-cloud-line"></i></div>
                    <h3>Cloud Hosting</h3>
                    <p>Public Git HTTPS to a live URL. Nixpacks, Dockerfile or Compose on Coolify.</p>
                    <a href="{{ $clientStore }}">Buy Now →</a>
                </article>
                <article class="op-card">
                    <div class="op-icon op-icon--blue"><i class="ri-wordpress-line"></i></div>
                    <h3>WordPress Hosting</h3>
                    <p>One-click WordPress with MySQL, Traefik and Let's Encrypt TLS.</p>
                    <a href="{{ $clientStore }}">Buy Now →</a>
                </article>
                <article class="op-card">
                    <div class="op-icon op-icon--sky"><i class="ri-database-2-line"></i></div>
                    <h3>Managed Databases</h3>
                    <p>PostgreSQL, MySQL, Redis, MongoDB and ClickHouse on your Coolify pool.</p>
                    <a href="{{ $clientStore }}">Buy Now →</a>
                </article>
                <article class="op-card">
                    <div class="op-icon op-icon--pink"><i class="ri-apps-2-line"></i></div>
                    <h3>One-click Apps</h3>
                    <p>n8n, Ghost, MinIO, Umami, Grafana — deploy from the portal or the Agent.</p>
                    <a href="{{ $clientStore }}">Buy Now →</a>
                </article>
            </div>
        </div>
    </section>

    <section id="pricing" class="op-section">
        <div class="op-container">
            <div class="op-section__head">
                <h2>Web Hosting Plans Built for Speed &amp; Scale</h2>
                <p>Monthly or yearly (20% off). Checkout lives on client.oneploy.dev; invoices on billing.oneploy.dev.</p>
            </div>
            <div class="op-toggle">
                <span>Monthly</span>
                <button type="button" class="op-switch" :class="yearly && 'on'" @click="yearly = !yearly" aria-label="Toggle yearly billing"><i></i></button>
                <span>Yearly</span>
                <span class="op-save">Save 20%</span>
            </div>
            <div class="op-plans">
                <article class="op-plan">
                    <h3>Silver Plan</h3>
                    <div class="op-price">$<span x-text="yearly ? '3.60' : '4.50'"></span><span>/mo</span></div>
                    <ul>
                        <li>1 Git or static site</li>
                        <li>Automatic TLS</li>
                        <li>Client portal access</li>
                        <li>Community support</li>
                    </ul>
                    <a class="op-btn op-btn--ghost" href="{{ $clientRegister }}">Get Started</a>
                </article>
                <article class="op-plan op-plan--featured">
                    <div class="op-plan__tag">Best Value</div>
                    <h3>Business Plus</h3>
                    <div class="op-price" style="color:#fff">$<span x-text="yearly ? '7.60' : '9.50'"></span><span style="color:#dcfce7">/mo</span></div>
                    <ul>
                        <li>Managed WordPress</li>
                        <li>20 GB SSD equivalent</li>
                        <li>100 email-ready DNS</li>
                        <li>Oneploy Agent jobs</li>
                    </ul>
                    <a class="op-btn" href="{{ $clientRegister }}">Get Started</a>
                </article>
                <article class="op-plan">
                    <h3>Premium Plan</h3>
                    <div class="op-price">$<span x-text="yearly ? '16.40' : '20.50'"></span><span>/mo</span></div>
                    <ul>
                        <li>Node.js / Next.js Git</li>
                        <li>Managed database</li>
                        <li>AI credit packs</li>
                        <li>Priority support</li>
                    </ul>
                    <a class="op-btn op-btn--ghost" href="{{ $clientRegister }}">Get Started</a>
                </article>
            </div>
        </div>
    </section>

    <section class="op-section op-section--muted">
        <div class="op-container op-why">
            <div>
                <h2>Why you should choose Oneploy.dev</h2>
                <p class="op-lead">Global Coolify destinations, Let's Encrypt on every app, and a billed Agent that actually deploys — not a chatbot that shrugs.</p>
                <div class="op-stats">
                    <div class="op-stat"><b>10.5M+</b><span>Requests we are built to take</span></div>
                    <div class="op-stat"><b>99%</b><span>Uptime guarantee</span></div>
                    <div class="op-stat"><b>200ms</b><span>Average origin speed</span></div>
                </div>
            </div>
            <div class="op-panel">
                <div class="op-panel__badge"><i class="ri-robot-2-line"></i> Oneploy Agent</div>
                <h3>AI assisted bot, Coolify parsed</h3>
                <p class="op-lead">“Deploy WordPress on blog.example.com”, “redeploy”, “set DATABASE_URL=…”. The agent is fenced to your services and talks to Coolify over the billed API.</p>
                <a class="op-btn" href="{{ $agentUrl }}">Open the Agent</a>
            </div>
        </div>
    </section>

    <section class="op-section op-section--dark">
        <div class="op-container">
            <div class="op-section__head">
                <h2>Seamless website migration with ease</h2>
                <p>Backup, transfer onto Coolify, then launch with TLS. Join from the client portal.</p>
            </div>
            <div class="op-steps">
                <div class="op-step"><b>01 — Backup &amp; Prepare</b>Snapshot files and the database before you move.</div>
                <div class="op-step"><b>02 — Transfer &amp; Configure</b>Git URL or WordPress one-click. Attach the domain.</div>
                <div class="op-step"><b>03 — Test &amp; Launch</b>Redeploy from the portal or tell the Agent to ship it.</div>
            </div>
            <div style="text-align:center;margin-top:32px">
                <a class="op-btn" href="{{ $clientRegister }}">Join Now</a>
            </div>
        </div>
    </section>

    <section class="op-section">
        <div class="op-container">
            <div class="op-section__head">
                <h2>Questions, answered</h2>
            </div>
            <div class="op-faq">
                <details open>
                    <summary>What is domain hosting on Oneploy?</summary>
                    <p>Search and buy a domain in the client portal, then attach it to a Coolify app. Traefik requests Let's Encrypt for you.</p>
                </details>
                <details>
                    <summary>How can I transfer my domain?</summary>
                    <p>Use Domain Transfer in client.oneploy.dev. After the registrar handshake, point the hostname at your Coolify service.</p>
                </details>
                <details>
                    <summary>Where do I pay?</summary>
                    <p>Invoices, AI credit packs and saved cards live on billing.oneploy.dev (Razorpay, Stripe, PayPal).</p>
                </details>
                <details>
                    <summary>What does one-click deploy use?</summary>
                    <p>Coolify — the same panel as https://github.com/ShubhamTuts/coolify. Oneploy bills; Coolify builds.</p>
                </details>
                <details>
                    <summary>What can the AI Agent do?</summary>
                    <p>Deploy WordPress, Git apps, databases and one-click tools; attach TLS; set env vars; redeploy; check AI credits. It never sees another customer.</p>
                </details>
            </div>
        </div>
    </section>

    <section class="op-section" style="padding-top:0">
        <div class="op-container">
            <div class="op-cta-bar">
                <div>
                    <h3 style="margin:0 0 6px">Get up to 75% off your first year</h3>
                    <p style="margin:0;color:#94a3b8">Open the client portal, pick a plan, pay on billing.oneploy.dev.</p>
                </div>
                <a class="op-btn" href="{{ $clientRegister }}">Get started</a>
            </div>
        </div>
    </section>

    <footer class="op-footer">
        <div class="op-container">
            <div class="op-footer__grid">
                <div>
                    <a class="op-logo" href="{{ oneploy_url('marketing', '/') }}" style="color:#fff">{{ $brand }}<span>.dev</span></a>
                    <p>Oneploy.dev is the customer-facing host. Coolify deploys it. The Agent runs it.</p>
                </div>
                <div>
                    <h4>Hosting</h4>
                    <a href="{{ $clientStore }}">Cloud / Git</a>
                    <a href="{{ $clientStore }}">WordPress</a>
                    <a href="{{ $clientStore }}">Databases</a>
                    <a href="{{ $clientStore }}">One-click</a>
                </div>
                <div>
                    <h4>Domains</h4>
                    <a href="{{ $domainSearch }}">Search Domain</a>
                    <a href="{{ oneploy_url('client', '/client/domains') }}">My domains</a>
                    <a href="{{ $clientHome }}">Client portal</a>
                </div>
                <div>
                    <h4>Company</h4>
                    <a href="{{ $billingHome }}">Billing portal</a>
                    <a href="{{ $agentUrl }}">AI Agent</a>
                    <a href="{{ oneploy_url('client', '/client/contact') }}">Support</a>
                </div>
            </div>
            <div class="op-copy">© {{ date('Y') }} {{ $brand }}.dev. All rights reserved.</div>
        </div>
    </footer>

    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</body>
</html>
