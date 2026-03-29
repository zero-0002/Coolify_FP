<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Http\Controllers\Api\TagsController;
use App\Models\Tag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

trait HandlesTagsApi
{
    /**
     * Find the taggable resource by UUID within the team.
     */
    abstract protected function findTaggableResource(string $uuid, int|string $teamId): mixed;

    /**
     * Get the 404 message for the taggable resource.
     */
    abstract protected function tagResourceNotFoundMessage(): string;

    public function listTags(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $resource = $this->findTaggableResource($request->route('uuid'), $teamId);
        if (! $resource) {
            return response()->json(['message' => $this->tagResourceNotFoundMessage()], 404);
        }

        $this->authorize('view', $resource);

        return response()->json($resource->tags->map(TagsController::serializeTag(...)));
    }

    public function createTag(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $return = validateIncomingRequest($request);
        if ($return instanceof \Illuminate\Http\JsonResponse) {
            return $return;
        }

        $resource = $this->findTaggableResource($request->route('uuid'), $teamId);
        if (! $resource) {
            return response()->json(['message' => $this->tagResourceNotFoundMessage()], 404);
        }

        $this->authorize('update', $resource);

        if ($request->has('tag_name') && $request->has('tag_names')) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => ['tag_name' => ['Provide either tag_name or tag_names, not both.']],
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'tag_name' => 'required_without:tag_names|string|min:2',
            'tag_names' => 'required_without:tag_name|array|min:1',
            'tag_names.*' => 'string|min:2',
        ]);

        $extraFields = array_diff(array_keys($request->all()), ['tag_name', 'tag_names']);
        if ($validator->fails() || ! empty($extraFields)) {
            $errors = $validator->errors();
            if (! empty($extraFields)) {
                foreach ($extraFields as $field) {
                    $errors->add($field, 'This field is not allowed.');
                }
            }

            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $errors,
            ], 422);
        }

        $tagNames = $request->has('tag_names') ? $request->tag_names : [$request->tag_name];

        $this->attachTagsToResource($resource, $tagNames, $teamId);

        return response()->json($resource->refresh()->tags->map(TagsController::serializeTag(...)))->setStatusCode(201);
    }

    public function deleteTag(Request $request): JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $resource = $this->findTaggableResource($request->route('uuid'), $teamId);
        if (! $resource) {
            return response()->json(['message' => $this->tagResourceNotFoundMessage()], 404);
        }

        $this->authorize('update', $resource);

        $tag = Tag::where('team_id', $teamId)->where('uuid', $request->route('tag_uuid'))->first();
        if (! $tag) {
            return response()->json(['message' => 'Tag not found.'], 404);
        }

        $resource->tags()->detach($tag->id);

        if (DB::table('taggables')->where('tag_id', $tag->id)->count() === 0) {
            $tag->delete();
        }

        return response()->json(['message' => 'Tag removed.']);
    }

    protected function attachTagsToResource($resource, array $tagNames, int|string $teamId): void
    {
        foreach ($tagNames as $tagName) {
            $tagName = strtolower(strip_tags($tagName));
            if (strlen($tagName) < 2) {
                continue;
            }

            $tag = Tag::where('team_id', $teamId)->where('name', $tagName)->first();
            if (! $tag) {
                $tag = Tag::create([
                    'name' => $tagName,
                    'team_id' => $teamId,
                ]);
            }

            $resource->tags()->syncWithoutDetaching([$tag->id]);
        }
    }
}
