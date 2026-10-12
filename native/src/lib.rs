pub mod budget;
pub mod cycles;
pub mod graph;
pub mod syntax;
use mago_allocator::LocalArena;
use mago_database::file::FileId;
use mago_names::{ResolvedNames, resolver::NameResolver};
use mago_span::{HasSpan, Span};
use mago_syntax::{cst::*, parser::parse_file_content};
use serde::Serialize;

const MAX_SOURCE_BYTES: usize = 8 * 1024 * 1024;
const MAX_REFERENCES: usize = 100_000;

#[derive(Debug, Clone, Serialize)]
pub struct Reference {
    pub name: String,
    pub namespace: String,
    pub source_class: Option<String>,
    pub import: bool,
    pub start: u32,
    pub end: u32,
}

#[derive(Debug, Clone, Serialize)]
pub struct AttributeRecord {
    pub name: String,
    pub literal: Option<String>,
    pub start: u32,
    pub end: u32,
}

#[derive(Debug, Clone, Serialize)]
pub struct ParameterRecord {
    pub position: usize,
    pub name: String,
    pub class_type: Option<String>,
    pub promoted: bool,
    pub attributes: Vec<AttributeRecord>,
}

#[derive(Debug, Clone, Serialize)]
pub struct ConstructorRecord {
    pub owner: String,
    pub parameters: Vec<ParameterRecord>,
}

#[derive(Debug, Clone, Serialize)]
pub struct SourceRecord {
    pub references: Vec<Reference>,
    pub constructors: Vec<ConstructorRecord>,
}

fn text(bytes: &[u8]) -> String {
    String::from_utf8_lossy(bytes).into_owned()
}
fn resolved(names: &ResolvedNames<'_>, id: &Identifier<'_>) -> String {
    text(names.resolve(&id.span().start).unwrap_or(id.value()))
        .trim_start_matches('\\')
        .into()
}

struct Collector<'names, 'arena> {
    names: &'names ResolvedNames<'arena>,
    result: SourceRecord,
    namespace: String,
    class: Option<String>,
    parents: Vec<Node<'names, 'arena>>,
    failure: Option<String>,
}

impl<'names, 'arena> Collector<'names, 'arena> {
    fn reference(&mut self, name: String, span: Span, import: bool) {
        if ["self", "static", "parent"].contains(&name.to_ascii_lowercase().as_str()) {
            return;
        }
        if self.result.references.len() == MAX_REFERENCES {
            self.failure = Some("Class reference source exceeds 100000 literal references.".into());
            return;
        }
        self.result.references.push(Reference {
            name,
            namespace: self.namespace.clone(),
            source_class: self.class.clone(),
            import,
            start: span.start.offset,
            end: span.end.offset,
        });
    }

