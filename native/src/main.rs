use mago_extension_engine::analyze;
use serde::Deserialize;
use serde_json::{Value, json};
use sha2::{Digest, Sha256};
use std::{
    collections::HashMap,
    io::{self, BufRead, Read, Write},
};

#[derive(Deserialize)]
#[serde(deny_unknown_fields)]
struct Request {
    op: String,
    source: Option<String>,
    adjacency: Option<std::collections::BTreeMap<String, Vec<String>>>,
    program: Option<mago_extension_engine::budget::Program>,
    models: Option<std::collections::BTreeMap<String, Value>>,
    class: Option<String>,
    method: Option<String>,
    function: Option<String>,
    file: Option<String>,
    bindings: Option<std::collections::BTreeMap<String, String>>,
    constructor_bindings: Option<
        std::collections::BTreeMap<String, std::collections::BTreeMap<String, Option<String>>>,
    >,
    #[serde(default)]
    assume_scalar: bool,
    start: Option<String>,
    limit: Option<usize>,
    #[serde(default)]
    transitive: bool,
    session: Option<String>,
    nodes: Option<Vec<Value>>,
    hook_contracts: Option<Vec<String>>,
}

type ProjectSession = (
    mago_extension_engine::budget::Program,
    std::collections::BTreeMap<String, String>,
    std::collections::BTreeMap<String, std::collections::BTreeMap<String, Option<String>>>,
);

