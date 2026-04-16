use chrono::{NaiveDate, NaiveDateTime, NaiveTime};
use rust_decimal::Decimal;
use serde_json::Value;
use sqlx::mysql::MySqlPoolOptions;
use sqlx::postgres::PgPoolOptions;
use sqlx::sqlite::{SqliteConnectOptions, SqliteJournalMode, SqlitePoolOptions, SqliteSynchronous};
use sqlx::{Column, Row, TypeInfo};
use std::collections::HashMap;
use std::str::FromStr;
use std::sync::{Arc, Mutex};
use std::time::Duration;

pub struct DatabaseState {
    pools: Mutex<HashMap<String, Arc<DatabasePool>>>,
}

enum DatabasePool {
    MySql(sqlx::MySqlPool),
    Postgres(sqlx::PgPool),
    Sqlite(sqlx::SqlitePool),
}

impl DatabaseState {
    pub fn new() -> Self {
        Self {
            pools: Mutex::new(HashMap::new()),
        }
    }
}

#[tauri::command]
pub async fn db_query(
    state: tauri::State<'_, DatabaseState>,
    driver: String,
    connection: String,
    query_type: String,
    sql: String,
    bindings: Vec<Value>,
) -> Result<Value, String> {
    let pool_key = format!("{}:{}", driver, connection);
    let pool = get_or_create_pool(&state, &pool_key, &driver, &connection).await?;

    match query_type.as_str() {
        "select" => execute_select(&pool, &sql, &bindings).await,
        "insert" => execute_insert(&pool, &sql, &bindings).await,
        "update" | "delete" | "statement" => execute_statement(&pool, &sql, &bindings).await,
        _ => Err(format!("Unknown query type: {}", query_type)),
    }
}

async fn get_or_create_pool(
    state: &tauri::State<'_, DatabaseState>,
    key: &str,
    driver: &str,
    connection: &str,
) -> Result<Arc<DatabasePool>, String> {
    {
        let pools = state.pools.lock().map_err(|e| e.to_string())?;
        if let Some(pool) = pools.get(key) {
            return Ok(Arc::clone(pool));
        }
    }

    let pool = match driver {
        "mysql" | "mariadb" => {
            let p = MySqlPoolOptions::new()
                .max_connections(10)
                .acquire_timeout(Duration::from_secs(10))
                .idle_timeout(Duration::from_secs(300))
                .max_lifetime(Duration::from_secs(1800))
                .connect(connection)
                .await
                .map_err(|e| e.to_string())?;
            DatabasePool::MySql(p)
        }
        "pgsql" | "postgres" => {
            let p = PgPoolOptions::new()
                .max_connections(10)
                .acquire_timeout(Duration::from_secs(10))
                .idle_timeout(Duration::from_secs(300))
                .max_lifetime(Duration::from_secs(1800))
                .connect(connection)
                .await
                .map_err(|e| e.to_string())?;
            DatabasePool::Postgres(p)
        }
        "sqlite" => {
            let raw = connection.strip_prefix("sqlite:").unwrap_or(connection);
            let opts = SqliteConnectOptions::from_str(raw)
                .map_err(|e| e.to_string())?
                .create_if_missing(true)
                .journal_mode(SqliteJournalMode::Wal)
                .synchronous(SqliteSynchronous::Normal)
                .busy_timeout(Duration::from_secs(5));
            let p = SqlitePoolOptions::new()
                .max_connections(4)
                .acquire_timeout(Duration::from_secs(10))
                .connect_with(opts)
                .await
                .map_err(|e| e.to_string())?;
            DatabasePool::Sqlite(p)
        }
        _ => return Err(format!("Unsupported driver: {}", driver)),
    };

    let pool = Arc::new(pool);

    let mut pools = state.pools.lock().map_err(|e| e.to_string())?;
    if let Some(existing) = pools.get(key) {
        return Ok(Arc::clone(existing));
    }
    pools.insert(key.to_string(), Arc::clone(&pool));
    Ok(pool)
}

