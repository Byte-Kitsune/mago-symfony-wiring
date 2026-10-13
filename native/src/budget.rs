use serde::{Deserialize, Serialize};
use serde_json::{Value, json};
use std::collections::BTreeMap;

type Env = BTreeMap<String, String>;

#[derive(Clone, Default)]
struct Scope {
    types: Env,
    values: BTreeMap<String, Bound>,
}
impl std::ops::Deref for Scope {
    type Target = Env;
    fn deref(&self) -> &Env {
        &self.types
    }
}
impl std::ops::DerefMut for Scope {
    fn deref_mut(&mut self) -> &mut Env {
        &mut self.types
    }
}
#[derive(Clone, Default)]
struct Bound {
    argument_name: Option<String>,
    object: Option<String>,
    callable: Option<std::sync::Arc<Callable>>,
    count: Option<i64>,
    scalar: bool,
    element: Option<Box<Bound>>,
    members: Option<std::sync::Arc<Vec<Bound>>>,
    deferred: Option<std::sync::Arc<Deferred>>,
    reflection: Option<std::sync::Arc<Callable>>,
    properties: Option<std::sync::Arc<BTreeMap<String, Bound>>>,
}
#[derive(Clone)]
struct Deferred {
    target: Callable,
    arguments: Vec<Bound>,
}

#[derive(Clone)]
enum Callable {
    Method(String, String),
    Function(String),
    Closure {
        node: Value,
        scope: Scope,
        class: String,
        file: String,
    },
}

#[derive(Debug, Clone, Default, Serialize, Deserialize)]
pub struct Estimate {
    pub lower: i64,
    pub upper: Option<i64>,
    pub unknown: Vec<String>,
    pub cycles: Vec<String>,
}
impl Estimate {
    fn zero() -> Self {
        Self {
            upper: Some(0),
            ..Self::default()
        }
    }
    fn unknown(reason: String) -> Self {
        Self {
            unknown: vec![reason],
            ..Self::default()
        }
    }
    fn plus(mut self, other: Self) -> Self {
        self.lower = self.lower.saturating_add(other.lower);
        self.upper = self
            .upper
            .zip(other.upper)
            .map(|(a, b)| a.saturating_add(b));
        for reason in other.unknown {
            if !self.unknown.contains(&reason) {
                self.unknown.push(reason);
            }
        }
        for cycle in other.cycles {
            if !self.cycles.contains(&cycle) {
                self.cycles.push(cycle);
            }
        }
        self
    }
    fn branch(mut self, other: Self) -> Self {
        let lower = self.lower.min(other.lower);
        let upper = self.upper.zip(other.upper).map(|(a, b)| a.max(b));
        self = self.plus(other);
        self.lower = lower;
        self.upper = upper;
        self
    }
    fn times(mut self, count: i64) -> Self {
        self.lower = self.lower.saturating_mul(count);
        self.upper = self.upper.map(|n| n.saturating_mul(count));
        self
    }
}

#[derive(Debug, Default, Deserialize)]
pub struct Program {
    #[serde(default)]
    pub framework_classes: BTreeMap<String, Value>,
    #[serde(default)]
    pub methods: BTreeMap<String, Value>,
    #[serde(default)]
    pub constants: BTreeMap<String, Value>,
    #[serde(default)]
    pub property_hooks: BTreeMap<String, Vec<String>>,
    #[serde(default)]
    pub trait_methods: BTreeMap<String, Value>,
    #[serde(default)]
    pub functions: BTreeMap<String, Value>,
    #[serde(default)]
    pub properties: BTreeMap<String, Env>,
    #[serde(default)]
    pub parents: Env,
    #[serde(default)]
    pub interfaces: BTreeMap<String, Vec<String>>,
    #[serde(default)]
    pub trait_uses: BTreeMap<String, Vec<String>>,
    #[serde(default)]
    pub trait_definitions: BTreeMap<String, bool>,
    #[serde(default)]
    pub adapted_traits: BTreeMap<String, bool>,
    #[serde(default)]
    pub ambiguous_functions: BTreeMap<String, bool>,
}

fn kind(node: &Value) -> &str {
    node["nodeType"].as_str().unwrap_or("")
}
fn string(value: &Value) -> &str {
    value.as_str().unwrap_or("")
}
fn array(value: &Value) -> &[Value] {
    value.as_array().map(Vec::as_slice).unwrap_or(&[])
}
fn name(node: &Value) -> String {
    let resolved = &node["attributes"]["resolvedName"];
    string(if resolved.is_null() {
        &node["name"]
    } else {
        &resolved["name"]
    })
    .trim_start_matches('\\')
    .into()
}
fn is_name(node: &Value) -> bool {
    kind(node).starts_with("Name")
}
fn first_class_callable(node: &Value) -> bool {
    let arguments = array(&node["args"]);
    arguments.len() == 1 && kind(&arguments[0]) == "VariadicPlaceholder"
}

fn line(node: &Value) -> i64 {
    node["attributes"]["startLine"].as_i64().unwrap_or(-1)
}
fn method_key(class: &str, method: &str) -> String {
    format!("{}::{method}", class.trim_start_matches('\\')).to_ascii_lowercase()
}

struct Model<'a> {
    node: &'a Value,
    class: String,
    file: &'a str,
}

impl Program {
    /// Select declaration candidates from the already indexed snapshot, avoiding source refetch/parsing in PHP.
    pub fn framework_hook_classes(&self, contracts: &[String]) -> Vec<Value> {
        let mut ancestry = BTreeMap::<String, Vec<String>>::new();
        for (owner, interfaces) in &self.interfaces {
            ancestry
                .entry(owner.to_ascii_lowercase())
                .or_default()
                .extend(interfaces.iter().map(|v| v.to_ascii_lowercase()));
        }
        for (owner, parent) in &self.parents {
            ancestry
                .entry(owner.to_ascii_lowercase())
                .or_default()
                .push(parent.to_ascii_lowercase());
        }
        let targets = contracts
            .iter()
            .map(|c| c.to_ascii_lowercase())
            .collect::<std::collections::BTreeSet<_>>();
        self.framework_classes
            .iter()
            .filter_map(|(owner, record)| {
                let node = &record["node"];
                let has_hook_attribute = |node: &Value| {
                    array(&node["attrGroups"]).iter().any(|group| {
                        array(&group["attrs"]).iter().any(|attribute| {
                            targets.contains(&name(&attribute["name"]).to_ascii_lowercase())
                        })
                    })
                };
                let declared = has_hook_attribute(node)
                    || array(&node["stmts"]).iter().any(|m| {
                        has_hook_attribute(m)
                            || string(&m["name"]["name"]).starts_with("__")
                                && !string(&m["name"]["name"]).eq_ignore_ascii_case("__construct")
                    });
                let mut queue = vec![owner.clone()];
                let mut seen = std::collections::BTreeSet::new();
                let mut inherited = false;
                while let Some(next) = queue.pop() {
                    if targets.contains(&next) {
                        inherited = true;
                        break;
                    }
                    if seen.len() >= 128 || !seen.insert(next.clone()) {
                        continue;
                    }
                    if let Some(parents) = ancestry.get(&next) {
                        queue.extend(parents.iter().cloned());
                    }
                }
                (declared || inherited).then(|| record.clone())
            })
            .collect()
    }
    fn parent(&self, class: &str) -> Option<&String> {
        self.parents
            .iter()
            .find(|(owner, _)| owner.eq_ignore_ascii_case(class))
            .map(|(_, parent)| parent)
    }
    fn api_type(&self, class: &str) -> Option<String> {
        let known = [
            "Doctrine\\DBAL\\Connection",
            "Doctrine\\DBAL\\Statement",
            "Doctrine\\DBAL\\Result",
            "Doctrine\\DBAL\\Query\\QueryBuilder",
            "Doctrine\\ORM\\QueryBuilder",
            "Doctrine\\ORM\\Query",
            "Doctrine\\ORM\\AbstractQuery",
            "Doctrine\\ORM\\NativeQuery",
            "Doctrine\\ORM\\EntityRepository",
            "Doctrine\\Bundle\\DoctrineBundle\\Repository\\ServiceEntityRepository",
            "Doctrine\\ORM\\EntityManagerInterface",
            "Doctrine\\ORM\\EntityManager",
            "Symfony\\Contracts\\Cache\\CacheInterface",
            "Symfony\\Contracts\\Cache\\TagAwareCacheInterface",
        ];
        let mut queue = vec![class.to_owned()];
        let mut seen = Vec::new();
        while let Some(next) = queue.pop() {
            if seen.len() >= 48 {
                return None;
            }
            if seen.contains(&next.to_ascii_lowercase()) {
                continue;
            }
            seen.push(next.to_ascii_lowercase());
            if let Some(api) = known.iter().find(|api| api.eq_ignore_ascii_case(&next)) {
                return Some((*api).into());
            }
            if let Some(parent) = self.parent(&next) {
                queue.push(parent.clone());
            }
            if let Some((_, interfaces)) = self
                .interfaces
                .iter()
                .find(|(owner, _)| owner.eq_ignore_ascii_case(&next))
            {
                queue.extend(interfaces.iter().cloned());
            }
        }
        None
    }
    fn traits_complete(&self, owner: &str, visited: &[String]) -> bool {
        let owner = owner.to_ascii_lowercase();
        if visited.len() >= 16 || self.adapted_traits.contains_key(&owner) {
            return false;
        }
        for target in self.trait_uses.get(&owner).into_iter().flatten() {
            if !self.trait_definitions.contains_key(target) || visited.contains(target) {
                return false;
            }
            let mut next = visited.to_vec();
            next.push(target.clone());
            if !self.traits_complete(target, &next) {
                return false;
            }
        }
        true
    }
    fn trait_method(
        &self,
        owner: &str,
        method: &str,
        consumer: &str,
        visited: &[String],
    ) -> Option<Model<'_>> {
        let owner = owner.to_ascii_lowercase();
        if visited.len() >= 16 || self.adapted_traits.contains_key(&owner) {
            return None;
        }
        for target in self.trait_uses.get(&owner).into_iter().flatten() {
            if visited.contains(target) {
                return None;
            }
            if let Some(model) = self.trait_methods.get(&method_key(target, method)) {
                return Some(Model {
                    node: &model["node"],
                    class: consumer.into(),
                    file: string(&model["file"]),
                });
            }
            let mut next = visited.to_vec();
            next.push(target.clone());
            if let Some(model) = self.trait_method(target, method, consumer, &next) {
                return Some(model);
            }
        }
        None
    }
    fn method(&self, class: &str, method: &str) -> Option<Model<'_>> {
        let mut class = class.to_owned();
        for _ in 0..16 {
            if let Some(model) = self.methods.get(&method_key(&class, method)) {
                return Some(Model {
                    node: &model["node"],
                    class: string(&model["class"]).into(),
                    file: string(&model["file"]),
                });
            }
            if !self.traits_complete(&class, &[]) {
                return None;
            }
            if let Some(model) = self.trait_method(&class, method, &class, &[]) {
                return Some(model);
            }
            class = self.parent(&class)?.clone();
        }
        None
    }
}

