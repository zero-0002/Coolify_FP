<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add predefined server variables to all existing servers
        $servers = DB::table('servers')->get();

        foreach ($servers as $server) {
            // Check if COOLIFY_SERVER_UUID already exists
            $uuidExists = DB::table('shared_environment_variables')
                ->where('type', 'server')
                ->where('server_id', $server->id)
                ->where('key', 'COOLIFY_SERVER_UUID')
                ->exists();

            if (! $uuidExists) {
                DB::table('shared_environment_variables')->insert([
                    'key' => 'COOLIFY_SERVER_UUID',
                    'value' => $server->uuid,
                    'type' => 'server',
                    'server_id' => $server->id,
                    'team_id' => $server->team_id,
                    'is_literal' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // Check if COOLIFY_SERVER_NAME already exists
            $nameExists = DB::table('shared_environment_variables')
                ->where('type', 'server')
                ->where('server_id', $server->id)
                ->where('key', 'COOLIFY_SERVER_NAME')
                ->exists();

            if (! $nameExists) {
                DB::table('shared_environment_variables')->insert([
                    'key' => 'COOLIFY_SERVER_NAME',
                    'value' => $server->name,
                    'type' => 'server',
                    'server_id' => $server->id,
                    'team_id' => $server->team_id,
                    'is_literal' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove predefined server variables
        DB::table('shared_environment_variables')
            ->where('type', 'server')
            ->whereIn('key', ['COOLIFY_SERVER_UUID', 'COOLIFY_SERVER_NAME'])
            ->delete();
    }
};
