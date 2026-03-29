<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Tag;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::updateOrCreate(['id' => 0]);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    session(['currentTeam' => $this->team]);

    $this->token = $this->user->createToken('test-token', ['*']);
    $this->bearerToken = $this->token->plainTextToken;

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->first();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);

    $this->application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);
});

function tagApiAuthHeaders($bearerToken): array
{
    return [
        'Authorization' => 'Bearer '.$bearerToken,
        'Content-Type' => 'application/json',
    ];
}

describe('GET /api/v1/tags', function () {
    test('returns all tags for current team', function () {
        Tag::create(['name' => 'production', 'team_id' => $this->team->id]);
        Tag::create(['name' => 'staging', 'team_id' => $this->team->id]);

        $response = $this->withHeaders(tagApiAuthHeaders($this->bearerToken))
            ->getJson('/api/v1/tags');

        $response->assertStatus(200);
        $response->assertJsonCount(2);
        $response->assertJsonFragment(['name' => 'production']);
        $response->assertJsonFragment(['name' => 'staging']);
    });

    test('returns empty array when no tags exist', function () {
        $response = $this->withHeaders(tagApiAuthHeaders($this->bearerToken))
            ->getJson('/api/v1/tags');

        $response->assertStatus(200);
        $response->assertJsonCount(0);
    });

    test('does not return tags from other teams', function () {
        $otherTeam = Team::factory()->create();
        Tag::create(['name' => 'other-team-tag', 'team_id' => $otherTeam->id]);
        Tag::create(['name' => 'my-tag', 'team_id' => $this->team->id]);

        $response = $this->withHeaders(tagApiAuthHeaders($this->bearerToken))
            ->getJson('/api/v1/tags');

        $response->assertStatus(200);
        $response->assertJsonCount(1);
        $response->assertJsonFragment(['name' => 'my-tag']);
        $response->assertJsonMissing(['name' => 'other-team-tag']);
    });
});

describe('GET /api/v1/applications/{uuid}/tags', function () {
    test('returns tags for an application', function () {
        $tag = Tag::create(['name' => 'production', 'team_id' => $this->team->id]);
        $this->application->tags()->attach($tag->id);

        $response = $this->withHeaders(tagApiAuthHeaders($this->bearerToken))
            ->getJson("/api/v1/applications/{$this->application->uuid}/tags");

        $response->assertStatus(200);
        $response->assertJsonCount(1);
        $response->assertJsonFragment(['name' => 'production']);
    });

    test('returns 404 for non-existent application', function () {
        $response = $this->withHeaders(tagApiAuthHeaders($this->bearerToken))
            ->getJson('/api/v1/applications/non-existent-uuid/tags');

        $response->assertStatus(404);
    });

    test('returns empty array when application has no tags', function () {
        $response = $this->withHeaders(tagApiAuthHeaders($this->bearerToken))
            ->getJson("/api/v1/applications/{$this->application->uuid}/tags");

        $response->assertStatus(200);
        $response->assertJsonCount(0);
    });
});