pub struct Evaluator<'a, F: FnMut(&str, &Value) -> Value> {
    program: &'a Program,
    visits: usize,
    pub recursion_paths: Vec<Vec<String>>,
    assume_scalar: bool,
    uncertain_callback: usize,
    bound_depth: usize,
    callback: F,
    consume_generator: bool,
    consumed: Vec<std::sync::Arc<Deferred>>,
}
impl<'a, F: FnMut(&str, &Value) -> Value> Evaluator<'a, F> {
    pub fn new(program: &'a Program, assume_scalar: bool, callback: F) -> Self {
        Self {
            program,
            visits: 0,
            recursion_paths: Vec::new(),
            assume_scalar,
            uncertain_callback: 0,
            bound_depth: 0,
            callback,
            consume_generator: false,
            consumed: Vec::new(),
        }
    }
    pub fn method(&mut self, class: &str, method: &str, stack: &[String]) -> Estimate {
        self.method_bound(class, method, stack, &[])
    }
    fn method_bound(
        &mut self,
        class: &str,
        method: &str,
        stack: &[String],
        arguments: &[Bound],
    ) -> Estimate {
        self.visits += 1;
        if self.visits > 25_000 {
            return Estimate::unknown("analysis work limit".into());
        }
        let key = method_key(class, method);
        let program = self.program;
        let Some(model) = program.method(class, method) else {
            return Estimate::unknown(format!("unresolved method {class}::{method}"));
        };
        if model.node["stmts"].is_null() {
            return Estimate::unknown(format!("unresolved abstract method {class}::{method}"));
        }
        if stack.contains(&key) {
            if self.uncertain_callback > 0 {
                return Estimate::unknown(format!(
                    "callback recursion has unknown invocation bound: {key}"
                ));
            }
            if stack.last() == Some(&key) && breaker(model.node, method, false) {
                return Estimate::unknown(format!(
                    "guarded recursion has unknown invocation bound: {key}"
                ));
            }
            self.recursion_paths
                .push(stack.iter().cloned().chain([key.clone()]).collect());
            return Estimate {
                lower: 0,
                upper: None,
                unknown: vec![format!("unbounded recursion: {key}")],
                cycles: vec![key],
            };
        }
        if stack.len() >= 48 {
            return Estimate::unknown("call depth limit".into());
        }
        let mut next = stack.to_vec();
        next.push(key);
        if has_yield(model.node) && !std::mem::take(&mut self.consume_generator) {
            return Estimate::zero();
        }
        let runtime_class = class.to_owned();
        let class = model.class.as_str();
        let file = model.file;
        let mut env = Scope::default();
        env.insert("this".into(), runtime_class);
        for param in array(&model.node["params"]) {
            if kind(&param["var"]) == "Expr_Variable"
                && param["var"]["name"].is_string()
                && is_name(&param["type"])
            {
                env.insert(string(&param["var"]["name"]).into(), name(&param["type"]));
            }
        }
        self.bind_parameters(model.node, arguments, &mut env, class, file);
        if let Some(value) = arguments
            .iter()
            .find(|arg| arg.argument_name.as_deref() == Some("\0this"))
        {
            if let Some(owner) = &value.object {
                env.insert("this".into(), owner.clone());
            }
            env.values.insert("this".into(), value.clone());
        }
        self.statements(array(&model.node["stmts"]), class, file, env, &next)
    }
    pub fn function(&mut self, function_name: &str, stack: &[String]) -> Estimate {
        self.function_bound(function_name, stack, &[])
    }
    fn function_bound(
        &mut self,
        function_name: &str,
        stack: &[String],
        arguments: &[Bound],
    ) -> Estimate {
        self.visits += 1;
        if self.visits > 25_000 {
            return Estimate::unknown("analysis work limit".into());
        }
        let program = self.program;
        let Some(model) = program.functions.get(&function_name.to_ascii_lowercase()) else {
            return Estimate::unknown(format!("unresolved function {function_name}"));
        };
        let key = format!("function:{}", string(&model["name"]).to_ascii_lowercase());
        if stack.contains(&key) {
            if self.uncertain_callback > 0 {
                return Estimate::unknown(format!(
                    "callback recursion has unknown invocation bound: {key}"
                ));
            }
            if stack.last() == Some(&key) && breaker(&model["node"], string(&model["name"]), true) {
                return Estimate::unknown(format!(
                    "guarded recursion has unknown invocation bound: {key}"
                ));
            }
            self.recursion_paths
                .push(stack.iter().cloned().chain([key.clone()]).collect());
            return Estimate {
                lower: 0,
                upper: None,
                unknown: vec![format!("unbounded recursion: {key}")],
                cycles: vec![key],
            };
        }
        if stack.len() >= 48 {
            return Estimate::unknown("call depth limit".into());
        }
        let mut next = stack.to_vec();
        next.push(key);
        if has_yield(&model["node"]) && !std::mem::take(&mut self.consume_generator) {
            return Estimate::zero();
        }
        let mut env = Scope::default();
        for param in array(&model["node"]["params"]) {
            if kind(&param["var"]) == "Expr_Variable"
                && param["var"]["name"].is_string()
                && is_name(&param["type"])
            {
                env.insert(string(&param["var"]["name"]).into(), name(&param["type"]));
            }
        }
        self.bind_parameters(
            &model["node"],
            arguments,
            &mut env,
            "",
            string(&model["file"]),
        );
        self.statements(
            array(&model["node"]["stmts"]),
            "",
            string(&model["file"]),
            env,
            &next,
        )
    }
    fn statements(
        &mut self,
        statements: &[Value],
        class: &str,
        file: &str,
        mut env: Scope,
        stack: &[String],
    ) -> Estimate {
        let mut sum = Estimate::zero();
        let mut early = None;
        let mut exception = None;
        for stmt in statements {
            self.visits += 1;
            if self.visits > 25_000 {
                return sum.plus(Estimate::unknown("analysis work limit".into()));
            }
            match kind(stmt) {
                "Stmt_If" => {
                    let condition = self.expr(&stmt["cond"], class, file, &mut env, stack);
                    let branch =
                        self.statements(array(&stmt["stmts"]), class, file, env.clone(), stack);
                    let mut alternative = self.statements(
                        array(&stmt["else"]["stmts"]),
                        class,
                        file,
                        env.clone(),
                        stack,
                    );
                    for elseif in array(&stmt["elseifs"]) {
                        let condition = self.expr(&elseif["cond"], class, file, &mut env, stack);
                        let body = self.statements(
                            array(&elseif["stmts"]),
                            class,
                            file,
                            env.clone(),
                            stack,
                        );
                        alternative = alternative.branch(condition.plus(body));
                    }
                    sum = sum.plus(condition).plus(branch.branch(alternative));
                    invalidate_assignments(stmt, &mut env);
                    if exits(array(&stmt["stmts"]))
                        || exits(array(&stmt["else"]["stmts"]))
                        || array(&stmt["elseifs"])
                            .iter()
                            .any(|part| exits(array(&part["stmts"])))
                    {
                        early.get_or_insert(sum.lower);
                    }
                }
                "Stmt_TryCatch" => {
                    exception.get_or_insert(sum.lower);
                    let body =
                        self.statements(array(&stmt["stmts"]), class, file, env.clone(), stack);
                    let mut catches = Estimate::zero();
                    for catch in array(&stmt["catches"]) {
                        let mut catch_env = env.clone();
                        if kind(&catch["var"]) == "Expr_Variable" {
                            catch_env.remove(string(&catch["var"]["name"]));
                            catch_env.values.remove(string(&catch["var"]["name"]));
                        }
                        catches = catches.branch(self.statements(
                            array(&catch["stmts"]),
                            class,
                            file,
                            catch_env,
                            stack,
                        ));
                    }
                    let mut body = body.plus(catches);
                    body.lower = 0;
                    sum = sum.plus(body);
                    if !stmt["finally"].is_null() {
                        sum = sum.plus(self.statements(
                            array(&stmt["finally"]["stmts"]),
                            class,
                            file,
                            env.clone(),
                            stack,
                        ));
                    }
                    invalidate_assignments(stmt, &mut env);
                }
                "Stmt_Foreach" => {
                    sum = sum.plus(self.expr(&stmt["expr"], class, file, &mut env, stack));
                    let iterable = self.bound(&stmt["expr"], class, file, &env);
                    if iterable.count.is_none() {
                        let mut consumption = self.consume(&iterable, stack, file);
                        consumption.lower = 0;
                        sum = sum.plus(consumption);
                    }
                    if stmt["byRef"] == true {
                        invalidate_target(&stmt["expr"], &mut env);
                        sum = sum.plus(Estimate::unknown(format!(
                            "reference foreach at {file}:{}",
                            line(stmt)
                        )));
                    }
                    let mut body_env = env.clone();
                    invalidate_target(&stmt["valueVar"], &mut body_env);
                    invalidate_target(&stmt["keyVar"], &mut body_env);
                    let body = self.statements(array(&stmt["stmts"]), class, file, body_env, stack);
                    if let Some(count) = self.bound(&stmt["expr"], class, file, &env).count {
                        sum = sum.plus(if count == 0 {
                            Estimate::zero()
                        } else {
                            body.times(count)
                        });
                    } else if body.upper != Some(0) || !body.unknown.is_empty() {
                        sum = sum.plus(Estimate::unknown(format!(
                            "unbounded foreach at {file}:{}",
                            line(stmt)
                        )));
                    }
                    invalidate_assignments(stmt, &mut env);
                }
                "Stmt_While" | "Stmt_Do" | "Stmt_For" => {
                    sum = sum.plus(Estimate::unknown(format!(
                        "unbounded loop at {file}:{}",
                        line(stmt)
                    )));
                    invalidate_assignments(stmt, &mut env);
                }
                "Stmt_Expression" => {
                    sum = sum.plus(self.expr(&stmt["expr"], class, file, &mut env, stack))
                }
                "Stmt_Return" => {
                    if !stmt["expr"].is_null() {
                        sum = sum.plus(self.expr(&stmt["expr"], class, file, &mut env, stack));
                    }
                    break;
                }
                "Stmt_Nop" => {}
                _ => {
                    sum = sum.plus(Estimate::unknown(format!(
                        "unsupported statement {} at {file}:{}",
                        php_class(kind(stmt)),
                        line(stmt)
                    )))
                }
            }
        }
        if let Some(lower) = early {
            sum.lower = sum.lower.min(lower);
        }
        if let Some(lower) = exception {
            sum.lower = sum.lower.min(lower);
        }
        sum
    }
    fn args(
        &mut self,
        node: &Value,
        class: &str,
        file: &str,
        env: &mut Scope,
        stack: &[String],
    ) -> (Estimate, Vec<Bound>) {
        let model = self.argument_model(node, class, file, env);
        let mut invalidated = Vec::new();
        let mut sum = Estimate::zero();
        let mut values = Vec::new();
        for (index, arg) in array(&node["args"]).iter().enumerate() {
            if kind(arg) != "Arg" {
                continue;
            }
            sum = sum.plus(self.expr(&arg["value"], class, file, env, stack));
            let mut value = self.bound(&arg["value"], class, file, env);
            value.argument_name = if arg["unpack"] == true {
                Some("*".into())
            } else {
                arg["name"]["name"].as_str().map(str::to_owned)
            };
            values.push(value);
            if let Some(model) = &model {
                let parameters = array(&model["params"]);
                let parameter = arg["name"]["name"]
                    .as_str()
                    .and_then(|name| {
                        parameters
                            .iter()
                            .find(|param| string(&param["var"]["name"]) == name)
                    })
                    .or_else(|| parameters.get(index));
                if parameter.is_some_and(|parameter| parameter["byRef"] == true)
                    && kind(&arg["value"]) == "Expr_Variable"
                {
                    invalidated.push(string(&arg["value"]["name"]).to_owned());
                }
            }
        }
        for name in invalidated {
            env.remove(&name);
            env.values.remove(&name);
        }
        (sum, values)
    }
    fn argument_model(
        &mut self,
        node: &Value,
        class: &str,
        file: &str,
        env: &Scope,
    ) -> Option<Value> {
        let callable = match kind(node) {
            "Expr_MethodCall" | "Expr_NullsafeMethodCall"
                if kind(&node["name"]) == "Identifier" =>
            {
                self.receiver(&node["var"], class, file, env)
                    .map(|owner| Callable::Method(owner, string(&node["name"]["name"]).into()))
            }
            "Expr_StaticCall" if kind(&node["name"]) == "Identifier" => self
                .class_name(&node["class"], class)
                .map(|owner| Callable::Method(owner, string(&node["name"]["name"]).into())),
            "Expr_New" => self
                .class_name(&node["class"], class)
                .map(|owner| Callable::Method(owner, "__construct".into())),
            "Expr_FuncCall" if is_name(&node["name"]) => {
                let ns = name(&node["name"]["attributes"]["namespacedName"]);
                Some(Callable::Function(
                    if self
                        .program
                        .functions
                        .contains_key(&ns.to_ascii_lowercase())
                    {
                        ns
                    } else {
                        name(&node["name"])
                    },
                ))
            }
            "Expr_FuncCall" => self
                .bound(&node["name"], class, file, env)
                .callable
                .map(|callback| (*callback).clone()),
            _ => None,
        };
        match callable {
            Some(Callable::Method(owner, method)) => self
                .program
                .method(&owner, &method)
                .map(|model| model.node.clone()),
            Some(Callable::Function(name)) => self
                .program
                .functions
                .get(&name.to_ascii_lowercase())
                .map(|model| model["node"].clone()),
            Some(Callable::Closure { node, .. }) => Some(node),
            None => None,
        }
    }
    fn expr(
        &mut self,
        expr: &Value,
        class: &str,
        file: &str,
        env: &mut Scope,
        stack: &[String],
    ) -> Estimate {
        self.visits += 1;
        if self.visits > 25_000 {
            return Estimate::unknown("analysis work limit".into());
        }
        match kind(expr) {
            "Expr_Closure" | "Expr_ArrowFunction" => Estimate::zero(),
            "Expr_PropertyFetch" | "Expr_NullsafePropertyFetch" => {
                let effect = self.expr(&expr["var"], class, file, env, stack);
                effect.plus(self.property_effect(expr, false, class, file, env))
            }
            "Expr_Clone" => self
                .expr(&expr["expr"], class, file, env, stack)
                .plus(Estimate::unknown(format!("object clone hooks at {file}"))),
            "Expr_BinaryOp_Concat" => {
                let mut effect = self.expr(&expr["left"], class, file, env, stack);
                let left = self.bound(&expr["left"], class, file, env);
                effect = effect.plus(self.expr(&expr["right"], class, file, env, stack));
                let right = self.bound(&expr["right"], class, file, env);
                if left.scalar && right.scalar {
                    effect
                } else {
                    effect.plus(Estimate::unknown(format!(
                        "object string conversion at {file}"
                    )))
                }
            }
            "Expr_AssignOp_Concat" | "Scalar_InterpolatedString" => {
                if kind(expr) == "Expr_AssignOp_Concat" {
                    invalidate_target(&expr["var"], env);
                }
                Estimate::unknown(format!("string conversion hooks at {file}"))
            }
            "Expr_Cast_String" => {
                let effect = self.expr(&expr["expr"], class, file, env, stack);
                let value = self.bound(&expr["expr"], class, file, env);
                if value.scalar {
                    effect
                } else {
                    effect.plus(Estimate::unknown(format!(
                        "object string conversion at {file}"
                    )))
                }
            }
            "Expr_YieldFrom" => {
                let effect = self.expr(&expr["expr"], class, file, env, stack);
                let value = self.bound(&expr["expr"], class, file, env);
                effect.plus(self.consume(&value, stack, file))
            }
            "Expr_BinaryOp_Pipe" => {
                let sum = self.expr(&expr["left"], class, file, env, stack);
                let argument = self.bound(&expr["left"], class, file, env);
                let sum = sum.plus(self.expr(&expr["right"], class, file, env, stack));
                let target = self.bound(&expr["right"], class, file, env);
                sum.plus(self.invoke(target.callable.as_deref(), &[argument], stack, file))
            }
            "Expr_MethodCall" | "Expr_NullsafeMethodCall" => {
                let mut sum = self.expr(&expr["var"], class, file, env, stack);
                if first_class_callable(expr) {
                    if kind(&expr["name"]).starts_with("Expr_") {
                        sum = sum.plus(self.expr(&expr["name"], class, file, env, stack));
                    }
                    return sum;
                }
                let target = self.bound(&expr["var"], class, file, env);
                let (argument_effects, arguments) = self.args(expr, class, file, env, stack);
                sum = sum.plus(argument_effects);
                if kind(&expr["name"]) != "Identifier" {
                    return sum.plus(Estimate::unknown(format!(
                        "dynamic method call at {file}:{}",
                        line(expr)
                    )));
                }
                let method = string(&expr["name"]["name"]);
                if let Some(effect) = self.special_method(&target, method, &arguments, stack, file)
                {
                    return sum.plus(effect);
                }
                if method.eq_ignore_ascii_case("__invoke") && target.callable.is_some() {
                    let effect = self.invoke(target.callable.as_deref(), &arguments, stack, file);
                    return sum.plus(if kind(expr) == "Expr_NullsafeMethodCall" {
                        effect.branch(Estimate::zero())
                    } else {
                        effect
                    });
                }
                let Some(receiver) = target.object.clone() else {
                    return sum.plus(Estimate::unknown(format!(
                        "unknown receiver for {method} at {file}:{}",
                        line(expr)
                    )));
                };
                let mut arguments = arguments;
                let mut this = target.clone();
                this.argument_name = Some("\0this".into());
                arguments.push(this);
                let effect = self.call(&receiver, method, file, stack, &arguments);
                sum.plus(if kind(expr) == "Expr_NullsafeMethodCall" {
                    effect.branch(Estimate::zero())
                } else {
                    effect
                })
            }
            "Expr_StaticCall" => {
                if first_class_callable(expr) {
                    let mut sum = Estimate::zero();
                    for selector in [&expr["class"], &expr["name"]] {
                        if kind(selector).starts_with("Expr_") {
                            sum = sum.plus(self.expr(selector, class, file, env, stack));
                        }
                    }
                    return sum;
                }
                let (sum, arguments) = self.args(expr, class, file, env, stack);
                if is_name(&expr["class"]) && kind(&expr["name"]) == "Identifier" {
                    let scope = string(&expr["class"]["name"]).to_ascii_lowercase();
                    let receiver = match scope.as_str() {
                        "self" => (!class.is_empty()).then(|| class.to_owned()),
                        "parent" => self.program.parents.get(class).cloned(),
                        "static" => None,
                        _ => Some(name(&expr["class"])),
                    };
                    let Some(receiver) = receiver else {
                        return sum.plus(Estimate::unknown(format!(
                            "unresolved static scope {scope} at {file}:{}",
                            line(expr)
                        )));
                    };
                    sum.plus(self.call(
                        &receiver,
                        string(&expr["name"]["name"]),
                        file,
                        stack,
                        &arguments,
                    ))
                } else {
                    sum.plus(Estimate::unknown(format!(
                        "dynamic static call at {file}:{}",
                        line(expr)
                    )))
                }
            }
            "Expr_AssignRef" => {
                let sum = self.expr(&expr["expr"], class, file, env, stack);
                invalidate_target(&expr["var"], env);
                invalidate_target(&expr["expr"], env);
                sum.plus(Estimate::unknown(format!(
                    "reference alias assignment at {file}:{}",
                    line(expr)
                )))
            }
            "Expr_Assign" => {
                let mut sum = Estimate::zero();
                if kind(&expr["var"]) == "Expr_PropertyFetch" {
                    sum = sum.plus(self.expr(&expr["var"]["var"], class, file, env, stack));
                    sum = sum.plus(self.property_effect(&expr["var"], true, class, file, env));
                }
                sum = sum.plus(self.expr(&expr["expr"], class, file, env, stack));
                if kind(&expr["var"]) == "Expr_Variable" && expr["var"]["name"].is_string() {
                    let key = string(&expr["var"]["name"]).to_owned();
                    let bound = self.bound(&expr["expr"], class, file, env);
                    env.remove(&key);
                    if let Some(ty) = &bound.object {
                        env.insert(key.clone(), ty.clone());
                    }
                    env.values.insert(key, bound);
                } else {
                    invalidate_target(&expr["var"], env);
                }
                sum
            }
            "Expr_Ternary" => {
                let condition = self.expr(&expr["cond"], class, file, env, stack);
                let yes = if expr["if"].is_null() {
                    Estimate::zero()
                } else {
                    self.expr(&expr["if"], class, file, &mut env.clone(), stack)
                };
                let no = self.expr(&expr["else"], class, file, &mut env.clone(), stack);
                invalidate_assignments(expr, env);
                condition.plus(yes.branch(no))
            }
            "Expr_New" => {
                let (sum, arguments) = self.args(expr, class, file, env, stack);
                if is_name(&expr["class"])
                    && self
                        .program
                        .method(&name(&expr["class"]), "__construct")
                        .is_some()
                {
                    sum.plus(self.method_bound(
                        &name(&expr["class"]),
                        "__construct",
                        stack,
                        &arguments,
                    ))
                } else {
                    sum
                }
            }
            "Expr_FuncCall" => {
                if first_class_callable(expr) {
                    return if kind(&expr["name"]).starts_with("Expr_") {
                        self.expr(&expr["name"], class, file, env, stack)
                    } else {
                        Estimate::zero()
                    };
                }
                let mut sum = if !is_name(&expr["name"]) {
                    self.expr(&expr["name"], class, file, env, stack)
                } else {
                    Estimate::zero()
                };
                let target = if !is_name(&expr["name"]) {
                    self.bound(&expr["name"], class, file, env)
                } else {
                    Bound::default()
                };
                let (argument_effects, arguments) = self.args(expr, class, file, env, stack);
                sum = sum.plus(argument_effects);
                if !is_name(&expr["name"]) {
                    return sum.plus(self.invoke(
                        target.callable.as_deref(),
                        &arguments,
                        stack,
                        file,
                    ));
                }
                let ns = &expr["name"]["attributes"]["namespacedName"];
                let candidates = if is_name(ns) {
                    vec![name(ns), string(&expr["name"]["name"]).into()]
                } else {
                    vec![name(&expr["name"])]
                };
                for candidate in &candidates {
                    if self
                        .program
                        .ambiguous_functions
                        .contains_key(&candidate.to_ascii_lowercase())
                    {
                        return sum.plus(Estimate::unknown(format!(
                            "ambiguous function call {candidate} at {file}:{}",
                            line(expr)
                        )));
                    }
                    if self
                        .program
                        .functions
                        .contains_key(&candidate.to_ascii_lowercase())
                    {
                        return sum.plus(self.function_bound(candidate, stack, &arguments));
                    }
                }
                if let Some(effect) = self.extended_builtin(expr, file, env, stack, &arguments) {
                    return sum.plus(effect);
                }
                if let Some(effect) =
                    self.callback_builtin(expr, class, file, env, stack, &arguments)
                {
                    return sum.plus(effect);
                }
                if self.scalar_builtin(expr, class, file, env, &arguments)
                    || self.array_builtin(expr, class)
                {
                    return sum;
                }
                sum.plus(Estimate::unknown(format!(
                    "unresolved function call {} at {file}:{}",
                    candidates.join(" or "),
                    line(expr)
                )))
            }
            _ => {
                if kind(expr).starts_with("Expr_AssignOp") {
                    invalidate_target(&expr["var"], env);
                }
                let mut sum = Estimate::zero();
                if let Some(object) = expr.as_object() {
                    for (key, value) in object {
                        if key == "attributes" {
                            continue;
                        }
                        if kind(value).starts_with("Expr_") {
                            sum = sum.plus(self.expr(value, class, file, env, stack));
                        }
                        for part in array(value) {
                            if kind(part).starts_with("Expr_") && kind(part) != "Expr_ArrayItem" {
                                sum = sum.plus(self.expr(part, class, file, env, stack));
                            } else if ["Arg", "ArrayItem", "Expr_ArrayItem"].contains(&kind(part)) {
                                sum = sum.plus(self.expr(&part["value"], class, file, env, stack));
                            }
                        }
                    }
                }
                sum
            }
        }
    }
    fn bind_parameters(
        &mut self,
        node: &Value,
        arguments: &[Bound],
        scope: &mut Scope,
        class: &str,
        file: &str,
    ) {
        for (index, parameter) in array(&node["params"]).iter().enumerate() {
            let key = string(&parameter["var"]["name"]);
            // Parameter declarations shadow captures even when the caller is unknown.
            scope.remove(key);
            scope.values.remove(key);
            if is_name(&parameter["type"]) {
                scope.insert(key.into(), name(&parameter["type"]));
            }
            if parameter["byRef"] == true || parameter["variadic"] == true {
                continue;
            }
            let default = if !parameter["default"].is_null()
                && !arguments
                    .iter()
                    .any(|arg| arg.argument_name.as_deref() == Some("*"))
            {
                Some(self.bound(&parameter["default"], class, file, &Scope::default()))
            } else {
                None
            };
            if let Some(value) = argument(arguments, index, key).or(default.as_ref()) {
                if let Some(ty) = &value.object {
                    scope.insert(key.into(), ty.clone());
                }
                scope.values.insert(key.into(), value.clone());
            }
        }
    }

