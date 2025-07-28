<?php

/**
 * Update Columns Service
 * 
 * 提供批量更新任务列位置的服务，基于工作板ID进行操作
 */
final class UpdateColumnsService extends Phobject {

  public function updateTaskColumns($tasks, $board_phid, $viewer) {
    $moved_count = 0;
    $error_count = 0;
    
    // 获取配置
    $config = $this->getUpdateConfig();

    // 获取工作板的所有列
    $columns = $this->getBoardColumns($board_phid, $viewer);
    if (empty($columns)) {
      throw new Exception("No columns found for this board");
    }

    foreach ($tasks as $task) {
      try {
        if ($this->processTaskMove($task, $board_phid, $columns, $config, $viewer)) {
          $moved_count++;
        }
      } catch (Exception $e) {
        $error_count++;
        phlog("UpdateColumns Error for task T{$task->getID()}: " . $e->getMessage());
      }
    }

    return array(
      'moved_count' => $moved_count,
      'error_count' => $error_count,
    );
  }

  /**
   * 获取指定工作板的所有列
   */
  private function getBoardColumns($board_phid, $viewer) {
    return id(new PhabricatorProjectColumnQuery())
      ->setViewer($viewer)
      ->withProjectPHIDs(array($board_phid))
      ->execute();
  }

  /**
   * 处理单个任务的移动
   */
  private function processTaskMove($task, $board_phid, $columns, $config, $viewer) {
    // 获取任务当前优先级和标题
    $priority = (int)$task->getPriority();
    $task_title = $task->getTitle();
    
    // 添加详细的调试日志，记录任务标题和优先级数值
    phlog("UpdateColumns: Processing task T{$task->getID()} - Title: '{$task_title}', Priority: {$priority}");
    
    // 通过优先级数值获取其对应的文本名称，这是识别"Needs Triage"更可靠的方法
    $priority_name = ManiphestTaskPriority::getTaskPriorityName($priority);
    $priority_name_lower = strtolower($priority_name);
    phlog("UpdateColumns: Task T{$task->getID()} - Priority Name is '{$priority_name}'");
    
    // **已修复的检查逻辑**
    // 明确检查优先级名称中是否包含 "triage"。如果包含，则立即跳过此任务。
    // 这是最主要的判断条件，确保"Needs Triage"任务不会被处理。
    if (strpos($priority_name_lower, 'triage') !== false) {
      phlog("UpdateColumns: Task T{$task->getID()} has a priority name containing 'triage'. SKIPPING this task.");
      return false; // 直接跳过，不进行任何移动操作
    }
    
    // 根据优先级确定目标列名模式
    $target_mapping = $this->getTargetColumnPattern($priority, $config, $task);
    if (!$target_mapping) {
      phlog("UpdateColumns: Task T{$task->getID()} - No matching priority mapping found. SKIPPING.");
      return false; // 无匹配的优先级范围
    }

    // 查找匹配的列
    $target_column = $this->findMatchingColumn($columns, $target_mapping['column_patterns'], $target_mapping);
    if (!$target_column) {
      phlog("UpdateColumns: Task T{$task->getID()} - No matching target column found. SKIPPING.");
      return false; // 没有找到匹配的列
    }

    // 检查任务是否已经在目标列中
    if ($this->isTaskInColumn($task, $target_column, $board_phid, $viewer)) {
      phlog("UpdateColumns: Task T{$task->getID()} is already in the target column '{$target_column->getDisplayName()}'. SKIPPING.");
      return false; // 任务已经在目标列中
    }

    // 移动任务
    return $this->moveTaskToColumn($task, $target_column, $board_phid, $config, $viewer);
  }

  /**
   * 检查任务是否已经在指定列中
   */
  private function isTaskInColumn($task, $column, $board_phid, $viewer) {
    $positions = id(new PhabricatorProjectColumnPositionQuery())
      ->setViewer($viewer)
      ->withObjectPHIDs(array($task->getPHID()))
      ->withBoardPHIDs(array($board_phid))
      ->execute();

    foreach ($positions as $position) {
      if ($position->getColumnPHID() === $column->getPHID()) {
        return true;
      }
    }

    return false;
  }

  /**
   * 将任务移动到指定列
   */
  private function moveTaskToColumn($task, $column, $board_phid, $config, $viewer) {
    try {
      // 构建移动事务
      $xactions = array();
      
      $xaction = id(new ManiphestTransaction())
        ->setTransactionType(PhabricatorTransactions::TYPE_COLUMNS)
        ->setNewValue(array(
          array(
            'columnPHID' => $column->getPHID(),
          ),
        ));
      
      $xactions[] = $xaction;

      // 应用事务
      $editor = id(new ManiphestTransactionEditor())
        ->setActor($viewer)
        ->setContentSource(PhabricatorContentSource::newForSource('web', array()))
        ->setContinueOnNoEffect(true)
        ->setContinueOnMissingFields(true);

      $editor->applyTransactions($task, $xactions);

      // 记录移动日志
      if (isset($config['log_moves']) && $config['log_moves']) {
        $task_id = $task->getID();
        $column_name = $column->getDisplayName();
        $priority = $task->getPriority();
        
        phlog("UpdateColumns: Successfully moved task T{$task_id} to column '{$column_name}' on board {$board_phid} (Priority: {$priority})");
      }

      return true;

    } catch (Exception $e) {
      $task_id = $task->getID();
      phlog("UpdateColumns Move Error for task T{$task_id}: " . $e->getMessage());
      throw $e;
    }
  }

