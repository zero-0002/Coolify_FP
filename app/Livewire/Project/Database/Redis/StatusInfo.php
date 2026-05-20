<?php

namespace App\Livewire\Project\Database\Redis;

use App\Helpers\SslHelper;
use App\Models\StandaloneRedis;
use Carbon\Carbon;
use Exception;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class StatusInfo extends Component
{
    use AuthorizesRequests;

    public StandaloneRedis $database;

    public bool $enableSsl = false;

    public ?Carbon $certificateValidUntil = null;

    public ?string $dbUrl = null;

    public ?string $dbUrlPublic = null;

    public function getListeners()
    {
        $userId = Auth::id();
        $teamId = Auth::user()->currentTeam()->id;

        return [
            "echo-private:user.{$userId},DatabaseStatusChanged" => 'refresh',
            "echo-private:team.{$teamId},ServiceChecked" => 'refresh',
            'databaseUpdated' => 'refresh',
        ];
    }

    public function mount(): void
    {
        $this->refresh();
    }

    public function refresh(): void
    {
        $this->database->refresh();
        $this->enableSsl = (bool) $this->database->enable_ssl;
        $this->dbUrl = $this->database->internal_db_url;
        $this->dbUrlPublic = $this->database->external_db_url;
        $this->certificateValidUntil = $this->database->sslCertificates()->first()?->valid_until;
    }

    public function instantSaveSSL(): void
    {
        try {
            $this->authorize('update', $this->database);
            $this->database->enable_ssl = $this->enableSsl;
            $this->database->save();
            $this->dispatch('success', 'SSL configuration updated.');
        } catch (Exception $e) {
            handleError($e, $this);
        }
    }

    public function regenerateSslCertificate(): void
    {
        try {
            $this->authorize('update', $this->database);

            $existingCert = $this->database->sslCertificates()->first();

            if (! $existingCert) {
                $this->dispatch('error', 'No existing SSL certificate found for this database.');

                return;
            }

            $server = $this->database->destination->server;
            $caCert = $server->sslCertificates()->where('is_ca_certificate', true)->first();

            if (! $caCert) {
                $server->generateCaCertificate();
                $caCert = $server->sslCertificates()->where('is_ca_certificate', true)->first();
            }

            if (! $caCert) {
                $this->dispatch('error', 'No CA certificate found for this database. Please generate a CA certificate for this server in the server/advanced page.');

                return;
            }

            SslHelper::generateSslCertificate(
                commonName: $existingCert->common_name,
                subjectAlternativeNames: $existingCert->subject_alternative_names ?? [],
                resourceType: $existingCert->resource_type,
                resourceId: $existingCert->resource_id,
                serverId: $existingCert->server_id,
                caCert: $caCert->ssl_certificate,
                caKey: $caCert->ssl_private_key,
                configurationDir: $existingCert->configuration_dir,
                mountPath: $existingCert->mount_path,
                isPemKeyFileRequired: true,
            );

            $this->refresh();
            $this->dispatch('success', 'SSL certificates regenerated. Restart database to apply changes.');
        } catch (Exception $e) {
            handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.project.database.redis.status-info');
    }
}