    fn bound_args(&mut self, node: &Value, class: &str, file: &str, env: &Scope) -> Vec<Bound> {
        // Unpacking may replace defaults or named slots; retain unknown argument presence.
        if array(&node["args"]).iter().any(|arg| arg["unpack"] == true) {
            return vec![Bound {
                argument_name: Some("*".into()),
                ..Bound::default()
            }];
        }
        array(&node["args"])
            .iter()
            .map(|arg| {
                let mut value = self.bound(&arg["value"], class, file, env);
                value.argument_name = arg["name"]["name"].as_str().map(str::to_owned);
                value
            })
            .collect()
    }
    fn bound(&mut self, node: &Value, class: &str, file: &str, env: &Scope) -> Bound {
        if self.bound_depth >= 48 {
            return Bound::default();
        }
        self.bound_depth += 1;
        let result = self.bound_inner(node, class, file, env);
        self.bound_depth -= 1;
        result
    }
    fn bound_inner(&mut self, node: &Value, class: &str, file: &str, env: &Scope) -> Bound {
        if kind(node) == "Expr_PropertyFetch" && kind(&node["name"]) == "Identifier" {
            let holder = self.bound(&node["var"], class, file, env);
            if let Some(value) = holder
                .properties
                .as_ref()
                .and_then(|props| props.get(string(&node["name"]["name"])))
            {
                return value.clone();
            }
        }
        if kind(node) == "Expr_Assign" {
            return if kind(&node["var"]) == "Expr_Variable" {
                env.values
                    .get(string(&node["var"]["name"]))
                    .cloned()
                    .unwrap_or_default()
            } else {
                Bound::default()
            };
        }
        if kind(node) == "Expr_Array" && contains_assignment(node) {
            return Bound {
                count: literal_count(node),
                ..Bound::default()
            };
        }
        if !first_class_callable(node)
            && !contains_assignment(&node["args"])
            && let Some(value) = self.special_bound(node, class, file, env)
        {
            return value;
        }
        let constant =
            if kind(node) == "Expr_ClassConstFetch" && kind(&node["name"]) == "Identifier" {
                self.class_name(&node["class"], class).map(|owner| {
                    format!(
                        "{}::{}",
                        owner.to_ascii_lowercase(),
                        string(&node["name"]["name"])
                    )
                })
            } else if kind(node) == "Expr_ConstFetch" {
                Some(name(&node["name"]))
            } else {
                None
            };
        if let Some(model) = constant.and_then(|key| self.program.constants.get(&key)) {
            return self.bound(
                &model["node"],
                string(&model["class"]),
                string(&model["file"]),
                &Scope::default(),
            );
        }
        if kind(node) == "Expr_Variable"
            && let Some(value) = env.values.get(string(&node["name"]))
        {
            let mut value = value.clone();
            value.argument_name = None;
            return value;
        }
        if kind(node) == "Expr_StaticCall"
            && !first_class_callable(node)
            && self
                .class_name(&node["class"], class)
                .is_some_and(|owner| owner.eq_ignore_ascii_case("Closure"))
            && string(&node["name"]["name"]).eq_ignore_ascii_case("fromCallable")
            && array(&node["args"]).len() == 1
            && node["args"][0]["unpack"] != true
        {
            return self.bound(&node["args"][0]["value"], class, file, env);
        }
        if !first_class_callable(node) && !contains_assignment(&node["args"]) {
            let model = if ["Expr_MethodCall", "Expr_StaticCall"].contains(&kind(node))
                && kind(&node["name"]) == "Identifier"
            {
                let owner = if kind(node) == "Expr_MethodCall" {
                    self.receiver(&node["var"], class, file, env)
                } else {
                    self.class_name(&node["class"], class)
                };
                owner
                    .and_then(|owner| self.program.method(&owner, string(&node["name"]["name"])))
                    .map(|m| (m.node.clone(), m.class, m.file.to_owned()))
            } else {
                None
            };
            if let Some((model, owner, model_file)) = model {
                let args = self.bound_args(node, class, file, env);
                let mut scope = Scope::default();
                scope.insert("this".into(), owner.clone());
                self.bind_parameters(&model, &args, &mut scope, &owner, &model_file);
                if let [statement] = array(&model["stmts"])
                    && kind(statement) == "Stmt_Return"
                    && !statement["expr"].is_null()
                {
                    let result = self.bound(&statement["expr"], &owner, &model_file, &scope);
                    if result.callable.is_some() {
                        return result;
                    }
                }
            }
        }
        let callable = match kind(node) {
            "Expr_Closure" | "Expr_ArrowFunction" => {
                let mut scope = Scope::default();
                if kind(node) == "Expr_ArrowFunction" {
                    let mut used = Vec::new();
                    variables(&node["expr"], &mut used);
                    for key in used {
                        if let Some(ty) = env.get(&key) {
                            scope.insert(key.clone(), ty.clone());
                        }
                        if let Some(value) = env.values.get(&key) {
                            scope.values.insert(key, value.clone());
                        }
                    }
                }
                if node["static"] != true {
                    if let Some(this) = env.get("this") {
                        scope.insert("this".into(), this.clone());
                    }
                } else {
                    scope.remove("this");
                    scope.values.remove("this");
                }
                for capture in array(&node["uses"]) {
                    // A by-reference capture may have changed before invocation.
                    if capture["byRef"] == true {
                        return Bound::default();
                    }
                    let key = string(&capture["var"]["name"]);
                    if let Some(ty) = env.get(key) {
                        scope.insert(key.into(), ty.clone());
                    }
                    if let Some(value) = env.values.get(key) {
                        scope.values.insert(key.into(), value.clone());
                    }
                }
                Some(Callable::Closure {
                    node: node.clone(),
                    scope,
                    class: class.into(),
                    file: file.into(),
                })
            }
            "Expr_MethodCall"
                if first_class_callable(node) && kind(&node["name"]) == "Identifier" =>
            {
                self.receiver(&node["var"], class, file, env)
                    .map(|receiver| {
                        Callable::Method(receiver, string(&node["name"]["name"]).into())
                    })
            }
            "Expr_StaticCall"
                if first_class_callable(node) && kind(&node["name"]) == "Identifier" =>
            {
                self.class_name(&node["class"], class).map(|receiver| {
                    Callable::Method(receiver, string(&node["name"]["name"]).into())
                })
            }
            "Expr_FuncCall" if first_class_callable(node) => {
                if is_name(&node["name"]) {
                    let ns = &node["name"]["attributes"]["namespacedName"];
                    let scoped = name(ns);
                    Some(Callable::Function(if !scoped.is_empty() {
                        scoped
                    } else {
                        name(&node["name"])
                    }))
                } else {
                    self.bound(&node["name"], class, file, env)
                        .callable
                        .map(|c| (*c).clone())
                }
            }
            "Scalar_String" => {
                let value = string(&node["value"]).trim_start_matches('\\');
                Some(if let Some((owner, method)) = value.split_once("::") {
                    Callable::Method(owner.into(), method.into())
                } else {
                    Callable::Function(value.into())
                })
            }
            "Expr_Array"
                if array(&node["items"]).len() == 2
                    && array(&node["items"])
                        .iter()
                        .all(|item| item["unpack"] != true && item["key"].is_null()) =>
            {
                let owner = &node["items"][0]["value"];
                let method = &node["items"][1]["value"];
                let receiver = if kind(owner) == "Scalar_String" {
                    Some(string(&owner["value"]).into())
                } else if kind(owner) == "Expr_ClassConstFetch"
                    && string(&owner["name"]["name"]).eq_ignore_ascii_case("class")
                {
                    self.class_name(&owner["class"], class)
                } else {
                    self.receiver(owner, class, file, env)
                };
                if kind(method) == "Scalar_String" {
                    receiver
                        .map(|receiver| Callable::Method(receiver, string(&method["value"]).into()))
                } else {
                    None
                }
            }
            _ => None,
        };
        let object = self.receiver(node, class, file, env);
        let callable = callable.or_else(|| {
            object
                .as_ref()
                .filter(|ty| self.program.method(ty, "__invoke").is_some())
                .map(|ty| Callable::Method(ty.clone(), "__invoke".into()))
        });
        let count = literal_count(node);
        let element = if count.is_some_and(|count| count > 0) {
            let items: Vec<_> = array(&node["items"])
                .iter()
                .map(|item| self.bound(&item["value"], class, file, env))
                .collect();
            let same_object = items
                .first()
                .and_then(|first| first.object.clone())
                .filter(|ty| items.iter().all(|item| item.object.as_ref() == Some(ty)));
            Some(Box::new(Bound {
                scalar: items.iter().all(|item| item.scalar),
                object: same_object,
                ..Bound::default()
            }))
        } else {
            None
        };
        let scalar = ["Scalar_String", "Scalar_Int", "Scalar_Float"].contains(&kind(node))
            || kind(node) == "Expr_ConstFetch"
                && ["null", "true", "false"]
                    .contains(&name(&node["name"]).to_ascii_lowercase().as_str())
            || kind(node) == "Expr_Variable" && self.metadata(file, node)["scalar"] == true;
        Bound {
            argument_name: None,
            object,
            callable: callable.map(std::sync::Arc::new),
            count,
            scalar,
            element,
            members: if safe_array_members(node) {
                Some(std::sync::Arc::new(
                    array(&node["items"])
                        .iter()
                        .map(|item| {
                            let mut value = self.bound(&item["value"], class, file, env);
                            value.argument_name = item["key"]["value"].as_str().map(str::to_owned);
                            value
                        })
                        .collect(),
                ))
            } else {
                None
            },
            ..Bound::default()
        }
    }
    fn class_name(&self, node: &Value, class: &str) -> Option<String> {
        if !is_name(node) {
            return None;
        }
        match name(node).to_ascii_lowercase().as_str() {
            "self" => (!class.is_empty()).then(|| class.into()),
            "parent" => self.program.parent(class).cloned(),
            "static" => None,
            _ => Some(name(node)),
        }
    }
    fn invoke(
        &mut self,
        target: Option<&Callable>,
        arguments: &[Bound],
        stack: &[String],
        file: &str,
    ) -> Estimate {
        match target {
            Some(Callable::Method(owner, method)) => {
                let mut arguments = arguments.to_vec();
                if !arguments
                    .iter()
                    .any(|arg| arg.argument_name.as_deref() == Some("\0this"))
                {
                    arguments.push(Bound {
                        object: Some(owner.clone()),
                        argument_name: Some("\0this".into()),
                        ..Bound::default()
                    });
                }
                self.call(owner, method, file, stack, &arguments)
            }
            Some(Callable::Function(name)) => {
                let builtin = name.to_ascii_lowercase();
                if !self.program.functions.contains_key(&builtin)
                    && !self.program.ambiguous_functions.contains_key(&builtin)
                    && pure_scalar_callback(&builtin, arguments)
                {
                    Estimate::zero()
                } else {
                    self.function_bound(name, stack, arguments)
                }
            }
            Some(Callable::Closure {
                node,
                scope,
                class,
                file,
            }) => {
                if has_yield(node) && !std::mem::take(&mut self.consume_generator) {
                    return Estimate::zero();
                }
                let key = format!("closure:{file}:{}", node["attributes"]["startFilePos"]);
                if stack.contains(&key) {
                    return Estimate::unknown(format!(
                        "callback recursion has unknown invocation bound: {key}"
                    ));
                }
                if stack.len() >= 48 {
                    return Estimate::unknown("call depth limit".into());
                }
                let mut next = stack.to_vec();
                next.push(key);
                let mut scope = scope.clone();
                self.bind_parameters(node, arguments, &mut scope, class, file);
                if kind(node) == "Expr_ArrowFunction" {
                    self.expr(&node["expr"], class, file, &mut scope, &next)
                } else {
                    self.statements(array(&node["stmts"]), class, file, scope, &next)
                }
            }
            None => Estimate::unknown(format!("unresolved callback invocation at {file}")),
        }
    }
    fn property_effect(
        &mut self,
        node: &Value,
        write: bool,
        class: &str,
        file: &str,
        env: &Scope,
    ) -> Estimate {
        let Some(owner) = self.receiver(&node["var"], class, file, env) else {
            return Estimate::unknown(format!("unresolved property hooks at {file}"));
        };
        let property = string(&node["name"]["name"]);
        let mut current = Some(owner);
        for _ in 0..48 {
            let Some(owner) = current else {
                break;
            };
            if self
                .program
                .property_hooks
                .get(&owner)
                .is_some_and(|names| names.iter().any(|name| name == property))
                || self
                    .program
                    .method(&owner, if write { "__set" } else { "__get" })
                    .is_some()
                || self.program.method(&owner, "__isset").is_some()
                || self.program.method(&owner, "__unset").is_some()
            {
                return Estimate::unknown(format!(
                    "property or magic access hooks for {owner}::{property} at {file}"
                ));
            }
            current = self.program.parent(&owner).cloned();
        }
        Estimate::zero()
    }
    fn selected_callable(
        &mut self,
        node: &Value,
        class: &str,
        file: &str,
        env: &Scope,
    ) -> Option<Callable> {
        match kind(node) {
            "Expr_MethodCall" if kind(&node["name"]) == "Identifier" => self
                .receiver(&node["var"], class, file, env)
                .map(|owner| Callable::Method(owner, string(&node["name"]["name"]).into())),
            "Expr_StaticCall" if kind(&node["name"]) == "Identifier" => self
                .class_name(&node["class"], class)
                .map(|owner| Callable::Method(owner, string(&node["name"]["name"]).into())),
            "Expr_FuncCall" if is_name(&node["name"]) => {
                let ns = name(&node["name"]["attributes"]["namespacedName"]);
                Some(Callable::Function(
                    if self
                        .program
                        .functions
                        .contains_key(&ns.to_ascii_lowercase())
                    {
                        ns
                    } else {
                        name(&node["name"])
                    },
                ))
            }
            "Expr_FuncCall" => self
                .bound(&node["name"], class, file, env)
                .callable
                .map(|c| (*c).clone()),
            _ => None,
        }
    }
    fn callable_yields(&self, target: &Callable) -> bool {
        match target {
            Callable::Method(owner, method) => self
                .program
                .method(owner, method)
                .is_some_and(|m| has_yield(m.node)),
            Callable::Function(name) => self
                .program
                .functions
                .get(&name.to_ascii_lowercase())
                .is_some_and(|m| has_yield(&m["node"])),
            Callable::Closure { node, .. } => has_yield(node),
        }
    }
    fn special_bound(
        &mut self,
        node: &Value,
        class: &str,
        file: &str,
        env: &Scope,
    ) -> Option<Bound> {
        if let Some(target) = self.selected_callable(node, class, file, env)
            && self.callable_yields(&target)
        {
            return Some(Bound {
                object: Some("Generator".into()),
                deferred: Some(std::sync::Arc::new(Deferred {
                    target,
                    arguments: self.bound_args(node, class, file, env),
                })),
                ..Bound::default()
            });
        }
        if ["Expr_StaticCall", "Expr_MethodCall"].contains(&kind(node)) {
            let method = string(&node["name"]["name"]).to_ascii_lowercase();
            let args = self.bound_args(node, class, file, env);
            let target = if kind(node) == "Expr_StaticCall"
                && self
                    .class_name(&node["class"], class)
                    .is_some_and(|c| c.eq_ignore_ascii_case("Closure"))
                && method == "bind"
            {
                argument(&args, 0, "closure").cloned()
            } else if kind(node) == "Expr_MethodCall" && method == "bindto" {
                Some(self.bound(&node["var"], class, file, env))
            } else {
                None
            };
            if let Some(target) = target {
                let position = usize::from(kind(node) == "Expr_StaticCall");
                if argument(&args, position + 1, "newScope").is_some() {
                    return Some(Bound::default());
                }
                return Some(self.rebind(&target, argument(&args, position, "newThis"), false));
            }
        }
        if kind(node) == "Expr_New" {
            let owner = self.class_name(&node["class"], class)?;
            let args = self.bound_args(node, class, file, env);
            let reflection = if owner.eq_ignore_ascii_case("ReflectionFunction") {
                argument(&args, 0, "function").and_then(|v| v.callable.clone())
            } else if owner.eq_ignore_ascii_case("ReflectionMethod") {
                let first = &node["args"][0]["value"];
                let ty = argument(&args, 0, "objectOrMethod")
                    .and_then(|v| v.object.clone())
                    .or_else(|| {
                        if kind(first) == "Scalar_String" {
                            Some(string(&first["value"]).into())
                        } else if kind(first) == "Expr_ClassConstFetch" {
                            self.class_name(&first["class"], class)
                        } else {
                            None
                        }
                    });
                ty.and_then(|ty| {
                    node["args"][1]["value"]["value"]
                        .as_str()
                        .map(|name| std::sync::Arc::new(Callable::Method(ty, name.into())))
                })
            } else {
                let constructor = self.program.method(&owner, "__construct")?;
                if !array(&constructor.node["stmts"]).is_empty() {
                    return None;
                }
                let mut properties = BTreeMap::new();
                for (index, param) in array(&constructor.node["params"]).iter().enumerate() {
                    if param["flags"].as_i64().unwrap_or(0) & 64 == 0 {
                        continue;
                    }
                    let key = string(&param["var"]["name"]);
                    if let Some(value) = argument(&args, index, key) {
                        properties.insert(key.to_owned(), value.clone());
                    }
                }
                return Some(Bound {
                    object: Some(owner),
                    properties: Some(std::sync::Arc::new(properties)),
                    ..Bound::default()
                });
            };
            return Some(Bound {
                object: Some(owner),
                reflection,
                ..Bound::default()
            });
        }
        None
    }
    fn rebind(&self, target: &Bound, receiver: Option<&Bound>, change_scope: bool) -> Bound {
        if let Some(Callable::Closure {
            node,
            scope,
            class,
            file,
        }) = target.callable.as_deref()
            && node["static"] != true
        {
            let mut scope = scope.clone();
            scope.remove("this");
            scope.values.remove("this");
            if let Some(receiver) = receiver
                && let Some(ty) = &receiver.object
            {
                scope.insert("this".into(), ty.clone());
            }
            return Bound {
                callable: Some(std::sync::Arc::new(Callable::Closure {
                    node: node.clone(),
                    scope,
                    class: if change_scope {
                        receiver.and_then(|v| v.object.clone()).unwrap_or_default()
                    } else {
                        class.clone()
                    },
                    file: file.clone(),
                })),
                ..Bound::default()
            };
        }
        Bound::default()
    }
    fn special_method(
        &mut self,
        target: &Bound,
        method: &str,
        args: &[Bound],
        stack: &[String],
        file: &str,
    ) -> Option<Estimate> {
        if target.callable.is_some() {
            if method.eq_ignore_ascii_case("bindTo") {
                return Some(Estimate::zero());
            }
            if method.eq_ignore_ascii_case("call") {
                let rebound = self.rebind(target, argument(args, 0, "newThis"), true);
                return Some(self.invoke(
                    rebound.callable.as_deref(),
                    args.get(1..).unwrap_or(&[]),
                    stack,
                    file,
                ));
            }
        }
        if let Some(callback) = target.reflection.as_deref() {
            let offset = usize::from(
                target
                    .object
                    .as_deref()
                    .is_some_and(|c| c.eq_ignore_ascii_case("ReflectionMethod")),
            );
            let mut values = if method.eq_ignore_ascii_case("invoke") {
                args.get(offset..).unwrap_or(&[]).to_vec()
            } else if method.eq_ignore_ascii_case("invokeArgs") {
                argument(args, offset, "args")
                    .and_then(|v| v.members.as_deref())
                    .cloned()
                    .unwrap_or_else(|| {
                        vec![Bound {
                            argument_name: Some("*".into()),
                            ..Bound::default()
                        }]
                    })
            } else {
                return None;
            };
            if offset == 1
                && let Some(receiver) = argument(args, 0, "object")
            {
                let mut receiver = receiver.clone();
                receiver.argument_name = Some("\0this".into());
                values.push(receiver);
            }
            return Some(self.invoke(Some(callback), &values, stack, file));
        }
        None
    }
    fn consume(&mut self, value: &Bound, stack: &[String], file: &str) -> Estimate {
        if let Some(deferred) = &value.deferred {
            if self
                .consumed
                .iter()
                .any(|seen| std::sync::Arc::ptr_eq(seen, deferred))
            {
                return Estimate::unknown(format!(
                    "generator already consumed or advanced at {file}"
                ));
            }
            self.consumed.push(deferred.clone());
            self.consume_generator = true;
            let effect = self.invoke(Some(&deferred.target), &deferred.arguments, stack, file);
            self.consume_generator = false;
            return effect;
        }
        if value.members.is_some() || value.count.is_some() {
            return Estimate::zero();
        }
        Estimate::unknown(format!("unresolved iterator execution at {file}"))
    }
    fn repeated_callback(
        &mut self,
        target: Option<&Callable>,
        args: &[Bound],
        builtin: &str,
        stack: &[String],
        file: &str,
    ) -> Estimate {
        self.uncertain_callback += 1;
        let mut effect = self.invoke(target, args, stack, file);
        self.uncertain_callback -= 1;
        if effect.upper != Some(0) || !effect.unknown.is_empty() {
            effect.lower = 0;
            effect.upper = None;
            effect = effect.plus(Estimate::unknown(format!(
                "unknown callback invocation count for {builtin} at {file}"
            )));
        }
        effect
    }
    fn recursive_walk(
        &mut self,
        value: &Bound,
        callback: Option<&Callable>,
        extra: &Bound,
        stack: &[String],
        file: &str,
    ) -> Estimate {
        let mut extra = extra.clone();
        extra.argument_name = None;
        if let Some(members) = &value.members {
            let mut result = Estimate::zero();
            for member in members.iter() {
                result = result.plus(if member.members.is_some() {
                    self.recursive_walk(member, callback, &extra, stack, file)
                } else if !member.scalar && member.object.is_none() {
                    self.repeated_callback(
                        callback,
                        &[
                            Bound::default(),
                            Bound {
                                scalar: true,
                                ..Bound::default()
                            },
                            extra.clone(),
                        ],
                        "array_walk_recursive",
                        stack,
                        file,
                    )
                } else {
                    self.uncertain_callback += 1;
                    let mut member = member.clone();
                    member.argument_name = None;
                    let effect = self.invoke(
                        callback,
                        &[
                            member,
                            Bound {
                                scalar: true,
                                ..Bound::default()
                            },
                            extra.clone(),
                        ],
                        stack,
                        file,
                    );
                    self.uncertain_callback -= 1;
                    effect
                });
            }
            result
        } else {
            self.repeated_callback(
                callback,
                &[
                    Bound::default(),
                    Bound {
                        scalar: true,
                        ..Bound::default()
                    },
                    extra.clone(),
                ],
                "array_walk_recursive",
                stack,
                file,
            )
        }
    }
    fn extended_builtin(
        &mut self,
        node: &Value,
        file: &str,
        env: &mut Scope,
        stack: &[String],
        args: &[Bound],
    ) -> Option<Estimate> {
        let builtin = name(&node["name"]).to_ascii_lowercase();
        if kind(&node["name"]) != "Name_FullyQualified"
            && name(&node["name"]["attributes"]["namespacedName"]).contains('\\')
        {
            return None;
        }
        let sort = ["usort", "uasort", "uksort"].contains(&builtin.as_str());
        let compare = [
            "array_udiff",
            "array_udiff_assoc",
            "array_udiff_uassoc",
            "array_uintersect",
            "array_uintersect_assoc",
            "array_uintersect_uassoc",
            "array_diff_uassoc",
            "array_diff_ukey",
            "array_intersect_uassoc",
            "array_intersect_ukey",
        ]
        .contains(&builtin.as_str());
        let registration = [
            "register_shutdown_function",
            "set_error_handler",
            "set_exception_handler",
            "spl_autoload_register",
        ]
        .contains(&builtin.as_str());
        if !(sort
            || compare
            || registration
            || [
                "array_walk_recursive",
                "preg_replace_callback",
                "preg_replace_callback_array",
                "iterator_apply",
                "iterator_count",
                "iterator_to_array",
            ]
            .contains(&builtin.as_str()))
        {
            return None;
        }
        if args.iter().any(|a| a.argument_name.as_deref() == Some("*")) {
            return Some(Estimate::unknown(format!(
                "unresolved callback arguments to {builtin}"
            )));
        }
        if registration {
            return Some(Estimate::unknown(format!(
                "deferred callback registration via {builtin} at {file}"
            )));
        }
        let empty = Bound::default();
        let first = argument(
            args,
            0,
            if builtin.starts_with("iterator_") {
                "iterator"
            } else {
                "array"
            },
        )
        .unwrap_or(&empty);
        if sort || builtin == "array_walk_recursive" {
            for (index, arg) in array(&node["args"]).iter().enumerate() {
                if (index == 0 && arg["name"].is_null()) || arg["name"]["name"] == "array" {
                    invalidate_target(&arg["value"], env);
                }
            }
        }
        if builtin == "iterator_count" || builtin == "iterator_to_array" {
            return Some(self.consume(first, stack, file));
        }
        if builtin == "iterator_apply" {
            let mut effect = self.consume(first, stack, file);
            effect.lower = 0;
            let values = argument(args, 2, "args")
                .and_then(|a| a.members.as_deref())
                .cloned()
                .unwrap_or_default();
            return Some(effect.plus(self.repeated_callback(
                argument(args, 1, "callback").and_then(|a| a.callable.as_deref()),
                &values,
                &builtin,
                stack,
                file,
            )));
        }
        if builtin == "array_walk_recursive" {
            return Some(self.recursive_walk(
                first,
                argument(args, 1, "callback").and_then(|a| a.callable.as_deref()),
                argument(args, 2, "arg").unwrap_or(&empty),
                stack,
                file,
            ));
        }
        if sort {
            if first.count.is_some_and(|n| n < 2) {
                return Some(Estimate::zero());
            }
            let value = if builtin == "uksort" {
                Bound {
                    scalar: true,
                    ..Bound::default()
                }
            } else {
                first.element.as_deref().cloned().unwrap_or_default()
            };
            return Some(self.repeated_callback(
                argument(args, 1, "callback").and_then(|a| a.callable.as_deref()),
                &[value.clone(), value],
                &builtin,
                stack,
                file,
            ));
        }
        if compare {
            let count = if builtin.ends_with("_uassoc")
                && (builtin.starts_with("array_udiff") || builtin.starts_with("array_uintersect"))
            {
                2
            } else {
                1
            };
            let mut effect = Estimate::zero();
            for target in args.iter().skip(args.len().saturating_sub(count)) {
                effect = effect.plus(self.repeated_callback(
                    target.callable.as_deref(),
                    &[Bound::default(), Bound::default()],
                    &builtin,
                    stack,
                    file,
                ));
            }
            if args
                .iter()
                .take(args.len().saturating_sub(count))
                .any(|a| a.count != Some(0) && !a.element.as_deref().is_some_and(|v| v.scalar))
            {
                effect = effect.plus(Estimate::unknown(format!(
                    "opaque comparison values for {builtin} at {file}"
                )));
            }
            return Some(effect);
        }
        if builtin == "preg_replace_callback_array" {
            let callbacks = argument(args, 0, "pattern").and_then(|v| v.members.as_deref());
            let Some(callbacks) = callbacks else {
                return Some(Estimate::unknown(format!(
                    "unresolved callback map for {builtin} at {file}"
                )));
            };
            let mut effect = Estimate::zero();
            for callback in callbacks {
                effect = effect.plus(self.repeated_callback(
                    callback.callable.as_deref(),
                    &[Bound::default()],
                    &builtin,
                    stack,
                    file,
                ));
            }
            return Some(effect.plus(Estimate::unknown(format!(
                "regular expression callback result conversion at {file}"
            ))));
        }
        Some(
            self.repeated_callback(
                argument(args, 1, "callback").and_then(|a| a.callable.as_deref()),
                &[Bound::default()],
                &builtin,
                stack,
                file,
            )
            .plus(Estimate::unknown(format!(
                "regular expression callback result conversion at {file}"
            ))),
        )
    }

