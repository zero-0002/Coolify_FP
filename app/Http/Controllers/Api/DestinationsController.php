<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class DestinationsController extends Controller
{
    private function transform($d): array
    {
        return [
            'id' => $d->id,
            'uuid' => $d->uuid,
            'name' => $d->name,
            'network' => $d->network,
            'type' => $d instanceof SwarmDocker ? 'swarm' : 'standalone',
            'server_uuid' => $d->server?->uuid,
            'created_at' => $d->created_at,
            'updated_at' => $d->updated_at,
        ];
    }

    private function teamIdOrAbort(): int|\Illuminate\Http\JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return response()->json(['message' => 'You are not allowed to access the API.'], 403);
        }

        return $teamId;
    }

    public function index(Request $request)
    {
        $teamId = $this->teamIdOrAbort();
        if (! is_int($teamId)) {
            return $teamId;
        }
        $standalone = StandaloneDocker::ownedByCurrentTeamAPI($teamId)->get();
        $swarm = SwarmDocker::ownedByCurrentTeamAPI($teamId)->get();

        return response()->json($standalone->concat($swarm)->map(fn ($d) => $this->transform($d))->values());
    }

    public function index_by_server(Request $request, string $server_uuid)
    {
        $teamId = $this->teamIdOrAbort();
        if (! is_int($teamId)) {
            return $teamId;
        }
        $server = Server::ownedByCurrentTeamAPI($teamId)->whereUuid($server_uuid)->firstOrFail();
        $list = $server->standaloneDockers->concat($server->swarmDockers);

        return response()->json($list->map(fn ($d) => $this->transform($d))->values());
    }

    public function show(Request $request, string $uuid)
    {
        $teamId = $this->teamIdOrAbort();
        if (! is_int($teamId)) {
            return $teamId;
        }
        $d = StandaloneDocker::ownedByCurrentTeamAPI($teamId)->whereUuid($uuid)->first()
            ?? SwarmDocker::ownedByCurrentTeamAPI($teamId)->whereUuid($uuid)->firstOrFail();

        return response()->json($this->transform($d));
    }

    public function create(Request $request, string $server_uuid)
    {
        $teamId = $this->teamIdOrAbort();
        if (! is_int($teamId)) {
            return $teamId;
        }
        $server = Server::ownedByCurrentTeamAPI($teamId)->whereUuid($server_uuid)->firstOrFail();

        $allowed = ['name', 'network', 'type'];
        $extra = array_diff(array_keys($request->all()), $allowed);
        if (! empty($extra)) {
            return response()->json(['message' => 'Unknown fields', 'fields' => array_values($extra)], 422);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'nullable|string|max:255',
            'network' => ['required', 'string', 'max:255', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9._-]*$/'],
            'type' => 'nullable|in:standalone,swarm',
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $type = $request->input('type', 'standalone');
        $name = $request->input('name') ?: ($server->name.'-'.$request->input('network'));
        $class = $type === 'swarm' ? SwarmDocker::class : StandaloneDocker::class;

        $exists = $class::where('server_id', $server->id)->where('network', $request->input('network'))->exists();
        if ($exists) {
            return response()->json(['message' => 'A destination with this network already exists on the server.'], 409);
        }

        $d = $class::create([
            'name' => $name,
            'network' => $request->input('network'),
            'server_id' => $server->id,
        ]);

        return response()->json(['uuid' => $d->uuid], 201);
    }

    public function delete(Request $request, string $uuid)
    {
        $teamId = $this->teamIdOrAbort();
        if (! is_int($teamId)) {
            return $teamId;
        }
        $d = StandaloneDocker::ownedByCurrentTeamAPI($teamId)->whereUuid($uuid)->first()
            ?? SwarmDocker::ownedByCurrentTeamAPI($teamId)->whereUuid($uuid)->firstOrFail();
        if ($d->attachedTo()) {
            return response()->json(['message' => 'Destination has attached resources, detach first.'], 409);
        }
        $d->delete();

        return response()->json(['message' => 'Deleted.']);
    }
}
