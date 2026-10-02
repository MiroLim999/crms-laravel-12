# How to Run the Implementation Plan

A step-by-step script for working through [IMPLEMENTATION_PLAN.md](IMPLEMENTATION_PLAN.md) with Claude Code, **one phase per conversation**. Copy each prompt exactly as written.

---

## Before every session

- [ ] Start **MySQL** in the XAMPP Control Panel. The tests need it.
- [ ] Open this project in VS Code, and open the Claude panel.
- [ ] Start a **new conversation** by clicking **+** in the Claude panel.

You only need to run `.\serve.ps1` when a step asks you to check something in the browser.

---

## Step 1: Set up (Phase 0)

**Prompt:**

```
Execute Phase 0 of @docs/IMPLEMENTATION_PLAN.md one task at a time and tick the checklist. Task 0.1 branch = A. You may commit docs/.
```

**When Claude finishes:**

- [ ] Check that it reports **409 tests passing** (PHP 262, JavaScript 85, Python 62).
- [ ] Check that you're on the new branch `review-fixes`.

---

## Step 2: Priority 1 fixes (Phase 1)

**Start a new conversation**, then send:

```
Execute Phase 1 of @docs/IMPLEMENTATION_PLAN.md one task at a time and tick the checklist. Stop at the Phase 1 checkpoint.
```

**When Claude stops at the checkpoint:**

- [ ] Run `.\serve.ps1`.
- [ ] Do the four browser checks listed under "Phase 1 checkpoint" in the plan.
- [ ] Tell Claude the result:
  ```
  Phase 1 checkpoint: all checks OK
  ```
  If something is wrong, describe what you saw instead.
- [ ] Review the changes in VS Code Source Control (`Ctrl+Shift+G`), or ask:
  ```
  Explain the Phase 1 changes in simple terms
  ```
- [ ] Commit:
  ```
  Commit each Phase 1 task separately
  ```

---

## Step 3: Priority 2 fixes (Phase 2)

**Start a new conversation**, then send:

```
Execute Phase 2 of @docs/IMPLEMENTATION_PLAN.md one task at a time and tick the checklist. Decisions: Task 2.1 = A, Task 2.3 = A if the boxes are off. Stop for [You] steps and at the Phase 2 checkpoint.
```

**During this phase, Claude will ask you to:**

- [ ] **Task 2.2:** test the "Submit anyway" dialog in the browser.
- [ ] **Task 2.3:** scan a tilted page and say whether the highlight boxes line up, e.g. `Task 2.3 check: the boxes line up`.
- [ ] **Task 2.5:** restart `serve.ps1` and confirm the scheduler is running.
- [ ] **Task 2.6:** turn off the internet and open a PDF.
- [ ] **Tasks 2.7 and 2.8:** restart the AI service window, then scan one page.

**When Claude stops at the checkpoint:**

- [ ] Do the browser checks listed under "Phase 2 checkpoint" in the plan, and report the result.
- [ ] Review the changes.
- [ ] Commit:
  ```
  Commit each Phase 2 task separately
  ```

---

## Step 4: Cleanup (Phase 3)

**Start a new conversation**, then send:

```
Execute Phase 3 of @docs/IMPLEMENTATION_PLAN.md one task at a time and tick the checklist. Decisions: Task 3.7 = A, Task 3.8 = A, Task 3.9 = A, Task 3.10 = A (postpone). Stop for [You] steps and at the Phase 3 checkpoint.
```

**During this phase:**

- [ ] **Task 3.3:** restart the AI service window and read one page.
- [ ] **Task 3.7:** back up the database before Claude drops the unused column. Claude will ask first.

**When Claude stops at the checkpoint:**

- [ ] Click through the app as listed under "Phase 3 checkpoint", and report the result.
- [ ] Review the changes.
- [ ] Commit:
  ```
  Commit each Phase 3 task separately
  ```

---

## Step 5: Speed (Phase 4)

**Start a new conversation**, then send:

```
Execute Phase 4 of @docs/IMPLEMENTATION_PLAN.md one task at a time and tick the checklist. Decision: Task 4.4 = A.
```

**During this phase:**

- [ ] **Task 4.3:** keep the AI service running, then restart it when Claude asks, so it can time reading a page before and after.
- [ ] **Task 4.4:** open the dashboard in another tab while "Test layout" runs.

**When Claude finishes:**

- [ ] Review the changes.
- [ ] Commit:
  ```
  Commit each Phase 4 task separately
  ```

---

## Step 6: After the defense

**Phase 5** holds the big refactors, so leave it until after the defense. Claude asks before starting each task. For each one, start a new conversation and send:

```
Execute Task 5.1 from @docs/IMPLEMENTATION_PLAN.md and tick the checklist.
```

Do the same for Task 5.2 and Task 5.3.

**Phase 6** is the wrap-up. Start a new conversation and send:

```
Execute Phase 6 of @docs/IMPLEMENTATION_PLAN.md and tick the checklist. Task 6.1 = A.
```

- [ ] Restart all services, then do the final browser checks.
- [ ] Commit:
  ```
  Commit Phase 6
  ```

---

## Useful prompts at any time

| When you want to… | Type |
|---|---|
| See how far you are | `Show the status of @docs/IMPLEMENTATION_PLAN.md` |
| Understand a task before doing it | `Explain Task 1.5 from @docs/IMPLEMENTATION_PLAN.md before doing anything` |
| Understand what changed | `Explain the Task 1.1 changes in simple terms` |
| Skip a task on purpose | `Mark Task 2.9 in @docs/IMPLEMENTATION_PLAN.md as not needed: <reason>` |
| Continue after a break | `Execute the next task in @docs/IMPLEMENTATION_PLAN.md` |

---

## If something goes wrong

| What you see | What to do |
|---|---|
| Claude stops partway through a phase | That's normal: it needs your answer or a **[You]** step. Answer it, and it continues. |
| The tests can't connect to the database | MySQL isn't running. Start it in XAMPP, then say `Run the tests again`. |
| Claude says an earlier task isn't done | Run that task first, e.g. `Execute Task 1.2 from @docs/IMPLEMENTATION_PLAN.md`. |
| A test fails for an unrelated reason | Claude stops and explains. Ask `What should we do about this failing test?` |
| You want a different decision | Change the letter in your prompt. Each task in the plan explains its options. |

**About the decisions in these prompts:** they're the plan's recommended options. You can change any letter before sending.