    fn callback_builtin(
        &mut self,
        node: &Value,
        _class: &str,
        file: &str,
        env: &mut Scope,
        stack: &[String],
        evaluated: &[Bound],
    ) -> Option<Estimate> {
        let builtin = name(&node["name"]).to_ascii_lowercase();
        if ![
            "call_user_func",
            "call_user_func_array",
            "array_map",
            "array_filter",
            "array_walk",
            "array_reduce",
            "array_any",
            "array_all",
            "array_find",
            "array_find_key",
        ]
        .contains(&builtin.as_str())
        {
            return None;
        }
        // A qualified name or explicit import proves the builtin. Namespaced
        // fallback can be overridden by functions outside the source snapshot.
        if kind(&node["name"]) != "Name_FullyQualified"
            && name(&node["name"]["attributes"]["namespacedName"]).contains('\\')
        {
            return None;
        }
        if array(&node["args"]).iter().any(|arg| arg["unpack"] == true) {
            return Some(Estimate::unknown(format!(
                "unresolved callback arguments to {builtin}"
            )));
        }
        let normalized = normalize_callback_arguments(node, &builtin);
        let node = &normalized;
        let arguments = normalize_bound_arguments(evaluated, &builtin);
        if builtin == "array_walk" && kind(&node["args"][0]["value"]) == "Expr_Variable" {
            let key = string(&node["args"][0]["value"]["name"]);
            env.remove(key);
            env.values.remove(key);
        }
        let callback_position = if ["call_user_func", "call_user_func_array", "array_map"]
            .contains(&builtin.as_str())
        {
            0
        } else {
            1
        };
        let target = arguments
            .get(callback_position)
            .and_then(|b| b.callable.as_deref());
        if builtin == "call_user_func" {
            return Some(self.invoke(target, arguments.get(1..).unwrap_or(&[]), stack, file));
        }
        if builtin == "call_user_func_array" {
            let values = arguments
                .get(1)
                .and_then(|v| v.members.as_deref())
                .cloned()
                .unwrap_or_else(|| {
                    vec![Bound {
                        argument_name: Some("*".into()),
                        ..Bound::default()
                    }]
                });
            return Some(self.invoke(target, &values, stack, file));
        }
        if builtin == "array_filter" && arguments.len() < 2 {
            return Some(Estimate::zero());
        }
        let count = if builtin == "array_map" {
            if arguments.len() < 2 {
                None
            } else {
                arguments[1..]
                    .iter()
                    .map(|arg| arg.count)
                    .collect::<Option<Vec<_>>>()
                    .and_then(|counts| counts.into_iter().max())
            }
        } else {
            arguments.first().and_then(|arg| arg.count)
        };
        if count == Some(0) {
            return Some(Estimate::zero());
        }
        if builtin == "array_map"
            && kind(&node["args"][0]["value"]) == "Expr_ConstFetch"
            && name(&node["args"][0]["value"]["name"]).eq_ignore_ascii_case("null")
        {
            return Some(Estimate::zero());
        }
        self.uncertain_callback += 1;
        let values = if builtin == "array_map" {
            arguments
                .get(1..)
                .unwrap_or(&[])
                .iter()
                .map(|arg| arg.element.as_deref().cloned().unwrap_or_default())
                .collect()
        } else if builtin == "array_reduce" {
            vec![
                Bound::default(),
                arguments
                    .first()
                    .and_then(|arg| arg.element.as_deref())
                    .cloned()
                    .unwrap_or_default(),
            ]
        } else {
            vec![
                arguments
                    .first()
                    .and_then(|arg| arg.element.as_deref())
                    .cloned()
                    .unwrap_or_default(),
                Bound {
                    scalar: true,
                    ..Bound::default()
                },
            ]
        };
        let mut effect = self.invoke(target, &values, stack, file);
        self.uncertain_callback -= 1;
        match count {
            Some(count) => {
                effect = effect.times(count);
                if ["array_any", "array_all", "array_find", "array_find_key"]
                    .contains(&builtin.as_str())
                {
                    effect.lower = 0;
                }
            }
            None if effect.upper != Some(0) || !effect.unknown.is_empty() => {
                effect.lower = 0;
                effect.upper = None;
                effect = effect.plus(Estimate::unknown(format!(
                    "unknown callback invocation count for {builtin} at {file}"
                )));
            }
            None => {}
        }
        Some(effect)
    }

