<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use Illuminate\Http\JsonResponse;
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

    /**
     * Resolve the calling token's team id, or return a 403 response.
     */
    private function teamIdOrAbort(): int|JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return response()->json(['message' => 'You are not allowed to access the API.'], 403);
        }

        return $teamId;
    }

    /**
     * StandaloneDocker / SwarmDocker scoped to a team via their parent server.
     * Uses whereHas instead of the model's ownedByCurrentTeamAPI() scope so the
     * controller works on Coolify versions that pre-date that scope being added
     * to the destination models (e.g. 4.0.0-beta.470).
     */
    private function teamScopedDockers(int $teamId)
    {
        return [
            'standalone' => StandaloneDocker::whereHas('server', fn ($q) => $q->whereTeamId($teamId))->get(),
            'swarm' => SwarmDocker::whereHas('server', fn ($q) => $q->whereTeamId($teamId))->get(),
        ];
    }

    public function index(Request $request)
    {
        $teamId = $this->teamIdOrAbort();
        if (! is_int($teamId)) {
            return $teamId;
        }
        $sets = $this->teamScopedDockers($teamId);

        return response()->json(
            $sets['standalone']->concat($sets['swarm'])
                ->map(fn ($d) => $this->transform($d))
                ->values()
        );
    }

    public function index_by_server(Request $request, string $server_uuid)
    {
        $teamId = $this->teamIdOrAbort();
        if (! is_int($teamId)) {
            return $teamId;
        }
        $server = Server::whereTeamId($teamId)->whereUuid($server_uuid)->firstOrFail();
        $list = $server->standaloneDockers->concat($server->swarmDockers);

        return response()->json($list->map(fn ($d) => $this->transform($d))->values());
    }

    public function show(Request $request, string $uuid)
    {
        $teamId = $this->teamIdOrAbort();
        if (! is_int($teamId)) {
            return $teamId;
        }
        $d = StandaloneDocker::whereHas('server', fn ($q) => $q->whereTeamId($teamId))->whereUuid($uuid)->first()
            ?? SwarmDocker::whereHas('server', fn ($q) => $q->whereTeamId($teamId))->whereUuid($uuid)->firstOrFail();

        return response()->json($this->transform($d));
    }

    public function create(Request $request, string $server_uuid)
    {
        $teamId = $this->teamIdOrAbort();
        if (! is_int($teamId)) {
            return $teamId;
        }

        $return = validateIncomingRequest($request);
        if ($return instanceof JsonResponse) {
            return $return;
        }

        $server = Server::whereTeamId($teamId)->whereUuid($server_uuid)->firstOrFail();

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

        $expectedType = $server->isSwarm() ? 'swarm' : 'standalone';
        $type = $request->input('type', $expectedType);
        if ($type !== $expectedType) {
            return response()->json(['message' => "Destination type must be {$expectedType} for this server."], 422);
        }

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
        $d = StandaloneDocker::whereHas('server', fn ($q) => $q->whereTeamId($teamId))->whereUuid($uuid)->first()
            ?? SwarmDocker::whereHas('server', fn ($q) => $q->whereTeamId($teamId))->whereUuid($uuid)->firstOrFail();

        // Guard against deleting destinations with attached resources. attachedTo()
        // is recent on the destination models; fall back to a manual check for
        // older Coolify versions (e.g. 4.0.0-beta.470).
        if (method_exists($d, 'attachedTo')) {
            if ($d->attachedTo()) {
                return response()->json(['message' => 'Destination has attached resources, detach first.'], 409);
            }
        } else {
            $hasAttached = $d->applications()->exists()
                || $d->postgresqls()->exists()
                || (method_exists($d, 'mysqls') && $d->mysqls()->exists())
                || (method_exists($d, 'mariadbs') && $d->mariadbs()->exists())
                || (method_exists($d, 'mongodbs') && $d->mongodbs()->exists())
                || (method_exists($d, 'redis') && $d->redis()->exists())
                || (method_exists($d, 'keydbs') && $d->keydbs()->exists())
                || (method_exists($d, 'dragonflies') && $d->dragonflies()->exists())
                || (method_exists($d, 'clickhouses') && $d->clickhouses()->exists())
                || (method_exists($d, 'services') && $d->services()->exists());
            if ($hasAttached) {
                return response()->json(['message' => 'Destination has attached resources, detach first.'], 409);
            }
        }
        $d->delete();

        return response()->json(['message' => 'Deleted.']);
    }
}
