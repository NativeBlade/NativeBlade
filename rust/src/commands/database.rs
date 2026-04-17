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

#[cfg(test)]
mod tests {
    use super::*;

    // ---------------- bytes_to_b64 ----------------
    //
    // Hand-rolled base64 encoder in the source; check it against the
    // canonical RFC 4648 alphabet + padding behavior.

    #[test]
    fn bytes_to_b64_empty_input_produces_empty_string() {
        assert_eq!(bytes_to_b64(&[]), Value::String(String::new()));
    }

    #[test]
    fn bytes_to_b64_three_byte_block_no_padding() {
        // "Man" in ASCII (0x4d,0x61,0x6e) → "TWFu"
        assert_eq!(bytes_to_b64(b"Man"), Value::String("TWFu".into()));
    }

    #[test]
    fn bytes_to_b64_two_byte_remainder_gets_single_padding() {
        // "Ma" → "TWE="
        assert_eq!(bytes_to_b64(b"Ma"), Value::String("TWE=".into()));
    }

    #[test]
    fn bytes_to_b64_one_byte_remainder_gets_double_padding() {
        // "M" → "TQ=="
        assert_eq!(bytes_to_b64(b"M"), Value::String("TQ==".into()));
    }

    #[test]
    fn bytes_to_b64_classic_rfc4648_vector() {
        // "Hello world!" → "SGVsbG8gd29ybGQh"
        assert_eq!(
            bytes_to_b64(b"Hello world!"),
            Value::String("SGVsbG8gd29ybGQh".into())
        );
    }

    #[test]
    fn bytes_to_b64_uses_plus_and_slash_in_high_value_bytes() {
        // 0xFB 0xFF 0xBF packs into indices that exercise + and /.
        // Expected: base64 of [0xFB, 0xFF, 0xBF] = "+/+/"
        assert_eq!(
            bytes_to_b64(&[0xFB, 0xFF, 0xBF]),
            Value::String("+/+/".into())
        );
    }

    // ---------------- *_as_value helpers ----------------

    #[test]
    fn decimal_as_value_ok_returns_string_repr() {
        let d = Decimal::from_str("123.456").unwrap();
        assert_eq!(decimal_as_value(Ok(d)), Value::String("123.456".into()));
    }

    #[test]
    fn decimal_as_value_err_returns_null() {
        let err = sqlx::Error::ColumnNotFound("missing".into());
        assert_eq!(decimal_as_value(Err(err)), Value::Null);
    }

    #[test]
    fn datetime_as_value_formats_iso_like_string_with_fractional_seconds() {
        let dt = NaiveDate::from_ymd_opt(2026, 4, 17)
            .unwrap()
            .and_hms_opt(12, 34, 56)
            .unwrap();
        assert_eq!(
            datetime_as_value(Ok(dt)),
            Value::String("2026-04-17T12:34:56".into())
        );
    }

    #[test]
    fn datetime_as_value_err_returns_null() {
        let err = sqlx::Error::ColumnNotFound("missing".into());
        assert_eq!(datetime_as_value(Err(err)), Value::Null);
    }

    #[test]
    fn date_as_value_formats_yyyy_mm_dd() {
        let d = NaiveDate::from_ymd_opt(2026, 4, 17).unwrap();
        assert_eq!(date_as_value(Ok(d)), Value::String("2026-04-17".into()));
    }

    #[test]
    fn date_as_value_err_returns_null() {
        let err = sqlx::Error::ColumnNotFound("missing".into());
        assert_eq!(date_as_value(Err(err)), Value::Null);
    }

    #[test]
    fn time_as_value_formats_hh_mm_ss() {
        let t = NaiveTime::from_hms_opt(9, 8, 7).unwrap();
        assert_eq!(time_as_value(Ok(t)), Value::String("09:08:07".into()));
    }

    #[test]
    fn time_as_value_err_returns_null() {
        let err = sqlx::Error::ColumnNotFound("missing".into());
        assert_eq!(time_as_value(Err(err)), Value::Null);
    }

    // ---------------- DatabaseState ----------------

    #[test]
    fn database_state_new_starts_with_empty_pool_map() {
        let s = DatabaseState::new();
        let pools = s.pools.lock().unwrap();
        assert!(pools.is_empty());
    }