    fn metadata(&mut self, file: &str, expr: &Value) -> Value {
        (self.callback)(file, expr)
    }
    fn receiver(&mut self, expr: &Value, class: &str, file: &str, env: &Scope) -> Option<String> {
        match kind(expr) {
            "Expr_Variable" if expr["name"].is_string() => {
                return env.get(string(&expr["name"])).cloned();
            }
            "Expr_PropertyFetch"
                if kind(&expr["var"]) == "Expr_Variable"
                    && expr["var"]["name"] == "this"
                    && kind(&expr["name"]) == "Identifier" =>
            {
                let mut owner = env.get("this")?.clone();
                for _ in 0..48 {
                    if let Some(properties) = self.program.properties.get(&owner)
                        && let Some(ty) = properties.get(string(&expr["name"]["name"]))
                    {
                        return Some(ty.clone());
                    }
                    owner = self.program.parent(&owner)?.clone();
                }
                return None;
            }
            "Expr_New" if is_name(&expr["class"]) => return Some(name(&expr["class"])),
            _ => {}
        }
        if !first_class_callable(expr)
            && ["Expr_MethodCall", "Expr_StaticCall"].contains(&kind(expr))
            && kind(&expr["name"]) == "Identifier"
        {
            let base = if kind(expr) == "Expr_StaticCall" {
                self.class_name(&expr["class"], class)
            } else {
                self.receiver(&expr["var"], class, file, env)
            };
            if let Some(base) = base {
                let method = string(&expr["name"]["name"]);
                if let Some(model) = self.program.method(&base, method) {
                    if is_name(&model.node["returnType"]) {
                        let ty = name(&model.node["returnType"]);
                        return match ty.to_ascii_lowercase().as_str() {
                            "self" => Some(model.class),
                            "static" => Some(base),
                            "parent" => self.program.parent(&model.class).cloned(),
                            _ => Some(ty),
                        };
                    }
                    if self
                        .program
                        .api_type(&base)
                        .is_some_and(|api| !api.eq_ignore_ascii_case(&base))
                    {
                        return self.metadata(file, expr)["object"]
                            .as_str()
                            .map(str::to_owned);
                    }
                }
                if let Some(api) = self.program.api_type(&base) {
                    let method = method.to_ascii_lowercase();
                    let ty = match (api.as_str(), method.as_str()) {
                        ("Doctrine\\DBAL\\Connection", "prepare") => {
                            Some("Doctrine\\DBAL\\Statement")
                        }
                        (
                            "Doctrine\\DBAL\\Connection"
                            | "Doctrine\\DBAL\\Statement"
                            | "Doctrine\\DBAL\\Query\\QueryBuilder",
                            "executequery",
                        ) => Some("Doctrine\\DBAL\\Result"),
                        ("Doctrine\\DBAL\\Connection", "createquerybuilder") => {
                            Some("Doctrine\\DBAL\\Query\\QueryBuilder")
                        }
                        ("Doctrine\\ORM\\QueryBuilder", "getquery") => Some("Doctrine\\ORM\\Query"),
                        (
                            "Doctrine\\ORM\\EntityManagerInterface"
                            | "Doctrine\\ORM\\EntityManager",
                            "createquerybuilder",
                        ) => Some("Doctrine\\ORM\\QueryBuilder"),
                        (
                            "Doctrine\\ORM\\EntityManagerInterface"
                            | "Doctrine\\ORM\\EntityManager",
                            "createquery",
                        ) => Some("Doctrine\\ORM\\Query"),
                        (
                            "Doctrine\\ORM\\EntityManagerInterface"
                            | "Doctrine\\ORM\\EntityManager",
                            "createnativequery",
                        ) => Some("Doctrine\\ORM\\NativeQuery"),
                        (
                            "Doctrine\\DBAL\\Query\\QueryBuilder" | "Doctrine\\ORM\\QueryBuilder",
                            "select" | "addselect" | "from" | "where" | "andwhere" | "orwhere"
                            | "setparameter" | "setmaxresults",
                        ) => Some(api.as_str()),
                        _ => None,
                    };
                    if let Some(ty) = ty {
                        return Some(ty.into());
                    }
                }
            }
        }
        let metadata = self.metadata(file, expr);
        if let Some(object) = metadata["object"].as_str() {
            return Some(object.into());
        }
        None
    }
    fn scalar_builtin(
        &mut self,
        call: &Value,
        class: &str,
        _file: &str,
        _env: &Scope,
        evaluated: &[Bound],
    ) -> bool {
        let builtin = string(&call["name"]["name"]).to_ascii_lowercase();
        if ![
            "mb_trim",
            "max",
            "min",
            "trim",
            "strlen",
            "strtolower",
            "strtoupper",
            "intval",
            "floatval",
            "boolval",
            "strval",
        ]
        .contains(&builtin.as_str())
        {
            return false;
        }
        if kind(&call["name"]) != "Name_FullyQualified" {
            if builtin.contains('\\') || class.is_empty() {
                return false;
            }
            let namespace = class.rsplit_once('\\').map(|(ns, _)| ns);
            let expected =
                namespace.map_or_else(|| builtin.clone(), |ns| format!("{ns}\\{builtin}"));
            if !is_name(&call["name"]["attributes"]["namespacedName"])
                || !name(&call["name"]["attributes"]["namespacedName"])
                    .eq_ignore_ascii_case(&expected)
                || namespace.is_some()
                    && (!self.assume_scalar
                        || !["mb_trim", "max", "min"].contains(&builtin.as_str()))
            {
                return false;
            }
        }
        for (arg, value) in array(&call["args"]).iter().zip(evaluated) {
            if kind(arg) != "Arg" || arg["unpack"] == true || !value.scalar {
                return false;
            }
        }
        true
    }
    fn array_builtin(&self, call: &Value, class: &str) -> bool {
        let builtin = string(&call["name"]["name"]).to_ascii_lowercase();
        if ![
            "array_merge",
            "array_keys",
            "array_values",
            "array_reverse",
            "array_slice",
        ]
        .contains(&builtin.as_str())
        {
            return false;
        }
        if kind(&call["name"]) != "Name_FullyQualified"
            && (builtin.contains('\\')
                || class.contains('\\')
                || !is_name(&call["name"]["attributes"]["namespacedName"])
                || !name(&call["name"]["attributes"]["namespacedName"])
                    .eq_ignore_ascii_case(&builtin))
        {
            return false;
        }
        array(&call["args"])
            .iter()
            .all(|arg| kind(arg) == "Arg" && arg["unpack"] != true)
    }
    fn call(
        &mut self,
        receiver: &str,
        method: &str,
        file: &str,
        stack: &[String],
        arguments: &[Bound],
    ) -> Estimate {
        if receiver.eq_ignore_ascii_case("Closure")
            && ["fromcallable", "bind"].contains(&method.to_ascii_lowercase().as_str())
        {
            return Estimate::zero();
        }
        let api = self.program.api_type(receiver);
        if api
            .as_deref()
            .is_some_and(|api| !api.eq_ignore_ascii_case(receiver))
            && self.program.method(receiver, method).is_some()
        {
            return self.method_bound(receiver, method, stack, arguments);
        }
        let receiver = api.as_deref().unwrap_or(receiver);
        let method_lower = method.to_ascii_lowercase();
        let m = method_lower.as_str();
        if receiver == "Doctrine\\DBAL\\Connection" && m == "transactional"
            || [
                "Doctrine\\ORM\\EntityManager",
                "Doctrine\\ORM\\EntityManagerInterface",
            ]
            .contains(&receiver)
                && m == "wrapintransaction"
        {
            self.uncertain_callback += 1;
            let argument_value = Bound {
                object: Some(receiver.into()),
                ..Bound::default()
            };
            let mut effect = self.invoke(
                argument(arguments, 0, "func").and_then(|arg| arg.callable.as_deref()),
                &[argument_value],
                stack,
                file,
            );
            self.uncertain_callback -= 1;
            effect.lower = 0;
            return effect.plus(Estimate::unknown(
                if m == "transactional" {
                    "Doctrine transaction control and callback invocation bound are not proven"
                } else {
                    "ORM transaction, flush and UnitOfWork effects are not bounded"
                }
                .into(),
            ));
        }
        if [
            "Doctrine\\ORM\\EntityManager",
            "Doctrine\\ORM\\EntityManagerInterface",
        ]
        .contains(&receiver)
            && ["flush", "persist", "remove"].contains(&m)
        {
            return Estimate::unknown(
                "ORM UnitOfWork, lifecycle events and lazy loading are not bounded".into(),
            );
        }

        let query = Estimate {
            lower: 1,
            upper: Some(1),
            unknown: vec![],
            cycles: vec![],
        };
        if [
            "Symfony\\Contracts\\Cache\\CacheInterface",
            "Symfony\\Contracts\\Cache\\TagAwareCacheInterface",
        ]
        .contains(&receiver)
            && m == "get"
        {
            // Cache backends, decorators and retry policies remain opaque; the
            // callback is potentially invoked but a hit guarantees no execution.
            self.uncertain_callback += 1;
            let mut effect = self.invoke(
                argument(arguments, 1, "callback").and_then(|arg| arg.callable.as_deref()),
                &[],
                stack,
                file,
            );
            self.uncertain_callback -= 1;
            effect.lower = 0;
            return effect.plus(Estimate::unknown(
                "Symfony cache backend and callback invocation bound are not proven".into(),
            ));
        }
        match receiver {
            "Doctrine\\DBAL\\Connection"
                if [
                    "executequery",
                    "executestatement",
                    "fetchallassociative",
                    "fetchallnumeric",
                    "fetchallkeyvalue",
                    "fetchallassociativeindexed",
                    "fetchfirstcolumn",
                    "fetchone",
                    "fetchassociative",
                    "fetchvalue",
                    "insert",
                    "update",
                    "delete",
                ]
                .contains(&m) =>
            {
                return query;
            }
            "Doctrine\\DBAL\\Statement"
                if ["execute", "executequery", "executestatement"].contains(&m) =>
            {
                return query;
            }
            "Doctrine\\DBAL\\Result"
                if [
                    "fetchone",
                    "fetchassociative",
                    "fetchallassociative",
                    "fetchallnumeric",
                    "fetchallkeyvalue",
                    "fetchallassociativeindexed",
                    "fetchfirstcolumn",
                    "rowcount",
                    "free",
                ]
                .contains(&m) =>
            {
                return Estimate::zero();
            }
            "Doctrine\\DBAL\\Query\\QueryBuilder"
                if ["executequery", "executestatement"].contains(&m) =>
            {
                return query;
            }
            "Doctrine\\ORM\\Query"
            | "Doctrine\\ORM\\AbstractQuery"
            | "Doctrine\\ORM\\NativeQuery"
                if [
                    "execute",
                    "getresult",
                    "getsingleresult",
                    "getoneornullresult",
                    "getscalarresult",
                    "getsinglescalarresult",
                    "getarrayresult",
                    "toiterable",
                ]
                .contains(&m) =>
            {
                return Estimate::unknown(
                    "ORM cache, hydration and lazy loading are not bounded".into(),
                );
            }
            "Doctrine\\ORM\\EntityRepository"
            | "Doctrine\\Bundle\\DoctrineBundle\\Repository\\ServiceEntityRepository"
                if ["find", "findall", "findby", "findoneby", "count"].contains(&m) =>
            {
                return Estimate::unknown("repository/cache/lazy-loading behavior".into());
            }
            _ => {}
        }
        if self.program.method(receiver, method).is_some() {
            return self.method_bound(receiver, method, stack, arguments);
        }
        let pure = match receiver {
            "Doctrine\\DBAL\\Connection" => ["prepare", "createquerybuilder"].contains(&m),
            "Doctrine\\DBAL\\Query\\QueryBuilder" | "Doctrine\\ORM\\QueryBuilder" => [
                "select",
                "addselect",
                "from",
                "where",
                "andwhere",
                "orwhere",
                "setparameter",
                "setmaxresults",
                "getquery",
                "getsql",
                "getdql",
            ]
            .contains(&m),
            "Doctrine\\ORM\\EntityManagerInterface" | "Doctrine\\ORM\\EntityManager" => {
                ["createquery", "createquerybuilder", "createnativequery"].contains(&m)
            }
            _ => false,
        };
        if pure {
            Estimate::zero()
        } else {
            Estimate::unknown(format!("unresolved call {receiver}::{method} at {file}"))
        }
    }
}

