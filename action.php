<?php
require_once __DIR__ . '/functions.php';
$user = require_login();
$action = $_POST['action'] ?? '';

switch ($action) {
  case 'start':
    $task = get_task($user['id'], $_POST['task_id'] ?? '');
    if ($task) {
      start_active_timer($user['id'], $task);
    }
    redirect('index.php');

  case 'force_complete': {
    $timer = get_active_timer($user['id']);
    if ($timer) {
      $task = get_task($user['id'], $timer['taskId']);
      if ($task) {
        $endNow = date('c');
        if ($task['type'] === 'Flexible') {
          $_SESSION['pending_completion'] = $timer['taskId'];
          $_SESSION['pending_timer'] = ['startAt' => $timer['startAt'], 'endAt' => $endNow];
          clear_active_timer($user['id']);
          redirect('complete.php');
        }
        complete_task($user['id'], $task, null, $timer['startAt'], $endNow);
      }
      clear_active_timer($user['id']);
    }
    redirect('index.php');
  }

  case 'complete':
    $task = get_task($user['id'], $_POST['task_id'] ?? '');
    if ($task) {
      if ($task['type'] === 'Flexible') {
        $_SESSION['pending_completion'] = $task['id'];
        redirect('complete.php');
      }
      complete_task($user['id'], $task);
    }
    redirect('browse.php');

  case 'complete_with_note': {
    $task = get_task($user['id'], $_POST['task_id'] ?? '');
    $pending = $_SESSION['pending_timer'] ?? null;
    if ($task) {
      complete_task($user['id'], $task, trim($_POST['note'] ?? '') ?: null, $pending['startAt'] ?? null, $pending['endAt'] ?? null);
    }
    unset($_SESSION['pending_completion'], $_SESSION['pending_timer']);
    redirect('index.php');
  }

  case 'cancel_completion':
    unset($_SESSION['pending_completion'], $_SESSION['pending_timer']);
    redirect('index.php');

  case 'set_collection_parent': {
    header('Content-Type: application/json');
    $id = (int)($_POST['id'] ?? 0);
    $parentId = $_POST['parent_id'] ?? '';
    $parentId = $parentId === '' ? null : (int)$parentId;
    if ($id) {
      set_collection_parent($user['id'], $id, $parentId);
      echo json_encode(['ok' => true]);
    } else {
      echo json_encode(['ok' => false]);
    }
    exit;
  }

  case 'save_collection': {
    $id = !empty($_POST['collection_id']) ? (int)$_POST['collection_id'] : null;
    $newId = save_collection($user['id'], [
      'id' => $id,
      'type' => $_POST['type'] ?? 'Project',
      'name' => trim($_POST['name'] ?? ''),
      'description' => trim($_POST['description'] ?? '') ?: null,
      'parentId' => !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : null,
    ]);
    redirect('collections.php');
  }

  case 'delete_collection': {
    $id = (int)($_POST['id'] ?? 0);
    if ($id) delete_collection($user['id'], $id);
    redirect('collections.php');
  }

  case 'transfer_budget': {
    $from = $_POST['from_category'] ?? '';
    $to = $_POST['to_category'] ?? '';
    $minutes = (int)($_POST['minutes'] ?? 0);
    if ($from && $to && $minutes > 0) {
      add_daily_transfer($user['id'], $from, $to, $minutes);
    }
    redirect('index.php');
  }

  case 'delete_transfer': {
    $id = (int)($_POST['transfer_id'] ?? 0);
    if ($id) delete_daily_transfer($user['id'], $id);
    redirect('index.php');
  }

  case 'skip': {
    $task = get_task($user['id'], $_POST['task_id'] ?? '');
    $duration = $_POST['skip_duration'] ?? '15min';
    if ($task) {
      skip_task($user['id'], $task['id'], skip_until($duration));
    }
    redirect('index.php');
  }

  case 'unsnooze': {
    $taskId = $_POST['task_id'] ?? '';
    if ($taskId) {
      unsnooze_task($user['id'], $taskId);
    }
    $referer = $_POST['from'] ?? 'browse.php';
    redirect($referer);
  }

  case 'delete':
    $stmt = db()->prepare('DELETE FROM tasks WHERE id = ? AND user_id = ?');
    $stmt->execute([$_POST['task_id'] ?? '', $user['id']]);
    redirect('calendar.php');

  case 'delete_completed':
    $stmt = db()->prepare('DELETE FROM completed_tasks WHERE id = ? AND user_id = ?');
    $stmt->execute([(int)($_POST['id'] ?? 0), $user['id']]);
    redirect('calendar.php');

  case 'update_completed': {
    $id = (int)($_POST['id'] ?? 0);
    if ($id) {
      $startAt = null;
      if (!empty($_POST['start_at'])) $startAt = date('c', strtotime($_POST['start_at']));
      $endAt = null;
      if (!empty($_POST['end_at'])) $endAt = date('c', strtotime($_POST['end_at']));
      update_completed($user['id'], $id, [
        'category' => $_POST['category'] ?? 'Revenue Today',
        'duration' => (int)($_POST['duration'] ?? 15),
        'startAt' => $startAt,
        'endAt' => $endAt,
        'note' => trim($_POST['note'] ?? '') ?: null,
        'collectionId' => !empty($_POST['collection_id']) ? (int)$_POST['collection_id'] : null,
      ]);
      if (isset($_POST['skill_ids'])) {
        set_completed_skills($user['id'], $id, array_map('intval', $_POST['skill_ids']));
      }
    }
    redirect('calendar.php');
  }

  case 'update_completed_times': {
    header('Content-Type: application/json');
    $id = (int)($_POST['id'] ?? 0);
    $startAt = !empty($_POST['start_at']) ? date('c', strtotime($_POST['start_at'])) : null;
    $endAt = !empty($_POST['end_at']) ? date('c', strtotime($_POST['end_at'])) : null;
    if ($id && $startAt && $endAt) {
      $entry = get_completed_by_id($user['id'], $id);
      if ($entry) {
        update_completed_times($user['id'], $id, $startAt, $endAt);
        echo json_encode(['ok' => true]);
      } else {
        echo json_encode(['ok' => false]);
      }
    } else {
      echo json_encode(['ok' => false]);
    }
    exit;
  }

  case 'quick_log': {
    $name = trim($_POST['name'] ?? '');
    if ($name === '') { redirect('calendar.php'); }
    $category = $_POST['category'] ?? 'Revenue Today';
    if (!in_array($category, CATEGORIES, true)) $category = 'Revenue Today';
    $duration = (int)($_POST['duration'] ?? 30);
    if (!in_array($duration, DURATIONS, true)) $duration = 30;
    $startAt = !empty($_POST['start_at']) ? date('c', strtotime($_POST['start_at'])) : null;
    $endAt = !empty($_POST['end_at']) ? date('c', strtotime($_POST['end_at'])) : null;
    if (!$startAt || !$endAt) { redirect('calendar.php'); }
    $id = 't_' . bin2hex(random_bytes(12));
    $taskData = [
      'id' => $id,
      'name' => $name,
      'category' => $category,
      'duration' => $duration,
      'type' => 'OneOff',
      'recurrence' => null,
      'deadline' => null,
      'lastCompleted' => null,
      'lastSkipped' => null,
      'pinned' => false,
      'collectionId' => !empty($_POST['collection_id']) ? (int)$_POST['collection_id'] : null,
      'note' => trim($_POST['note'] ?? '') ?: null,
      'createdAt' => date('c'),
    ];
    save_task($user['id'], $taskData);
    $task = get_task($user['id'], $id);
    if (!$task) { redirect('calendar.php'); }
    $note = trim($_POST['note'] ?? '') ?: null;
    complete_task($user['id'], $task, $note, $startAt, $endAt);
    redirect('calendar.php');
  }

  case 'quick_log_existing': {
    $taskId = $_POST['task_id'] ?? '';
    $task = get_task($user['id'], $taskId);
    if (!$task || $task['type'] !== 'Flexible') { redirect('calendar.php'); }
    $startAt = !empty($_POST['start_at']) ? date('c', strtotime($_POST['start_at'])) : null;
    $endAt = !empty($_POST['end_at']) ? date('c', strtotime($_POST['end_at'])) : null;
    if (!$startAt || !$endAt) { redirect('calendar.php'); }
    $note = trim($_POST['note'] ?? '') ?: null;
    $duration = (int)($_POST['duration_existing'] ?? $task['duration']);
    if (!in_array($duration, DURATIONS, true)) $duration = $task['duration'];
    $task['duration'] = $duration;
    complete_task($user['id'], $task, $note, $startAt, $endAt);
    redirect('calendar.php');
  }

  case 'save_skill': {
    $id = !empty($_POST['skill_id']) ? (int)$_POST['skill_id'] : null;
    $parentIds = null;
    if (isset($_POST['parent_ids'])) {
      $parentIds = [];
      foreach ($_POST['parent_ids'] as $pid) {
        $pid = (int)$pid;
        if ($pid > 0) $parentIds[] = $pid;
      }
    }
    save_skill($user['id'], [
      'id' => $id,
      'name' => trim($_POST['name'] ?? ''),
      'parentIds' => $parentIds,
      'archived' => !empty($_POST['archived']),
    ]);
    redirect('skills.php');
  }

  case 'set_skill_parent': {
    header('Content-Type: application/json');
    $id = (int)($_POST['id'] ?? 0);
    $parentId = $_POST['parent_id'] ?? '';
    $parentId = $parentId === '' ? null : (int)$parentId;
    if ($id) {
      set_skill_parent($user['id'], $id, $parentId);
      echo json_encode(['ok' => true]);
    } else {
      echo json_encode(['ok' => false]);
    }
    exit;
  }

  case 'add_skill_parent': {
    header('Content-Type: application/json');
    $id = (int)($_POST['id'] ?? 0);
    $parentId = (int)($_POST['parent_id'] ?? 0);
    if ($id && $parentId) {
      add_skill_parent($user['id'], $id, $parentId);
      echo json_encode(['ok' => true]);
    } else {
      echo json_encode(['ok' => false]);
    }
    exit;
  }

  case 'delete_skill': {
    $id = (int)($_POST['id'] ?? 0);
    if ($id) delete_skill($user['id'], $id);
    redirect('skills.php');
  }

  case 'save_task_skills': {
    $taskId = $_POST['task_id'] ?? '';
    $skillIds = array_map('intval', $_POST['skill_ids'] ?? []);
    if ($taskId) set_task_skills($user['id'], $taskId, $skillIds);
    redirect($_POST['from'] ?? 'browse.php');
  }

  case 'save_completed_skills': {
    $id = (int)($_POST['completed_id'] ?? 0);
    $skillIds = array_map('intval', $_POST['skill_ids'] ?? []);
    if ($id) set_completed_skills($user['id'], $id, $skillIds);
    redirect('calendar.php');
  }

  case 'logout':
    session_destroy();
    redirect('login.php');

  default:
    redirect('index.php');
}