<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Services\ThemeManager;
use Illuminate\Console\Command;

class WebkahostBrandCommand extends Command
{
    protected $signature = 'webkahost:brand {--company=Webkahost} {--theme=webkahost}';

    protected $description = 'Apply Webkahost white-label branding to this PNLCS install';

    public function handle(ThemeManager $themes): int
    {
        $company = (string) $this->option('company');

        Setting::set('whitelabel_company_name', $company, 'whitelabel');
        Setting::set('whitelabel_remove_branding', '1', 'whitelabel');
        Setting::set('whitelabel_copyright', '© '.date('Y').' '.$company, 'whitelabel');
        Setting::set('CompanyName', $company, 'general');

        $slug = (string) $this->option('theme');
        if ($themes->activate($slug)) {
            $this->info("Active theme is {$slug}.");
        } else {
            $this->warn("Theme {$slug} was not found; white-label name was still applied.");
        }

        app()->forgetInstance('pnlcs.company_name');

        $this->info("Customer-facing brand is now {$company}.");

        return self::SUCCESS;
    }
}
