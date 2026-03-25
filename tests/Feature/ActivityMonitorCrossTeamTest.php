<?php

use App\Livewire\ActivityMonitor;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    $this->otherTeam = Team::factory()->create();
});

test('hydrateActivity blocks access to another teams activity', function () {
    $otherActivity = Activity::create([
        'log_name' => 'default',
        'description' => 'test activity',
        'properties' => ['team_id' => $this->otherTeam->id],
    ]);

    $this->actingAs($this->user);
    session(['currentTeam' => ['id' => $this->team->id]]);

    $component = Livewire::test(ActivityMonitor::class)
        ->set('activityId', $otherActivity->id)
        ->assertSet('activity', null);
});

test('hydrateActivity allows access to own teams activity', function () {
    $ownActivity = Activity::create([
        'log_name' => 'default',
        'description' => 'test activity',
        'properties' => ['team_id' => $this->team->id],
    ]);

    $this->actingAs($this->user);
    session(['currentTeam' => ['id' => $this->team->id]]);

    $component = Livewire::test(ActivityMonitor::class)
        ->set('activityId', $ownActivity->id);

    expect($component->get('activity'))->not->toBeNull();
    expect($component->get('activity')->id)->toBe($ownActivity->id);
});

test('hydrateActivity allows access to activity without team_id in properties', function () {
    $legacyActivity = Activity::create([
        'log_name' => 'default',
        'description' => 'legacy activity',
        'properties' => [],
    ]);

    $this->actingAs($this->user);
    session(['currentTeam' => ['id' => $this->team->id]]);

    $component = Livewire::test(ActivityMonitor::class)
        ->set('activityId', $legacyActivity->id);

    expect($component->get('activity'))->not->toBeNull();
    expect($component->get('activity')->id)->toBe($legacyActivity->id);
});
