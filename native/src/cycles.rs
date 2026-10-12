use std::collections::{BTreeMap, BTreeSet};

/// Iterative SCC traversal over compact integer node IDs. Output order matches
/// the existing conservative cycle checker; unknown targets are excluded.
pub fn find(input: BTreeMap<String, Vec<String>>) -> Result<Vec<Vec<String>>, String> {
    if input.len() > 250_000 || input.values().map(Vec::len).sum::<usize>() > 1_000_000 {
        return Err("Graph exceeds native cycle limits.".into());
    }
    let keys: Vec<_> = input.keys().cloned().collect();
    let ids: BTreeMap<_, _> = keys
        .iter()
        .enumerate()
        .map(|(i, k)| (k.clone(), i))
        .collect();
    let mut edges = Vec::with_capacity(keys.len());
    let mut reverse = vec![Vec::new(); keys.len()];
    for (i, key) in keys.iter().enumerate() {
        let targets: BTreeSet<_> = input[key]
            .iter()
            .filter_map(|key| ids.get(key).copied())
            .collect();
        for &target in &targets {
            reverse[target].push(i);
        }
        edges.push(targets.into_iter().collect::<Vec<_>>());
    }
    let mut visited = vec![false; keys.len()];
    let mut order = Vec::with_capacity(keys.len());
    for start in 0..keys.len() {
        if visited[start] {
            continue;
        }
        visited[start] = true;
        let mut stack = vec![(start, 0usize)];
        while let Some((node, index)) = stack.last_mut() {
            if *index == edges[*node].len() {
                order.push(*node);
                stack.pop();
                continue;
            }
            let next = edges[*node][*index];
            *index += 1;
            if !visited[next] {
                visited[next] = true;
                stack.push((next, 0));
            }
        }
    }
    visited.fill(false);
    let mut cycles = Vec::new();
    for start in order.into_iter().rev() {
        if visited[start] {
            continue;
        }
        visited[start] = true;
        let mut component = Vec::new();
        let mut stack = vec![start];
        while let Some(node) = stack.pop() {
            component.push(node);
            for &next in &reverse[node] {
                if !visited[next] {
                    visited[next] = true;
                    stack.push(next);
                }
            }
        }
        component.sort_unstable();
        if component.len() > 1 || edges[start].contains(&start) {
            cycles.push(
                component
                    .into_iter()
                    .map(|id| keys[id].clone())
                    .collect::<Vec<_>>(),
            );
        }
    }
    cycles.sort_by(|a, b| a[0].cmp(&b[0]));
    Ok(cycles)
}

#[cfg(test)]
mod tests {
    use super::*;
    #[test]
    fn long_chains_and_self_cycles_do_not_recurse() {
        let mut graph: BTreeMap<_, _> = (0..20_000)
            .map(|i| (format!("node{i:05}"), vec![format!("node{:05}", i + 1)]))
            .collect();
        graph.insert("node19999".into(), vec!["node00000".into()]);
        let cycles = find(graph).unwrap();
        assert_eq!(cycles.len(), 1);
        assert_eq!(cycles[0].len(), 20_000);
        assert_eq!(
            find(BTreeMap::from([(
                "a".into(),
                vec!["a".into(), "missing".into()]
            )]))
            .unwrap(),
            vec![vec!["a"]]
        );
    }
}