    fn imports(&mut self, import: &Use<'arena>) {
        let mut add = |item: &UseItem<'arena>, prefix: &str| {
            let name = format!("{prefix}{}", text(item.name.value()))
                .trim_start_matches('\\')
                .into();
            self.reference(name, item.name.span(), true);
        };
        match &import.items {
            UseItems::Sequence(sequence) => {
                for item in &sequence.items {
                    add(item, "");
                }
            }
            UseItems::TypedSequence(_) | UseItems::TypedList(_) => {}
            UseItems::MixedList(list) => {
                let prefix = format!(
                    "{}\\",
                    text(list.namespace.value()).trim_start_matches('\\')
                );
                for item in &list.items {
                    if item.r#type.is_none() {
                        add(&item.item, &prefix);
                    }
                }
            }
        }
    }

    fn is_class_position(&self, id: &Identifier<'arena>) -> bool {
        let span = id.span();
        let Some(parent) = self.parents.last() else {
            return false;
        };
        match parent {
            Node::Hint(_)
            | Node::Attribute(_)
            | Node::Extends(_)
            | Node::Implements(_)
            | Node::TraitUse(_)
            | Node::TraitUseAbsoluteMethodReference(_)
            | Node::TraitUsePrecedenceAdaptation(_) => true,
            Node::Expression(_) => match self.parents.iter().rev().nth(1) {
                Some(Node::Instantiation(n)) => n.class.span() == span,
                Some(Node::StaticMethodCall(n)) => n.class.span() == span,
                Some(Node::ClassConstantAccess(n)) => n.class.span() == span,
                Some(Node::StaticPropertyAccess(n)) => n.class.span() == span,
                Some(Node::Binary(n)) => {
                    matches!(n.operator, BinaryOperator::Instanceof(_)) && n.rhs.span() == span
                }
                _ => false,
            },
            _ => false,
        }
    }

    fn constructor(&mut self, method: &Method<'arena>) {
        if !method.name.value.eq_ignore_ascii_case(b"__construct") {
            return;
        }
        let Some(owner) = self.class.clone() else {
            return;
        };
        let parameters = method
            .parameter_list
            .parameters
            .iter()
            .enumerate()
            .map(|(position, param)| {
                let class_type = match &param.hint {
                    Some(Hint::Identifier(id)) => Some(resolved(self.names, id)),
                    _ => None,
                };
                let attributes = param
                    .attribute_lists
                    .iter()
                    .flat_map(|group| group.attributes.iter())
                    .map(|attribute| {
                        let literal = attribute
                            .argument_list
                            .as_ref()
                            .and_then(|args| args.arguments.first())
                            .and_then(|argument| {
                                let value = match argument {
                                    PartialArgument::Positional(p) => p.value,
                                    PartialArgument::Named(n) => n.value,
                                    _ => return None,
                                };
                                match value {
                                    Expression::Literal(Literal::String(value)) => {
                                        value.value.map(text)
                                    }
                                    _ => None,
                                }
                            });
                        AttributeRecord {
                            name: resolved(self.names, &attribute.name),
                            literal,
                            start: attribute.span().start.offset,
                            end: attribute.span().end.offset,
                        }
                    })
                    .collect();
                ParameterRecord {
                    position,
                    name: text(param.variable.name).trim_start_matches('$').into(),
                    class_type,
                    promoted: param.is_promoted_property(),
                    attributes,
                }
            })
            .collect();
        self.result
            .constructors
            .push(ConstructorRecord { owner, parameters });
    }

    fn walk(&mut self, node: Node<'names, 'arena>) {
        if self.failure.is_some() {
            return;
        }
        if self.parents.len() >= 512 {
            self.failure = Some("Class reference source exceeds traversal depth 512.".into());
            return;
        }
        if let Node::Use(import) = node {
            self.imports(import);
            return;
        }
        let mut previous_namespace = None;
        let mut previous_class = None;
        match node {
            Node::Namespace(n) => {
                previous_namespace = Some(std::mem::replace(
                    &mut self.namespace,
                    n.name
                        .as_ref()
                        .map(|name| text(name.value()))
                        .unwrap_or_default(),
                ))
            }
            Node::Class(n) => {
                previous_class = Some(
                    self.class.replace(text(
                        self.names
                            .resolve(&n.name.span.start)
                            .unwrap_or(n.name.value),
                    )),
                )
            }
            Node::Interface(n) => {
                previous_class = Some(
                    self.class.replace(text(
                        self.names
                            .resolve(&n.name.span.start)
                            .unwrap_or(n.name.value),
                    )),
                )
            }
            Node::Trait(n) => {
                previous_class = Some(
                    self.class.replace(text(
                        self.names
                            .resolve(&n.name.span.start)
                            .unwrap_or(n.name.value),
                    )),
                )
            }
            Node::Enum(n) => {
                previous_class = Some(
                    self.class.replace(text(
                        self.names
                            .resolve(&n.name.span.start)
                            .unwrap_or(n.name.value),
                    )),
                )
            }
            Node::AnonymousClass(_) => previous_class = Some(self.class.take()),
            Node::Identifier(id) if self.is_class_position(id) => {
                self.reference(resolved(self.names, id), id.span(), false)
            }
            Node::Method(method) => self.constructor(method),
            _ => {}
        }
        self.parents.push(node);
        node.visit_children(|child| self.walk(child));
        self.parents.pop();
        if let Some(namespace) = previous_namespace {
            self.namespace = namespace;
        }
        if let Some(class) = previous_class {
            self.class = class;
        }
    }
}

/// Parse immutable source bytes once and collect compact facts for all consumers.
/// No paths are opened and analyzed PHP is never executed.
pub fn analyze(source: &str) -> Result<SourceRecord, String> {
    if source.len() > MAX_SOURCE_BYTES {
        return Err("Class reference source exceeds 8 MiB.".into());
    }
    let arena = LocalArena::new();
    let program = parse_file_content(&arena, FileId::new(b"snapshot.php"), source.as_bytes());
    if program.has_errors() {
        return Err(format!("PHP parse failed: {:?}", program.errors[0]));
    }
    let names = NameResolver::new(&arena).resolve(program);
    let mut collector = Collector {
        names: &names,
        result: SourceRecord {
            references: vec![],
            constructors: vec![],
        },
        namespace: String::new(),
        class: None,
        parents: vec![],
        failure: None,
    };
    collector.walk(Node::Program(program));
    if let Some(error) = collector.failure {
        Err(error)
    } else {
        Ok(collector.result)
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    #[test]
    fn same_namespace_and_typed_constructors() {
        let result = analyze("<?php namespace Sample; final class Caller { public function __construct(public Peer $peer) {} }").unwrap();
        assert_eq!(result.references[0].name, "Sample\\Peer");
        assert_eq!(
            result.references[0].source_class.as_deref(),
            Some("Sample\\Caller")
        );
        assert_eq!(
            result.constructors[0].parameters[0].class_type.as_deref(),
            Some("Sample\\Peer")
        );
    }
    #[test]
    fn imports_functions_and_strings_are_separated() {
        let result = analyze("<?php namespace Sample; use Other\\{Peer as P, function run, const LIMIT}; class Caller { function make(): P { run(); $x = LIMIT; $s = 'Ignored'; return new P(); } }").unwrap();
        assert_eq!(
            result
                .references
                .iter()
                .filter(|r| r.name == "Other\\Peer")
                .count(),
            3
        );
        assert!(
            result
                .references
                .iter()
                .all(|r| !r.name.contains("run") && !r.name.contains("LIMIT"))
        );
    }
    #[test]
    fn malformed_and_oversized_sources_fail() {
        assert!(analyze("<?php class {").is_err());
        assert!(analyze(&" ".repeat(MAX_SOURCE_BYTES + 1)).is_err());
    }
}