describe('POST /api/v1/applications/{uuid}/tags', function () {
    test('adds a single tag via tag_name', function () {
        $response = $this->withHeaders(tagApiAuthHeaders($this->bearerToken))
            ->postJson("/api/v1/applications/{$this->application->uuid}/tags", [
                'tag_name' => 'production',
            ]);

        $response->assertStatus(201);
        $response->assertJsonCount(1);
        $response->assertJsonFragment(['name' => 'production']);

        expect($this->application->tags()->count())->toBe(1);
    });

    test('adds multiple tags via tag_names array', function () {
        $response = $this->withHeaders(tagApiAuthHeaders($this->bearerToken))
            ->postJson("/api/v1/applications/{$this->application->uuid}/tags", [
                'tag_names' => ['production', 'frontend'],
            ]);

        $response->assertStatus(201);
        $response->assertJsonCount(2);

        expect($this->application->tags()->count())->toBe(2);
    });

    test('reuses existing team tag instead of creating duplicate', function () {
        Tag::create(['name' => 'production', 'team_id' => $this->team->id]);

        $response = $this->withHeaders(tagApiAuthHeaders($this->bearerToken))
            ->postJson("/api/v1/applications/{$this->application->uuid}/tags", [
                'tag_name' => 'production',
            ]);

        $response->assertStatus(201);
        expect(Tag::where('team_id', $this->team->id)->where('name', 'production')->count())->toBe(1);
    });

    test('rejects tag_name shorter than 2 characters', function () {
        $response = $this->withHeaders(tagApiAuthHeaders($this->bearerToken))
            ->postJson("/api/v1/applications/{$this->application->uuid}/tags", [
                'tag_name' => 'x',
            ]);

        $response->assertStatus(422);
    });

    test('rejects both tag_name and tag_names provided simultaneously', function () {
        $response = $this->withHeaders(tagApiAuthHeaders($this->bearerToken))
            ->postJson("/api/v1/applications/{$this->application->uuid}/tags", [
                'tag_name' => 'production',
                'tag_names' => ['staging'],
            ]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['tag_name' => ['Provide either tag_name or tag_names, not both.']]);
    });

    test('skips duplicate tag already on resource', function () {
        $tag = Tag::create(['name' => 'production', 'team_id' => $this->team->id]);
        $this->application->tags()->attach($tag->id);

        $response = $this->withHeaders(tagApiAuthHeaders($this->bearerToken))
            ->postJson("/api/v1/applications/{$this->application->uuid}/tags", [
                'tag_name' => 'production',
            ]);

        $response->assertStatus(201);
        expect($this->application->tags()->count())->toBe(1);
    });

    test('returns 404 for non-existent application', function () {
        $response = $this->withHeaders(tagApiAuthHeaders($this->bearerToken))
            ->postJson('/api/v1/applications/non-existent-uuid/tags', [
                'tag_name' => 'production',
            ]);

        $response->assertStatus(404);
    });
});

describe('DELETE /api/v1/applications/{uuid}/tags/{tag_uuid}', function () {
    test('removes tag from application', function () {
        $tag = Tag::create(['name' => 'production', 'team_id' => $this->team->id]);
        $this->application->tags()->attach($tag->id);

        $response = $this->withHeaders(tagApiAuthHeaders($this->bearerToken))
            ->deleteJson("/api/v1/applications/{$this->application->uuid}/tags/{$tag->uuid}");

        $response->assertStatus(200);
        expect($this->application->tags()->count())->toBe(0);
    });

    test('garbage-collects orphaned tag', function () {
        $tag = Tag::create(['name' => 'production', 'team_id' => $this->team->id]);
        $this->application->tags()->attach($tag->id);

        $this->withHeaders(tagApiAuthHeaders($this->bearerToken))
            ->deleteJson("/api/v1/applications/{$this->application->uuid}/tags/{$tag->uuid}");

        expect(Tag::find($tag->id))->toBeNull();
    });

    test('keeps tag if still used by other resources', function () {
        $tag = Tag::create(['name' => 'production', 'team_id' => $this->team->id]);
        $this->application->tags()->attach($tag->id);

        $otherApp = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
        ]);
        $otherApp->tags()->attach($tag->id);

        $this->withHeaders(tagApiAuthHeaders($this->bearerToken))
            ->deleteJson("/api/v1/applications/{$this->application->uuid}/tags/{$tag->uuid}");

        expect(Tag::find($tag->id))->not->toBeNull();
    });

    test('returns 404 for non-existent tag', function () {
        $response = $this->withHeaders(tagApiAuthHeaders($this->bearerToken))
            ->deleteJson("/api/v1/applications/{$this->application->uuid}/tags/non-existent-uuid");

        $response->assertStatus(404);
    });
});

describe('GET /api/v1/databases/{uuid}/tags', function () {
    test('returns tags for a database', function () {
        $database = StandalonePostgresql::create([
            'name' => 'test-pg',
            'postgres_password' => 'testpassword',
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
        ]);

        $tag = Tag::create(['name' => 'database-tag', 'team_id' => $this->team->id]);
        $database->tags()->attach($tag->id);

        $response = $this->withHeaders(tagApiAuthHeaders($this->bearerToken))
            ->getJson("/api/v1/databases/{$database->uuid}/tags");

        $response->assertStatus(200);
        $response->assertJsonCount(1);
        $response->assertJsonFragment(['name' => 'database-tag']);
    });
});

describe('POST /api/v1/databases/{uuid}/tags', function () {
    test('adds tag to database', function () {
        $database = StandalonePostgresql::create([
            'name' => 'test-pg',
            'postgres_password' => 'testpassword',
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
        ]);

        $response = $this->withHeaders(tagApiAuthHeaders($this->bearerToken))
            ->postJson("/api/v1/databases/{$database->uuid}/tags", [
                'tag_name' => 'database-tag',
            ]);

        $response->assertStatus(201);
        expect($database->tags()->count())->toBe(1);
    });
});

