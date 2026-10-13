#!/usr/bin/env python3
"""TaskFlow MCP server.

Exposes the TaskFlow HTTP API (on GreenGeeks) as MCP tools so that opencode
(or any MCP client) can act on the user's TaskFlow on their behalf.

Auth: the API token is read from the TASKFLOW_API_TOKEN environment variable
(set in opencode's MCP config). The token is NEVER sent to the LLM — it lives
only in this local process and goes out as a Bearer header to the API.

Routing: every tool here is a thin wrapper that calls one HTTP endpoint on the
TaskFlow API and returns the JSON. The base URL comes from
TASKFLOW_API_BASE (defaults to the production URL).
"""

from __future__ import annotations

import json
import os
import urllib.error
import urllib.parse
import urllib.request
from typing import Any

from mcp.server.mcpserver.server import MCPServer

API_BASE = os.environ.get(
    "TASKFLOW_API_BASE", "https://symbolonbranding.co.uk/taskflow/api.php"
)
API_TOKEN = os.environ.get("TASKFLOW_API_TOKEN", "")

server = MCPServer(
    name="taskflow",
    title="TaskFlow",
    description="Act on the user's TaskFlow task manager (tasks, budgets, timer, history, collections, skills).",
    instructions=(
        "TaskFlow is a time-budgeted task manager. Tasks belong to one of 5 categories "
        "(Revenue Today, Revenue Tomorrow, Exploration, Entertainment, Social-Household), "
        "each with a daily minute budget. Task types: Flexible, Recurring, OneOff. "
        "Use get_next_up to see what the user should work on now (the priority engine picks top 3). "
        "Before completing a recurring task, check its `eligible` field — if false, it's already done "
        "today or not yet available. Mutating tools (complete, skip, transfer, delete, timer) act on "
        "the live DB immediately; confirm with the user before destructive actions like delete. "
        "SKILLS: tasks/completions are tagged with skills from the user's skill tree, rooted in the "
        "four archetypes (Magician, Lover, Warrior, King). The tree is a DAG: a skill can have "
        "multiple parents (pass parent_ids to save_skill; the full set replaces existing parents). "
        "Completing a task freezes its skill tags onto the completion; minutes roll up from each "
        "tag through every ancestor branch, deduped per stamp. Tree edits (rename/re-parent/"
        "archive) re-interpret history on the next read — old stamps are never retrofitted. "
        "Deleting a referenced skill is refused (archive instead). Use save_skill for tree "
        "management and set skill_ids on tasks/completions for tagging; pass skill_ids to "
        "complete_task only when overriding that instance's tags."
    ),
)


# ---------------------------------------------------------------------------
# HTTP helper
# ---------------------------------------------------------------------------
def _request(method: str, path: str, body: dict | None = None, timeout: int = 25) -> Any:
    url = f"{API_BASE}{path}"
    data = None
    headers = {"Authorization": f"Bearer {API_TOKEN}", "Accept": "application/json"}
    if body is not None:
        data = json.dumps(body).encode("utf-8")
        headers["Content-Type"] = "application/json"
    req = urllib.request.Request(url, data=data, method=method, headers=headers)
    try:
        with urllib.request.urlopen(req, timeout=timeout) as resp:
            raw = resp.read().decode("utf-8")
            if not raw:
                return None
            return json.loads(raw)
    except urllib.error.HTTPError as e:
        raw = e.read().decode("utf-8", errors="replace")
        try:
            payload = json.loads(raw)
        except Exception:
            payload = {"error": raw or e.reason}
        raise RuntimeError(f"HTTP {e.code} {e.reason}: {json.dumps(payload)}") from None
    except urllib.error.URLError as e:
        raise RuntimeError(f"Network error contacting TaskFlow API: {e.reason}") from None


def _ok(payload: Any) -> str:
    """Render a tool result as compact JSON text for the LLM."""
    return json.dumps(payload, ensure_ascii=False)