async fn execute_select(pool: &DatabasePool, sql: &str, bindings: &[Value]) -> Result<Value, String> {
    match pool {
        DatabasePool::MySql(p) => {
            let mut query = sqlx::query(sql);
            for b in bindings { query = bind_value_mysql(query, b); }
            let rows = query.fetch_all(p).await.map_err(|e| e.to_string())?;
            Ok(Value::Array(rows.iter().map(row_to_json_mysql).collect()))
        }
        DatabasePool::Postgres(p) => {
            let mut query = sqlx::query(sql);
            for b in bindings { query = bind_value_pg(query, b); }
            let rows = query.fetch_all(p).await.map_err(|e| e.to_string())?;
            Ok(Value::Array(rows.iter().map(row_to_json_pg).collect()))
        }
        DatabasePool::Sqlite(p) => {
            let mut query = sqlx::query(sql);
            for b in bindings { query = bind_value_sqlite(query, b); }
            let rows = query.fetch_all(p).await.map_err(|e| e.to_string())?;
            Ok(Value::Array(rows.iter().map(row_to_json_sqlite).collect()))
        }
    }
}

async fn execute_insert(pool: &DatabasePool, sql: &str, bindings: &[Value]) -> Result<Value, String> {
    match pool {
        DatabasePool::MySql(p) => {
            let mut query = sqlx::query(sql);
            for b in bindings { query = bind_value_mysql(query, b); }
            let r = query.execute(p).await.map_err(|e| e.to_string())?;
            Ok(serde_json::json!({ "affected": r.rows_affected(), "lastInsertId": r.last_insert_id() }))
        }
        DatabasePool::Postgres(p) => {
            let mut query = sqlx::query(sql);
            for b in bindings { query = bind_value_pg(query, b); }
            let r = query.execute(p).await.map_err(|e| e.to_string())?;
            Ok(serde_json::json!({ "affected": r.rows_affected(), "lastInsertId": 0 }))
        }
        DatabasePool::Sqlite(p) => {
            let mut query = sqlx::query(sql);
            for b in bindings { query = bind_value_sqlite(query, b); }
            let r = query.execute(p).await.map_err(|e| e.to_string())?;
            Ok(serde_json::json!({ "affected": r.rows_affected(), "lastInsertId": r.last_insert_rowid() }))
        }
    }
}

async fn execute_statement(pool: &DatabasePool, sql: &str, bindings: &[Value]) -> Result<Value, String> {
    let affected = match pool {
        DatabasePool::MySql(p) => {
            let mut query = sqlx::query(sql);
            for b in bindings { query = bind_value_mysql(query, b); }
            query.execute(p).await.map_err(|e| e.to_string())?.rows_affected()
        }
        DatabasePool::Postgres(p) => {
            let mut query = sqlx::query(sql);
            for b in bindings { query = bind_value_pg(query, b); }
            query.execute(p).await.map_err(|e| e.to_string())?.rows_affected()
        }
        DatabasePool::Sqlite(p) => {
            let mut query = sqlx::query(sql);
            for b in bindings { query = bind_value_sqlite(query, b); }
            query.execute(p).await.map_err(|e| e.to_string())?.rows_affected()
        }
    };
    Ok(serde_json::json!({ "affected": affected }))
}

fn bind_value_mysql<'a>(
    query: sqlx::query::Query<'a, sqlx::MySql, sqlx::mysql::MySqlArguments>,
    value: &'a Value,
) -> sqlx::query::Query<'a, sqlx::MySql, sqlx::mysql::MySqlArguments> {
    match value {
        Value::Null => query.bind(None::<String>),
        Value::Bool(b) => query.bind(*b),
        Value::Number(n) => {
            if let Some(i) = n.as_i64() { query.bind(i) }
            else if let Some(f) = n.as_f64() { query.bind(f) }
            else { query.bind(n.to_string()) }
        }
        Value::String(s) => query.bind(s.as_str()),
        _ => query.bind(value.to_string()),
    }
}

fn bind_value_pg<'a>(
    query: sqlx::query::Query<'a, sqlx::Postgres, sqlx::postgres::PgArguments>,
    value: &'a Value,
) -> sqlx::query::Query<'a, sqlx::Postgres, sqlx::postgres::PgArguments> {
    match value {
        Value::Null => query.bind(None::<String>),
        Value::Bool(b) => query.bind(*b),
        Value::Number(n) => {
            if let Some(i) = n.as_i64() { query.bind(i) }
            else if let Some(f) = n.as_f64() { query.bind(f) }
            else { query.bind(n.to_string()) }
        }
        Value::String(s) => query.bind(s.as_str()),
        _ => query.bind(value.to_string()),
    }
}

