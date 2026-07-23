---
title: "Database"
description: "On-device SQLite and access to a remote database."
---

# Database

NativeBlade supports two database strategies that work together:

- **SQLite (WASM)**, Local database inside the app. Offline, instant, persisted to IndexedDB. Used for state, migrations, and local data.
- **Native Database**, External MySQL, MariaDB, PostgreSQL, or SQLite via Rust bridge. Used for server-side data, shared databases, and enterprise apps.

## SQLite (Default)

Works out of the box. No configuration needed.

```php
// Eloquent works normally
$tasks = Task::where('done', false)->get();
Task::create(['title' => 'Buy milk']);

// State management
NativeBlade::setState('user', ['name' => 'John']);
$user = NativeBlade::getState('user');
```

Migrations run automatically on boot. See [Lifecycle](/core/lifecycle/).

## Native Database

Connect to a real database server using Eloquent, the Rust runtime executes queries via `sqlx`.

### Setup

The `native` connection is added automatically by `nativeblade:install`. Configure your credentials in `.env`:

```env
NB_DB_HOST=127.0.0.1
NB_DB_PORT=3306
NB_DB_DATABASE=myapp
NB_DB_USERNAME=root
NB_DB_PASSWORD=secret
```

The connection in `config/database.php`:

```php
'native' => [
    'driver' => 'nativeblade-db',
    'native_driver' => 'mysql',          // mysql, mariadb, pgsql, sqlite
    'host' => env('NB_DB_HOST', '127.0.0.1'),
    'port' => env('NB_DB_PORT', '3306'),
    'database' => env('NB_DB_DATABASE', 'myapp'),
    'username' => env('NB_DB_USERNAME', 'root'),
    'password' => env('NB_DB_PASSWORD', ''),
    'prefix' => '',
],
```

### Usage

Set the connection on your Model:

```php
class Setting extends Model
{
    protected $connection = 'native';
    protected $fillable = ['key', 'value'];
}
```

Then use Eloquent normally:

```php
// Read
$settings = Setting::all();
$value = Setting::where('key', 'theme')->value('value');

// Write
Setting::create(['key' => 'language', 'value' => 'en']);
Setting::updateOrCreate(['key' => 'theme'], ['value' => 'dark']);

// Delete
Setting::where('key', 'old_setting')->delete();

// Relationships
$user = User::with('posts.comments')->find(1);
```

### Supported Drivers

| `native_driver` | Database | Port |
|------------------|----------|------|
| `mysql` | MySQL 5.7+ | 3306 |
| `mariadb` | MariaDB 10.5+ | 3306 |
| `pgsql` | PostgreSQL 12+ | 5432 |
| `sqlite` | SQLite (native, not WASM) |, |

### PostgreSQL Example

```php
// config/database.php
'native' => [
    'driver' => 'nativeblade-db',
    'native_driver' => 'pgsql',
    'host' => env('NB_DB_HOST', '127.0.0.1'),
    'port' => env('NB_DB_PORT', '5432'),
    'database' => env('NB_DB_DATABASE', 'myapp'),
    'username' => env('NB_DB_USERNAME', 'postgres'),
    'password' => env('NB_DB_PASSWORD', ''),
],
```

### Native SQLite Example

For a real SQLite file on the filesystem (not WASM):

```php
'native' => [
    'driver' => 'nativeblade-db',
    'native_driver' => 'sqlite',
    'database' => '/path/to/database.sqlite',
],
```

## How It Works

The native database uses a bridge pattern:

```
PHP: Setting::where('key', 'theme')->get()
→ NativeConnection intercepts the query
→ Serializes SQL + bindings → writes to temp file → exits
→ JS detects pending query → invokes Rust
→ Rust executes via sqlx against the real database
→ Caches result → PHP re-executes
→ NativeConnection finds cached result → returns to Eloquent
```

Each query triggers one re-execution of PHP. For a page with N queries, there are N+1 PHP executions.

### Performance Tips

Use `wire:init` to avoid blocking navigation while queries execute:

```php
class Settings extends Component
{
    public bool $loading = true;
    public array $settings = [];

    public function loadData()
    {
        $this->settings = Setting::all()->toArray();
        $this->loading = false;
    }
}
```

```blade
<div wire:init="loadData">
    @if($loading)
        <x-nativeblade-skeleton class="h-12 w-full rounded-xl" />
        <x-nativeblade-skeleton class="h-12 w-full rounded-xl" />
    @else
        {{-- Real content --}}
    @endif
</div>
```

Use eager loading to reduce queries:

```php
// Bad: N+1 queries (N+2 PHP executions)
$users = User::all();
foreach ($users as $user) {
    $user->posts; // extra query per user
}

// Good: 2 queries (3 PHP executions)
$users = User::with('posts')->get();
```

### HasNativeDatabase Trait

The `updateOrCreate` and `firstOrCreate` Eloquent methods do multiple queries internally (SELECT + INSERT/UPDATE), which can cause issues with the bridge cache. Use the `HasNativeDatabase` trait on your Models to optimize these automatically:

```php
use NativeBlade\Database\HasNativeDatabase;

class Setting extends Model
{
    use HasNativeDatabase;

    protected $connection = 'native';
    protected $fillable = ['key', 'value'];
}

// Now works correctly, uses upsert under the hood
Setting::updateOrCreate(['key' => 'theme'], ['value' => 'dark']);
Setting::firstOrCreate(['key' => 'language'], ['value' => 'en']);
```

The trait converts `updateOrCreate` to `upsert` (single query) when using the native connection. Always add this trait to Models that use `protected $connection = 'native'`.

### Multiple Queries

Multiple queries work correctly as long as they run in the same order on every re-execution. Standard Eloquent code is deterministic and works fine:

```php
// OK, queries run in the same order every time
$users = User::all();                    // queryIndex 0
$posts = Post::latest()->take(10)->get(); // queryIndex 1
$stats = Task::where('done', true)->count(); // queryIndex 2
// 3 queries = 4 PHP executions
```

Relationships work:

```php
// OK, deterministic queries
$user = User::find(1);          // queryIndex 0
$posts = $user->posts()->get(); // queryIndex 1
// 2 queries = 3 PHP executions
```

Avoid non-deterministic logic between queries:

```php
// AVOID, different code paths between re-executions
if (rand(0, 1)) {
    $a = User::find(1);  // queryIndex 0
} else {
    $b = Post::find(1);  // queryIndex 0, different SQL, cache mismatch!
}
```

### Transactions

Transactions work within the bridge pattern:

```php
DB::connection('native')->transaction(function () {
    Setting::create(['key' => 'a', 'value' => '1']);
    Setting::create(['key' => 'b', 'value' => '2']);
});
```

The Rust side maintains connection pools, so the transaction executes on the same connection.

## SQLite vs Native, When to Use What

| Use Case | SQLite (WASM) | Native Database |
|----------|---------------|-----------------|
| Auth state, preferences | ✓ | |
| Offline-first data | ✓ | |
| App cache | ✓ | |
| Shared/team data | | ✓ |
| Server-side database | | ✓ |
| Large datasets | | ✓ |
| Full-text search | | ✓ |
| Complex queries/joins | ✓ | ✓ |

## Important Notes

- The default `sqlite` connection always uses the local WASM SQLite, `NativeBlade::setState()` and migrations always use this
- The `native` connection uses separate env vars (`NB_DB_*`) to avoid conflicts
- The database server must be accessible from the user's machine (localhost, LAN, or internet)
- Each query goes through the bridge, use eager loading and `wire:init` for best performance