  private function getUpdateConfig() {
    return array(
      'priority_mappings' => array(
        // Unbreak Now! (100) → "Unbreak Now!" column
        array(
          'priority_values' => array(100),
          'column_patterns' => array(
            'unbreak now', 'unbreak', 'urgent', 'emergency', 'critical',
            '紧急', '立即', '马上'
          )
        ),
        // High (80) → "High Priority" column
        array(
          'priority_values' => array(80),
          'column_patterns' => array(
            'high priority', 'high', 'important', 'critical', '高', '重要',
            'priority.*high', 'high.*priority'
          )
        ),
        // Normal (50) → "In Progress" column
        array(
          'priority_values' => array(50),
          'column_patterns' => array(
            'in progress', 'normal', 'in.*progress', 'doing', 'active',
            '进行中', '正常', '普通'
          )
        ),
        // Low (25) → "Low Priority" column
        array(
          'priority_values' => array(25),
          'column_patterns' => array(
            'low priority', 'low', 'later', 'someday',
            '低', '待办', '以后', '低优先级'
          )
        ),
        // Wishlist (0) → "Wishlist" column
        array(
          'priority_values' => array(0),
          'column_patterns' => array(
            'wishlist', 'wish', '心愿', '愿望', '愿望清单', '愿望列表', '愿望箱', '愿望池', '愿望墙', '心愿单', '心愿墙', '心愿池', '心愿箱', 'wishlist.*', 'wish.*list'
          )
        ),
      ),
      'match_mode' => 'fuzzy',
      'case_sensitive' => false,
      'log_moves' => true
    );
  }

  private function getTargetColumnPattern($priority, $config, $task = null) {
    $matching_mappings = array();
    
    // 找到所有匹配精确优先级数值的映射
    foreach ($config['priority_mappings'] as $mapping) {
      if (isset($mapping['priority_values']) && in_array($priority, $mapping['priority_values'])) {
        $matching_mappings[] = $mapping;
      }
    }
    
    // 如果只有一个匹配的映射，直接返回
    if (count($matching_mappings) == 1) {
      return $matching_mappings[0];
    }
    
    // 如果有多个匹配的映射，需要根据任务类型进行筛选
    if (count($matching_mappings) > 1 && $task) {
      $task_title = strtolower($task->getTitle());
      
      foreach ($matching_mappings as $mapping) {
        // 检查是否有任务类型模式匹配
        if (isset($mapping['task_type_patterns'])) {
          foreach ($mapping['task_type_patterns'] as $pattern) {
            if ($this->matchColumnName($task_title, $pattern, 'fuzzy', false)) {
              return $mapping; // 返回匹配任务类型的映射
            }
          }
        }
        
        // 检查是否有排除任务类型模式
        if (isset($mapping['exclude_task_types'])) {
          $excluded = false;
          foreach ($mapping['exclude_task_types'] as $exclude_pattern) {
            if ($this->matchColumnName($task_title, $exclude_pattern, 'fuzzy', false)) {
              $excluded = true;
              break;
            }
          }
          if ($excluded) {
            continue; // 跳过这个映射
          }
        }
        
        // 如果没有特殊模式，返回第一个匹配的映射
        return $mapping;
      }
    }
    
    return null;
  }

  private function findMatchingColumn($columns, $column_patterns, $config) {
    $match_mode = isset($config['match_mode']) ? $config['match_mode'] : 'fuzzy';
    $case_sensitive = isset($config['case_sensitive']) ? $config['case_sensitive'] : false;

    foreach ($columns as $column) {
      $column_name = $column->getDisplayName();
      
      // 检查列名模式匹配
      foreach ($column_patterns as $pattern) {
        if ($this->matchColumnName($column_name, $pattern, $match_mode, $case_sensitive)) {
          return $column;
        }
      }
    }

    return null;
  }

  private function matchColumnName($column_name, $pattern, $match_mode, $case_sensitive) {
    if (!$case_sensitive) {
      $column_name = strtolower($column_name);
      $pattern = strtolower($pattern);
    }

    switch ($match_mode) {
      case 'regex':
        return preg_match('/' . $pattern . '/i', $column_name);
      
      case 'fuzzy':
        return strpos($column_name, $pattern) !== false;
      
      default:
        return $column_name === $pattern;
    }
  }
} 