fn pure_scalar_callback(name: &str, args: &[Bound]) -> bool {
    !args.is_empty()
        && args.iter().all(|arg| arg.scalar)
        && [
            "mb_trim",
            "max",
            "min",
            "trim",
            "strlen",
            "strtolower",
            "strtoupper",
            "intval",
            "floatval",
            "boolval",
            "strval",
        ]
        .contains(&name)
}

fn argument<'a>(arguments: &'a [Bound], index: usize, name: &str) -> Option<&'a Bound> {
    arguments
        .iter()
        .find(|arg| arg.argument_name.as_deref() == Some(name))
        .or_else(|| {
            arguments
                .get(index)
                .filter(|arg| arg.argument_name.is_none())
        })
}
fn normalize_bound_arguments(values: &[Bound], builtin: &str) -> Vec<Bound> {
    let names: &[&str] = match builtin {
        "array_map" => &["callback", "array"],
        "array_filter" => &["array", "callback", "mode"],
        "array_walk" => &["array", "callback", "arg"],
        "array_reduce" => &["array", "callback", "initial"],
        "call_user_func" => &["callback"],
        "call_user_func_array" => &["callback", "args"],
        _ => &["array", "callback"],
    };
    let mut result = Vec::new();
    for (index, value) in values.iter().enumerate() {
        let position = value
            .argument_name
            .as_ref()
            .and_then(|name| names.iter().position(|expected| expected == name))
            .unwrap_or(index);
        while result.len() <= position {
            result.push(Bound::default());
        }
        result[position] = value.clone();
        result[position].argument_name = None;
    }
    result
}
fn safe_array_members(node: &Value) -> bool {
    if kind(node) != "Expr_Array" {
        return false;
    }
    let mut keys = Vec::new();
    for item in array(&node["items"]) {
        if item["unpack"] == true || item["byRef"] == true {
            return false;
        }
        if !item["key"].is_null() {
            let Some(key) = item["key"]["value"].as_str() else {
                return false;
            };
            if kind(&item["key"]) != "Scalar_String"
                || key.parse::<f64>().is_ok()
                || keys.contains(&key)
            {
                return false;
            }
            keys.push(key);
        }
    }
    true
}

fn contains_assignment(node: &Value) -> bool {
    if ["Expr_Closure", "Expr_ArrowFunction"].contains(&kind(node)) {
        return false;
    }
    kind(node).starts_with("Expr_Assign")
        || node.as_object().is_some_and(|object| {
            object
                .iter()
                .any(|(key, value)| key != "attributes" && contains_assignment(value))
        })
        || array(node).iter().any(contains_assignment)
}