# ---------------------------------------------------------------------------
# READ tools
# ---------------------------------------------------------------------------


@server.tool()
def get_next_up() -> str:
    """Get the user's top 3 priority tasks right now (TaskFlow's priority engine).
    Returns each task with name, category, duration, type, eligibility, snooze status,
    remaining budget for its category, and a human-readable detail line. Start here
    when the user asks 'what should I do' or 'what's next'."""
    return _ok(_request("GET", "/next"))


@server.tool()
def list_tasks(category: str | None = None, type: str | None = None, collection_id: int | None = None) -> str:
    """List all of the user's tasks, optionally filtered.
    Args:
        category: one of Revenue Today, Revenue Tomorrow, Exploration, Entertainment, Social-Household.
        type: one of Flexible, Recurring, OneOff.
        collection_id: filter to tasks in a given collection.
    Each task includes eligibility/snooze/budget info."""
    params = []
    if category:
        params.append(f"category={urllib.parse.quote(category)}")
    if type:
        params.append(f"type={urllib.parse.quote(type)}")
    if collection_id is not None:
        params.append(f"collectionId={collection_id}")
    qs = ("?" + "&".join(params)) if params else ""
    return _ok(_request("GET", f"/tasks{qs}"))


@server.tool()
def get_task(task_id: str) -> str:
    """Get a single task by its id, with full detail (eligibility, snooze, budget, recurrence)."""
    return _ok(_request("GET", f"/tasks/{urllib.parse.quote(task_id, safe='')}"))


@server.tool()
def get_history(date: str | None = None, collection_id: int | None = None) -> str:
    """Get completed-task history. If date is given (YYYY-MM-DD), returns that day only;
    otherwise returns the most recent 200 completions. Each entry has taskName, category,
    duration, completedAt, startAt/endAt, note, collectionId, collectionName.
    Pass collection_id to filter to completions in a given collection."""
    params = []
    if date:
        params.append(f"date={urllib.parse.quote(date)}")
    if collection_id is not None:
        params.append(f"collectionId={collection_id}")
    qs = ("?" + "&".join(params)) if params else ""
    return _ok(_request("GET", f"/history{qs}"))


@server.tool()
def get_budgets() -> str:
    """Get today's time budgets for all 5 categories: weekday/weekend budget, effective
    budget (after transfers), consumed minutes today, and remaining minutes. Also lists
    today's budget transfers and whether today is a weekday. Useful before suggesting work
    or transferring minutes."""
    return _ok(_request("GET", "/budgets"))


@server.tool()
def update_completion(
    completion_id: int,
    category: str | None = None,
    duration: int | None = None,
    start_at: str | None = None,
    end_at: str | None = None,
    note: str | None = None,
    collection_id: int | None = None,
    skill_ids: list[int] | None = None,
) -> str:
    """Update a completed-task history entry (partial edit — only provided fields change).
    Use this to retroactively assign a completion to a collection, fix the category/duration,
    or adjust timing. Fetch the entry first with get_history to see current values.
    Args:
        completion_id: the history entry id (from get_history).
        category: one of Revenue Today, Revenue Tomorrow, Exploration, Entertainment, Social-Household.
        duration: planned duration in minutes.
        start_at: ISO datetime for the start time.
        end_at: ISO datetime for the end time.
        note: free-text note (pass empty string or null to clear).
        collection_id: collection to assign this completion to (pass null/omit to leave unchanged).
        skill_ids: replace this completion's skill stamps (deliberate retro-tagging; only do
            this when the user explicitly asks).
    """
    body: dict[str, Any] = {}
    if category is not None:
        body["category"] = category
    if duration is not None:
        body["duration"] = duration
    if start_at is not None:
        body["startAt"] = start_at
    if end_at is not None:
        body["endAt"] = end_at
    if note is not None:
        body["note"] = note
    if collection_id is not None:
        body["collectionId"] = collection_id
    if skill_ids is not None:
        body["skillIds"] = skill_ids
    return _ok(_request("PUT", f"/history/{completion_id}", body))


