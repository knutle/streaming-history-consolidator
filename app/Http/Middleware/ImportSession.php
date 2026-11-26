<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ImportSession extends StartSession
{
    protected function handleStatefulRequest(Request $request, $session, Closure $next)
    {
        if (! $request->routeIs('imports.index', 'imports.store', 'imports.abort', 'imports.retry', 'imports.bulk.abort', 'listens.create')
            || config('session.driver') !== 'database'
            || config('database.connections.'.(config('session.connection') ?? config('database.default')).'.driver') !== 'sqlite') {
            return parent::handleStatefulRequest($request, $session, $next);
        }

        $request->setLaravelSession($this->startSession($request, $session));
        // SQLite import transactions can block session writes, but flash data must still age normally.
        $hasFlash = $session->get('_flash.old', []) !== [] || $session->get('_flash.new', []) !== [];
        $response = $next($request);
        $readOnly = ($request->routeIs('imports.index', 'listens.create') && $response->getStatusCode() === 200
            && DB::table('import_runs')->whereIn('status', ['queued', 'running'])->exists())
            || $request->attributes->get('import_queued') === true;
        if (! $readOnly || $hasFlash || Auth::viaRemember()) {
            $this->collectGarbage($session);
            $this->storeCurrentUrl($request, $session);
            $this->addCookieToResponse($response, $session);
            $this->saveSession($request);
        }

        return $response;
    }
}
