use serde::Serialize;
use serde_json::{Value, json};
use std::collections::HashMap;

#[derive(Serialize)]
pub struct Visit {
    pub node: String,
    pub parent: Option<usize>,
    pub edge: Option<usize>,
    pub depth: usize,
}
#[derive(Serialize)]
pub struct Traversal {
    pub visits: Vec<Visit>,
    pub depth_warning: bool,
}

/// Breadth-first traversal with predecessor links instead of copying entire
/// proof paths for every queued node. The host resolves only reachable targets.
pub fn walk<F>(
    start: String,
    limit: usize,
    transitive: bool,
    mut callback: F,
) -> Result<Traversal, String>
where
    F: FnMut(Value) -> Result<Value, String>,
{
    if limit == 0 || limit > 64 {
        return Err("Invalid native traversal depth".into());
    }
    let mut visits = vec![Visit {
        node: start.clone(),
        parent: None,
        edge: None,
        depth: 0,
    }];
    let mut seen = HashMap::from([(start, 0usize)]);
    let mut offset = 0;
    let mut depth_warning = false;
    while offset < visits.len() {
        let end = (offset + 256).min(visits.len());
        let mut expand = Vec::new();
        let mut depth = Vec::new();
        for visit in &visits[offset..end] {
            if visit.depth < limit {
                expand.push(visit.node.clone());
            } else if transitive {
                depth.push(visit.node.clone());
            }
        }
        let response = callback(json!({"expand":expand,"depth":depth}))?;
        for index in offset..end {
            let visit = &visits[index];
            if visit.depth >= limit {
                if transitive {
                    for target in response["depth"][&visit.node]
                        .as_array()
                        .ok_or("Missing depth response")?
                    {
                        let target = target.as_str().ok_or("Invalid depth target")?;
                        if !seen.contains_key(target) {
                            depth_warning = true;
                        }
                    }
                }
                continue;
            }
            let targets = response["expand"][&visit.node]
                .as_array()
                .ok_or("Missing expansion response")?;
            let next_depth = visit.depth + 1;
            for (edge, target) in targets.iter().enumerate() {
                let target = target.as_str().ok_or("Invalid graph target")?;
                if seen.contains_key(target) {
                    continue;
                }
                if visits.len() >= 250_000 {
                    return Err("Native graph traversal exceeds 250000 nodes".into());
                }
                seen.insert(target.to_owned(), visits.len());
                visits.push(Visit {
                    node: target.to_owned(),
                    parent: Some(index),
                    edge: Some(edge),
                    depth: next_depth,
                });
            }
        }
        offset = end;
    }
    Ok(Traversal {
        visits,
        depth_warning,
    })
}

#[cfg(test)]
mod tests {
    use super::*;
    #[test]
    fn predecessor_order_and_depth_are_stable() {
        let result = walk("a".into(), 2, true, |query| {
            let mut expand = serde_json::Map::new();
            let mut depth = serde_json::Map::new();
            for node in query["expand"].as_array().unwrap() {
                expand.insert(
                    node.as_str().unwrap().into(),
                    match node.as_str().unwrap() {
                        "a" => json!(["b", "c"]),
                        "b" => json!(["c", "d"]),
                        _ => json!([]),
                    },
                );
            }
            for node in query["depth"].as_array().unwrap() {
                depth.insert(node.as_str().unwrap().into(), json!(["e"]));
            }
            Ok(json!({"expand":expand,"depth":depth}))
        })
        .unwrap();
        assert_eq!(
            result
                .visits
                .iter()
                .map(|v| v.node.as_str())
                .collect::<Vec<_>>(),
            vec!["a", "b", "c", "d"]
        );
        assert_eq!(result.visits[3].parent, Some(1));
        assert!(result.depth_warning);
    }
}
