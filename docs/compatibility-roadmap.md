# Compatibility Roadmap

This document tracks the remaining parity work against `jsonata-js/jsonata` after the first upstream-fixture import.

## Implemented in this wave

- Structured upstream parity harness in `tests/Unit/UpstreamParityTest.php`
- External variable `bindings` support in `ExpressionService`/`Evaluator`
- Better string parity for Unicode-aware `$length`, `$substring`, `$trim`, `$pad`
- Stricter type handling for `$contains`
- Duplicate datetime builtin registrations removed
- Optional conditional branch support (`condition ? then`) now returns empty sequence when false
- Negative numeric subscripts now work for simple selectors

## Remaining gaps

### Sequence semantics and missing propagation

- Missing values are still normalized too early when flowing into builtins and callbacks.
- This shows up in upstream-style cases where `undefined` should stay missing instead of becoming `null` and then a singleton value.
- Concrete examples:
  - `hof-single/case001.json`
  - `function-append/case005.json`

### Path and selector semantics

- Multi-selector array subscripts are not yet fully JSONata-compatible.
- Nested range selectors and mixed selectors still diverge.
- Concrete examples:
  - `multiple-array-selectors/case000.json`
  - `multiple-array-selectors/case001.json`
  - `multiple-array-selectors/case002.json`
- Path-step subscript application over projected arrays is still incomplete.
- Concrete example:
  - `simple-array-selectors/case000.json`
- Descendant traversal still over-collects in some structures.
- Concrete example:
  - `descendent-operator/case001.json`

### Parent operator semantics and errors

- `%` still needs tighter parser/runtime validation and richer traversal behavior.
- Parent-based projections with richer path shapes are not fully aligned.
- Concrete examples:
  - `parent-operator/parent.json`
  - `parent-operator/errors.json`

### Signature and coercion parity

- Nested typed signatures and exact mismatch behavior are not complete.
- Some parser/signature interactions still produce syntax errors where JSONata produces type errors.
- Concrete example:
  - `function-signatures/case030.json`

### Higher-order corner cases

- Some closure and reducer cases still diverge because missing/context propagation into callbacks is not yet exact.
- Concrete examples:
  - `hof-reduce/case001.json`
  - `hof-single/case001.json`

### Parser and error model

- The engine still does not guarantee upstream parser codes/messages/tokens/positions 1:1.
- Important uncovered areas:
  - unterminated string/backtick cases
  - parent operator parser recovery
  - exact token reporting for signature/type mismatches

### Regex / datetime / transforms coverage width

- Core representative fixtures are covered, but the imported set is still curated rather than exhaustive.
- Still missing wider imports for:
  - advanced regex error cases
  - broader datetime picture/timezone fixtures
  - full transform matrix

### Stdlib completeness audit

- The current harness covers representative builtins, not a full function-by-function upstream audit.
- Priority files remain:
  - `src/Builtins/RegistersCollectionBuiltins.php`
  - `src/Builtins/RegistersStringBuiltins.php`
  - `src/Builtins/RegistersObjectBuiltins.php`
  - `src/Builtins/RegistersDatetimeBuiltins.php`
  - `src/Builtins/RegistersMetaBuiltins.php`

## Recommended next implementation order

1. Preserve missing values through function calls and chain application.
2. Rework subscript semantics for multi-selectors and projected arrays.
3. Tighten parent operator parsing/runtime/error codes.
4. Expand signature grammar and mismatch reporting.
5. Import the next upstream fixture tranche for errors, transforms, regex, and datetime.
