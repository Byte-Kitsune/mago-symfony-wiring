use serde::{Deserialize, Serialize};
use serde_json::{Value, json};
use std::collections::BTreeMap;

type Env = BTreeMap<String, String>;

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
    pub methods: BTreeMap<String, Value>,
    #[serde(default)]
    pub trait_methods: BTreeMap<String, Value>,
    #[serde(default)]
    pub functions: BTreeMap<String, Value>,
    #[serde(default)]
    pub properties: BTreeMap<String, Env>,
    #[serde(default)]
    pub parents: Env,
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
            class = self.parents.get(&class)?.clone();
        }
        None
    }
}

pub struct Evaluator<'a, F: FnMut(&str, &Value) -> Value> {
    program: &'a Program,
    visits: usize,
    pub recursion_paths: Vec<Vec<String>>,
    assume_scalar: bool,
    callback: F,
}
impl<'a, F: FnMut(&str, &Value) -> Value> Evaluator<'a, F> {
    pub fn new(program: &'a Program, assume_scalar: bool, callback: F) -> Self {
        Self {
            program,
            visits: 0,
            recursion_paths: Vec::new(),
            assume_scalar,
            callback,
        }
    }
    pub fn method(&mut self, class: &str, method: &str, stack: &[String]) -> Estimate {
        self.visits += 1;
        if self.visits > 25_000 {
            return Estimate::unknown("analysis work limit".into());
        }
        let key = method_key(class, method);
        let program = self.program;
        let Some(model) = program.method(class, method) else {
            return Estimate::unknown(format!("unresolved method {class}::{method}"));
        };
        if stack.contains(&key) {
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
        let class = model.class.as_str();
        let file = model.file;
        let mut env = Env::from([("this".into(), class.into())]);
        for param in array(&model.node["params"]) {
            if kind(&param["var"]) == "Expr_Variable"
                && param["var"]["name"].is_string()
                && is_name(&param["type"])
            {
                env.insert(string(&param["var"]["name"]).into(), name(&param["type"]));
            }
        }
        self.statements(array(&model.node["stmts"]), class, file, env, &next)
    }
    pub fn function(&mut self, function_name: &str, stack: &[String]) -> Estimate {
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
        let mut env = Env::new();
        for param in array(&model["node"]["params"]) {
            if kind(&param["var"]) == "Expr_Variable"
                && param["var"]["name"].is_string()
                && is_name(&param["type"])
            {
                env.insert(string(&param["var"]["name"]).into(), name(&param["type"]));
            }
        }
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
        mut env: Env,
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
                }
                "Stmt_Foreach" => {
                    sum = sum.plus(self.expr(&stmt["expr"], class, file, &mut env, stack));
                    let body =
                        self.statements(array(&stmt["stmts"]), class, file, env.clone(), stack);
                    if kind(&stmt["expr"]) == "Expr_Array" {
                        sum = sum.plus(body.times(array(&stmt["expr"]["items"]).len() as i64));
                    } else if body.upper != Some(0) || !body.unknown.is_empty() {
                        sum = sum.plus(Estimate::unknown(format!(
                            "unbounded foreach at {file}:{}",
                            line(stmt)
                        )));
                    }
                }
                "Stmt_While" | "Stmt_Do" | "Stmt_For" => {
                    sum = sum.plus(Estimate::unknown(format!(
                        "unbounded loop at {file}:{}",
                        line(stmt)
                    )))
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
        env: &mut Env,
        stack: &[String],
    ) -> Estimate {
        let mut sum = Estimate::zero();
        for arg in array(&node["args"]) {
            if kind(arg) == "Arg" {
                sum = sum.plus(self.expr(&arg["value"], class, file, env, stack));
            }
        }
        sum
    }
    fn expr(
        &mut self,
        expr: &Value,
        class: &str,
        file: &str,
        env: &mut Env,
        stack: &[String],
    ) -> Estimate {
        self.visits += 1;
        if self.visits > 25_000 {
            return Estimate::unknown("analysis work limit".into());
        }
        match kind(expr) {
            "Expr_MethodCall" | "Expr_NullsafeMethodCall" => {
                let mut sum = self.expr(&expr["var"], class, file, env, stack);
                sum = sum.plus(self.args(expr, class, file, env, stack));
                if kind(&expr["name"]) != "Identifier" {
                    return sum.plus(Estimate::unknown(format!(
                        "dynamic method call at {file}:{}",
                        line(expr)
                    )));
                }
                let method = string(&expr["name"]["name"]);
                let Some(receiver) = self.receiver(&expr["var"], class, file, env) else {
                    return sum.plus(Estimate::unknown(format!(
                        "unknown receiver for {method} at {file}:{}",
                        line(expr)
                    )));
                };
                let effect = self.call(&receiver, method, file, stack);
                sum.plus(if kind(expr) == "Expr_NullsafeMethodCall" {
                    effect.branch(Estimate::zero())
                } else {
                    effect
                })
            }
            "Expr_StaticCall" => {
                let sum = self.args(expr, class, file, env, stack);
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
                    sum.plus(self.call(&receiver, string(&expr["name"]["name"]), file, stack))
                } else {
                    sum.plus(Estimate::unknown(format!(
                        "dynamic static call at {file}:{}",
                        line(expr)
                    )))
                }
            }
            "Expr_Assign" => {
                let sum = self.expr(&expr["expr"], class, file, env, stack);
                if kind(&expr["var"]) == "Expr_Variable"
                    && expr["var"]["name"].is_string()
                    && let Some(ty) = self.receiver(&expr["expr"], class, file, env)
                {
                    env.insert(string(&expr["var"]["name"]).into(), ty);
                }
                sum
            }
            "Expr_Ternary" => {
                let condition = self.expr(&expr["cond"], class, file, env, stack);
                let yes = if expr["if"].is_null() {
                    Estimate::zero()
                } else {
                    self.expr(&expr["if"], class, file, env, stack)
                };
                condition.plus(yes.branch(self.expr(&expr["else"], class, file, env, stack)))
            }
            "Expr_New" => {
                let sum = self.args(expr, class, file, env, stack);
                if is_name(&expr["class"])
                    && self
                        .program
                        .method(&name(&expr["class"]), "__construct")
                        .is_some()
                {
                    sum.plus(self.method(&name(&expr["class"]), "__construct", stack))
                } else {
                    sum
                }
            }
            "Expr_FuncCall" => {
                let sum = self.args(expr, class, file, env, stack);
                if !is_name(&expr["name"]) {
                    return sum.plus(Estimate::unknown(format!(
                        "dynamic function call at {file}:{}",
                        line(expr)
                    )));
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
                        return sum.plus(self.function(candidate, stack));
                    }
                }
                if self.scalar_builtin(expr, class, file) || self.array_builtin(expr, class) {
                    return sum;
                }
                sum.plus(Estimate::unknown(format!(
                    "unresolved function call {} at {file}:{}",
                    candidates.join(" or "),
                    line(expr)
                )))
            }
            _ => {
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
    fn metadata(&mut self, file: &str, expr: &Value) -> Value {
        (self.callback)(file, expr)
    }
    fn receiver(&mut self, expr: &Value, class: &str, file: &str, env: &Env) -> Option<String> {
        match kind(expr) {
            "Expr_Variable" if expr["name"].is_string() => {
                return env.get(string(&expr["name"])).cloned();
            }
            "Expr_PropertyFetch"
                if kind(&expr["var"]) == "Expr_Variable"
                    && expr["var"]["name"] == "this"
                    && kind(&expr["name"]) == "Identifier" =>
            {
                return self
                    .program
                    .properties
                    .get(class)?
                    .get(string(&expr["name"]["name"]))
                    .cloned();
            }
            "Expr_New" if is_name(&expr["class"]) => return Some(name(&expr["class"])),
            _ => {}
        }
        let metadata = self.metadata(file, expr);
        if let Some(object) = metadata["object"].as_str() {
            return Some(object.into());
        }
        if kind(expr) == "Expr_MethodCall" && kind(&expr["name"]) == "Identifier" {
            let base = self.receiver(&expr["var"], class, file, env)?;
            let method = string(&expr["name"]["name"]).to_ascii_lowercase();
            if base == "Doctrine\\ORM\\QueryBuilder" && method == "getquery" {
                return Some("Doctrine\\ORM\\Query".into());
            }
            if base == "Doctrine\\DBAL\\Connection" && method == "createquerybuilder" {
                return Some("Doctrine\\DBAL\\Query\\QueryBuilder".into());
            }
        }
        None
    }
    fn scalar_builtin(&mut self, call: &Value, class: &str, file: &str) -> bool {
        let builtin = string(&call["name"]["name"]).to_ascii_lowercase();
        if !["mb_trim", "max", "min"].contains(&builtin.as_str()) {
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
                || namespace.is_some() && !self.assume_scalar
            {
                return false;
            }
        }
        for arg in array(&call["args"]) {
            if kind(arg) != "Arg" || arg["unpack"] == true {
                return false;
            }
            let value = &arg["value"];
            if ["Scalar_String", "Scalar_Int", "Scalar_Float"].contains(&kind(value)) {
                continue;
            }
            if kind(value) != "Expr_Variable"
                || !value["name"].is_string()
                || self.metadata(file, value)["scalar"] != true
            {
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
    fn call(&mut self, receiver: &str, method: &str, file: &str, stack: &[String]) -> Estimate {
        let method_lower = method.to_ascii_lowercase();
        let m = method_lower.as_str();
        let query = Estimate {
            lower: 1,
            upper: Some(1),
            unknown: vec![],
            cycles: vec![],
        };
        match receiver {
            "Doctrine\\DBAL\\Connection"
                if [
                    "executequery",
                    "executestatement",
                    "fetchallassociative",
                    "fetchallnumeric",
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
            "Doctrine\\DBAL\\Statement" if m == "execute" => return query,
            "Doctrine\\DBAL\\Result"
                if [
                    "fetchone",
                    "fetchassociative",
                    "fetchallassociative",
                    "fetchallnumeric",
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
                if [
                    "execute",
                    "getresult",
                    "getsingleresult",
                    "getoneornullresult",
                    "getscalarresult",
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
            return self.method(receiver, method, stack);
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
                ["createquery", "createquerybuilder"].contains(&m)
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
        if kind(node)
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
            if ["Stmt_Class", "Stmt_Trait"].contains(&kind(nodes)) {
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
