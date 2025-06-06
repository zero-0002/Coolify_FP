<?php

namespace App\Livewire\Server;

use App\Actions\Proxy\CheckConfiguration;
use App\Actions\Proxy\CheckProxy;
use App\Actions\Proxy\StartProxy;
use App\Actions\Proxy\StopProxy;
use App\Jobs\RestartProxyJob;
use App\Models\Server;
use Livewire\Component;

class Navbar extends Component
{
    public Server $server;

    public bool $isChecking = false;

    public ?string $currentRoute = null;

    public bool $traefikDashboardAvailable = false;

    public ?string $serverIp = null;

    public function getListeners()
    {
        $teamId = auth()->user()->currentTeam()->id;

        return [
            "echo-private:team.{$teamId},ProxyStatusChangedUI" => 'showNotification',
        ];
    }

    public function mount(Server $server)
    {
        $this->server = $server;
        $this->currentRoute = request()->route()->getName();
        $this->serverIp = $this->server->id === 0 ? base_ip() : $this->server->ip;
    }

    public function loadProxyConfiguration()
    {
        try {
            $proxy_settings = CheckConfiguration::run($this->server);
            if (str($proxy_settings)->contains('--api.dashboard=true') && str($proxy_settings)->contains('--api.insecure=true')) {
                $this->traefikDashboardAvailable = true;
            } else {
                $this->traefikDashboardAvailable = false;
            }
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function restart()
    {
        try {
            RestartProxyJob::dispatch($this->server);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function checkProxy()
    {
        try {
            CheckProxy::run($this->server, true);
            $this->dispatch('startProxy')->self();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function startProxy()
    {
        try {
            $activity = StartProxy::run($this->server, force: true);
            $this->dispatch('activityMonitor', $activity->id);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function stop(bool $forceStop = true)
    {
        try {
            StopProxy::dispatch($this->server, $forceStop);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function checkProxyStatus()
    {
        if ($this->isChecking) {
            return;
        }

        try {
            $this->isChecking = true;
            CheckProxy::run($this->server, true);
            $this->showNotification();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        } finally {
            $this->isChecking = false;
        }
    }

    public function showNotification()
    {
        $status = $this->server->proxy->status ?? 'unknown';
        $forceStop = $this->server->proxy->force_stop ?? false;

        switch ($status) {
            case 'running':
                $this->dispatch('success', 'Proxy is running.');
                break;
            case 'restarting':
                $this->dispatch('info', 'Initiating proxy restart.');
                break;
            case 'exited':
                if ($forceStop) {
                    $this->dispatch('info', 'Proxy is stopped manually.');
                } else {
                    $this->dispatch('info', 'Proxy is stopped manually. Starting in a moment.');
                }
                break;
            default:
                $this->dispatch('warning', 'Proxy is not running.');
                break;
        }
    }

    public function render()
    {
        return view('livewire.server.navbar');
    }
}