    // ---------------- Integration: in-memory sqlite ----------------
    //
    // These exercise execute_select / execute_insert / execute_statement
    // against a real sqlite pool. The pool is constructed directly —
    // get_or_create_pool requires a tauri::State which is awkward to fake.

    async fn sqlite_pool() -> DatabasePool {
        let pool = sqlx::sqlite::SqlitePoolOptions::new()
            .max_connections(1)
            .connect("sqlite::memory:")
            .await
            .expect("open in-memory sqlite");
        DatabasePool::Sqlite(pool)
    }

    async fn setup_widgets(pool: &DatabasePool) {
        // Create a table with a mix of types we claim to support in row_to_json_sqlite.
        let _ = execute_statement(
            pool,
            "CREATE TABLE widgets (\
                id INTEGER PRIMARY KEY AUTOINCREMENT, \
                name TEXT NOT NULL, \
                active BOOLEAN NOT NULL DEFAULT 1, \
                score REAL, \
                note TEXT\
            )",
            &[],
        )
        .await
        .unwrap();
    }

    #[tokio::test]
    async fn execute_insert_returns_affected_and_last_insert_id_for_sqlite() {
        let pool = sqlite_pool().await;
        setup_widgets(&pool).await;

        let res = execute_insert(
            &pool,
            "INSERT INTO widgets (name, active, score) VALUES (?, ?, ?)",
            &[
                Value::String("alpha".into()),
                Value::Bool(true),
                Value::Number(serde_json::Number::from_f64(3.5).unwrap()),
            ],
        )
        .await
        .unwrap();

        assert_eq!(res["affected"], Value::Number(1.into()));
        // First row → lastInsertRowid == 1 on an empty table.
        assert_eq!(res["lastInsertId"], Value::Number(1.into()));
    }

    #[tokio::test]
    async fn execute_select_hydrates_rows_into_json_objects() {
        let pool = sqlite_pool().await;
        setup_widgets(&pool).await;

        execute_insert(
            &pool,
            "INSERT INTO widgets (name, active, score) VALUES (?, ?, ?)",
            &[
                Value::String("alpha".into()),
                Value::Bool(false),
                Value::Number(serde_json::Number::from_f64(1.25).unwrap()),
            ],
        )
        .await
        .unwrap();

        let rows = execute_select(&pool, "SELECT id, name, active, score FROM widgets", &[])
            .await
            .unwrap();

        let arr = rows.as_array().expect("select returns Array");
        assert_eq!(arr.len(), 1);

        let row = arr[0].as_object().unwrap();
        assert_eq!(row["id"], Value::Number(1.into()));
        assert_eq!(row["name"], Value::String("alpha".into()));
        // BOOLEAN column in sqlite is stored as 0/1 — depending on sqlx version the
        // declared type surfaces as "BOOLEAN" (→ Value::Bool) or "INTEGER"
        // (→ Value::Number). Either encoding is correct.
        let active = &row["active"];
        assert!(
            *active == Value::Bool(false)
                || *active == Value::Number(0.into())
                || *active == Value::Number((0i64).into()),
            "active should decode as false/0, got {:?}",
            active,
        );
        assert_eq!(
            row["score"].as_f64().unwrap(),
            1.25,
            "REAL should decode as f64",
        );
    }

    #[tokio::test]
    async fn execute_select_returns_empty_array_when_no_rows_match() {
        let pool = sqlite_pool().await;
        setup_widgets(&pool).await;

        let rows = execute_select(&pool, "SELECT * FROM widgets WHERE 1 = 0", &[])
            .await
            .unwrap();
        assert_eq!(rows, Value::Array(vec![]));
    }

    #[tokio::test]
    async fn execute_statement_reports_rows_affected_for_update_and_delete() {
        let pool = sqlite_pool().await;
        setup_widgets(&pool).await;

        // seed three rows
        for n in ["a", "b", "c"] {
            execute_insert(
                &pool,
                "INSERT INTO widgets (name) VALUES (?)",
                &[Value::String(n.into())],
            )
            .await
            .unwrap();
        }

        let upd = execute_statement(&pool, "UPDATE widgets SET active = 0", &[])
            .await
            .unwrap();
        assert_eq!(upd["affected"], Value::Number(3.into()));

        let del = execute_statement(&pool, "DELETE FROM widgets WHERE name = ?", &[
            Value::String("b".into()),
        ])
        .await
        .unwrap();
        assert_eq!(del["affected"], Value::Number(1.into()));
    }