@server.tool()
def delete_completion(completion_id: int) -> str:
    """Delete a completed-task history entry. Confirm with the user first — this
    cannot be undone. Use when cleaning up duplicate or incorrect log entries."""
    return _ok(_request("DELETE", f"/history/{completion_id}"))


@server.tool()
def list_collections() -> str:
    """List all collections (projects/initiatives) with type, name, description, parentId,
    and how many tasks each contains."""
    return _ok(_request("GET", "/collections"))


@server.tool()
def get_timer() -> str:
    """Get the user's currently active timer, if any (taskId, taskName, startAt, endAt,
    duration). Returns null if no timer is running."""
    return _ok(_request("GET", "/timer"))


@server.tool()
def list_skills(range: str | None = None) -> str:
    """List the user's skill tree (rooted in the archetypes Magician/Lover/Warrior/King).
    Each skill has id, name, parentId, archived flag, accumulated minutes (rolled up from
    tagged completions through all ancestors), taskCount and completionCount.
    Args:
        range: optional — today, 7d, 30d, month, year (default all time)."""
    qs = f"?range={urllib.parse.quote(range)}" if range else ""
    return _ok(_request("GET", f"/skills{qs}"))


# ---------------------------------------------------------------------------
# WRITE tools — tasks
# ---------------------------------------------------------------------------


@server.tool()
def create_task(
    name: str,
    category: str,
    duration: int,
    type: str,
    deadline: str | None = None,
    pinned: bool = False,
    collection_id: int | None = None,
    note: str | None = None,
    recurrence_frequency: str | None = None,
    recurrence_interval: int | None = None,
    recurrence_days_of_week: list[int] | None = None,
    skill_ids: list[int] | None = None,
) -> str:
    """Create a new task. Returns the created task with its generated id.
    Args:
        name: task name.
        category: one of Revenue Today, Revenue Tomorrow, Exploration, Entertainment, Social-Household.
        duration: duration in minutes (must be > 0).
        type: Flexible | Recurring | OneOff.
        deadline: ISO date/time (for OneOff), e.g. '2026-08-15' or '2026-08-15T18:00'.
        pinned: if true, task is prioritized above others.
        collection_id: optional collection to assign the task to.
        note: optional free-text note.
        recurrence_frequency: required if type=Recurring — one of Days, Weeks, Months, SpecificDays.
        recurrence_interval: required if type=Recurring — every N units (>=1).
        recurrence_days_of_week: required if frequency is SpecificDays or Weeks — list of weekday
            numbers where 0=Sunday ... 6=Saturday.
        skill_ids: optional skill tag ids (see list_skills) — stamped onto completions of this task.
    """
    body: dict[str, Any] = {
        "name": name,
        "category": category,
        "duration": duration,
        "type": type,
        "pinned": pinned,
    }
    if deadline is not None:
        body["deadline"] = deadline
    if collection_id is not None:
        body["collectionId"] = collection_id
    if note is not None:
        body["note"] = note
    if skill_ids is not None:
        body["skillIds"] = skill_ids
    if type == "Recurring":
        if not recurrence_frequency:
            raise ValueError("recurrence_frequency is required for Recurring tasks")
        body["recurrence"] = {
            "frequency": recurrence_frequency,
            "interval": recurrence_interval or 1,
        }
        if recurrence_days_of_week is not None:
            body["recurrence"]["daysOfWeek"] = recurrence_days_of_week
    return _ok(_request("POST", "/tasks", body))


