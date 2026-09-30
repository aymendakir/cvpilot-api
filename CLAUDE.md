## Working rules
- Work in phases. One phase = one branch = one PR. Never start a new phase until the previous PR is merged into main.
- Never open more than one PR at a time.
- For any feature or refactor: write the spec first, then STOP and wait for my approval before /plan or /build.
- Priority order: audit → cleanup → API/backend → ATS checker → UI/UX → new features → ship.
- The ATS checker is the core of the product. The UI must be built on what the API actually returns.
- Behavior must not change during cleanup/refactor unless the spec says so.
- Commit in small, atomic steps. Run Prettier and lint before every commit.
