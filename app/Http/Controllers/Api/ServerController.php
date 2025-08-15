<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class ServerController extends Controller
{
    public function showByName(string $name)
    {
        // Fetch the server by name
        $server = \App\Models\Server::where('name', $name)->first();

        // Check if server exists
        if (!$server) {
            return response()->json(['error' => 'Server not found'], 404);
        }

        // Return the server details
        return response()->json($server);
    }
}
