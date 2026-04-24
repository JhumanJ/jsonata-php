# Upstream JSONata Test Suite Fixtures

This directory vendors the JSONata JavaScript test-suite fixtures used for parity testing.

- Source repository: https://github.com/jsonata-js/jsonata
- Source paths:
  - `test/test-suite/datasets`
  - `test/test-suite/groups`
- Imported from commit: `597e5ee6ada3e13eaa4880f00468dcc1cba21142`
- Upstream license: MIT, matching the JSONata JavaScript project.

The files are copied into this repository so the PHP port can run deterministic parity checks without cloning the upstream repository at test time. `tests/Unit/UpstreamParityTest.php` enumerates the full vendored fixture catalog. Cases that already match the PHP implementation are executed against the local `jsonata` npm package; remaining upstream fixtures are tracked as explicit skipped parity work.
