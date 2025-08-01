<?php

/**
 * Update Columns Service
 * 
 * 提供批量更新任务列位置的服务，基于工作板ID进行操作
 * Advanced Performance Optimized Version - 高级性能优化版本
 */
final class UpdateColumnsService extends Phobject {

  // 静态缓存配置，避免重复创建
  private static $cached_config = null;
  
  // 优先级映射缓存
  private static $priority_mapping_cache = array();
  
  // 列名索引缓存
  private $column_index_cache = array();

  public function updateTaskColumns($tasks, $board_phid, $viewer) {
    // 获取配置
    $config = $this->getUpdateConfigCached();

    // 获取工作板的所有列
    $columns = $this->getBoardColumns($board_phid, $viewer);
    if (empty($columns)) {
      throw new Exception("No columns found for this board");
    }

    // 预建列名索引，加速查找
    $column_index = $this->buildColumnIndex($columns, $config);

    // 批量日志记录 - 只在开始时记录一次
    phlog("UpdateColumns: Starting optimized batch update for " . count($tasks) . " tasks");

    // 早期过滤：快速过滤掉不需要处理的任务（如Triage任务）
    $filterable_tasks = $this->preFilterTasks($tasks);
    $filtered_count = count($tasks) - count($filterable_tasks);
    
    if ($filtered_count > 0) {
      phlog("UpdateColumns: Pre-filtered {$filtered_count} tasks (triage, etc.)");
    }

    if (empty($filterable_tasks)) {
      phlog("UpdateColumns: No tasks need processing after filtering");
      return array('moved_count' => 0, 'error_count' => 0);
    }

    // 批量获取所有任务的位置信息，避免重复查询
    $task_positions = $this->getTaskPositions($filterable_tasks, $board_phid, $viewer);
    
    // 收集所有需要移动的任务
    $task_moves = array();
    $error_count = 0;

    foreach ($filterable_tasks as $task) {
      try {
        $move_info = $this->processTaskMoveOptimized($task, $columns, $config, $task_positions, $column_index);
        if ($move_info) {
          $task_moves[] = $move_info;
        }
      } catch (Exception $e) {
        $error_count++;
        phlog("UpdateColumns Error for task T{$task->getID()}: " . $e->getMessage());
      }
    }

    // 批量执行所有移动操作
    $moved_count = 0;
    if (!empty($task_moves)) {
      $moved_count = $this->batchMoveTasksOptimized($task_moves, $viewer);
    }

    // 在结束时记录一次总结
    phlog("UpdateColumns: Optimized batch completed - moved: {$moved_count}, errors: {$error_count}, filtered: {$filtered_count}");

    return array(
      'moved_count' => $moved_count,
      'error_count' => $error_count,
    );
  }

  /**
   * 批量获取所有任务的位置信息
   */
  private function getTaskPositions($tasks, $board_phid, $viewer) {
    $task_phids = array();
    foreach ($tasks as $task) {
      $task_phids[] = $task->getPHID();
    }
    
    // 一次性查询所有任务的位置
    $positions = id(new PhabricatorProjectColumnPositionQuery())
      ->setViewer($viewer)
      ->withObjectPHIDs($task_phids)
      ->withBoardPHIDs(array($board_phid))
      ->execute();

    // 按任务PHID索引位置信息
    $position_map = array();
    foreach ($positions as $position) {
      $position_map[$position->getObjectPHID()] = $position;
    }

    return $position_map;
  }

  /**
   * 预过滤任务：提前过滤掉明确不需要处理的任务
   */
  private function preFilterTasks($tasks) {
    $filtered_tasks = array();
    
    foreach ($tasks as $task) {
      $priority = (int)$task->getPriority();
      $priority_name = ManiphestTaskPriority::getTaskPriorityName($priority);
      $priority_name_lower = strtolower($priority_name);
      
      // 快速跳过 Triage 任务
      if (strpos($priority_name_lower, 'triage') !== false) {
        continue;
      }
      
      $filtered_tasks[] = $task;
    }
    
    return $filtered_tasks;
  }