fn main() -> Result<(), Box<dyn std::error::Error>> {
    if std::env::args().nth(1).as_deref() == Some("--version") {
        println!("mago-extension-engine {}", env!("CARGO_PKG_VERSION"));
        return Ok(());
    }
    let mut cache: HashMap<[u8; 32], (Value, usize)> = HashMap::new();
    let mut bytes = 0usize;
    let mut hits = 0usize;
    let mut sessions: std::collections::BTreeMap<String, ProjectSession> =
        std::collections::BTreeMap::new();
    let stdin = io::stdin();
    let mut input = stdin.lock();
    let stdout = io::stdout();
    let mut output = stdout.lock();
    loop {
        let mut line = String::new();
        let count = input.by_ref().take(64 * 1024 * 1024).read_line(&mut line)?;
        if count == 0 {
            break;
        }
        let result = if !line.ends_with('\n') {
            json!({"error":"Request exceeds transport limit or is truncated."})
        } else {
            match serde_json::from_str::<Request>(&line) {
                Ok(request) if request.op == "info" => {
                    json!({"schema":"1","result":{"version":env!("CARGO_PKG_VERSION"),"protocol":"1"}})
                }
                Ok(request) if request.op == "ast" => {
                    let source = request.source.ok_or("Missing AST source")?;
                    match mago_extension_engine::syntax::parse(&source) {
                        Ok(nodes) => json!({"schema":"1","result":nodes}),
                        Err(error) => json!({"schema":"1","error":error}),
                    }
                }
                Ok(request) if request.op == "init-budget" => {
                    let session = request.session.unwrap_or_else(|| "legacy".into());
                    if sessions.len() >= 16 && !sessions.contains_key(&session) {
                        return Err("Native project session limit reached".into());
                    }
                    sessions.insert(
                        session,
                        (
                            request.program.ok_or("Missing program")?,
                            request.bindings.unwrap_or_default(),
                            request.constructor_bindings.unwrap_or_default(),
                        ),
                    );
                    json!({"schema":"1","result":{}})
                }
                Ok(request) if request.op == "framework-hook-classes" => {
                    let session = request.session.unwrap_or_else(|| "legacy".into());
                    let (program, _, _) = sessions
                        .get(&session)
                        .ok_or("Missing native project session")?;
                    let contracts = request
                        .hook_contracts
                        .ok_or("Missing framework hook contracts")?;
                    if contracts.len() > 256 {
                        return Err("Framework hook contract limit exceeded".into());
                    }
                    json!({"schema":"1","result":program.framework_hook_classes(&contracts)})
                }
                Ok(request) if request.op == "index-budget-source" => {
                    let source = request.source.ok_or("Missing source")?;
                    let file = request.file.ok_or("Missing source location")?;
                    let session = request.session.unwrap_or_else(|| "legacy".into());
                    let (program, bindings, constructor_bindings) = sessions
                        .get_mut(&session)
                        .ok_or("Missing native project session")?;
                    match mago_extension_engine::syntax::parse(&source) {
                        Ok(nodes) => {
                            let metadata =
                                program.index_source(nodes, &file, bindings, constructor_bindings);
                            json!({"schema":"1","result":metadata})
                        }
                        Err(error) => json!({"schema":"1","error":error}),
                    }
                }
                Ok(request) if request.op == "index-budget-nodes" => {
                    let session = request.session.unwrap_or_else(|| "legacy".into());
                    let (program, bindings, constructors) = sessions
                        .get_mut(&session)
                        .ok_or("Missing project session")?;
                    let metadata = program.index_source(
                        request.nodes.ok_or("Missing node fallback")?,
                        request.file.as_deref().ok_or("Missing fallback location")?,
                        bindings,
                        constructors,
                    );
                    json!({"schema":"1","result":metadata})
                }
                Ok(request) if request.op.starts_with("add-budget-") => {
                    let models = request.models.ok_or("Missing model batch")?;
                    let session = request.session.unwrap_or_else(|| "legacy".into());
                    let (program, _, _) = sessions
                        .get_mut(&session)
                        .ok_or("Missing native project session")?;
                    let target = match request.op.as_str() {
                        "add-budget-methods" => &mut program.methods,
                        "add-budget-traits" => &mut program.trait_methods,
                        "add-budget-functions" => &mut program.functions,
                        _ => return Err("Unknown budget model kind".into()),
                    };
                    if target.len() + models.len() > 1_000_000 {
                        return Err("Native budget method limit exceeded".into());
                    }
                    target.extend(models);
                    json!({"schema":"1","result":{}})
                }
                Ok(request) if request.op == "walk" => {
                    let mut callback = |batch: Value| -> Result<Value, String> {
                        let query = json!({"schema":"1","callback":{"graph_batch":batch}});
                        serde_json::to_writer(&mut output, &query).map_err(|e| e.to_string())?;
                        output.write_all(b"\n").map_err(|e| e.to_string())?;
                        output.flush().map_err(|e| e.to_string())?;
                        loop {
                            let mut response = String::new();
                            input
                                .by_ref()
                                .take(64 * 1024 * 1024)
                                .read_line(&mut response)
                                .map_err(|e| e.to_string())?;
                            if response.is_empty() {
                                return Err("Graph callback disconnected".into());
                            }
                            let response: Value =
                                serde_json::from_str(&response).map_err(|e| e.to_string())?;
                            if let Some(result) = response.get("callback_result") {
                                return Ok(result.clone());
                            }
                            let nested = if response["op"] == "ast" {
                                match mago_extension_engine::syntax::parse(
                                    response["source"].as_str().ok_or("Missing nested source")?,
                                ) {
                                    Ok(nodes) => json!({"schema":"1","result":nodes}),
                                    Err(error) => json!({"schema":"1","error":error}),
                                }
                            } else {
                                return Err("Unsupported nested native operation".into());
                            };
                            serde_json::to_writer(&mut output, &nested)
                                .map_err(|e| e.to_string())?;
                            output.write_all(b"\n").map_err(|e| e.to_string())?;
                            output.flush().map_err(|e| e.to_string())?;
                        }
                    };
                    match mago_extension_engine::graph::walk(
                        request.start.ok_or("Missing graph start")?,
                        request.limit.ok_or("Missing graph depth")?,
                        request.transitive,
                        &mut callback,
                    ) {
                        Ok(result) => json!({"schema":"1","result":result}),
                        Err(error) => json!({"schema":"1","error":error}),
                    }
                }
                Ok(request) if request.op == "budget" => {
                    let mut callback = |file: &str, node: &Value| -> Value {
                        let start = node["attributes"]["startFilePos"].as_i64().unwrap_or(-1);
                        let end = node["attributes"]["endFilePos"].as_i64().unwrap_or(-1);
                        if start < 0 || end < start {
                            return Value::Null;
                        }
                        let query = json!({"schema":"1","callback":{"file":file,"start":start,"end":end+1}});
                        if serde_json::to_writer(&mut output, &query).is_err()
                            || output.write_all(b"\n").is_err()
                            || output.flush().is_err()
                        {
                            return Value::Null;
                        }
                        let mut response = String::new();
                        if input
                            .by_ref()
                            .take(64 * 1024)
                            .read_line(&mut response)
                            .is_err()
                        {
                            return Value::Null;
                        }
                        serde_json::from_str::<Value>(&response)
                            .ok()
                            .and_then(|reply| reply.get("callback_result").cloned())
                            .unwrap_or(Value::Null)
                    };
                    let session = request.session.unwrap_or_else(|| "legacy".into());
                    let (program, _, _) = sessions
                        .get(&session)
                        .ok_or("Missing native project session")?;
                    let mut evaluator = mago_extension_engine::budget::Evaluator::new(
                        program,
                        request.assume_scalar,
                        &mut callback,
                    );
                    let estimate = if let Some(function) = request.function {
                        evaluator.function(&function, &[])
                    } else {
                        evaluator.method(
                            request.class.as_deref().unwrap_or(""),
                            request.method.as_deref().unwrap_or(""),
                            &[],
                        )
                    };
                    if std::env::var("MAGO_NATIVE_TRACE").as_deref() == Ok("1") {
                        for path in &evaluator.recursion_paths {
                            eprintln!("native-recursion-trace: {}", serde_json::to_string(path)?);
                        }
                    }
                    json!({"schema":"1","result":estimate})
                }
                Ok(request) if request.op == "cycles" => {
                    match request
                        .adjacency
                        .and_then(|graph| mago_extension_engine::cycles::find(graph).ok())
                    {
                        Some(result) => json!({"schema":"1","result":result}),
                        None => json!({"schema":"1","error":"Invalid or oversized cycle graph."}),
                    }
                }
                Ok(request) if request.op == "stats" => {
                    json!({"schema":"1","result":{"cached_sources":cache.len(),"cache_bytes":bytes,"cache_hits":hits}})
                }
                Ok(request) if request.op == "analyze" => match request.source {
                    Some(source) => {
                        let key: [u8; 32] = Sha256::digest(source.as_bytes()).into();
                        if let Some((value, _)) = cache.get(&key) {
                            hits += 1;
                            json!({"schema":"1","result":value})
                        } else {
                            match analyze(&source) {
                                Ok(record) => {
                                    let value = serde_json::to_value(record)?;
                                    let size = serde_json::to_vec(&value)?.len();
                                    if size <= 64 * 1024 * 1024 {
                                        if bytes + size > 64 * 1024 * 1024 || cache.len() >= 4096 {
                                            cache.clear();
                                            bytes = 0;
                                        }
                                        cache.insert(key, (value.clone(), size));
                                        bytes += size;
                                    }
                                    json!({"schema":"1","result":value})
                                }
                                Err(error) => json!({"schema":"1","error":error}),
                            }
                        }
                    }
                    None => json!({"schema":"1","error":"Missing source snapshot."}),
                },
                Ok(_) => json!({"schema":"1","error":"Unknown operation."}),
                Err(error) => json!({"schema":"1","error":format!("Invalid request: {error}")}),
            }
        };
        serde_json::to_writer(&mut output, &result)?;
        output.write_all(b"\n")?;
        output.flush()?;
    }
    Ok(())
}
