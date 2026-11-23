# Local four-hour continuation

The user requested continuing the existing project every four hours without routine confirmation pauses. The helper in [continue-project.mjs](../scripts/codex/continue-project.mjs) submits the [continuation prompt](../scripts/codex/continuation-prompt.txt) through the installed `codex queue` command to an explicit existing session UUID. It does not create another agent process, change permissions or bypass usage limits.

## Registration and operation

Status: REGISTERED and read back on 2026-11-02. The task is Ready, with first timed run **2026-11-02 18:10:32 EAT (UTC+03:00)** and repetition `PT4H`. The action, same-session target, Limited/Interactive principal, IgnoreNew behavior and two-minute limit were verified. One direct submission and one manual Task Scheduler test run were accepted by the queue; the latter finished with result0 at 14:13 EAT. Nine mocked helper cases passed. The timed trigger at 18:10:32 EAT returned 0 and the helper recorded queue acceptance at 18:10:38 EAT; next run is 22:10:32 EAT. Queue acceptance confirms submission, not completion of a future turn.

The intended Windows task is `Harusi-Codex-Continue-4h`, owned by the current interactive user with limited privileges. Its first trigger is four hours after registration, then every four hours. It runs Node with the helper's absolute path, an explicit thread UUID and the verified installed Codex executable path. Multiple instances are ignored; the helper has a 90-second queue timeout and the task has a two-minute execution limit. No execution-policy override, stored password, wake request, service restart or independent worker is used.

The computer must be on, the user signed in, and the installed Codex session/queue available. Successful submission is serialized by the existing session queue; it does not guarantee that the account can run when a usage limit applies. The helper keeps one bounded status file under the user's TEMP directory, with a workspace/session-derived directory name printed by `--dry-run`. A local lock and a 3-hour-55-minute acceptance window prevent duplicate helper invocations from immediately queuing another message. Queue backlog across long-running turns is not inspected; future four-hour submissions can wait in the session queue.

The CLI path is fixed at registration. If the IDE extension moves or removes its executable during an update, the task fails instead of selecting another binary. Re-register against the verified new executable path when needed. A forcibly terminated helper can leave `queue.lock`; later invocations skip rather than deleting another process's lock. After confirming the task/helper has stopped, remove only that lock file to recover. TEMP status can be removed by system cleanup; Windows task settings remain the authoritative schedule.

## Inspect or stop

Use `Get-ScheduledTask -TaskName 'Harusi-Codex-Continue-4h'` and `Get-ScheduledTaskInfo -TaskName 'Harusi-Codex-Continue-4h'` to inspect the local task. Use `Disable-ScheduledTask -TaskName 'Harusi-Codex-Continue-4h'` to stop future submissions. A later cancellation from the user revokes scheduled continuation; disable the task and remove any unstarted scheduled inputs where the session supports it. Disabling the task does not cancel already queued messages.

This local automation is separate from the application roadmap. It does not close Phase3 rollout gates, advance Phase4 by itself, implement the approved UI, configure WhatsApp, run migrations or change admission rules.
