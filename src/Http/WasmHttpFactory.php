<?php

namespace NativeBlade\Http;

use Illuminate\Http\Client\Factory;

/**
 * Laravel's Http factory with WasmHttpHandler installed as the Guzzle
 * handler of every PendingRequest.
 *
 * Replacing the handler, instead of short-circuiting the stack with a global
 * middleware, keeps the whole Laravel request pipeline in place inside the
 * wasm runtime: beforeSending callbacks, Http::fake(), retries, timeouts and
 * per-request middleware all run exactly as they do on a server, and only
 * the final network hop is swapped for the Tauri bridge.
 */
class WasmHttpFactory extends Factory
{
    protected function newPendingRequest()
    {
        return parent::newPendingRequest()->setHandler(new WasmHttpHandler());
    }

    /**
     * Http::pool() hands every pooled request Guzzle's default (curl) handler
     * through Factory::setHandler(). There is no curl inside the wasm runtime,
     * so the bridge handler installed above stays in place.
     */
    public function __call($method, $parameters)
    {
        if ($method === 'setHandler') {
            return $this->createPendingRequest();
        }

        return parent::__call($method, $parameters);
    }
}
