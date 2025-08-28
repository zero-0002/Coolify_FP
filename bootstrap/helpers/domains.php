<?php

use App\Models\Application;
use App\Models\ServiceApplication;

function checkDomainUsage(ServiceApplication|Application|null $resource = null, ?string $domain = null)
{
    if ($resource) {
        if ($resource->getMorphClass() === Application::class && $resource->build_pack === 'dockercompose') {
            $domains = data_get(json_decode($resource->docker_compose_domains, true), '*.domain');
            $domains = collect($domains);
        } else {
            $domains = collect($resource->fqdns);
        }
    } elseif ($domain) {
        $domains = collect([$domain]);
    } else {
        throw new \RuntimeException('No resource or FQDN provided.');
    }
    $domains = $domains->map(function ($domain) {
        if (str($domain)->endsWith('/')) {
            $domain = str($domain)->beforeLast('/');
        }

        return str($domain);
    });
    $apps = Application::all();
    foreach ($apps as $app) {
        $list_of_domains = collect(explode(',', $app->fqdn))->filter(fn ($fqdn) => $fqdn !== '');
        foreach ($list_of_domains as $domain) {
            if (str($domain)->endsWith('/')) {
                $domain = str($domain)->beforeLast('/');
            }
            $naked_domain = str($domain)->value();
            if ($domains->contains($naked_domain)) {
                if (data_get($resource, 'uuid')) {
                    if ($resource->uuid !== $app->uuid) {
                        throw new \RuntimeException("Domain $naked_domain is already in use by another resource: <br><br>Link: <a class='underline' target='_blank' href='{$app->link()}'>{$app->name}</a>");
                    }
                } elseif ($domain) {
                    throw new \RuntimeException("Domain $naked_domain is already in use by another resource: <br><br>Link: <a class='underline' target='_blank' href='{$app->link()}'>{$app->name}</a>");
                }
            }
        }
    }
    $apps = ServiceApplication::all();
    foreach ($apps as $app) {
        $list_of_domains = collect(explode(',', $app->fqdn))->filter(fn ($fqdn) => $fqdn !== '');
        foreach ($list_of_domains as $domain) {
            if (str($domain)->endsWith('/')) {
                $domain = str($domain)->beforeLast('/');
            }
            $naked_domain = str($domain)->value();
            if ($domains->contains($naked_domain)) {
                if (data_get($resource, 'uuid')) {
                    if ($resource->uuid !== $app->uuid) {
                        throw new \RuntimeException("Domain $naked_domain is already in use by another resource: <br><br>Link: <a class='underline' target='_blank' href='{$app->service->link()}'>{$app->service->name}</a>");
                    }
                } elseif ($domain) {
                    throw new \RuntimeException("Domain $naked_domain is already in use by another resource: <br><br>Link: <a class='underline' target='_blank' href='{$app->service->link()}'>{$app->service->name}</a>");
                }
            }
        }
    }
    if ($resource) {
        $settings = instanceSettings();
        if (data_get($settings, 'fqdn')) {
            $domain = data_get($settings, 'fqdn');
            if (str($domain)->endsWith('/')) {
                $domain = str($domain)->beforeLast('/');
            }
            $naked_domain = str($domain)->value();
            if ($domains->contains($naked_domain)) {
                throw new \RuntimeException("Domain $naked_domain is already in use by this Coolify instance.");
            }
        }
    }
}
