# Outlaw Retention Lock

Work in progress for N5-24. This directory is the dedicated app source,
separate from the server-fork enforcement changes and infrastructure tests.
It is installed only in the independent disposable HTTP qualification copy.
It has not been published or qualified for real files.

The initial service validates administrator identity and a required reason,
persists a unique operation intent before deletion, logs the requested action
through admin_audit, and records completion or an uncertain outcome. Reusing
the same operation ID never repeats deletion. A crash leaves a pending row
for administrator inspection. The database record is the durable evidence;
audit-log shipping and failure behavior still need integration qualification.

`python3 tests/run.py` runs 23 offline failure/retry/identity/access cases using the cached
PHP image with networking disabled. `python3 tests/integration.py --server-source /absolute/path/to/server-fork --groupfolders-source /absolute/path/to/groupfolders-fork` deliberately
uses the local disposable copy: creates the migration table and two fixture
users, purges personal and Teamfolders file trash/history, retries without another deletion, checks
one completed database record and exactly one admin-audit entry with required
fields, then removes its users/table. It refuses a pre-existing app table.
The integration runner currently names the controller's local Compose path.
It loads candidate trash-manager classes into that PHP process and preserves
the real registered backends; it does not replace the running artifact.

The real log check initially failed because the global warning level filtered
audit info messages. The N5-24 baseline now includes
`log.condition: {apps: [admin_audit]}`; with that setting the check passes.
Log forwarding and explicit administrator recovery still need qualification.

The POST `/purges`, `/trash` and `/operations` routes retain Nextcloud's default
administrator, login and CSRF middleware. Twenty installed HTTP checks pass on
the independent localhost:8191 copy: the settings page loads, all three routes
refuse anonymous/non-admin/missing-CSRF requests, invalid selection/reason
requests preserve trash and create no intent, and a real purge/repeat/inspection
completes. Browser interaction remains to be qualified.
The service also refuses deletion if the required admin-audit log condition
is missing. Teamfolders file purge explicitly removes the history identified
by its trash database row: the stock manual backend does not do that itself.
Requests select an exact trash file ID and deletion time, scoped to the owner;
matching names cannot redirect the operation. Stale or duplicate identities
are refused before mutation, and the identity is part of the retry fingerprint.
Requests also require the selected etag. Restore refreshes cached etags under
the mutation lock, and personal trash exposes that value rather than an empty
etag. Four real same-second restore/redelete cases reproduce ID/time reuse but
prove the previous etag is refused before intent creation. The default runtime
suite includes these four cases.
Two additional Teamfolders cases inject duplicate history rows and verify
refusal before any intent or deletion. All 22 cases passed in one invocation.
Two further cases interrupt actual history deletion, then prove retries preserve
the remaining history exactly. The default suite now contains 24 cases; the two
new cases passed separately after the 22-case run. Four more cases now cover
whole-Teamfolder deletion refusal through storage/command/controller and personal
history unlink failure for files/directories. These four pass separately; the
default suite now contains 28 cases.
Candidate Teamfolders expiry shares the mutation lock and manual cleanup honors
the native retention flag. Both contention/refusal assertions and the maintained
60-day lifecycle pass. Candidate packaging passed; installed-job checks remain open.
Teamfolders directory purge records the subtree's history IDs before removing
trash, then removes and verifies each associated history. The real nested-
directory case also proves an unrelated sibling and its history survive.
Storage that did not preserve the original history identity is refused.

The service requires the fork's `withMutationLock` boundary and holds it from
request lookup through intent, deletion, history cleanup and completion. The
candidate restore/remove methods use the same boundary. The current candidate
uses a process-held file lock in the shared local configuration dataset. Redis
expiry and PostgreSQL disconnects cannot release it. Workers must share the
same local filesystem and run as the service user; the persistent empty
`.retention-mutation.lock` file must never be deleted/replaced during operation.
This implements the planned single-host deployment, not a distributed lock.
Six nesting/ownership checks, separate-process exclusion after database close,
normal release, exit without finally and SIGKILL release pass. Creation uses
mode 0600; inability to open/acquire the lock refuses the operation.
Restore-versus-purge and crash checks below extend this lock qualification;
these checks alone do not establish deployment readiness.

The integration audit decorator now launches a separate PHP restore contender
after the purge intent/audit write and before deletion. It selects the actual
trash root and every descendant, and requires each real manager restore call to
fail with LockedException. All four integration cases pass with this contender;
they still check history/content outcomes and exactly one audit entry. This
covers purge-first ordering. Four additional restore-first cases restore through
the real backend, submit the stale trash identity, require refusal before any
intent is written, and compare restored content and version history byte-for-byte.
Eight further cases exit a separate PHP worker after committed intent/audit or
after backend deletion. They verify pending state survives, two retries do not
mutate remaining content/history, and exactly one audit entry exists. Teamfolders
post-delete interruption explicitly retains its history. All sixteen runtime
cases passed before the identity/ambiguity additions. Two later Teamfolders
cases exit after the first actual history deletion. The nested-directory case
proves unfinished descendant and unrelated sibling history remain byte-for-byte
across retries. Administrator resolution remains open; pending does not mean
completed or rolled back.

Remaining: browser interaction, administrator recovery, remaining bypass paths,
audit forwarding, review of subsequent work, public repositories and release
qualification. Do not treat this incomplete service as a deployed lock.

The integration runner uses four fixture types: personal file, nested personal
directory, Teamfolders file and nested Teamfolders directory. The personal
directory case checks both descendants' old version bytes exist in trash before
purging, then checks trash/history removal and preservation of an unrelated
live sibling and its original version bytes. All cases verify retry behavior
and exactly one audit entry, and remove their disposable fixtures.

## Installed candidate qualification

The installed scheduled-job harness now binds to the selected build record,
instead of one historical release. Supply `NEXTCLOUD_ARTIFACT` (verified bundle),
`NEXTCLOUD_COMPOSE_FILE` (disposable local stack), and `NEXTCLOUD_PLATFORM_SOURCE`
(matching platform source checkout) through the environment, then run
`python3 tests/installed-scheduled-jobs.py`. It records results beside the
artifact, checks job code comes from that exact release directory, and preserves
the existing process-local clock, scoped enumeration and cleanup protections.

`tests/assemble-candidate.py --output /absolute/new-directory` snapshots the
server changes into an isolated fixture commit, checks the Teamfolders patch
base against the previously verified archive, packages the app and commits
exact fixture inputs. It then runs the existing N5-8 signed-release builder.
Generated app URLs are explicitly unpublished fixture labels; this is not a
production manifest or a promotion. Original implementation trees are not committed.

`tests/prepare-http-copy.py --candidate /absolute/candidate-directory` prepares
a separate Compose project, image and state volumes on localhost:8191. Build
and start its `http-copy/compose.yml`, then run `tests/qualify-http.py --compose
/absolute/candidate-directory/http-copy/compose.yml`. This enables the candidate
apps and applies retention/audit settings in that disposable copy, creates a
temporary user, and removes the user's data and operation records after testing.
Credentials stay internal. Never print captured runtime logs or response bodies.

The first qualified input fixture is commit
`1507c8a4787dad8741dbdd424926e6a07f611bd6`, artifact
`35.0.2-outlaw.3fbfe573e5a2f658`. It includes the server/app/Teamfolders candidate
code and passed the builder and installed 20-check HTTP suite. The original
localhost:8190 copy remains available for process-local backend regression tests.