fn bind_value_sqlite<'a>(
    query: sqlx::query::Query<'a, sqlx::Sqlite, sqlx::sqlite::SqliteArguments<'a>>,
    value: &'a Value,
) -> sqlx::query::Query<'a, sqlx::Sqlite, sqlx::sqlite::SqliteArguments<'a>> {
    match value {
        Value::Null => query.bind(None::<String>),
        Value::Bool(b) => query.bind(*b),
        Value::Number(n) => {
            if let Some(i) = n.as_i64() { query.bind(i) }
            else if let Some(f) = n.as_f64() { query.bind(f) }
            else { query.bind(n.to_string()) }
        }
        Value::String(s) => query.bind(s.as_str()),
        _ => query.bind(value.to_string()),
    }
}

fn bytes_to_b64(bytes: &[u8]) -> Value {
    use std::fmt::Write;
    const CHARS: &[u8] = b"ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/";
    let mut out = String::with_capacity(((bytes.len() + 2) / 3) * 4);
    let mut i = 0;
    while i + 3 <= bytes.len() {
        let n = ((bytes[i] as u32) << 16) | ((bytes[i + 1] as u32) << 8) | bytes[i + 2] as u32;
        let _ = write!(out, "{}{}{}{}",
            CHARS[((n >> 18) & 63) as usize] as char,
            CHARS[((n >> 12) & 63) as usize] as char,
            CHARS[((n >> 6) & 63) as usize] as char,
            CHARS[(n & 63) as usize] as char);
        i += 3;
    }
    let rem = bytes.len() - i;
    if rem == 1 {
        let n = (bytes[i] as u32) << 16;
        let _ = write!(out, "{}{}==",
            CHARS[((n >> 18) & 63) as usize] as char,
            CHARS[((n >> 12) & 63) as usize] as char);
    } else if rem == 2 {
        let n = ((bytes[i] as u32) << 16) | ((bytes[i + 1] as u32) << 8);
        let _ = write!(out, "{}{}{}=",
            CHARS[((n >> 18) & 63) as usize] as char,
            CHARS[((n >> 12) & 63) as usize] as char,
            CHARS[((n >> 6) & 63) as usize] as char);
    }
    Value::String(out)
}

fn decimal_as_value(row_res: Result<Decimal, sqlx::Error>) -> Value {
    match row_res {
        Ok(d) => Value::String(d.to_string()),
        Err(_) => Value::Null,
    }
}

fn datetime_as_value(row_res: Result<NaiveDateTime, sqlx::Error>) -> Value {
    match row_res {
        Ok(dt) => Value::String(dt.format("%Y-%m-%dT%H:%M:%S%.f").to_string()),
        Err(_) => Value::Null,
    }
}

fn date_as_value(row_res: Result<NaiveDate, sqlx::Error>) -> Value {
    match row_res {
        Ok(d) => Value::String(d.format("%Y-%m-%d").to_string()),
        Err(_) => Value::Null,
    }
}

fn time_as_value(row_res: Result<NaiveTime, sqlx::Error>) -> Value {
    match row_res {
        Ok(t) => Value::String(t.format("%H:%M:%S%.f").to_string()),
        Err(_) => Value::Null,
    }
}

fn row_to_json_mysql(row: &sqlx::mysql::MySqlRow) -> Value {
    let mut map = serde_json::Map::new();
    for col in row.columns() {
        let name = col.name();
        let type_name = col.type_info().name();
        let value: Value = match type_name {
            "BOOLEAN" | "TINYINT(1)" => row.try_get::<bool, _>(name).map(Value::Bool).unwrap_or(Value::Null),
            "TINYINT UNSIGNED" | "SMALLINT UNSIGNED" | "MEDIUMINT UNSIGNED" | "INT UNSIGNED" => {
                row.try_get::<u32, _>(name).map(|v| Value::Number(v.into())).unwrap_or(Value::Null)
            }
            "BIGINT UNSIGNED" => row.try_get::<u64, _>(name).map(|v| Value::Number(v.into())).unwrap_or(Value::Null),
            "TINYINT" | "SMALLINT" | "MEDIUMINT" | "INT" | "INTEGER" | "BIGINT" => {
                row.try_get::<i64, _>(name).map(|v| Value::Number(v.into())).unwrap_or(Value::Null)
            }
            "FLOAT" | "DOUBLE" => row.try_get::<f64, _>(name)
                .map(|v| serde_json::Number::from_f64(v).map(Value::Number).unwrap_or(Value::Null))
                .unwrap_or(Value::Null),
            "DECIMAL" | "NUMERIC" => decimal_as_value(row.try_get::<Decimal, _>(name)),
            "DATETIME" | "TIMESTAMP" => datetime_as_value(row.try_get::<NaiveDateTime, _>(name)),
            "DATE" => date_as_value(row.try_get::<NaiveDate, _>(name)),
            "TIME" => time_as_value(row.try_get::<NaiveTime, _>(name)),
            "JSON" => row.try_get::<String, _>(name)
                .ok()
                .and_then(|s| serde_json::from_str(&s).ok())
                .unwrap_or(Value::Null),
            "BLOB" | "TINYBLOB" | "MEDIUMBLOB" | "LONGBLOB" | "BINARY" | "VARBINARY" => {
                row.try_get::<Vec<u8>, _>(name).map(|b| bytes_to_b64(&b)).unwrap_or(Value::Null)
            }
            _ => row.try_get::<String, _>(name).map(Value::String).unwrap_or(Value::Null),
        };
        map.insert(name.to_string(), value);
    }
    Value::Object(map)
}