fn normalize_callback_arguments(node: &Value, builtin: &str) -> Value {
    let names: &[&str] = match builtin {
        "array_map" => &["callback", "array"],
        "array_filter" => &["array", "callback", "mode"],
        "array_walk" => &["array", "callback", "arg"],
        "array_reduce" => &["array", "callback", "initial"],
        "call_user_func" => &["callback"],
        "call_user_func_array" => &["callback", "args"],
        _ => &["array", "callback"],
    };
    let mut ordered = Vec::new();
    for (index, arg) in array(&node["args"]).iter().enumerate() {
        let name = arg["name"]["name"].as_str();
        let position = name
            .and_then(|name| names.iter().position(|expected| *expected == name))
            .unwrap_or(index);
        while ordered.len() <= position {
            ordered.push(json!({"nodeType":"Arg","value":null}));
        }
        ordered[position] = arg.clone();
        ordered[position]["name"] = Value::Null;
    }
    let mut result = node.clone();
    result["args"] = json!(ordered);
    result
}

fn variables(node: &Value, result: &mut Vec<String>) {
    if kind(node) == "Expr_Variable" && node["name"].is_string() {
        result.push(string(&node["name"]).into());
    }
    if let Some(object) = node.as_object() {
        for (key, value) in object {
            if key != "attributes" {
                variables(value, result);
            }
        }
    }
    for value in array(node) {
        variables(value, result);
    }
}
fn invalidate_target(node: &Value, scope: &mut Scope) {
    if kind(node) == "Expr_Variable" {
        let key = string(&node["name"]);
        scope.remove(key);
        scope.values.remove(key);
    } else if kind(node) == "Expr_ArrayDimFetch" {
        invalidate_target(&node["var"], scope);
    }
}

fn invalidate_assignments(node: &Value, scope: &mut Scope) {
    if ["Expr_Closure", "Expr_ArrowFunction"].contains(&kind(node)) {
        return;
    }
    if kind(node).starts_with("Expr_Assign") {
        invalidate_target(&node["var"], scope);
    }
    if kind(node) == "Stmt_Foreach" {
        invalidate_target(&node["valueVar"], scope);
        invalidate_target(&node["keyVar"], scope);
    }
    if kind(node) == "Stmt_Catch" {
        invalidate_target(&node["var"], scope);
    }
    if let Some(object) = node.as_object() {
        for (key, value) in object {
            if key != "attributes" {
                invalidate_assignments(value, scope);
            }
        }
    }
    for value in array(node) {
        invalidate_assignments(value, scope);
    }
}
fn has_yield(node: &Value) -> bool {
    fn visit(node: &Value) -> bool {
        if [
            "Expr_Closure",
            "Expr_ArrowFunction",
            "Stmt_Function",
            "Stmt_Class",
        ]
        .contains(&kind(node))
        {
            return false;
        }
        if ["Expr_Yield", "Expr_YieldFrom"].contains(&kind(node)) {
            return true;
        }
        node.as_object().is_some_and(|object| {
            object
                .iter()
                .any(|(key, value)| key != "attributes" && visit(value))
        }) || array(node).iter().any(visit)
    }
    visit(&node["stmts"]) || visit(&node["expr"])
}

fn literal_count(node: &Value) -> Option<i64> {
    // Duplicate/explicit keys and unpacking affect cardinality.
    (kind(node) == "Expr_Array"
        && array(&node["items"])
            .iter()
            .all(|item| !item.is_null() && item["unpack"] != true && item["key"].is_null()))
    .then(|| array(&node["items"]).len() as i64)
}

fn exits(stmts: &[Value]) -> bool {
    stmts.iter().any(|n| {
        kind(n) == "Stmt_Return" || kind(n) == "Stmt_Expression" && kind(&n["expr"]) == "Expr_Throw"
    })
}
fn php_class(node_type: &str) -> String {
    let mut parts: Vec<_> = node_type.split('_').map(str::to_owned).collect();
    if let Some(last) = parts.last_mut()
        && [
            "Break", "Continue", "Switch", "Echo", "Unset", "Global", "Static", "Throw",
        ]
        .contains(&last.as_str())
    {
        last.push('_');
    }
    format!("PhpParser\\Node\\{}", parts.join("\\"))
}
fn breaker(node: &Value, name_: &str, function: bool) -> bool {
    let param = &node["params"][0];
    let guard = &node["stmts"][0];
    let cond = &guard["cond"];
    if kind(&param["var"]) != "Expr_Variable"
        || !param["var"]["name"].is_string()
        || kind(guard) != "Stmt_If"
        || kind(cond) != "Expr_BinaryOp_SmallerOrEqual"
        || kind(&cond["left"]) != "Expr_Variable"
        || cond["left"]["name"] != param["var"]["name"]
        || kind(&cond["right"]) != "Scalar_Int"
        || cond["right"]["value"] != 0
        || kind(&guard["stmts"][0]) != "Stmt_Return"
    {
        return false;
    }
    fn find(node: &Value, result: &mut Vec<Value>, function: bool) {
        if [
            "Expr_Closure",
            "Expr_ArrowFunction",
            "Stmt_Class",
            "Stmt_Function",
        ]
        .contains(&kind(node))
        {
            return;
        }
        if !first_class_callable(node)
            && kind(node)
                == if function {
                    "Expr_FuncCall"
                } else {
                    "Expr_MethodCall"
                }
        {
            result.push(node.clone());
        }
        match node {
            Value::Array(a) => {
                for item in a {
                    find(item, result, function);
                }
            }
            Value::Object(m) => {
                for (key, item) in m {
                    if key != "attributes" {
                        find(item, result, function);
                    }
                }
            }
            _ => {}
        }
    }
    let mut calls = Vec::new();
    find(&node["stmts"], &mut calls, function);
    let mut found = false;
    for call in calls {
        let called = if function {
            if !is_name(&call["name"]) {
                continue;
            }
            let ns = &call["name"]["attributes"]["namespacedName"];
            name(if is_name(ns) { ns } else { &call["name"] })
        } else {
            if kind(&call["name"]) != "Identifier" {
                continue;
            }
            string(&call["name"]["name"]).into()
        };
        if !called.eq_ignore_ascii_case(name_) {
            continue;
        }
        if !function && (kind(&call["var"]) != "Expr_Variable" || call["var"]["name"] != "this") {
            return false;
        }
        let arg = &call["args"][0]["value"];
        if kind(arg) != "Expr_BinaryOp_Minus"
            || kind(&arg["left"]) != "Expr_Variable"
            || arg["left"]["name"] != param["var"]["name"]
            || kind(&arg["right"]) != "Scalar_Int"
            || arg["right"]["value"].as_i64().unwrap_or(0) < 1
        {
            return false;
        }
        found = true;
    }
    found
}

impl Program {
    /// Build the project index in Rust; PHP receives only reporting metadata.
    pub fn index_source(
        &mut self,
        nodes: Vec<Value>,
        file: &str,
        bindings: &Env,
        constructor_bindings: &BTreeMap<String, BTreeMap<String, Option<String>>>,
    ) -> Vec<Value> {
        fn functions(program: &mut Program, nodes: &[Value], file: &str) {
            for node in nodes {
                if kind(node) == "Stmt_Namespace" {
                    functions(program, array(&node["stmts"]), file);
                } else if kind(node) == "Stmt_Const" {
                    for constant in array(&node["consts"]) {
                        program.constants.insert(
                            name(&constant["namespacedName"]),
                            json!({"node":constant["value"],"class":"","file":file}),
                        );
                    }
                } else if kind(node) == "Stmt_Function" {
                    let fqn = name(&node["namespacedName"]);
                    let key = fqn.to_ascii_lowercase();
                    if program.functions.contains_key(&key)
                        || program.ambiguous_functions.contains_key(&key)
                    {
                        program.functions.remove(&key);
                        program.ambiguous_functions.insert(key, true);
                    } else {
                        program
                            .functions
                            .insert(key, json!({"node":node,"name":fqn,"file":file}));
                    }
                }
            }
        }
        fn classes<'a>(nodes: &'a Value, result: &mut Vec<&'a Value>) {
            if ["Stmt_Class", "Stmt_Trait", "Stmt_Interface"].contains(&kind(nodes)) {
                result.push(nodes);
            }
            match nodes {
                Value::Array(a) => {
                    for node in a {
                        classes(node, result);
                    }
                }
                Value::Object(o) => {
                    for (key, node) in o {
                        if key != "attributes" {
                            classes(node, result);
                        }
                    }
                }
                _ => {}
            }
        }
        functions(self, &nodes, file);
        let mut declarations = Vec::new();
        for node in &nodes {
            classes(node, &mut declarations);
        }
        let mut metadata = Vec::new();
        for declaration in declarations {
            let class = name(&declaration["namespacedName"]);
            if class.is_empty() {
                continue;
            }
            if ["Stmt_Class", "Stmt_Interface"].contains(&kind(declaration)) {
                let mut compact = Value::Object(
                    declaration
                        .as_object()
                        .unwrap()
                        .iter()
                        .filter(|(key, _)| key.as_str() != "stmts")
                        .map(|(key, value)| (key.clone(), value.clone()))
                        .collect(),
                );
                let methods = array(&declaration["stmts"])
                    .iter()
                    .filter(|member| kind(member) == "Stmt_ClassMethod")
                    .map(|member| {
                        let mut method = Value::Object(
                            member
                                .as_object()
                                .unwrap()
                                .iter()
                                .filter(|(key, _)| key.as_str() != "stmts")
                                .map(|(key, value)| (key.clone(), value.clone()))
                                .collect(),
                        );
                        if !string(&member["name"]["name"])
                            .eq_ignore_ascii_case("getSubscribedEvents")
                        {
                            method["stmts"] = json!([]);
                        } else {
                            method["stmts"] = member["stmts"].clone();
                        }
                        method
                    })
                    .collect::<Vec<_>>();
                compact["stmts"] = json!(methods);
                let key = class.to_ascii_lowercase();
                match self.framework_classes.entry(key) {
                    std::collections::btree_map::Entry::Occupied(mut entry) => {
                        entry.get_mut()["duplicate"] = json!(true);
                    }
                    std::collections::btree_map::Entry::Vacant(entry) => {
                        entry.insert(json!({"name":class,"file":file,"node":compact}));
                    }
                }
            }
            if kind(declaration) == "Stmt_Interface" {
                self.interfaces.insert(
                    class.clone(),
                    array(&declaration["extends"]).iter().map(name).collect(),
                );
                continue;
            }
            self.interfaces.insert(
                class.clone(),
                array(&declaration["implements"]).iter().map(name).collect(),
            );
            let is_trait = kind(declaration) == "Stmt_Trait";
            if is_trait {
                self.trait_definitions
                    .insert(class.to_ascii_lowercase(), true);
            } else {
                if is_name(&declaration["extends"]) {
                    self.parents
                        .insert(class.clone(), name(&declaration["extends"]));
                }
                self.properties.insert(class.clone(), Env::new());
            }
            let mut declared_types = Env::new();
            for member in array(&declaration["stmts"]) {
                if kind(member) == "Stmt_ClassConst" {
                    for constant in array(&member["consts"]) {
                        self.constants.insert(
                            format!(
                                "{}::{}",
                                class.to_ascii_lowercase(),
                                string(&constant["name"]["name"])
                            ),
                            json!({"node":constant["value"],"class":class,"file":file}),
                        );
                    }
                }
                if kind(member) == "Stmt_TraitUse" {
                    if !array(&member["adaptations"]).is_empty() {
                        self.adapted_traits.insert(class.to_ascii_lowercase(), true);
                    } else {
                        self.trait_uses
                            .entry(class.to_ascii_lowercase())
                            .or_default()
                            .extend(
                                array(&member["traits"])
                                    .iter()
                                    .map(|n| name(n).to_ascii_lowercase()),
                            );
                    }
                }
                if !is_trait && kind(member) == "Stmt_Property" && is_name(&member["type"]) {
                    let ty = name(&member["type"]);
                    for property in array(&member["props"]) {
                        let property = string(&property["name"]["name"]);
                        declared_types.insert(property.into(), ty.clone());
                        self.properties
                            .get_mut(&class)
                            .unwrap()
                            .insert(property.into(), bindings.get(&ty).unwrap_or(&ty).clone());
                    }
                }
            }
            for method in array(&declaration["stmts"]) {
                if kind(method) != "Stmt_ClassMethod" {
                    continue;
                }
                let method_name = string(&method["name"]["name"]);
                let key = method_key(&class, method_name);
                let model = json!({"node":method,"class":class,"file":file});
                if is_trait {
                    self.trait_methods.insert(key, model);
                    continue;
                }
                self.methods.insert(key.clone(), model);
                metadata.push(json!({"key":key,"class":class,"file":file,"name":method_name,"flags":method["flags"],"name_attributes":method["name"]["attributes"],"method_attributes":method["attributes"],"abstract":method["stmts"].is_null()}));
                if !method_name.eq_ignore_ascii_case("__construct") {
                    continue;
                }
                let mut parameter_classes: BTreeMap<String, Option<String>> = BTreeMap::new();
                for (position, param) in array(&method["params"]).iter().enumerate() {
                    if kind(&param["var"]) != "Expr_Variable"
                        || !param["var"]["name"].is_string()
                        || !is_name(&param["type"])
                    {
                        continue;
                    }
                    let ty = name(&param["type"]);
                    let var = string(&param["var"]["name"]);
                    let mut target = None;
                    for group in array(&param["attrGroups"]) {
                        for attr in array(&group["attrs"]) {
                            if name(&attr["name"]).ends_with("\\Target")
                                && kind(&attr["args"][0]["value"]) == "Scalar_String"
                            {
                                target =
                                    Some(string(&attr["args"][0]["value"]["value"]).to_owned());
                            }
                        }
                    }
                    let lookup =
                        target.map_or_else(|| ty.clone(), |target| format!("{ty} ${target}"));
                    let compiled = constructor_bindings
                        .get(&class)
                        .and_then(|b| b.get(&position.to_string()));
                    let concrete = compiled
                        .cloned()
                        .unwrap_or_else(|| Some(bindings.get(&lookup).unwrap_or(&ty).clone()));
                    parameter_classes.insert(var.into(), concrete.clone());
                    let properties = self.properties.get_mut(&class).unwrap();
                    if compiled.is_some() {
                        for (property, declared) in &declared_types {
                            if declared == &ty {
                                properties.remove(property);
                            }
                        }
                    }
                    if param["flags"].as_i64().unwrap_or(0) != 0 {
                        match concrete {
                            Some(c) => {
                                properties.insert(var.into(), c);
                            }
                            None => {
                                properties.remove(var);
                            }
                        }
                    }
                }
                for stmt in array(&method["stmts"]) {
                    let assignment = &stmt["expr"];
                    let lhs = &assignment["var"];
                    let rhs = &assignment["expr"];
                    if kind(stmt) != "Stmt_Expression"
                        || kind(assignment) != "Expr_Assign"
                        || kind(lhs) != "Expr_PropertyFetch"
                        || kind(&lhs["var"]) != "Expr_Variable"
                        || lhs["var"]["name"] != "this"
                        || kind(&lhs["name"]) != "Identifier"
                        || kind(rhs) != "Expr_Variable"
                    {
                        continue;
                    }
                    if let Some(concrete) = parameter_classes.get(string(&rhs["name"])) {
                        let properties = self.properties.get_mut(&class).unwrap();
                        let property = string(&lhs["name"]["name"]);
                        match concrete {
                            Some(c) => {
                                properties.insert(property.into(), c.clone());
                            }
                            None => {
                                properties.remove(property);
                            }
                        }
                    }
                }
            }
        }
        metadata
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    #[test]
    fn framework_hook_metadata_uses_indexed_ancestry_and_compact_methods() {
        let source = r#"<?php namespace Neutral;
            abstract class Base implements \Symfony\Component\Messenger\Middleware\MiddlewareInterface {}
            abstract class Middle extends Base {}
            final class Worker extends Middle { public function handle(): void { $unrelated = 42; } }
            final class Ordinary { public function run(): void {} }
            #[\Symfony\Component\EventDispatcher\Attribute\AsEventListener(method: 'receive')]
            final class Listener { public function receive(): void { $unrelated = 42; } }
        "#;
        let mut program = Program::default();
        program.index_source(
            crate::syntax::parse(source).unwrap(),
            "fixture.php",
            &Env::new(),
            &BTreeMap::new(),
        );
        let records = program.framework_hook_classes(&[
            "Symfony\\Component\\Messenger\\Middleware\\MiddlewareInterface".into(),
            "Symfony\\Component\\EventDispatcher\\Attribute\\AsEventListener".into(),
        ]);
        assert_eq!(records.len(), 4);
        assert!(!records.iter().any(|r| r["name"] == "Neutral\\Ordinary"));
        for record in records {
            for method in array(&record["node"]["stmts"]) {
                assert!(array(&method["stmts"]).is_empty());
            }
        }
    }