@server.tool()
def update_task(
    task_id: str,
    name: str,
    category: str,
    duration: int,
    type: str,
    deadline: str | None = None,
    pinned: bool = False,
    collection_id: int | None = None,
    note: str | None = None,
    recurrence_frequency: str | None = None,
    recurrence_interval: int | None = None,
    recurrence_days_of_week: list[int] | None = None,
    skill_ids: list[int] | None = None,
) -> str:
    """Update an existing task (full replace of editable fields). Same field meanings as
    create_task. Fetch the task first with get_task to see current values."""
    body: dict[str, Any] = {
        "name": name,
        "category": category,
        "duration": duration,
        "type": type,
        "pinned": pinned,
    }
    if deadline is not None:
        body["deadline"] = deadline
    if collection_id is not None:
        body["collectionId"] = collection_id
    if note is not None:
        body["note"] = note
    if skill_ids is not None:
        body["skillIds"] = skill_ids
    if type == "Recurring":
        if not recurrence_frequency:
            raise ValueError("recurrence_frequency is required for Recurring tasks")
        body["recurrence"] = {
            "frequency": recurrence_frequency,
            "interval": recurrence_interval or 1,
        }
        if recurrence_days_of_week is not None:
            body["recurrence"]["daysOfWeek"] = recurrence_days_of_week
    return _ok(_request("PUT", f"/tasks/{urllib.parse.quote(task_id, safe='')}", body))


@server.tool()
def complete_task(task_id: str, note: str | None = None, start_at: str | None = None, end_at: str | None = None, skill_ids: list[int] | None = None) -> str:
    """Mark a task as completed and log it to history. For OneOff tasks this also deletes
    the task; for Recurring it records lastCompleted (so it won't reappear until next
    availability); for Flexible it just logs. Optional note/start_at/end_at let you record
    actual timing. Check the task's `eligible` field first for recurring tasks.
    Args:
        skill_ids: optional per-completion override of the task's skill tags — pass only
            when this instance exercised different skills than the task's defaults."""
    body: dict[str, Any] = {}
    if note is not None:
        body["note"] = note
    if start_at is not None:
        body["startAt"] = start_at
    if end_at is not None:
        body["endAt"] = end_at
    if skill_ids is not None:
        body["skillIds"] = skill_ids
    return _ok(_request("POST", f"/tasks/{urllib.parse.quote(task_id, safe='')}/complete", body))


@server.tool()
def skip_task(task_id: str, duration: str = "15min", until: str | None = None) -> str:
    """Snooze a task so it won't appear in 'next up' for a while.
    Args:
        task_id: the task id.
        duration: one of 15min, 1hour, today, tomorrow (default 15min).
        until: absolute ISO datetime to skip until (overrides duration if given)."""
    body: dict[str, Any] = {"duration": duration}
    if until is not None:
        body["until"] = until
    return _ok(_request("POST", f"/tasks/{urllib.parse.quote(task_id, safe='')}/skip", body))


@server.tool()
def unsnooze_task(task_id: str) -> str:
    """Cancel a snooze so the task is eligible again immediately."""
    return _ok(_request("POST", f"/tasks/{urllib.parse.quote(task_id, safe='')}/unsnooze", {}))


@server.tool()
def delete_task(task_id: str) -> str:
    """Permanently delete a task. The task's past completions in history are NOT affected.
    Confirm with the user first — this cannot be undone."""
    return _ok(_request("DELETE", f"/tasks/{urllib.parse.quote(task_id, safe='')}"))


# ---------------------------------------------------------------------------
# WRITE tools — budgets / transfers
# ---------------------------------------------------------------------------


@server.tool()
def transfer_budget(from_category: str, to_category: str, minutes: int) -> str:
    """Transfer minutes from one category's daily budget to another, for today only.
    Refused if the source category doesn't have enough remaining budget. Returns the new
    transfer record plus updated budgets for all categories."""
    return _ok(
        _request(
            "POST",
            "/budgets/transfer",
            {"fromCategory": from_category, "toCategory": to_category, "minutes": minutes},
        )
    )


