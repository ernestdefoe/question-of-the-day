# Question of the Day: working rules

- **Commit straight to `main` and push. Never open a pull request on this repo.** Commit subjects are conventional (`feat:`, `fix:`); the release drafter bumps from the subject.
- `js/dist` is committed and is what forums load. After any change under `js/src`, run `npm run build` in `js/` (from a fresh `npm ci`) and commit the bundle; CI fails if it is stale.
- Publishing a release is Ernest's click. Nothing is announced until he says so.

## Design rules that exist for a reason

- **Answers are hidden until you post yours, on the server.** `Access::canSeeAnswers()` is the one gate and `Present::answers()` the one place an answer's words are written out; every path that returns answers asks the gate first (today, a question, its answer pages, the archive's top answers only for closed questions). Moderators see them only when they open the question to moderate (`?moderate=1` + the moderate permission). Who answered (faces, the count) is not secret; what they said is. No JSON:API resource for questions or answers, so no `include` can pull one in.
- **One question a day, enforced by the database.** `asked_on` is unique: the `Rotator` asks with a conditional UPDATE on a question still in the pool and closes yesterday's (storing its top answer) in the same transaction; a racing scheduler or page request hits the unique index and changes nothing. Page requests rotate too (forums without a scheduler), behind a cached "checked today" key that the pool's writers `forget()`.
- **Day columns are plain `Y-m-d` strings**, never date casts: a cast writes `Y-m-d 00:00:00`, which on SQLite no longer equals or sorts against `Y-m-d`.
- The forum's day comes from the admin's timezone setting (`Day`), not the server's.
- **The current question** is the latest one asked; it stays open until the next is asked, so a dry pool never leaves the front page empty. Answers and reactions only go to the current, open question.
- **Streaks count questions in a row, not calendar days** (`prev_asked_on`): a day with no question breaks nobody's streak. Credit is keyed by question id (`last_question_id`), and the state before the last credit is kept (`prev_*`) so deleting an answer to the open question, or the open question itself, gives the credit back (`Streaks::undo`).
- **"Ask now"** (`Rotator::swap`) reads the day and today's question inside its locked transaction (a swap racing midnight changes nothing), only while nobody has answered and today's is still open, and `Delivery::retract()` hides the old question's daily discussion and deletes its alerts (sent emails can't be recalled).
- **A page request delivers little**: on a sync queue the `Deliver` job runs inside the visitor's request, so it alerts one chunk of `Delivery::REQUEST_CHUNK`; the scheduler does the rest. Alerts go in batches; emails one by one behind `email_cursor`, so a timeout resumes rather than skipping.
- A suggestion's words can't change under its votes: the author can't edit once it has any; a moderator's rewording clears them.
- **extend.php uses string literals outside closures**, never `Settings::…`: a constant lookup at boot fatals every request on a worker with a stale autoload map mid-install.
- Reactions: one per member per answer (primary key), never your own, only from members who answered (they are the only ones who can see the answers), and only while the question is up, so the stored top answer stays true. `reactions_count` moves only when a row really went in or out.
- **Delivery is idempotent** (`Delivery`, queued as `Job\Deliver` when a question goes up, and run by the scheduler every minute): the submitter's alert is one blueprint per question; subscribers go in chunks behind `notify_cursor`, skipping anyone who has it; the daily discussion is claimed with `posted_at IS NULL` and released (3 tries) if posting throws. The tag's permission is asked by hand (`process()` skips it).
- **Notification subjects are Users** (a subject model without an API resource 500s every notification list); the question rides in the data.
- Deleting a member (`Deleting`, and GDPR erasure) goes through `Gdpr\Forget`: it takes back the counts their reactions and votes added before the foreign keys drop the rows.
- The pool is a surprise: only moderators see approved questions waiting their turn; members see the queue of pending suggestions.
- Answers and questions are rendered as text (Mithril text nodes, `white-space: pre-line`), never HTML.
- Helpers that return vnodes (`face`, `who`, `faces`, `reactions`, `topAnswer`) are CALLED, never used as JSX tags: Mithril treats a function tag as a closure component and crashes.
- forum.js only wires routes, the sidebar link, notifications and the front-page slot; the card, the pages and today's data are one lazy chunk.

## Testing

- `DB_PREFIX=qt_ composer test:setup && DB_PREFIX=qt_ composer test` (integration; SQLite and prefixed MySQL), `composer analyse:phpstan`.
- `tests/integration/api/QuestionOfTheDayTest.php::answers_stay_hidden_until_you_post_yours` is the gate's proof: add any new endpoint that returns answers to it.
- `cd js && npm ci && npm run build && npm run format-check`.
- The showcase seed is `~/github/showcase/seeds/98-question-of-the-day.php`.
