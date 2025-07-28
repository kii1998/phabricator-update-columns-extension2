<?php

/**
 * Update Columns Controller
 * 
 * 处理"Update_Columns"按钮点击，根据任务优先级批量更新工作板列位置
 */
final class UpdateColumnsController extends PhabricatorController {

  public function shouldAllowPublic() {
    return false;
  }

  public function handleRequest(AphrontRequest $request) {
    $viewer = $this->getViewer();
    $project_id = $request->getURIData('projectID');
    
    // 添加调试日志
    phlog('UpdateColumnsController: handleRequest called');
    phlog('UpdateColumnsController: isAjax=' . ($request->isAjax() ? 'true' : 'false'));
    phlog('UpdateColumnsController: isFormPost=' . ($request->isFormPost() ? 'true' : 'false'));
    
    // 检查AJAX相关的请求头
    $headers = $request->getHTTPHeader('X-Requested-With');
    phlog('UpdateColumnsController: X-Requested-With header=' . ($headers ?: 'not set'));
    
    $accept = $request->getHTTPHeader('Accept');
    phlog('UpdateColumnsController: Accept header=' . ($accept ?: 'not set'));
    
    $contentType = $request->getHTTPHeader('Content-Type');
    phlog('UpdateColumnsController: Content-Type header=' . ($contentType ?: 'not set'));
    
    // 检查POST数据中的__ajax__参数
    $ajaxParam = $request->getStr('__ajax__');
    phlog('UpdateColumnsController: __ajax__ parameter=' . ($ajaxParam ?: 'not set'));
    
    // 通过数字ID获取项目对象
    $project = id(new PhabricatorProjectQuery())
      ->setViewer($viewer)
      ->withIDs(array($project_id))
      ->executeOne();
    
    if (!$project) {
      return new Aphront404Response();
    }

    // 检查用户权限
    if (!PhabricatorPolicyFilter::hasCapability(
      $viewer,
      $project,
      PhabricatorPolicyCapability::CAN_VIEW)) {
      return new Aphront403Response();
    }

    // 处理AJAX请求 - 尝试多种识别方法
    $isAjax = $request->isAjax() || 
               $request->getHTTPHeader('X-Requested-With') === 'XMLHttpRequest' ||
               $request->getStr('__ajax__') === '1' ||
               strpos($request->getHTTPHeader('Accept') ?: '', 'application/json') !== false;
    
    phlog('UpdateColumnsController: Final AJAX detection=' . ($isAjax ? 'true' : 'false'));
    
    if ($isAjax && $request->isFormPost()) {
      phlog('UpdateColumnsController: Processing AJAX request');
      return $this->processUpdateColumnsAjax($project, $viewer, $request);
    }

    if ($request->isFormPost()) {
      phlog('UpdateColumnsController: Processing regular form post');
      return $this->processUpdateColumns($project, $viewer);
    }

    phlog('UpdateColumnsController: Rendering confirmation dialog');
    return $this->renderConfirmationDialog($project);
  }

  private function processUpdateColumnsAjax($project, $viewer, $request) {
    phlog('UpdateColumnsController: processUpdateColumnsAjax called');
    
    try {
      // 获取项目PHID（这是真正的board ID）
      $board_phid = $project->getPHID();
      
      // 获取该工作板上的所有任务
      $tasks = $this->getBoardTasks($board_phid, $viewer);
      
      if (empty($tasks)) {
        phlog('UpdateColumnsController: No tasks found');
        return id(new AphrontAjaxResponse())
          ->setContent(array(
            'success' => false,
            'error' => pht('No tasks found on this workboard.'),
            'moved_count' => 0,
            'error_count' => 0,
            'total_count' => 0
          ));
      }

      // 使用更新服务批量处理任务
      $service = new UpdateColumnsService();
      $results = $service->updateTaskColumns($tasks, $board_phid, $viewer);

      // 创建成功响应
      $moved_count = $results['moved_count'];
      $error_count = $results['error_count'];
      $total_count = count($tasks);
      
      phlog('UpdateColumnsController: AJAX success - moved: ' . $moved_count . ', errors: ' . $error_count);
      
      return id(new AphrontAjaxResponse())
        ->setContent(array(
          'success' => true,
          'moved_count' => $moved_count,
          'error_count' => $error_count,
          'total_count' => $total_count,
          'message' => pht(
            'Successfully updated %d of %d tasks based on priority.',
            $moved_count,
            $total_count
          )
        ));

    } catch (Exception $e) {
      phlog('UpdateColumnsController: AJAX error - ' . $e->getMessage());
      // 生成错误日志链接
      $error_log_uri = '/logs/error/' . date('Y-m-d');
      
      return id(new AphrontAjaxResponse())
        ->setContent(array(
          'success' => false,
          'error' => pht('Failed to update columns: %s', $e->getMessage()),
          'error_log_uri' => $error_log_uri,
          'moved_count' => 0,
          'error_count' => 1,
          'total_count' => 0
        ));
    }
  }

