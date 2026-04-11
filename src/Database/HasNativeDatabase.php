<?php

namespace NativeBlade\Database;

use Illuminate\Database\Eloquent\Builder;

trait HasNativeDatabase
{
    public static function updateOrCreate(array $attributes, array $values = [])
    {
        $instance = new static;

        if ($instance->getConnectionName() === 'native' || $instance->getConnection() instanceof NativeConnection) {
            $merged = array_merge($attributes, $values);
            static::upsert([$merged], array_keys($attributes), array_keys($values));

            return static::where($attributes)->first() ?? (new static)->forceFill($merged);
        }

        return static::query()->updateOrCreate($attributes, $values);
    }

    public static function firstOrCreate(array $attributes, array $values = [])
    {
        $instance = new static;

        if ($instance->getConnectionName() === 'native' || $instance->getConnection() instanceof NativeConnection) {
            $existing = static::where($attributes)->first();
            if ($existing) return $existing;

            return static::create(array_merge($attributes, $values));
        }

        return static::query()->firstOrCreate($attributes, $values);
    }
}
