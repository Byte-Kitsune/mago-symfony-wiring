//! Bounded native syntax frontend. The adapter receives semantic node records,
//! not a second token stream to lex/parse in PHP.
use mago_allocator::LocalArena;
use mago_database::file::FileId;
use mago_names::{ResolvedNames, resolver::NameResolver};
use mago_span::{HasSpan, Span};
use mago_syntax::{cst::*, parser::parse_file_content};
use serde_json::{Value, json};

type Result<T> = std::result::Result<T, String>;
fn text(value: &[u8]) -> String {
    String::from_utf8_lossy(value).into_owned()
}
struct Emitter<'a, 'b> {
    names: &'a ResolvedNames<'b>,
    source: &'a str,
    trivia: Vec<(u32, u32, String, bool)>,
    lines: Vec<usize>,
}
impl Emitter<'_, '_> {
    fn node(&self, kind: &str, span: Span, fields: Value) -> Value {
        let mut node = fields;
        node["nodeType"] = json!(kind);
        let start = span.start.offset as usize;
        let end = span.end.offset as usize;
        node["attributes"] = json!({"startFilePos":start,"endFilePos":end.saturating_sub(1),"startLine":self.lines.partition_point(|&offset| offset <= start),"endLine":self.lines.partition_point(|&offset| offset < end)});
        node
    }
    fn comments(&self, mut node: Value, start: u32) -> Value {
        let mut comments = Vec::new();
        let mut boundary = start;
        for (a, b, content, doc) in self.trivia.iter().rev() {
            if *b > boundary {
                continue;
            }
            if !self.source.as_bytes()[*b as usize..boundary as usize]
                .iter()
                .all(u8::is_ascii_whitespace)
            {
                break;
            }
            comments.push(json!({"_comment":if *doc { "Doc" } else { "Comment" },"text":content,"startLine":self.lines.partition_point(|&offset| offset <= *a as usize),"startFilePos":a,"endLine":self.lines.partition_point(|&offset| offset < *b as usize),"endFilePos":b.saturating_sub(1)}));
            boundary = *a;
        }
        comments.reverse();
        node["attributes"]["comments"] = json!(comments);
        node
    }
    fn identifier(&self, id: &LocalIdentifier<'_>) -> Value {
        self.node("Identifier", id.span, json!({"name":text(id.value)}))
    }
    fn name(&self, id: &Identifier<'_>, function: bool) -> Value {
        let raw = text(id.value());
        let resolved = text(self.names.resolve(&id.span().start).unwrap_or(id.value()))
            .trim_start_matches('\\')
            .to_owned();
        if function && id.is_local() && !self.names.is_imported(&id.span().start) {
            let mut node = self.node("Name", id.span(), json!({"name":raw}));
            node["attributes"]["namespacedName"] =
                self.node("Name_FullyQualified", id.span(), json!({"name":resolved}));
            node
        } else {
            self.node("Name_FullyQualified", id.span(), json!({"name":resolved}))
        }
    }
    fn class_expr(&self, expr: &Expression<'_>) -> Result<Value> {
        match expr {
            Expression::Identifier(id) => Ok(self.name(id,false)),
            Expression::Self_(keyword) | Expression::Parent(keyword) | Expression::Static(keyword) => Ok(self.node("Name",keyword.span(),json!({"name":text(&self.source.as_bytes()[keyword.span().start.offset as usize..keyword.span().end.offset as usize])}))),
            _ => self.expr(expr),
        }
    }
    fn hint(&self, hint: &Hint<'_>) -> Result<Value> {
        let value = match hint {
            Hint::Identifier(id) => self.name(id,false),
            Hint::Nullable(n) => self.node("NullableType",n.span(),json!({"type":self.hint(n.hint)?})),
            Hint::Parenthesized(n) => self.hint(n.hint)?,
            Hint::Union(n) => self.node("UnionType",n.span(),json!({"types":[self.hint(n.left)?,self.hint(n.right)?]})),
            Hint::Intersection(n) => self.node("IntersectionType",n.span(),json!({"types":[self.hint(n.left)?,self.hint(n.right)?]})),
            _ => self.node("Identifier",hint.span(),json!({"name":&self.source[hint.span().start.offset as usize..hint.span().end.offset as usize]})),
        };
        Ok(value)
    }
    fn selector(&self, selector: &ClassLikeMemberSelector<'_>) -> Result<Value> {
        match selector {
            ClassLikeMemberSelector::Identifier(id) => Ok(self.identifier(id)),
            ClassLikeMemberSelector::Variable(v) => self.variable(v),
            ClassLikeMemberSelector::Expression(e) => self.expr(e.expression),
            _ => Err("Unsupported native syntax: missing member selector".into()),
        }
    }
    fn variable(&self, v: &Variable<'_>) -> Result<Value> {
        match v {
            Variable::Direct(v) => Ok(self.node(
                "Expr_Variable",
                v.span(),
                json!({"name":text(v.name).trim_start_matches('$')}),
            )),
            _ => Err("Unsupported native syntax: indirect variable".into()),
        }
    }
    fn args(&self, list: &ArgumentList<'_>) -> Result<Vec<Value>> {
        list.arguments
            .iter()
            .map(|arg| {
                let (value, name, unpack) = match arg {
                    Argument::Positional(arg) => (arg.value, Value::Null, arg.ellipsis.is_some()),
                    Argument::Named(arg) => (arg.value, self.identifier(&arg.name), false),
                };
                Ok(self.node(
                    "Arg",
                    arg.span(),
                    json!({"value":self.expr(value)?,"name":name,"unpack":unpack,"byRef":false}),
                ))
            })
            .collect()
    }
    fn partial_args(&self, list: &PartialArgumentList<'_>) -> Result<Vec<Value>> {
        list.arguments
            .iter()
            .map(|arg| {
                let (value, name, unpack) = match arg {
                    PartialArgument::Positional(a) => (a.value, Value::Null, a.ellipsis.is_some()),
                    PartialArgument::Named(a) => (a.value, self.identifier(&a.name), false),
                    PartialArgument::VariadicPlaceholder(_) => {
                        return Ok(self.node("VariadicPlaceholder", arg.span(), json!({})));
                    }
                    _ => return Err("Unsupported native syntax: partial placeholder".into()),
                };
                Ok(self.node(
                    "Arg",
                    arg.span(),
                    json!({"value":self.expr(value)?,"name":name,"unpack":unpack,"byRef":false}),
                ))
            })
            .collect()
    }
    fn attrs(&self, lists: &Sequence<'_, AttributeList<'_>>) -> Result<Vec<Value>> {
        lists.iter().map(|group| {
            let attrs: Result<Vec<_>> = group.attributes.iter().map(|a| Ok(self.node("Attribute",a.span(),json!({"name":self.name(&a.name,false),"args":a.argument_list.as_ref().map(|args| self.partial_args(args)).transpose()?.unwrap_or_default()})))).collect();
            Ok(self.node("AttributeGroup",group.span(),json!({"attrs":attrs?})))
        }).collect()
    }
    fn flags(&self, modifiers: &Sequence<'_, Modifier<'_>>) -> u32 {
        modifiers
            .iter()
            .map(|m| match m {
                Modifier::Public(_) => 1,
                Modifier::Protected(_) => 2,
                Modifier::Private(_) => 4,
                Modifier::Static(_) => 8,
                Modifier::Abstract(_) => 16,
                Modifier::Final(_) => 32,
                Modifier::Readonly(_) => 64,
                _ => 0,
            })
            .fold(0, |a, b| a | b)
    }
    fn params(&self, list: &FunctionLikeParameterList<'_>) -> Result<Vec<Value>> {
        list.parameters.iter().map(|p| { if p.hooks.is_some() { return Err("Unsupported native syntax: promoted property hooks".into()); } Ok(self.node("Param",p.span(),json!({"var":self.node("Expr_Variable",p.variable.span(),json!({"name":text(p.variable.name).trim_start_matches('$')})),"type":p.hint.as_ref().map(|h|self.hint(h)).transpose()?,"default":p.default_value.as_ref().map(|v|self.expr(v.value)).transpose()?,"byRef":p.ampersand.is_some(),"variadic":p.ellipsis.is_some(),"flags":self.flags(&p.modifiers),"attrGroups":self.attrs(&p.attribute_lists)?,"hooks":[]}))) }).collect()
    }
    fn array(
        &self,
        span: Span,
        items: &TokenSeparatedSequence<'_, ArrayElement<'_>>,
    ) -> Result<Value> {
        let items: Result<Vec<_>> = items
            .iter()
            .map(|item| {
                let (key, value, unpack) = match item {
                    ArrayElement::Value(a) => (Value::Null, a.value, false),
                    ArrayElement::KeyValue(a) => (self.expr(a.key)?, a.value, false),
                    ArrayElement::Variadic(a) => (Value::Null, a.value, true),
                    ArrayElement::Missing(_) => return Ok(Value::Null),
                };
                Ok(self.node(
                    "ArrayItem",
                    item.span(),
                    json!({"key":key,"value":self.expr(value)?,"unpack":unpack,"byRef":false}),
                ))
            })
            .collect();
        Ok(self.node("Expr_Array", span, json!({"items":items?})))
    }
    fn expr(&self, expr: &Expression<'_>) -> Result<Value> {
        let span = expr.span();
        Ok(match expr {
            Expression::Parenthesized(p) => self.expr(p.expression)?,
            Expression::Variable(v) => self.variable(v)?,
            Expression::Identifier(id) => self.node("Expr_ConstFetch",span,json!({"name":self.name(id,false)})),
            Expression::ConstantAccess(c) => self.node("Expr_ConstFetch",span,json!({"name":self.name(&c.name,false)})),
            Expression::Literal(value) => match value {
                Literal::String(v) => self.node("Scalar_String",span,json!({"value":std::str::from_utf8(v.value.ok_or("Unsupported native syntax: undecoded string")?).map_err(|_|"Unsupported native syntax: binary string")?})),
                Literal::Integer(v) => self.node("Scalar_Int",span,json!({"value":i64::try_from(v.value.ok_or("Unsupported native syntax: integer literal")?).map_err(|_|"Unsupported native syntax: large integer")?})),
                Literal::Float(v) => self.node("Scalar_Float",span,json!({"value":v.value.0})),
                Literal::True(_) | Literal::False(_) | Literal::Null(_) => self.node("Expr_ConstFetch",span,json!({"name":self.node("Name",span,json!({"name":&self.source[span.start.offset as usize..span.end.offset as usize]}))})),
            },
            Expression::Instantiation(n) => self.node("Expr_New",span,json!({"class":self.class_expr(n.class)?,"args":n.argument_list.as_ref().map(|a|self.args(a)).transpose()?.unwrap_or_default()})),
            Expression::Call(c) => match c {
                Call::Function(c) => self.node("Expr_FuncCall",span,json!({"name":match c.function { Expression::Identifier(id) => self.name(id,true), _ => self.expr(c.function)? },"args":self.args(&c.argument_list)?})),
                Call::Method(c) => self.node("Expr_MethodCall",span,json!({"var":self.expr(c.object)?,"name":self.selector(&c.method)?,"args":self.args(&c.argument_list)?})),
                Call::NullSafeMethod(c) => self.node("Expr_NullsafeMethodCall",span,json!({"var":self.expr(c.object)?,"name":self.selector(&c.method)?,"args":self.args(&c.argument_list)?})),
                Call::StaticMethod(c) => self.node("Expr_StaticCall",span,json!({"class":self.class_expr(c.class)?,"name":self.selector(&c.method)?,"args":self.args(&c.argument_list)?})),
            },
            Expression::PartialApplication(c) => match c {
                PartialApplication::Function(c) => self.node("Expr_FuncCall",span,json!({"name":match c.function { Expression::Identifier(id) => self.name(id,true), _ => self.expr(c.function)? },"args":self.partial_args(&c.argument_list)?})),
                PartialApplication::Method(c) => self.node("Expr_MethodCall",span,json!({"var":self.expr(c.object)?,"name":self.selector(&c.method)?,"args":self.partial_args(&c.argument_list)?})),
                PartialApplication::StaticMethod(c) => self.node("Expr_StaticCall",span,json!({"class":self.class_expr(c.class)?,"name":self.selector(&c.method)?,"args":self.partial_args(&c.argument_list)?})),
            },
            Expression::Access(a) => match a {
                Access::Property(a) => self.node("Expr_PropertyFetch",span,json!({"var":self.expr(a.object)?,"name":self.selector(&a.property)?})),
                Access::NullSafeProperty(a) => self.node("Expr_NullsafePropertyFetch",span,json!({"var":self.expr(a.object)?,"name":self.selector(&a.property)?})),
                Access::StaticProperty(a) => self.node("Expr_StaticPropertyFetch",span,json!({"class":self.class_expr(a.class)?,"name":self.variable(&a.property)?})),
                Access::ClassConstant(a) => self.node("Expr_ClassConstFetch",span,json!({"class":self.class_expr(a.class)?,"name":match &a.constant { ClassLikeConstantSelector::Identifier(id) => self.identifier(id), ClassLikeConstantSelector::Expression(e) => self.expr(e.expression)?, _ => return Err("Unsupported native syntax: missing constant selector".into()) }})),
            },
            Expression::Assignment(a) => {
                if let Expression::UnaryPrefix(reference) = a.rhs && reference.operator.is_reference() {
                    self.node("Expr_AssignRef",span,json!({"var":self.expr(a.lhs)?,"expr":self.expr(reference.operand)?}))
                } else { self.node(if a.operator.is_assign() { "Expr_Assign" } else { match &a.operator { AssignmentOperator::Addition(_) => "Expr_AssignOp_Plus", AssignmentOperator::Subtraction(_) => "Expr_AssignOp_Minus", _ => return Err("Unsupported native syntax: assignment operator".into()) } },span,json!({"var":self.expr(a.lhs)?,"expr":self.expr(a.rhs)?})) }
            },
            Expression::Binary(b) => {
                if matches!(b.operator,BinaryOperator::Instanceof(_)) { self.node("Expr_Instanceof",span,json!({"expr":self.expr(b.lhs)?,"class":self.class_expr(b.rhs)?})) }
                else { let operator = match b.operator { BinaryOperator::Addition(_) => "Plus", BinaryOperator::Subtraction(_) => "Minus", BinaryOperator::Multiplication(_) => "Mul", BinaryOperator::Division(_) => "Div", BinaryOperator::Modulo(_) => "Mod", BinaryOperator::Equal(_) => "Equal", BinaryOperator::NotEqual(_) | BinaryOperator::AngledNotEqual(_) => "NotEqual", BinaryOperator::Identical(_) => "Identical", BinaryOperator::NotIdentical(_) => "NotIdentical", BinaryOperator::LessThan(_) => "Smaller", BinaryOperator::LessThanOrEqual(_) => "SmallerOrEqual", BinaryOperator::GreaterThan(_) => "Greater", BinaryOperator::GreaterThanOrEqual(_) => "GreaterOrEqual", BinaryOperator::And(_) => "BooleanAnd", BinaryOperator::Or(_) => "BooleanOr", BinaryOperator::NullCoalesce(_) => "Coalesce", BinaryOperator::StringConcat(_) => "Concat", _ => return Err("Unsupported native syntax: binary operator".into()) }; self.node(&format!("Expr_BinaryOp_{operator}"),span,json!({"left":self.expr(b.lhs)?,"right":self.expr(b.rhs)?})) }
            },
            Expression::Yield(y) => match y {
                Yield::Value(y) => self.node("Expr_Yield",span,json!({"key":null,"value":y.value.map(|v|self.expr(v)).transpose()?})),
                Yield::Pair(y) => self.node("Expr_Yield",span,json!({"key":self.expr(y.key)?,"value":self.expr(y.value)?})),
                Yield::From(y) => self.node("Expr_YieldFrom",span,json!({"expr":self.expr(y.iterator)?})),
            },
            Expression::Pipe(p) => self.node("Expr_BinaryOp_Pipe",span,json!({"left":self.expr(p.input)?,"right":self.expr(p.callable)?})),
            Expression::Conditional(c) => self.node("Expr_Ternary",span,json!({"cond":self.expr(c.condition)?,"if":c.then.map(|e|self.expr(e)).transpose()?,"else":self.expr(c.r#else)?})),
            Expression::Array(a) => self.array(span,&a.elements)?,
            Expression::LegacyArray(a) => self.array(span,&a.elements)?,
            Expression::ArrayAccess(a) => self.node("Expr_ArrayDimFetch",span,json!({"var":self.expr(a.array)?,"dim":self.expr(a.index)?})),
            Expression::ArrayAppend(a) => self.node("Expr_ArrayDimFetch",span,json!({"var":self.expr(a.array)?,"dim":null})),
            Expression::Throw(t) => self.node("Expr_Throw",span,json!({"expr":self.expr(t.exception)?})),
            Expression::UnaryPrefix(u) => self.node(match u.operator { UnaryPrefixOperator::Not(_) => "Expr_BooleanNot", UnaryPrefixOperator::Negation(_) => "Expr_UnaryMinus", UnaryPrefixOperator::Plus(_) => "Expr_UnaryPlus", UnaryPrefixOperator::PreIncrement(_) => "Expr_PreInc", UnaryPrefixOperator::PreDecrement(_) => "Expr_PreDec", _ => return Err("Unsupported native syntax: unary prefix".into()) },span,json!({"expr":self.expr(u.operand)?})),
            Expression::UnaryPostfix(u) => self.node(match u.operator { UnaryPostfixOperator::PostIncrement(_) => "Expr_PostInc", UnaryPostfixOperator::PostDecrement(_) => "Expr_PostDec" },span,json!({"var":self.expr(u.operand)?})),
            Expression::Closure(c) => self.node("Expr_Closure",span,json!({"params":self.params(&c.parameter_list)?,"uses":c.use_clause.as_ref().map(|u| u.variables.iter().map(|v|self.node("ClosureUse",v.span(),json!({"var":self.node("Expr_Variable",v.variable.span(),json!({"name":text(v.variable.name).trim_start_matches('$')})),"byRef":v.ampersand.is_some()}))).collect::<Vec<_>>()).unwrap_or_default(),"stmts":self.statements(c.body.statements.as_slice())?,"attrGroups":self.attrs(&c.attribute_lists)?,"returnType":c.return_type_hint.as_ref().map(|h|self.hint(&h.hint)).transpose()?,"static":c.r#static.is_some(),"byRef":c.ampersand.is_some()})),
            Expression::ArrowFunction(c) => self.node("Expr_ArrowFunction",span,json!({"params":self.params(&c.parameter_list)?,"expr":self.expr(c.expression)?,"attrGroups":self.attrs(&c.attribute_lists)?,"returnType":c.return_type_hint.as_ref().map(|h|self.hint(&h.hint)).transpose()?,"static":c.r#static.is_some(),"byRef":c.ampersand.is_some()})),
            _ => return Err(format!("Unsupported native syntax: expression {expr}")),
        })
    }
    fn statements(&self, statements: &[Statement<'_>]) -> Result<Vec<Value>> {
        let mut result = Vec::new();
        for stmt in statements {
            if let Statement::Block(block) = stmt {
                result.extend(self.statements(block.statements.as_slice())?);
                continue;
            }
            if let Some(value) = self.statement(stmt)? {
                result.push(value);
            }
        }
        Ok(result)
    }
    fn statement(&self, stmt: &Statement<'_>) -> Result<Option<Value>> {
        let span = stmt.span();
        let value = match stmt {
            Statement::OpeningTag(_) | Statement::ClosingTag(_) | Statement::Inline(_) | Statement::Use(_) => return Ok(None),
            Statement::Declare(d) => self.node("Stmt_Declare",span,json!({"declares":d.items.iter().map(|item|Ok(self.node("DeclareItem",item.span(),json!({"key":self.identifier(&item.name),"value":self.expr(item.value)?})))).collect::<Result<Vec<_>>>()?,"stmts":match &d.body { DeclareBody::Statement(Statement::Noop(_)) => None, DeclareBody::Statement(stmt) => Some(self.statements(std::slice::from_ref(*stmt))?), DeclareBody::ColonDelimited(body) => Some(self.statements(body.statements.as_slice())?) }})),
            Statement::Noop(_) => self.node("Stmt_Nop",span,json!({})),
            Statement::Namespace(n) => self.node("Stmt_Namespace",span,json!({"name":n.name.as_ref().map(|id| self.node("Name",id.span(),json!({"name":text(id.value())}))),"stmts":self.statements(n.statements().as_slice())?})),
            Statement::Class(c) => self.comments(self.node("Stmt_Class",span,json!({"name":self.identifier(&c.name),"namespacedName":self.node("Name_FullyQualified",c.name.span,json!({"name":text(self.names.resolve(&c.name.span.start).unwrap_or(c.name.value))})),"flags":self.flags(&c.modifiers),"extends":c.extends.as_ref().and_then(|e|e.types.first()).map(|id|self.name(id,false)),"implements":c.implements.as_ref().map(|i|i.types.iter().map(|id|self.name(id,false)).collect::<Vec<_>>()).unwrap_or_default(),"stmts":self.members(&c.members)?,"attrGroups":self.attrs(&c.attribute_lists)?})),span.start.offset),
            Statement::Trait(t) => self.comments(self.node("Stmt_Trait",span,json!({"name":self.identifier(&t.name),"namespacedName":self.node("Name_FullyQualified",t.name.span,json!({"name":text(self.names.resolve(&t.name.span.start).unwrap_or(t.name.value))})),"stmts":self.members(&t.members)?,"attrGroups":self.attrs(&t.attribute_lists)?})),span.start.offset),
            Statement::Interface(i) => self.comments(self.node("Stmt_Interface",span,json!({"name":self.identifier(&i.name),"namespacedName":self.node("Name_FullyQualified",i.name.span,json!({"name":text(self.names.resolve(&i.name.span.start).unwrap_or(i.name.value))})),"extends":i.extends.as_ref().map(|e|e.types.iter().map(|id|self.name(id,false)).collect::<Vec<_>>()).unwrap_or_default(),"stmts":self.members(&i.members)?,"attrGroups":self.attrs(&i.attribute_lists)?})),span.start.offset),
            Statement::Constant(c) => self.node("Stmt_Const",span,json!({"consts":c.items.iter().map(|item| Ok(self.node("Const",item.span(),json!({"name":self.identifier(&item.name),"namespacedName":self.node("Name_FullyQualified",item.name.span,json!({"name":text(self.names.resolve(&item.name.span.start).unwrap_or(item.name.value))})),"value":self.expr(item.value)?})))).collect::<Result<Vec<_>>>()?})),
            Statement::Function(f) => self.comments(self.node("Stmt_Function",span,json!({"name":self.identifier(&f.name),"namespacedName":self.node("Name_FullyQualified",f.name.span,json!({"name":text(self.names.resolve(&f.name.span.start).unwrap_or(f.name.value))})),"params":self.params(&f.parameter_list)?,"stmts":self.statements(f.body.statements.as_slice())?,"byRef":f.ampersand.is_some(),"returnType":f.return_type_hint.as_ref().map(|h|self.hint(&h.hint)).transpose()?,"attrGroups":self.attrs(&f.attribute_lists)?})),span.start.offset),
            Statement::Expression(e) => self.node("Stmt_Expression",span,json!({"expr":self.expr(e.expression)?})),
            Statement::Return(r) => self.node("Stmt_Return",span,json!({"expr":r.value.map(|e|self.expr(e)).transpose()?})),
            Statement::If(i) => self.node("Stmt_If",span,json!({"cond":self.expr(i.condition)?,"stmts":self.statements(i.body.statements())?,"elseifs":i.body.else_if_clauses().into_iter().map(|(cond,stmts)|Ok(self.node("Stmt_ElseIf",cond.span(),json!({"cond":self.expr(cond)?,"stmts":self.statements(stmts)?})))).collect::<Result<Vec<_>>>()?,"else":i.body.else_statements().map(|stmts|Ok::<_,String>(self.node("Stmt_Else",span,json!({"stmts":self.statements(stmts)?})))).transpose()?})),
            Statement::Foreach(f) => {
                let (target, by_ref) = match f.target.value() { Expression::UnaryPrefix(reference) if reference.operator.is_reference() => (reference.operand, true), target => (target,false) };
                self.node("Stmt_Foreach",span,json!({"expr":self.expr(f.expression)?,"stmts":self.statements(f.body.statements())?,"valueVar":self.expr(target)?,"keyVar":f.target.key().map(|key|self.expr(key)).transpose()?,"byRef":by_ref}))
            },
            Statement::Try(t) => self.node("Stmt_TryCatch",span,json!({"stmts":self.statements(t.block.statements.as_slice())?,"catches":t.catch_clauses.iter().map(|c|Ok(self.node("Stmt_Catch",c.span(),json!({"types":[self.hint(&c.hint)?],"var":c.variable.as_ref().map(|v|self.node("Expr_Variable",v.span(),json!({"name":text(v.name).trim_start_matches('$')}))),"stmts":self.statements(c.block.statements.as_slice())?})))).collect::<Result<Vec<_>>>()?,"finally":t.finally_clause.as_ref().map(|f|Ok::<_,String>(self.node("Stmt_Finally",f.span(),json!({"stmts":self.statements(f.block.statements.as_slice())?})))).transpose()?})),
            Statement::While(w) => self.node("Stmt_While",span,json!({"cond":self.expr(w.condition)?,"stmts":self.statements(w.body.statements())?})),
            Statement::DoWhile(w) => self.node("Stmt_Do",span,json!({"cond":self.expr(w.condition)?,"stmts":self.statements(std::slice::from_ref(w.statement))?})),
            Statement::For(_) | Statement::Switch(_) | Statement::Echo(_) | Statement::Break(_) | Statement::Continue(_) => return Err(format!("Unsupported native syntax: statement {stmt}")),
            _ => return Err(format!("Unsupported native syntax: statement {stmt}")),
        };
        Ok(Some(value))
    }
    fn members(&self, members: &Sequence<'_, ClassLikeMember<'_>>) -> Result<Vec<Value>> {
        members.iter().map(|member| {
            let span = member.span();
            Ok(match member {
                ClassLikeMember::Method(m) => self.comments(self.node("Stmt_ClassMethod",span,json!({"name":self.identifier(&m.name),"flags":self.flags(&m.modifiers),"params":self.params(&m.parameter_list)?,"stmts":match &m.body { MethodBody::Concrete(b) => Some(self.statements(b.statements.as_slice())?), _ => None },"byRef":m.ampersand.is_some(),"returnType":m.return_type_hint.as_ref().map(|h|self.hint(&h.hint)).transpose()?,"attrGroups":self.attrs(&m.attribute_lists)?})),span.start.offset),
                ClassLikeMember::Property(Property::Plain(p)) => self.node("Stmt_Property",span,json!({"flags":self.flags(&p.modifiers),"type":p.hint.as_ref().map(|h|self.hint(h)).transpose()?,"attrGroups":self.attrs(&p.attribute_lists)?,"props":p.items.iter().map(|item| { let (var,default) = match item { PropertyItem::Abstract(p) => (&p.variable,Value::Null),PropertyItem::Concrete(p) => (&p.variable,self.expr(p.value)?) }; Ok(self.node("PropertyItem",item.span(),json!({"name":self.node("VarLikeIdentifier",var.span(),json!({"name":text(var.name).trim_start_matches('$')})),"default":default}))) }).collect::<Result<Vec<_>>>()?})),
                ClassLikeMember::Constant(c) => self.node("Stmt_ClassConst",span,json!({"flags":self.flags(&c.modifiers),"consts":c.items.iter().map(|item| Ok(self.node("Const",item.span(),json!({"name":self.identifier(&item.name),"value":self.expr(item.value)?})))).collect::<Result<Vec<_>>>()?})),
                ClassLikeMember::TraitUse(t) => {
                    let traits=t.trait_names.iter().map(|id|self.name(id,false)).collect::<Vec<_>>();
                    let adaptations=match &t.specification {
                        TraitUseSpecification::Abstract(_) => vec![],
                        TraitUseSpecification::Concrete(c) => c.adaptations.iter().map(|a| match a {
                            TraitUseAdaptation::Precedence(a) => self.node("Stmt_TraitUseAdaptation_Precedence",a.span(),json!({"trait":self.name(&a.method_reference.trait_name,false),"method":self.identifier(&a.method_reference.method_name),"insteadof":a.trait_names.iter().map(|id|self.name(id,false)).collect::<Vec<_>>()})),
                            TraitUseAdaptation::Alias(a) => {
                                let (trait_,method)=match &a.method_reference { TraitUseMethodReference::Identifier(id) => (Value::Null,self.identifier(id)),TraitUseMethodReference::Absolute(r) => (self.name(&r.trait_name,false),self.identifier(&r.method_name)) };
                                let modifier=a.modifier.as_ref().map(|m|match m {Modifier::Public(_) =>1,Modifier::Protected(_) =>2,Modifier::Private(_) =>4,_=>0});
                                self.node("Stmt_TraitUseAdaptation_Alias",a.span(),json!({"trait":trait_,"method":method,"newModifier":modifier,"newName":a.alias.as_ref().map(|id|self.identifier(id))}))
                            }
                        }).collect(),
                    };
                    self.node("Stmt_TraitUse",span,json!({"traits":traits,"adaptations":adaptations}))
                },
                _ => return Err(format!("Unsupported native syntax: class member {member}")),
            })
        }).collect()
    }
}

pub fn parse(source: &str) -> Result<Vec<Value>> {
    if source.len() > 8 * 1024 * 1024 {
        return Err("Native syntax source exceeds 8 MiB.".into());
    }
    let arena = LocalArena::new();
    let program = parse_file_content(&arena, FileId::new(b"snapshot.php"), source.as_bytes());
    if program.has_errors() {
        return Err(format!("PHP parse failed: {:?}", program.errors[0]));
    }
    let names = NameResolver::new(&arena).resolve(program);
    let trivia = program
        .trivia
        .iter()
        .filter(|t| format!("{:?}", t.kind).contains("Comment"))
        .map(|t| {
            (
                t.span.start.offset,
                t.span.end.offset,
                text(t.value),
                text(t.value).starts_with("/**"),
            )
        })
        .collect();
    let lines = std::iter::once(0)
        .chain(
            source
                .as_bytes()
                .iter()
                .enumerate()
                .filter_map(|(i, &byte)| (byte == b'\n').then_some(i + 1)),
        )
        .collect();
    Emitter {
        names: &names,
        source,
        trivia,
        lines,
    }
    .statements(program.statements.as_slice())
}

#[cfg(test)]
mod tests {
    use super::*;
    #[test]
    fn yield_forms_are_preserved_and_promoted_hooks_are_rejected() {
        let parsed =
            parse("<?php function values(){yield; yield 1; yield 2=>3; yield from [4];}").unwrap();
        let text = serde_json::to_string(&parsed).unwrap();
        assert!(text.contains("Expr_YieldFrom"));
        assert_eq!(text.matches("\"nodeType\":\"Expr_Yield\"").count(), 3);
        assert!(parse("<?php class Hooked {public function __construct(public int $value { get => 1; }) {}}").is_err());
        assert!(parse("<?php class Hooked {public int $value { get => 1; }}").is_err());
    }

    #[test]
    fn out_of_range_integer_is_not_rewritten_as_a_proven_guard() {
        assert!(parse("<?php final class Counter { function run(int $n): void { if ($n <= 0) return; $this->run($n - 18446744073709551615); } }").is_err());
    }
    #[test]
    fn closure_body_and_canonical_declarations_are_preserved() {
        let nodes=parse("<?php namespace Example; final class Consumer { function make(): void { $f = function () { Worker::run(); }; } }").unwrap();
        let encoded = serde_json::to_string(&nodes).unwrap();
        assert!(encoded.contains("Expr_Closure"));
        assert!(encoded.contains("Expr_StaticCall"));
        assert!(encoded.contains("Example\\\\Consumer"));
    }
}