  /**
   * 构建列名索引，加速列查找
   */
  private function buildColumnIndex($columns, $config) {
    $cache_key = 'columns_' . count($columns);
    
    if (isset($this->column_index_cache[$cache_key])) {
      return $this->column_index_cache[$cache_key];
    }
    
    $index = array();
    $match_mode = $config['match_mode'];
    $case_sensitive = $config['case_sensitive'];
    
    foreach ($columns as $column) {
      $column_name = $column->getDisplayName();
      if (!$case_sensitive) {
        $column_name = strtolower($column_name);
      }
      $index[$column_name] = $column;
    }
    
    $this->column_index_cache[$cache_key] = $index;
    return $index;
  }

  /**
   * 优化版本的任务处理
   */
  private function processTaskMoveOptimized($task, $columns, $config, $task_positions, $column_index) {
    $priority = (int)$task->getPriority();
    
    // 使用缓存的优先级映射
    $target_mapping = $this->getTargetColumnPatternCached($priority, $config, $task);
    if (!$target_mapping) {
      return false;
    }

    // 使用预建索引快速查找列
    $target_column = $this->findMatchingColumnOptimized($target_mapping['column_patterns'], $config, $column_index);
    if (!$target_column) {
      return false;
    }

    // 检查任务是否已经在目标列中
    if ($this->isTaskInColumnOptimized($task, $target_column, $task_positions)) {
      return false;
    }

    // 返回移动信息
    return $this->prepareTaskMove($task, $target_column);
  }

  /**
   * 优化版本的任务位置检查 - 使用预先获取的位置信息
   */
  private function isTaskInColumnOptimized($task, $column, $task_positions) {
    $task_phid = $task->getPHID();
    
    if (isset($task_positions[$task_phid])) {
      $position = $task_positions[$task_phid];
      return $position->getColumnPHID() === $column->getPHID();
    }

    return false;
  }

  /**
   * 准备任务移动事务
   */
  private function prepareTaskMove($task, $column) {
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

    return array(
      'task' => $task,
      'column' => $column,
      'xactions' => $xactions
    );
  }

  /**
   * 缓存版本的配置获取
   */
  private function getUpdateConfigCached() {
    if (self::$cached_config === null) {
      self::$cached_config = array(
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
        'log_moves' => false
      );
    }
    return self::$cached_config;
  }

  /**
   * 缓存版本的优先级映射查找
   */
  private function getTargetColumnPatternCached($priority, $config, $task = null) {
    $cache_key = $priority;
    if ($task) {
      $cache_key .= '_' . substr(md5($task->getTitle()), 0, 8);
    }
    
    if (isset(self::$priority_mapping_cache[$cache_key])) {
      return self::$priority_mapping_cache[$cache_key];
    }
    
    $result = $this->getTargetColumnPattern($priority, $config, $task);
    self::$priority_mapping_cache[$cache_key] = $result;
    
    return $result;
  }

  /**
   * 优化版本的列查找：使用预建索引
   */
  private function findMatchingColumnOptimized($column_patterns, $config, $column_index) {
    $case_sensitive = $config['case_sensitive'];
    
    foreach ($column_patterns as $pattern) {
      if (!$case_sensitive) {
        $pattern = strtolower($pattern);
      }
      
      // 首先尝试精确匹配
      if (isset($column_index[$pattern])) {
        return $column_index[$pattern];
      }
      
      // 然后尝试模糊匹配
      foreach ($column_index as $column_name => $column) {
        if (strpos($column_name, $pattern) !== false) {
          return $column;
        }
      }
    }
    
    return null;
  }

  /**
   * 优化版本的批量移动：重用编辑器
   */
  private function batchMoveTasksOptimized($task_moves, $viewer) {
    $moved_count = 0;
    
    // 重用编辑器实例，减少对象创建开销
    $editor = id(new ManiphestTransactionEditor())
      ->setActor($viewer)
      ->setContentSource(PhabricatorContentSource::newForSource('web', array()))
      ->setContinueOnNoEffect(true)
      ->setContinueOnMissingFields(true);
    
    foreach ($task_moves as $task_move) {
      try {
        $editor->applyTransactions($task_move['task'], $task_move['xactions']);
        $moved_count++;
      } catch (Exception $e) {
        $task_id = $task_move['task']->getID();
        phlog("UpdateColumns Move Error for task T{$task_id}: " . $e->getMessage());
      }
    }

    return $moved_count;
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