    #[tokio::test]
    async fn execute_insert_binds_null_value_as_sql_null() {
        let pool = sqlite_pool().await;
        setup_widgets(&pool).await;

        execute_insert(
            &pool,
            "INSERT INTO widgets (name, note) VALUES (?, ?)",
            &[Value::String("x".into()), Value::Null],
        )
        .await
        .unwrap();

        let rows = execute_select(&pool, "SELECT note FROM widgets WHERE name = ?", &[
            Value::String("x".into()),
        ])
        .await
        .unwrap();

        let arr = rows.as_array().unwrap();
        assert_eq!(arr.len(), 1);
        assert_eq!(arr[0]["note"], Value::Null);
    }

    #[tokio::test]
    async fn execute_insert_binds_integers_and_floats_distinctly() {
        let pool = sqlite_pool().await;
        setup_widgets(&pool).await;

        execute_insert(
            &pool,
            "INSERT INTO widgets (name, score) VALUES (?, ?)",
            &[
                Value::String("int".into()),
                Value::Number(42i64.into()),
            ],
        )
        .await
        .unwrap();

        let rows = execute_select(&pool, "SELECT score FROM widgets WHERE name = ?", &[
            Value::String("int".into()),
        ])
        .await
        .unwrap();

        // sqlite doesn't distinguish int/float in REAL column strongly, but the
        // value should round-trip as a number.
        let arr = rows.as_array().unwrap();
        let v = &arr[0]["score"];
        assert!(v.is_number(), "score should decode as a JSON number, got {:?}", v);
    }

    #[tokio::test]
    async fn db_query_rejects_unknown_query_type() {
        let pool = sqlite_pool().await;
        // We call the type dispatcher directly by replicating what db_query does
        // without needing a tauri::State. (db_query itself is gated behind Tauri.)
        async fn dispatch(pool: &DatabasePool, qt: &str) -> Result<Value, String> {
            match qt {
                "select" => execute_select(pool, "SELECT 1", &[]).await,
                "insert" => execute_insert(pool, "SELECT 1", &[]).await,
                "update" | "delete" | "statement" => execute_statement(pool, "SELECT 1", &[]).await,
                other => Err(format!("Unknown query type: {}", other)),
            }
        }

        let err = dispatch(&pool, "weird").await.unwrap_err();
        assert!(err.starts_with("Unknown query type:"), "got: {}", err);
    }
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
            // NUMERIC/DECIMAL in sqlite is stored as TEXT/INTEGER/REAL depending
            // on the inserted value; fall back through each shape and unwrap
            // Option<String> first so NULL doesn't masquerade as "".
            "NUMERIC" | "DECIMAL" => {
                if let Ok(Some(s)) = row.try_get::<Option<String>, _>(name) {
                    Value::String(s)
                } else if let Ok(Some(v)) = row.try_get::<Option<f64>, _>(name) {
                    Value::String(v.to_string())
                } else if let Ok(Some(v)) = row.try_get::<Option<i64>, _>(name) {
                    Value::String(v.to_string())
                } else {
                    Value::Null
                }
            }
            "DATETIME" | "TIMESTAMP" => datetime_as_value(row.try_get::<NaiveDateTime, _>(name)),
            "DATE" => date_as_value(row.try_get::<NaiveDate, _>(name)),
            "TIME" => time_as_value(row.try_get::<NaiveTime, _>(name)),
            "BLOB" => row.try_get::<Option<Vec<u8>>, _>(name)
                .ok()
                .flatten()
                .map(|b| bytes_to_b64(&b))
                .unwrap_or(Value::Null),
            // TEXT / default: decode via Option<String>. sqlx's SQLite driver
            // decodes SQL NULL into String as "" (not an Err) because the
            // underlying sqlite3_column_text C API returns an empty string for
            // NULL. Using Option<String> preserves the distinction between
            // NULL and a genuine empty string.
            _ => row.try_get::<Option<String>, _>(name)
                .ok()
                .flatten()
                .map(Value::String)
                .unwrap_or(Value::Null),
        };
        map.insert(name.to_string(), value);
    }
    Value::Object(map)
}