    #[test]
    fn forwarding_endpoints_are_not_recursive() {
        let source = r#"<?php
        namespace Sample;
        interface DetailsPort { public function fetch(): int; }
        final class DetailsService implements DetailsPort {
            public function fetch(): int { return 1; }
        }
        final class ForwardingEndpoint {
            public function __construct(private readonly DetailsPort $details) {}
            public function get(): int { return $this->details->fetch(); }
        }
        final class AssignedEndpoint {
            private DetailsPort $details;
            public function __construct(DetailsPort $details) { $this->details = $details; }
            public function get(): int { return $this->details->fetch(); }
        }
        final class ChainedEndpoint {
            public function get(): int { return (new DetailsService())->fetch(); }
        }
        final class RecursiveEndpoint {
            public function get(): int { return $this->get(); }
        }
        "#;
        let mut program = Program::default();
        let bindings = Env::from([(
            "Sample\\DetailsPort".into(),
            "Sample\\DetailsService".into(),
        )]);
        program.index_source(
            crate::syntax::parse(source).unwrap(),
            "Endpoint.php",
            &bindings,
            &BTreeMap::new(),
        );
        for owner in ["ForwardingEndpoint", "AssignedEndpoint", "ChainedEndpoint"] {
            let result = Evaluator::new(&program, false, |_, _| Value::Null).method(
                &format!("Sample\\{owner}"),
                "get",
                &[],
            );
            assert!(result.cycles.is_empty(), "{owner}: {result:?}");
            assert_eq!(result.upper, Some(0), "{owner}: {result:?}");
        }
        let recursive = Evaluator::new(&program, false, |_, _| Value::Null).method(
            "Sample\\RecursiveEndpoint",
            "get",
            &[],
        );
        assert_eq!(recursive.cycles, ["sample\\recursiveendpoint::get"]);
    }

    #[test]
    fn callable_creation_does_not_invoke_its_target() {
        let source = r#"<?php
        namespace Example;
        final class CallbackModel {
            public function __construct(private \Doctrine\DBAL\Connection $connection) {}
            public function tree(array $children): array { return \array_map($this->tree(...), $children); }
            public function methodReference(): \Closure { return $this->tree(...); }
            public function arrowReference(): \Closure { return fn(array $children) => $this->tree($children); }
            public function queryReference(): \Closure { return $this->query(...); }
            public function query(): void { $this->connection->executeQuery('SELECT 1'); }
            public function factory(): self { $this->connection->executeQuery('SELECT 1'); return $this; }
            public function receiverReference(): \Closure { return $this->factory()->query(...); }
            public static function staticReference(): \Closure { return self::staticReference(...); }
            public function name(): string { $this->connection->executeQuery('SELECT 1'); return 'query'; }
            public function dynamicReference(): \Closure { return $this->{$this->name()}(...); }
            public function directCycle(): void { $this->directCycle(); }
            public function bounded(int $n): void { if ($n <= 0) return; $cb = $this->bounded(...); $arrow = fn() => $this->bounded(100); $this->bounded($n - 1); }
        }
        function functionReference(): \Closure { return functionReference(...); }
        "#;
        let mut program = Program::default();
        program.index_source(
            crate::syntax::parse(source).unwrap(),
            "Callback.php",
            &Env::new(),
            &BTreeMap::new(),
        );
        for method in [
            "methodReference",
            "arrowReference",
            "queryReference",
            "staticReference",
        ] {
            let result = Evaluator::new(&program, false, |_, _| Value::Null).method(
                "Example\\CallbackModel",
                method,
                &[],
            );
            assert_eq!(result.upper, Some(0), "{method}: {result:?}");
            assert!(result.cycles.is_empty());
        }
        let tree = Evaluator::new(&program, false, |_, _| Value::Null).method(
            "Example\\CallbackModel",
            "tree",
            &[],
        );
        assert_eq!(tree.upper, None);
        assert!(!tree.unknown.is_empty());
        assert!(tree.cycles.is_empty());
        let dynamic = Evaluator::new(&program, false, |_, _| Value::Null).method(
            "Example\\CallbackModel",
            "dynamicReference",
            &[],
        );
        assert_eq!((dynamic.lower, dynamic.upper), (1, Some(1)));
        let receiver = Evaluator::new(&program, false, |_, _| Value::Null).method(
            "Example\\CallbackModel",
            "receiverReference",
            &[],
        );
        assert_eq!((receiver.lower, receiver.upper), (1, Some(1)));
        let function = Evaluator::new(&program, false, |_, _| Value::Null)
            .function("Example\\functionReference", &[]);
        assert_eq!(function.upper, Some(0));
        let direct = Evaluator::new(&program, false, |_, _| Value::Null).method(
            "Example\\CallbackModel",
            "directCycle",
            &[],
        );
        assert!(!direct.cycles.is_empty());
        let bounded = Evaluator::new(&program, false, |_, _| Value::Null).method(
            "Example\\CallbackModel",
            "bounded",
            &[],
        );
        assert_eq!(bounded.upper, None);
        assert!(bounded.cycles.is_empty());
    }

    #[test]
    fn callback_effects_and_doctrine_provenance() {
        let source = include_str!("../tests/fixtures/callback-effects.php");
        let mut program = Program::default();
        program.index_source(
            crate::syntax::parse(source).unwrap(),
            "EffectsController.php",
            &Env::new(),
            &BTreeMap::new(),
        );
        for (method, lower, upper) in [
            ("create", 0, Some(0)),
            ("variable", 1, Some(1)),
            ("captured", 1, Some(1)),
            ("arrow", 1, Some(1)),
            ("immediate", 1, Some(1)),
            ("forwarding", 1, Some(1)),
            ("namedForwarding", 1, Some(1)),
            ("arrayTarget", 1, Some(1)),
            ("stringTarget", 1, Some(1)),
            ("invokable", 1, Some(1)),
            ("map", 3, Some(3)),
            ("namedMap", 2, Some(2)),
            ("storedArray", 2, Some(2)),
            ("emptyMap", 0, Some(0)),
            ("unknownMap", 0, None),
            ("shortCircuit", 0, Some(3)),
            ("referenceAlias", 0, None),
            ("referenceLoop", 0, None),
            ("tryBinding", 0, None),
            ("foreachBinding", 0, None),
            ("mutatedArray", 0, None),
            ("referenceWalk", 0, None),
            ("fromCallable", 1, Some(1)),
            ("fromCallableCreation", 0, Some(0)),
            ("referenceParameter", 0, None),
            ("calleeBeforeArgument", 0, Some(0)),
            ("queryCalleeBeforeArgument", 1, Some(1)),
            ("mapCalleeBeforeArgument", 0, Some(0)),
            ("mapQueryBeforeArgument", 2, Some(2)),
            ("defaultCallback", 1, Some(1)),
            ("returnedCallback", 1, Some(1)),
            ("callbackInvoke", 1, Some(1)),
            ("nullsafeInvoke", 0, Some(1)),
            ("effectfulInvocation", 1, None),
            ("scalarCallbacks", 0, Some(0)),
            ("opaqueScalar", 0, None),
            ("callUser", 1, Some(1)),
            ("callArray", 1, Some(1)),
            ("pipe", 1, Some(1)),
            ("constants", 3, Some(3)),
            ("byReference", 0, None),
            ("overwritten", 1, Some(1)),
            ("invalidated", 0, None),
            ("branched", 0, None),
            ("recursiveTree", 0, None),
            ("deferred", 0, None),
            ("cache", 0, None),
            ("unrelated", 0, Some(0)),
            ("external", 1, None),
            ("inherited", 1, Some(1)),
            ("override", 0, Some(0)),
            ("inheritedOverride", 0, Some(0)),
            ("statement", 1, Some(1)),
            ("dbalBuilder", 1, Some(1)),
            ("transaction", 0, None),
            ("transactionCallback", 0, None),
            ("ormTransaction", 0, None),
            ("unitOfWork", 0, None),
            ("orm", 0, None),
        ] {
            let result = Evaluator::new(&program, false, |_, _| Value::Null).method(
                "CallbackEffects\\EffectsController",
                method,
                &[],
            );
            assert_eq!(
                (result.lower, result.upper),
                (lower, upper),
                "{method}: {result:?}"
            );
            assert!(result.cycles.is_empty(), "{method}: {result:?}");
        }
        let cycle = Evaluator::new(&program, false, |_, _| Value::Null).method(
            "CallbackEffects\\EffectsController",
            "directCycle",
            &[],
        );
        assert_eq!(
            cycle.cycles,
            ["callbackeffects\\effectscontroller::directcycle"]
        );
    }

    #[test]
    fn php_consumers_follow_execution_and_defer_registration() {
        let source = include_str!("../tests/fixtures/php-consumers.php");
        let mut program = Program::default();
        program.index_source(
            crate::syntax::parse(source).unwrap(),
            "Consumers.php",
            &Env::new(),
            &BTreeMap::new(),
        );
        for row in source
            .lines()
            .filter_map(|row| row.trim().strip_prefix("// budget: "))
        {
            let cells: Vec<_> = row.split_whitespace().collect();
            let lower = cells[1].parse::<i64>().unwrap();
            let upper = cells[2].parse::<i64>().ok();
            let result = Evaluator::new(&program, false, |_, _| Value::Null).method(
                "PhpConsumers\\ConsumersController",
                cells[0],
                &[],
            );
            assert_eq!(
                (result.lower, result.upper),
                (lower, upper),
                "{}: {:?}",
                cells[0],
                result
            );
            assert!(result.cycles.is_empty(), "{}: {:?}", cells[0], result);
        }
    }

    #[test]
    fn scalar_opt_in_does_not_expand_to_new_namespace_fallbacks() {
        let source = r#"<?php namespace ScopePolicy; class Endpoint {
            public function legacy(): void { max(1,2); }
            public function newlySupported(): void { trim('value'); }
            public function explicitGlobal(): void { \trim('value'); }
        }"#;
        let mut program = Program::default();
        program.index_source(
            crate::syntax::parse(source).unwrap(),
            "Scope.php",
            &Env::new(),
            &BTreeMap::new(),
        );
        for (method, upper) in [
            ("legacy", Some(0)),
            ("newlySupported", None),
            ("explicitGlobal", Some(0)),
        ] {
            assert_eq!(
                Evaluator::new(&program, true, |_, _| Value::Null)
                    .method("ScopePolicy\\Endpoint", method, &[])
                    .upper,
                upper
            );
        }
    }

    #[test]
    fn unrelated_closure_captures_do_not_grow_exponentially() {
        let mut source = "<?php class CaptureStress { public function create(): void {".to_owned();
        for index in 0..1000 {
            source.push_str(&format!("$cb{index} = static fn() => 0;"));
        }
        source.push_str("} }");
        let mut program = Program::default();
        program.index_source(
            crate::syntax::parse(&source).unwrap(),
            "CaptureStress.php",
            &Env::new(),
            &BTreeMap::new(),
        );
        let result = Evaluator::new(&program, false, |_, _| Value::Null).method(
            "CaptureStress",
            "create",
            &[],
        );
        assert_eq!(result.upper, Some(0));
    }

    #[test]
    fn arithmetic_is_bounded_and_unknown_propagates() {
        let huge = Estimate {
            lower: i64::MAX,
            upper: Some(i64::MAX),
            unknown: vec![],
            cycles: vec![],
        };
        assert_eq!(huge.clone().plus(huge).upper, Some(i64::MAX));
        assert_eq!(
            Estimate::zero()
                .branch(Estimate::unknown("unknown".into()))
                .upper,
            None
        );
    }
}
