# Lessons Learned

## Docker / Worktree Setup
- The Docker dev container mounts from `young-stork` worktree, NOT `ivory-raccoon`
- Do NOT copy files to `young-stork` or use `docker cp` — only modify files in the `ivory-raccoon` worktree
- Do NOT use `docker exec` to run tests — work entirely within the `ivory-raccoon` worktree

## Policy Tests
- Policy methods have typed parameters (e.g., `Server $server`) — anonymous classes cause TypeError
- Must use `Mockery::mock(Model::class)->makePartial()` instead of anonymous classes for model stubs
- Use `shouldReceive('getAttribute')->with('property')->andReturn(value)` for model properties accessed via relationship chains