  private function processUpdateColumns($project, $viewer) {
    try {
      // 获取项目PHID（这是真正的board ID）
      $board_phid = $project->getPHID();
      
      // 获取该工作板上的所有任务
      $tasks = $this->getBoardTasks($board_phid, $viewer);
      
      if (empty($tasks)) {
        return $this->newDialog()
          ->setTitle(pht('No Tasks Found'))
          ->appendChild(pht('No tasks found on this workboard.'))
          ->addCancelButton('/project/board/' . $project->getID() . '/', pht('OK'));
      }

      // 使用更新服务批量处理任务
      $service = new UpdateColumnsService();
      $results = $service->updateTaskColumns($tasks, $board_phid, $viewer);

      // 创建成功响应
      $moved_count = $results['moved_count'];
      $error_count = $results['error_count'];
      $total_count = count($tasks);
      
      $message = pht(
        'Updated %d of %d tasks based on priority. %d errors occurred.',
        $moved_count,
        $total_count,
        $error_count
      );

      return id(new AphrontRedirectResponse())
        ->setURI('/project/board/' . $project->getID() . '/');

    } catch (Exception $e) {
      return $this->newDialog()
        ->setTitle(pht('Update Failed'))
        ->appendChild(pht('Failed to update columns: %s', $e->getMessage()))
        ->addCancelButton('/project/board/' . $project->getID() . '/', pht('OK'));
    }
  }

  private function renderConfirmationDialog($project) {
    // Load the JavaScript resource for AJAX functionality
    $this->requireResource('update-columns-js');

    $dialog = $this->newDialog()
      ->setTitle(pht('Update Task Columns'))
      ->appendChild(
        pht(
          'This will move all tasks on the "%s" workboard to appropriate columns ' .
          'based on their priority levels.',
          $project->getDisplayName()
        )
      )
      ->appendChild(
        phutil_tag('br')
      )
      ->appendChild(
        pht(
          'Tasks will be moved according to the configured priority mapping rules:'
        )
      )
      ->appendChild(
        phutil_tag('ul', array(), array(
          phutil_tag('li', array(), pht('Unbreak Now! (100) → "Unbreak Now!" column')),
          phutil_tag('li', array(), pht('High Priority (90, 80) → "High Priority" column')),
          phutil_tag('li', array(), pht('Normal Priority (50) → "In Progress" column')),
          phutil_tag('li', array(), pht('Low Priority (25, 1-39) → "Low Priority" column')),
          phutil_tag('li', array(), pht('Wishlist (0) → "Wishlist" column')),
        ))
      )
      ->addSubmitButton(pht('Update Columns'))
      ->addCancelButton('/project/board/' . $project->getID() . '/', pht('Cancel'));

    return $dialog;
  }

  /**
   * 获取指定工作板上的所有任务
   * 修复：不依赖位置查询，直接通过项目关系获取任务
   */
  private function getBoardTasks($board_phid, $viewer) {
    phlog('UpdateColumnsController: getBoardTasks called for board=' . $board_phid);
    
    // 方法1：通过边查询获取与项目关联的任务（更可靠）
    $edge_query = id(new PhabricatorEdgeQuery())
      ->withSourcePHIDs(array($board_phid))
      ->withEdgeTypes(array(
        PhabricatorProjectProjectHasObjectEdgeType::EDGECONST,
      ));
    $task_phids = $edge_query->execute();
    $task_phids = $edge_query->getDestinationPHIDs(array($board_phid));
    
    // 过滤出任务PHIDs
    $task_phids = array_filter($task_phids, function($phid) {
      return substr($phid, 0, 10) === 'PHID-TASK-';
    });
    
    phlog('UpdateColumnsController: Found ' . count($task_phids) . ' task PHIDs via edge query');
    
    $tasks = array();
    if (!empty($task_phids)) {
      $tasks = id(new ManiphestTaskQuery())
        ->setViewer($viewer)
        ->withPHIDs($task_phids)
        ->withStatuses(ManiphestTaskStatus::getOpenStatusConstants())
        ->execute();
    }

    phlog('UpdateColumnsController: Found ' . count($tasks) . ' tasks via project relation');
    
    // 如果直接查询没有结果，尝试位置查询作为备用方案
    if (empty($tasks)) {
      phlog('UpdateColumnsController: Falling back to position query');
      
      $positions = id(new PhabricatorProjectColumnPositionQuery())
        ->setViewer($viewer)
        ->withBoardPHIDs(array($board_phid))
        ->execute();

      if (!empty($positions)) {
        // 提取任务PHIDs
        $task_phids = array();
        foreach ($positions as $position) {
          $object_phid = $position->getObjectPHID();
          // 只处理任务对象
          if (substr($object_phid, 0, 10) === 'PHID-TASK-') {
            $task_phids[] = $object_phid;
          }
        }

        if (!empty($task_phids)) {
          // 获取任务对象
          $tasks = id(new ManiphestTaskQuery())
            ->setViewer($viewer)
            ->withPHIDs($task_phids)
            ->withStatuses(ManiphestTaskStatus::getOpenStatusConstants())
            ->execute();
            
          phlog('UpdateColumnsController: Found ' . count($tasks) . ' tasks via position query fallback');
        }
      }
    }

    // 为每个任务记录调试信息
    foreach ($tasks as $task) {
      $priority = $task->getPriority();
      $status = $task->getStatus();
      phlog('UpdateColumnsController: Task T' . $task->getID() . ' - Priority: ' . $priority . ', Status: ' . $status);
    }

    return $tasks;
  }
} 