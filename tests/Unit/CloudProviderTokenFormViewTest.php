<?php

it('allows selecting Vultr in cloud provider token forms', function () {
    $view = file_get_contents(__DIR__.'/../../resources/views/livewire/security/cloud-provider-token-form.blade.php');
    $component = file_get_contents(__DIR__.'/../../app/Livewire/Security/CloudProviderTokenForm.php');

    expect($view)->toContain('<x-forms.select required id="provider" label="Provider" wire:model.live="provider">')
        ->and($view)->toContain('<option value="vultr">Vultr</option>')
        ->and($view)->toContain('<option disabled value="digitalocean">DigitalOcean</option>')
        ->and(substr_count($view, 'Open Account → API Access.'))->toBe(2)
        ->and(substr_count($view, 'https://console.vultr.com/user/apiaccess/'))->toBe(2)
        ->and($view)->not->toContain('<option value="digitalocean">DigitalOcean</option>')
        ->and($view)->not->toContain('cloudProviderTokens->where(\'provider\', $provider)->isEmpty()')
        ->and($view)->not->toContain('<x-forms.select required id="provider" label="Provider" disabled>')
        ->and($component)->toContain("'provider' => 'required|string|in:hetzner,digitalocean,vultr'");
});