fn row_to_json_pg(row: &sqlx::postgres::PgRow) -> Value {
    let mut map = serde_json::Map::new();
    for col in row.columns() {
        let name = col.name();
        let type_name = col.type_info().name();
        let value: Value = match type_name {
            "BOOL" => row.try_get::<bool, _>(name).map(Value::Bool).unwrap_or(Value::Null),
            "INT2" | "INT4" | "INT8" => row.try_get::<i64, _>(name).map(|v| Value::Number(v.into())).unwrap_or(Value::Null),
            "FLOAT4" | "FLOAT8" => row.try_get::<f64, _>(name)
                .map(|v| serde_json::Number::from_f64(v).map(Value::Number).unwrap_or(Value::Null))
                .unwrap_or(Value::Null),
            "NUMERIC" => decimal_as_value(row.try_get::<Decimal, _>(name)),
            "TIMESTAMP" | "TIMESTAMPTZ" => datetime_as_value(row.try_get::<NaiveDateTime, _>(name)),
            "DATE" => date_as_value(row.try_get::<NaiveDate, _>(name)),
            "TIME" | "TIMETZ" => time_as_value(row.try_get::<NaiveTime, _>(name)),
            "JSON" | "JSONB" => row.try_get::<Value, _>(name).unwrap_or(Value::Null),
            "UUID" => row.try_get::<String, _>(name).map(Value::String).unwrap_or(Value::Null),
            "BYTEA" => row.try_get::<Vec<u8>, _>(name).map(|b| bytes_to_b64(&b)).unwrap_or(Value::Null),
            _ => row.try_get::<String, _>(name).map(Value::String).unwrap_or(Value::Null),
        };
        map.insert(name.to_string(), value);
    }
    Value::Object(map)
}

fn row_to_json_sqlite(row: &sqlx::sqlite::SqliteRow) -> Value {
    let mut map = serde_json::Map::new();
    for col in row.columns() {
        let name = col.name();
        let type_name = col.type_info().name();
        let value: Value = match type_name {
            "BOOLEAN" => row.try_get::<bool, _>(name).map(Value::Bool).unwrap_or(Value::Null),
            "INTEGER" => row.try_get::<i64, _>(name).map(|v| Value::Number(v.into())).unwrap_or(Value::Null),
            "REAL" => row.try_get::<f64, _>(name)
                .map(|v| serde_json::Number::from_f64(v).map(Value::Number).unwrap_or(Value::Null))
                .unwrap_or(Value::Null),
            "NUMERIC" | "DECIMAL" => row.try_get::<String, _>(name).map(Value::String)
                .or_else(|_| row.try_get::<f64, _>(name).map(|v| Value::String(v.to_string())))
                .or_else(|_| row.try_get::<i64, _>(name).map(|v| Value::String(v.to_string())))
                .unwrap_or(Value::Null),
            "DATETIME" | "TIMESTAMP" => datetime_as_value(row.try_get::<NaiveDateTime, _>(name)),
            "DATE" => date_as_value(row.try_get::<NaiveDate, _>(name)),
            "TIME" => time_as_value(row.try_get::<NaiveTime, _>(name)),
            "BLOB" => row.try_get::<Vec<u8>, _>(name).map(|b| bytes_to_b64(&b)).unwrap_or(Value::Null),
            _ => row.try_get::<String, _>(name).map(Value::String).unwrap_or(Value::Null),
        };
        map.insert(name.to_string(), value);
    }
    Value::Object(map)
}