@server.tool()
def delete_budget_transfer(transfer_id: int) -> str:
    """Delete one of today's budget transfers by its id (reverses the transfer)."""
    return _ok(_request("DELETE", f"/budgets/transfer/{transfer_id}"))


# ---------------------------------------------------------------------------
# WRITE tools — collections
# ---------------------------------------------------------------------------


@server.tool()
def save_collection(
    name: str,
    type: str = "Project",
    description: str | None = None,
    parent_id: int | None = None,
    collection_id: int | None = None,
) -> str:
    """Create or update a collection (Project/Initiative). If collection_id is given, updates
    that collection; otherwise creates a new one. A parent_id makes it a sub-collection."""
    body: dict[str, Any] = {"name": name, "type": type}
    if description is not None:
        body["description"] = description
    if parent_id is not None:
        body["parentId"] = parent_id
    if collection_id is not None:
        body["id"] = collection_id
    return _ok(_request("POST", "/collections", body))


@server.tool()
def delete_collection(collection_id: int) -> str:
    """Delete a collection. Tasks in it are NOT deleted (their collection_id becomes null).
    Confirm with the user first."""
    return _ok(_request("DELETE", f"/collections/{collection_id}"))


# ---------------------------------------------------------------------------
# WRITE tools — skills
# ---------------------------------------------------------------------------


@server.tool()
def save_skill(
    name: str,
    parent_ids: list[int] | None = None,
    skill_id: int | None = None,
    archived: bool = False,
) -> str:
    """Create or update a skill in the user's skill DAG (rooted in the archetypes
    Magician/Lover/Warrior/King). Skills can have MULTIPLE parents — the tree is a DAG,
    and a multi-parent skill appears under each parent; minutes roll up every branch,
    deduped per completion stamp. Tree edits re-interpret history on the next read (old
    completion stamps are never retrofitted).
    Args:
        name: skill name.
        parent_ids: parent skill ids (pass the FULL set — it replaces existing parents;
            omit/null to leave parents unchanged, [] for top level).
        skill_id: existing skill id to update, or omit/null to create.
        archived: archive the skill (hidden from tagging, time still counts).
    """
    body: dict[str, Any] = {"name": name, "archived": archived}
    if parent_ids is not None:
        body["parentIds"] = parent_ids
    if skill_id is not None:
        body["id"] = skill_id
    return _ok(_request("POST", "/skills", body))


@server.tool()
def delete_skill(skill_id: int) -> str:
    """Delete a skill. Refused (409) while anything references it — pass archived=true to
    save_skill instead. Stamps keep a deleted skill's history honest, so prefer archiving."""
    return _ok(_request("DELETE", f"/skills/{skill_id}"))


# ---------------------------------------------------------------------------
# WRITE tools — timer
# ---------------------------------------------------------------------------


@server.tool()
def start_timer(task_id: str) -> str:
    """Start the active timer for a task (replaces any running timer). Returns the running
    timer with its computed endAt."""
    return _ok(_request("POST", f"/timer/start/{urllib.parse.quote(task_id, safe='')}", {}))


@server.tool()
def complete_timer(note: str | None = None) -> str:
    """Complete the currently running timer: logs the task to history using the timer's
    start_at and now as end_at, then clears the timer. Errors if no timer is running."""
    body: dict[str, Any] = {}
    if note is not None:
        body["note"] = note
    return _ok(_request("POST", "/timer/complete", body))


@server.tool()
def cancel_timer() -> str:
    """Cancel the running timer without logging a completion."""
    return _ok(_request("POST", "/timer/cancel", {}))


# ---------------------------------------------------------------------------
# Entry point
# ---------------------------------------------------------------------------
if __name__ == "__main__":
    if not API_TOKEN:
        # Log to stderr so it shows in opencode MCP debug without corrupting stdio JSON.
        import sys

        print("taskflow-mcp: TASKFLOW_API_TOKEN env var is not set.", file=sys.stderr)
    server.run("stdio")