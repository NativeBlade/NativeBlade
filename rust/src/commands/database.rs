use serde_json::Value;
use sqlx::{Column, Row, TypeInfo};
use std::collections::HashMap;
use std::sync::Mutex;

pub struct DatabaseState {
    pools: Mutex<HashMap<String, DatabasePool>>,
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
        "select" => execute_select(pool, &sql, &bindings).await,
        "insert" => execute_insert(pool, &sql, &bindings).await,
        "update" | "delete" | "statement" => execute_statement(pool, &sql, &bindings).await,
        _ => Err(format!("Unknown query type: {}", query_type)),
    }
}

async fn get_or_create_pool<'a>(
    state: &'a tauri::State<'_, DatabaseState>,
    key: &str,
    driver: &str,
    connection: &str,
) -> Result<&'a DatabasePool, String> {
    {
        let pools = state.pools.lock().map_err(|e| e.to_string())?;
        if pools.contains_key(key) {
            drop(pools);
            let pools = state.pools.lock().map_err(|e| e.to_string())?;
            // Safety: we just checked it exists
            return Ok(unsafe { &*(pools.get(key).unwrap() as *const DatabasePool) });
        }
    }

    let pool = match driver {
        "mysql" | "mariadb" => {
            let p = sqlx::MySqlPool::connect(connection).await.map_err(|e| e.to_string())?;
            DatabasePool::MySql(p)
        }
        "pgsql" | "postgres" => {
            let p = sqlx::PgPool::connect(connection).await.map_err(|e| e.to_string())?;
            DatabasePool::Postgres(p)
        }
        "sqlite" => {
            let url = if connection.starts_with("sqlite:") { connection.to_string() } else { format!("sqlite:{}", connection) };
            let p = sqlx::SqlitePool::connect(&url).await.map_err(|e| e.to_string())?;
            DatabasePool::Sqlite(p)
        }
        _ => return Err(format!("Unsupported driver: {}", driver)),
    };

    let mut pools = state.pools.lock().map_err(|e| e.to_string())?;
    pools.insert(key.to_string(), pool);
    Ok(unsafe { &*(pools.get(key).unwrap() as *const DatabasePool) })
}

async fn execute_select(pool: &DatabasePool, sql: &str, bindings: &[Value]) -> Result<Value, String> {
    match pool {
        DatabasePool::MySql(p) => {
            let mut query = sqlx::query(sql);
            for b in bindings { query = bind_value_mysql(query, b); }
            let rows = query.fetch_all(p).await.map_err(|e| e.to_string())?;
            Ok(Value::Array(rows.iter().map(|r| row_to_json_mysql(r)).collect()))
        }
        DatabasePool::Postgres(p) => {
            let mut query = sqlx::query(sql);
            for b in bindings { query = bind_value_pg(query, b); }
            let rows = query.fetch_all(p).await.map_err(|e| e.to_string())?;
            Ok(Value::Array(rows.iter().map(|r| row_to_json_pg(r)).collect()))
        }
        DatabasePool::Sqlite(p) => {
            let mut query = sqlx::query(sql);
            for b in bindings { query = bind_value_sqlite(query, b); }
            let rows = query.fetch_all(p).await.map_err(|e| e.to_string())?;
            Ok(Value::Array(rows.iter().map(|r| row_to_json_sqlite(r)).collect()))
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

fn bind_value_mysql<'a>(query: sqlx::query::Query<'a, sqlx::MySql, sqlx::mysql::MySqlArguments>, value: &'a Value) -> sqlx::query::Query<'a, sqlx::MySql, sqlx::mysql::MySqlArguments> {
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

fn bind_value_pg<'a>(query: sqlx::query::Query<'a, sqlx::Postgres, sqlx::postgres::PgArguments>, value: &'a Value) -> sqlx::query::Query<'a, sqlx::Postgres, sqlx::postgres::PgArguments> {
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

fn bind_value_sqlite<'a>(query: sqlx::query::Query<'a, sqlx::Sqlite, sqlx::sqlite::SqliteArguments<'a>>, value: &'a Value) -> sqlx::query::Query<'a, sqlx::Sqlite, sqlx::sqlite::SqliteArguments<'a>> {
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

fn row_to_json_mysql(row: &sqlx::mysql::MySqlRow) -> Value {
    let mut map = serde_json::Map::new();
    for col in row.columns() {
        let name = col.name();
        let value: Value = match col.type_info().name() {
            "BOOLEAN" | "TINYINT(1)" => row.try_get::<bool, _>(name).map(Value::Bool).unwrap_or(Value::Null),
            "BIGINT" | "INT" | "INTEGER" | "SMALLINT" | "MEDIUMINT" => row.try_get::<i64, _>(name).map(|v| Value::Number(v.into())).unwrap_or(Value::Null),
            "FLOAT" | "DOUBLE" | "DECIMAL" => row.try_get::<f64, _>(name).map(|v| serde_json::Number::from_f64(v).map(Value::Number).unwrap_or(Value::Null)).unwrap_or(Value::Null),
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
        let value: Value = match col.type_info().name() {
            "BOOL" => row.try_get::<bool, _>(name).map(Value::Bool).unwrap_or(Value::Null),
            "INT2" | "INT4" | "INT8" => row.try_get::<i64, _>(name).map(|v| Value::Number(v.into())).unwrap_or(Value::Null),
            "FLOAT4" | "FLOAT8" | "NUMERIC" => row.try_get::<f64, _>(name).map(|v| serde_json::Number::from_f64(v).map(Value::Number).unwrap_or(Value::Null)).unwrap_or(Value::Null),
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
        let value: Value = match col.type_info().name() {
            "BOOLEAN" => row.try_get::<bool, _>(name).map(Value::Bool).unwrap_or(Value::Null),
            "INTEGER" => row.try_get::<i64, _>(name).map(|v| Value::Number(v.into())).unwrap_or(Value::Null),
            "REAL" => row.try_get::<f64, _>(name).map(|v| serde_json::Number::from_f64(v).map(Value::Number).unwrap_or(Value::Null)).unwrap_or(Value::Null),
            _ => row.try_get::<String, _>(name).map(Value::String).unwrap_or(Value::Null),
        };
        map.insert(name.to_string(), value);
    }
    Value::Object(map)
}
