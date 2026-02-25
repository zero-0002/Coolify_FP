# Lessons Learned

## Docker / Worktree Setup
- The Docker dev container mounts from `young-stork` worktree, NOT `ivory-raccoon`
- Do NOT copy files to `young-stork` or use `docker cp` — only modify files in the `ivory-raccoon` worktree
- Do NOT use `docker exec` to run tests — work entirely within the `ivory-raccoon` worktree

## Policy Tests
- Policy methods have typed parameters (e.g., `Server $server`) — anonymous classes cause TypeError
- Must use `Mockery::mock(Model::class)->makePartial()` instead of anonymous classes for model stubs
- Use `shouldReceive('getAttribute')->with('property')->andReturn(value)` for model properties accessed via relationship chains

## Browser Tests (Pest Browser Plugin)
- Plugin runs an in-process HTTP server (Amphp) sharing the same SQLite :memory: database as the test process
- Model boot events that call external services (e.g., `StandaloneDocker::created` runs docker commands) WILL fail in tests — use `Model::withoutEvents()` to wrap creation
- Livewire full-page components that fail during `mount()` silently redirect to the previous URL instead of showing an error page
- `Server::proxySet()` requires `isFunctional()` which requires `is_reachable=true` AND `is_usable=true` in ServerSetting — tests without a validated server won't show proxy controls
- Application/Database/Service pages require complex model chains (Application → Environment → Project → Team, with StandaloneDocker destination) that are difficult to fully set up for browser tests due to Livewire mount() redirecting on any chain failure
- The `currentTeam()` helper reads from session (`data_get(session('currentTeam'), 'id')`) — set during browser login flow
- `Project::created` auto-creates a "production" environment — don't manually create one with that name
