<?php

use App\Livewire\Team\Index as TeamIndex;
use App\Livewire\Team\Member as TeamMember;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::updateOrCreate(['id' => 0]);

    $this->team = Team::factory()->create(['personal_team' => false]);

    $this->owner = User::factory()->create();
    $this->owner->teams()->attach($this->team, ['role' => 'owner']);

    $this->admin = User::factory()->create();
    $this->admin->teams()->attach($this->team, ['role' => 'admin']);

    $this->member = User::factory()->create();
    $this->member->teams()->attach($this->team, ['role' => 'member']);
});

// --- Team Policy: update ---

test('owner can update team', function () {
    $this->actingAs($this->owner);
    session(['currentTeam' => $this->team]);

    expect(auth()->user()->can('update', $this->team))->toBeTrue();
});

test('admin can update team', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    expect(auth()->user()->can('update', $this->team))->toBeTrue();
});

test('member cannot update team', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    expect(auth()->user()->can('update', $this->team))->toBeFalse();
});

// --- Team Policy: delete ---

test('owner can delete team', function () {
    $this->actingAs($this->owner);
    session(['currentTeam' => $this->team]);

    expect(auth()->user()->can('delete', $this->team))->toBeTrue();
});

test('admin can delete team', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    expect(auth()->user()->can('delete', $this->team))->toBeTrue();
});

test('member cannot delete team', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    expect(auth()->user()->can('delete', $this->team))->toBeFalse();
});

// --- Team Policy: manageMembers ---

test('owner can manage team members', function () {
    $this->actingAs($this->owner);
    session(['currentTeam' => $this->team]);

    expect(auth()->user()->can('manageMembers', $this->team))->toBeTrue();
});

test('admin can manage team members', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    expect(auth()->user()->can('manageMembers', $this->team))->toBeTrue();
});

test('member cannot manage team members', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    expect(auth()->user()->can('manageMembers', $this->team))->toBeFalse();
});

// --- Team Policy: manageInvitations ---

test('owner can manage invitations', function () {
    $this->actingAs($this->owner);
    session(['currentTeam' => $this->team]);

    expect(auth()->user()->can('manageInvitations', $this->team))->toBeTrue();
});

test('admin can manage invitations', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    expect(auth()->user()->can('manageInvitations', $this->team))->toBeTrue();
});

test('member cannot manage invitations', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    expect(auth()->user()->can('manageInvitations', $this->team))->toBeFalse();
});

// --- Team Index Livewire: update ---

test('member cannot submit team settings via policy', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    expect(auth()->user()->can('update', $this->team))->toBeFalse();
});

// --- Team Index Livewire: delete ---

test('member cannot delete team via index', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    Livewire::test(TeamIndex::class)
        ->call('delete', 'password')
        ->assertDispatched('error');

    expect(Team::find($this->team->id))->not->toBeNull();
});

test('admin can delete team via policy', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    expect(auth()->user()->can('delete', $this->team))->toBeTrue();
});

// --- Team Member Livewire: role changes ---

test('member cannot change roles via team member component', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    Livewire::test(TeamMember::class, ['member' => $this->admin])
        ->call('makeAdmin')
        ->assertDispatched('error');
});

test('member cannot remove team members', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    Livewire::test(TeamMember::class, ['member' => $this->admin])
        ->call('remove')
        ->assertDispatched('error');
});

// --- Invitations & Invite Link (policy checks — views wrapped in @can render empty for members) ---

test('member cannot manage invitations via policy', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    expect(auth()->user()->can('manageInvitations', $this->team))->toBeFalse();
});

test('owner can manage invitations via policy', function () {
    $this->actingAs($this->owner);
    session(['currentTeam' => $this->team]);

    expect(auth()->user()->can('manageInvitations', $this->team))->toBeTrue();
});

// --- Cross-team isolation ---

test('user from different team cannot update team', function () {
    $otherTeam = Team::factory()->create();
    $otherUser = User::factory()->create();
    $otherUser->teams()->attach($otherTeam, ['role' => 'owner']);

    $this->actingAs($otherUser);
    session(['currentTeam' => $otherTeam]);

    expect(auth()->user()->can('update', $this->team))->toBeFalse();
});

test('user from different team cannot manage members', function () {
    $otherTeam = Team::factory()->create();
    $otherUser = User::factory()->create();
    $otherUser->teams()->attach($otherTeam, ['role' => 'owner']);

    $this->actingAs($otherUser);
    session(['currentTeam' => $otherTeam]);

    expect(auth()->user()->can('manageMembers', $this->team))->toBeFalse();
});