describe('DELETE /api/v1/databases/{uuid}/tags/{tag_uuid}', function () {
    test('removes tag from database', function () {
        $database = StandalonePostgresql::create([
            'name' => 'test-pg',
            'postgres_password' => 'testpassword',
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
        ]);

        $tag = Tag::create(['name' => 'database-tag', 'team_id' => $this->team->id]);
        $database->tags()->attach($tag->id);

        $response = $this->withHeaders(tagApiAuthHeaders($this->bearerToken))
            ->deleteJson("/api/v1/databases/{$database->uuid}/tags/{$tag->uuid}");

        $response->assertStatus(200);
        expect($database->tags()->count())->toBe(0);
    });
});

describe('GET /api/v1/services/{uuid}/tags', function () {
    test('returns tags for a service', function () {
        $service = Service::factory()->create([
            'server_id' => $this->server->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
            'environment_id' => $this->environment->id,
        ]);

        $tag = Tag::create(['name' => 'service-tag', 'team_id' => $this->team->id]);
        $service->tags()->attach($tag->id);

        $response = $this->withHeaders(tagApiAuthHeaders($this->bearerToken))
            ->getJson("/api/v1/services/{$service->uuid}/tags");

        $response->assertStatus(200);
        $response->assertJsonCount(1);
        $response->assertJsonFragment(['name' => 'service-tag']);
    });
});

describe('POST /api/v1/services/{uuid}/tags', function () {
    test('adds tag to service', function () {
        $service = Service::factory()->create([
            'server_id' => $this->server->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
            'environment_id' => $this->environment->id,
        ]);

        $response = $this->withHeaders(tagApiAuthHeaders($this->bearerToken))
            ->postJson("/api/v1/services/{$service->uuid}/tags", [
                'tag_name' => 'service-tag',
            ]);

        $response->assertStatus(201);
        expect($service->tags()->count())->toBe(1);
    });
});

describe('DELETE /api/v1/services/{uuid}/tags/{tag_uuid}', function () {
    test('removes tag from service', function () {
        $service = Service::factory()->create([
            'server_id' => $this->server->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
            'environment_id' => $this->environment->id,
        ]);

        $tag = Tag::create(['name' => 'service-tag', 'team_id' => $this->team->id]);
        $service->tags()->attach($tag->id);

        $response = $this->withHeaders(tagApiAuthHeaders($this->bearerToken))
            ->deleteJson("/api/v1/services/{$service->uuid}/tags/{$tag->uuid}");

        $response->assertStatus(200);
        expect($service->tags()->count())->toBe(0);
    });
});

describe('Tag name sanitization', function () {
    test('strips HTML tags from tag names', function () {
        $response = $this->withHeaders(tagApiAuthHeaders($this->bearerToken))
            ->postJson("/api/v1/applications/{$this->application->uuid}/tags", [
                'tag_name' => '<script>alert("xss")</script>production',
            ]);

        $response->assertStatus(201);
        $response->assertJsonFragment(['name' => 'alert("xss")production']);
        $response->assertJsonMissing(['name' => '<script>']);
    });

    test('lowercases tag names', function () {
        $response = $this->withHeaders(tagApiAuthHeaders($this->bearerToken))
            ->postJson("/api/v1/applications/{$this->application->uuid}/tags", [
                'tag_name' => 'Production',
            ]);

        $response->assertStatus(201);
        $response->assertJsonFragment(['name' => 'production']);
    });
});

describe('Cross-resource tag isolation', function () {
    test('cannot delete tag via wrong resource', function () {
        $tag = Tag::create(['name' => 'shared-tag', 'team_id' => $this->team->id]);

        $otherApp = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
        ]);
        $otherApp->tags()->attach($tag->id);

        // Tag is not attached to $this->application, but we try to delete via it
        $this->withHeaders(tagApiAuthHeaders($this->bearerToken))
            ->deleteJson("/api/v1/applications/{$this->application->uuid}/tags/{$tag->uuid}");

        // Tag should still exist on the other app (detach on non-attached is a no-op)
        expect($otherApp->tags()->count())->toBe(1);
        // Tag should NOT be garbage-collected since otherApp still uses it
        expect(Tag::find($tag->id))->not->toBeNull();
    });
});
