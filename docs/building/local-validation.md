# Local build validation

Use this workflow only after the active `AGENTS.md` build policy authorizes a
build. Documentation-only, script-only, and other clearly non-build-affecting
changes do not require compilation.

## Preset workflow

Prefer the CMake preset workflow over a Visual Studio solution unless the user
explicitly requests the solution or the preset is unusable. For normal local
validation, prefer the release preset because it uses the intended cache and
unity settings:

```bash
cmake --preset linux-release
cmake --build --preset linux-release --target canary
```

## Cache recovery

The following files and directories identify an active CMake/Ninja cache:

- `CMakeCache.txt`
- `CMakeFiles/`
- `build.ninja`
- `.ninja_deps`
- `.ninja_log`
- `cmake_install.cmake`
- `compile_commands.json`
- `vcpkg-manifest-install.log`

If CMake reports changed compiler variables, a missing
`CMAKE_MAKE_PROGRAM`, or an incompatible cache, first verify the affected
preset directory is inside `build/`. Remove only that preset directory, then
rerun the same preset. Do not switch to a generated solution merely because
configuration failed; repair the preset environment or cache first.


